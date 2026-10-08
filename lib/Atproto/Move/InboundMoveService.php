<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Move;

use InvalidArgumentException;
use OCA\Social\Atproto\AppView\ServiceAuth;
use OCA\Social\Atproto\Client\ClientSession;
use OCA\Social\Atproto\Client\Preferences;
use OCA\Social\Atproto\Client\SessionService;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Crypto\PublicKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\InstanceKeyService;
use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Atproto\Identity\PlcOperation;
use OCA\Social\Atproto\Model\BlobRef;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\Move;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Atproto\Xrpc\XrpcException;
use OCA\Social\Cron\AtprotoMove;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoMoveRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCA\Social\Service\DocumentService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Security\Bruteforce\IThrottler;
use OCP\Security\Bruteforce\MaxDelayReached;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Moving a Bluesky account here driven by the other side (§13.3): Bridgy
 * Fed for an account it bridged, or any migration tool. This server is the
 * new PDS of the AT Protocol's account migration.
 *
 * The person invites the DID first, and gets a one-time code. With it and a
 * service-auth token the DID signs, the other side makes the account here
 * (`createAccount`) and gets a session that can do one thing: move the
 * account. It sends the repository (`importRepo`, checked against the
 * DID's key and kept under a commit signed with a key made for the account
 * here) and the blobs, asks what to point the DID at
 * (`getRecommendedDidCredentials`), points it here — itself, or through
 * `submitPlcOperation` — and activates the account. Activation checks that
 * the directory names this server and its key; the account then takes the
 * DID, the one this server had made for it is retired, and its follows
 * become follows here. Blobs may still arrive after that, as Bridgy sends
 * them.
 */
class InboundMoveService {
	/** how long an invitation waits for the account to be made with it */
	public const INVITATION_LIFETIME = 86400;
	/** how long the session the other side gets lasts */
	public const SESSION_LIFETIME = 7 * 86400;
	/** the largest repository taken */
	public const MAX_REPO = 64 * 1024 * 1024;
	private const PAGE = 500;
	private const THROTTLE = 'social_atproto_move_inbound';
	private const CREATE = 'com.atproto.server.createAccount';

	public function __construct(
		private AtprotoConfig $config,
		private SessionService $sessions,
		private ServiceAuth $serviceAuth,
		private PlcClient $plc,
		private IdentityService $identities,
		private InstanceKeyService $instanceKeys,
		private RepositoryService $repositories,
		private AtprotoRepoRequest $repoRequest,
		private AtprotoBlobRequest $blobs,
		private DocumentService $documents,
		private Preferences $preferences,
		private MoveInService $moveIn,
		private BridgyTwin $bridgy,
		private ActorsRequest $actors,
		private AtprotoMoveRequest $moves,
		private ICrypto $crypto,
		private ISecureRandom $random,
		private IThrottler $throttler,
		private IJobList $jobList,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Invites a DID to move here: a Bridgy Fed twin is asked to with Bridgy's
	 * command, sent from the account; for anything else, what a migration
	 * tool needs is answered, the code once.
	 *
	 * @return array{move: Move, bridgy: bool, pds: string, handle: string, email: string, code: string}
	 * @throws InvalidArgumentException with what to tell the person
	 * @throws AtprotoException
	 */
	public function invite(string $userId, string $typed): array {
		$latest = $this->moves->latestOfUser($userId);
		if ($latest !== null && in_array($latest->state, [Move::RUNNING, Move::WAITING], true)) {
			if ($latest->direction !== Move::INBOUND || $latest->step !== Move::STEP_INVITED) {
				throw new InvalidArgumentException('A move is already under way');
			}
			$this->end($latest, 'Replaced by a new invitation');
		}
		$did = $this->moveIn->didOf($typed);
		try {
			$this->identities->getByDid($did);
			throw new InvalidArgumentException('That Bluesky account is on this server already');
		} catch (AtprotoIdentityNotFoundException) {
		}
		$data = $this->plc->data($did);
		$endpoint = rtrim((string)($data['services']['atproto_pds']['endpoint'] ?? ''), '/');
		if ($endpoint === '') {
			throw new InvalidArgumentException('The directory names no server for that account');
		}
		$actor = $this->actors->getFromUserId($userId);
		$identity = $this->identities->forActor($actor) ?? throw new InvalidArgumentException('Bluesky is off for this account');
		$oldHandle = (string)preg_replace('~^at://~', '', (string)($data['alsoKnownAs'][0] ?? $did));

		$code = implode('-', str_split($this->random->generate(24, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS), 6));
		$key = PrivateKey::generate(Curve::K256);
		$move = new Move(0, $did, $userId, Move::INBOUND, $endpoint, '', $oldHandle, Move::STEP_INVITED, Move::WAITING);
		$move->session = $this->seal([
			'code' => hash('sha256', $code),
			'key' => bin2hex($key->secret()),
			'public' => $key->didKey(),
			'until' => $this->time->getTime() + self::INVITATION_LIFETIME,
		]);
		$move = $this->moves->get($this->moves->add($move)) ?? $move;

		$pds = (string)parse_url($this->config->pdsEndpoint(), PHP_URL_HOST);
		$port = parse_url($this->config->pdsEndpoint(), PHP_URL_PORT);
		$pds .= is_int($port) ? ':' . $port : '';
		$email = ltrim($actor->getAccount(), '@');
		$bridgy = BridgyTwin::hosts($endpoint);
		if ($bridgy) {
			try {
				$this->bridgy->ask($actor, BridgyTwin::command($pds, $email, $identity->handle, $code));
			} catch (Throwable $e) {
				$this->end($move, 'Bridgy Fed could not be asked');
				$this->logger->warning('Bridgy Fed not asked to move a twin here', ['did' => $did, 'exception' => $e]);

				throw new InvalidArgumentException('Bridgy Fed could not be sent the request; try again later');
			}
		}

		return ['move' => $move, 'bridgy' => $bridgy, 'pds' => $pds, 'handle' => $identity->handle, 'email' => $email, 'code' => $bridgy ? '' : $code];
	}

	/**
	 * Calls off a move here that is not finished: what arrived of it is
	 * dropped.
	 *
	 * @throws InvalidArgumentException
	 */
	public function cancel(string $userId): Move {
		$move = $this->latest($userId);
		if ($move === null || $move->direction !== Move::INBOUND || $move->state !== Move::WAITING) {
			throw new InvalidArgumentException('There is no move here to call off');
		}
		$this->end($move, 'Called off');

		return $move;
	}

	/**
	 * The person's latest move, an inbound one that ran out of time ended.
	 */
	public function latest(string $userId): ?Move {
		$move = $this->moves->latestOfUser($userId);
		if ($move !== null && $move->direction === Move::INBOUND && $move->state === Move::WAITING
			&& (int)($this->unseal($move->session)['until'] ?? 0) < $this->time->getTime()) {
			$this->end($move, $move->step === Move::STEP_INVITED ? 'The invitation expired' : 'The move was not finished in time');
		}

		return $move;
	}

	/**
	 * Whether a bearer token is one of a move here, which only this answers.
	 */
	public function owns(string $authorization): bool {
		if (preg_match('/^Bearer\s+[A-Za-z0-9_-]+\.([A-Za-z0-9_-]+)\.[A-Za-z0-9_-]+$/', trim($authorization), $m) !== 1) {
			return false;
		}
		$padded = strtr($m[1], '-_', '+/');
		$claims = json_decode((string)base64_decode($padded . str_repeat('=', (4 - strlen($padded) % 4) % 4), true), true);

		return is_array($claims) && is_int($claims['mv'] ?? null);
	}

	/**
	 * `com.atproto.server.createAccount`: only for a DID that was invited,
	 * with its code and a token the DID signed.
	 *
	 * @throws XrpcException
	 */
	public function createAccount(string $authorization, array $body, string $ip): array {
		try {
			$this->throttler->sleepDelayOrThrowOnMax($ip, self::THROTTLE);
		} catch (MaxDelayReached) {
			throw new XrpcException(429, 'RateLimitExceeded', 'Too many attempts; try again later');
		}
		$did = is_string($body['did'] ?? null) ? $body['did'] : '';
		$move = str_starts_with($did, 'did:plc:') ? $this->moves->latestOfDid($did, Move::INBOUND) : null;
		$sealed = $move === null ? [] : $this->unseal($move->session);
		$offered = array_map(static fn ($value): string => hash('sha256', is_string($value) ? $value : ''), [$body['password'] ?? '', $body['inviteCode'] ?? '']);
		if ($move === null || $move->step !== Move::STEP_INVITED || $move->state !== Move::WAITING
			|| (int)($sealed['until'] ?? 0) < $this->time->getTime()
			|| !(hash_equals((string)($sealed['code'] ?? ''), $offered[0]) || hash_equals((string)($sealed['code'] ?? ''), $offered[1]))) {
			$this->throttler->registerAttempt(self::THROTTLE, $ip, ['did' => substr($did, 0, 64)]);

			throw new XrpcException(400, 'InvalidInviteCode', 'Accounts are made here only by moving one in, with the code its owner was given');
		}
		$token = (string)preg_replace('/^Bearer\s+/', '', trim($authorization));
		if ($this->serviceAuth->issuer($token) !== $did || !$this->serviceAuth->verify($token, $this->signingKeyOf($did), $this->config->serviceDid(), self::CREATE)) {
			$this->throttler->registerAttempt(self::THROTTLE, $ip, ['did' => $did]);

			throw new XrpcException(401, 'AuthenticationRequired', 'A token the account signed for this server is required');
		}

		try {
			$this->repositories->delete($did);
			$this->repositories->import($did, $this->key($move), []);
		} catch (AtprotoException $e) {
			$this->logger->error('Repository for a Bluesky account moving here not made', ['did' => $did, 'exception' => $e]);

			throw new XrpcException(500, 'InternalServerError', 'The account could not be made');
		}
		$sid = bin2hex(random_bytes(16));
		$until = $this->time->getTime() + self::SESSION_LIFETIME;
		$move->session = $this->seal(['sid' => $sid, 'until' => $until] + $sealed);
		$move->step = Move::STEP_REPO;
		$this->moves->update($move);
		$this->logger->info('Bluesky account made here to move in', ['did' => $did, 'user' => $move->userId]);

		return $this->open($move, $sid, $until);
	}

	/**
	 * A query a move's session makes.
	 *
	 * @param array<string, mixed> $params
	 * @throws XrpcException
	 */
	public function query(string $method, array $params, string $authorization): array {
		$move = $this->authenticate($authorization);

		return match ($method) {
			'com.atproto.server.getSession' => ['handle' => $this->handleHere($move), 'did' => $move->did, 'active' => $this->isActivated($move), 'emailConfirmed' => false],
			'com.atproto.server.checkAccountStatus' => $this->status($move),
			'com.atproto.identity.getRecommendedDidCredentials' => $this->credentials($move),
			'com.atproto.repo.listMissingBlobs' => $this->missingBlobs($move, (int)($params['limit'] ?? self::PAGE), is_string($params['cursor'] ?? null) ? $params['cursor'] : ''),
			'app.bsky.actor.getPreferences' => $this->preferences->get($this->sessionFor($move)),
			default => throw self::onlyMoving(),
		};
	}

	/**
	 * A procedure a move's session calls.
	 *
	 * @throws XrpcException
	 */
	public function procedure(string $method, array $body, string $authorization): array {
		if ($method === 'com.atproto.server.refreshSession') {
			return $this->refresh($authorization);
		}
		$move = $this->authenticate($authorization);

		return match ($method) {
			'com.atproto.server.activateAccount' => $this->activate($move),
			'com.atproto.identity.submitPlcOperation' => $this->submit($move, is_array($body['operation'] ?? null) ? $body['operation'] : []),
			'app.bsky.actor.putPreferences' => $this->preferences->put($this->sessionFor($move), $body),
			'com.atproto.server.deleteSession' => [],
			default => throw self::onlyMoving(),
		};
	}

	/**
	 * `com.atproto.repo.importRepo`, from the file the CAR was copied to:
	 * the repository as the old PDS kept it, checked against the key the
	 * DID document names now, replacing what came before it.
	 *
	 * @throws XrpcException
	 */
	public function importRepo(string $path, string $authorization): array {
		$move = $this->authenticate($authorization);
		if ($this->isActivated($move)) {
			throw XrpcException::invalidRequest('The account is active here; its repository is not replaced');
		}
		try {
			$archive = RepoArchive::read((string)file_get_contents($path), $move->did, $this->signingKeyOf($move->did));
			$this->repositories->delete($move->did);
			$this->repositories->import($move->did, $this->key($move), $archive['records'], $archive['rev']);
		} catch (AtprotoException $e) {
			throw XrpcException::invalidRequest($e->getMessage());
		}
		$expected = count($this->referencedBlobs($move->did));
		$move->progress = ['records' => count($archive['records']), 'expectedBlobs' => $expected] + $move->progress;
		$move->step = Move::STEP_BLOBS;
		$this->moves->update($move);

		return [];
	}

	/**
	 * `com.atproto.repo.uploadBlob` for a move: kept byte for byte, so its CID
	 * is the one the records name.
	 *
	 * @throws XrpcException
	 */
	public function upload(string $path, string $authorization, string $type): array {
		$move = $this->authenticate($authorization);
		$size = (int)filesize($path);
		$cid = Cid::forRawDigest((string)hash_file('sha256', $path, true));
		$mime = $type !== '' && $type !== '*/*' ? $type : (string)mime_content_type($path);
		$stored = $this->blobs->get($move->did, $cid->toString());
		if ($stored === null) {
			try {
				$document = $this->documents->storeAsIs($this->actors->getFromUserId($move->userId), $path);
			} catch (Throwable $e) {
				$this->logger->warning('Blob of a move here not stored', ['did' => $move->did, 'exception' => $e]);

				throw new XrpcException(500, 'InternalServerError', 'The blob could not be stored');
			}
			$this->blobs->put(new BlobRef($move->did, $cid, $document->getId(), $mime, $size));
			$this->repoRequest->addBlobBytes($move->did, $size);
			$move->progress = ['blobs' => (int)($move->progress['blobs'] ?? 0) + 1] + $move->progress;
			$this->moves->update($move);
		}

		return ['blob' => ['$type' => 'blob', 'ref' => ['$link' => $cid->toString()], 'mimeType' => $stored?->mime ?? $mime, 'size' => $stored?->size ?? $size]];
	}

	/**
	 * After activation: the follows become follows here, and the move is done.
	 */
	public function run(Move $move): void {
		if ($move->direction !== Move::INBOUND || $move->state !== Move::RUNNING || $move->step !== Move::STEP_FOLLOWS) {
			return;
		}
		try {
			$this->moveIn->adoptFollows($move);
		} catch (Throwable $e) {
			$this->logger->warning('Follows of a Bluesky account that moved here not taken over', ['did' => $move->did, 'exception' => $e]);
		}
		// the key lives on in the identity; what the session still needs is kept
		$sealed = $this->unseal($move->session);
		$move->session = $this->seal(['sid' => $sealed['sid'] ?? '', 'until' => $sealed['until'] ?? 0, 'public' => $sealed['public'] ?? '']);
		$move->step = Move::STEP_DONE;
		$move->state = Move::DONE;
		$this->moves->update($move);
	}

	/**
	 * `com.atproto.server.activateAccount`: once the directory names this
	 * server and the key made here, the account takes the DID.
	 *
	 * @throws XrpcException
	 */
	private function activate(Move $move): array {
		if ($this->isActivated($move)) {
			return [];
		}
		if ($move->step === Move::STEP_INVITED || $this->repositories->getHead($move->did) === null) {
			throw XrpcException::invalidRequest('The repository has not arrived');
		}
		if (!$this->pointsHere($move, $this->plc->data($move->did) ?? [])) {
			throw XrpcException::invalidRequest('The DID does not name this server and the key it recommended yet');
		}
		$current = $this->identities->forActor($this->actors->getFromUserId($move->userId));
		if ($current === null) {
			throw XrpcException::invalidRequest('Bluesky is off for this account');
		}
		try {
			$this->identities->receive($current, $move->did, $this->key($move), $move->pds);
		} catch (AtprotoException $e) {
			$this->logger->error('A Bluesky account that moved here could not take its DID', ['did' => $move->did, 'exception' => $e]);

			throw new XrpcException(500, 'InternalServerError', 'The account could not be activated');
		}
		$move->step = Move::STEP_FOLLOWS;
		$move->state = Move::RUNNING;
		$this->moves->update($move);
		$this->jobList->add(AtprotoMove::class, ['move' => $move->id]);
		$this->logger->info('Bluesky account moved here', ['did' => $move->did, 'user' => $move->userId, 'from' => $move->pds]);

		return [];
	}

	/**
	 * `com.atproto.identity.submitPlcOperation`: an operation that points the
	 * DID here, as recommended, is sent to the directory.
	 *
	 * @throws XrpcException
	 */
	private function submit(Move $move, array $operation): array {
		if ($this->isActivated($move)) {
			throw XrpcException::invalidRequest('The account is active here already');
		}
		if (!$this->pointsHere($move, $operation)) {
			throw XrpcException::invalidRequest('The operation does not point the DID at this server with the recommended key');
		}
		try {
			$this->identities->submit($move->did, $operation);
		} catch (AtprotoException $e) {
			throw XrpcException::invalidRequest($e->getMessage());
		}

		return [];
	}

	/**
	 * `com.atproto.server.checkAccountStatus`.
	 */
	private function status(Move $move): array {
		$head = $this->repositories->getHead($move->did);
		$referenced = $this->referencedBlobs($move->did);
		$imported = 0;
		foreach (array_keys($referenced) as $cid) {
			$imported += $this->blobs->get($move->did, (string)$cid) !== null ? 1 : 0;
		}
		try {
			$valid = $this->pointsHere($move, $this->plc->data($move->did) ?? []);
		} catch (AtprotoException) {
			$valid = false;
		}

		return [
			'activated' => $this->isActivated($move),
			'validDid' => $valid,
			'repoCommit' => $head?->commitCid ?? '',
			'repoRev' => $head?->rev ?? '',
			'repoBlocks' => count($this->repoRequest->getBlockCids($move->did)) + $this->repoRequest->countRecords($move->did),
			'indexedRecords' => $this->repoRequest->countRecords($move->did),
			'privateStateValues' => 0,
			'expectedBlobs' => count($referenced),
			'importedBlobs' => $imported,
		];
	}

	/**
	 * `com.atproto.identity.getRecommendedDidCredentials`: this server's
	 * rotation key, the account's handle here, the key made for it here and
	 * this PDS.
	 */
	private function credentials(Move $move): array {
		return [
			'rotationKeys' => [$this->instanceKeys->rotationKey()->didKey()],
			'alsoKnownAs' => ['at://' . $this->handleHere($move)],
			'verificationMethods' => ['atproto' => $this->publicKey($move)],
			'services' => ['atproto_pds' => ['type' => PlcOperation::PDS_SERVICE_TYPE, 'endpoint' => $this->config->pdsEndpoint()]],
		];
	}

	/**
	 * `com.atproto.repo.listMissingBlobs`, in CID order.
	 */
	private function missingBlobs(Move $move, int $limit, string $cursor): array {
		$limit = max(1, min(1000, $limit));
		$referenced = $this->referencedBlobs($move->did);
		ksort($referenced, SORT_STRING);
		$blobs = [];
		foreach ($referenced as $cid => $path) {
			if (strcmp((string)$cid, $cursor) <= 0 || $this->blobs->get($move->did, (string)$cid) !== null) {
				continue;
			}
			$blobs[] = ['cid' => (string)$cid, 'recordUri' => 'at://' . $move->did . '/' . $path];
			if (count($blobs) === $limit) {
				return ['cursor' => (string)$cid, 'blobs' => $blobs];
			}
		}

		return ['blobs' => $blobs];
	}

	/**
	 * Every blob the repository's records name, with the first record that
	 * names it.
	 *
	 * @return array<string, string> CID => record path
	 */
	private function referencedBlobs(string $did): array {
		$referenced = [];
		$this->repoRequest->eachRecordBytes($did, static function (Cid $cid, string $bytes, string $path) use (&$referenced): void {
			try {
				$value = DagCbor::decode($bytes);
			} catch (Throwable) {
				return;
			}
			foreach (self::blobsIn($value) as $blob) {
				$referenced[$blob] ??= $path;
			}
		});

		return $referenced;
	}

	/**
	 * @return list<string> the CIDs of the blobs a record value names
	 */
	private static function blobsIn(mixed $value): array {
		if (!is_array($value)) {
			return [];
		}
		if (($value['$type'] ?? null) === 'blob' && ($value['ref'] ?? null) instanceof Cid) {
			return [$value['ref']->toString()];
		}
		$found = [];
		foreach ($value as $item) {
			array_push($found, ...self::blobsIn($item));
		}

		return $found;
	}

	/**
	 * Whether a DID's state, or an operation, names this PDS, the key made
	 * for the account here and this server's rotation key.
	 */
	private function pointsHere(Move $move, array $state): bool {
		return rtrim((string)($state['services']['atproto_pds']['endpoint'] ?? ''), '/') === $this->config->pdsEndpoint()
			&& ($state['verificationMethods']['atproto'] ?? '') === $this->publicKey($move)
			&& in_array($this->instanceKeys->rotationKey()->didKey(), is_array($state['rotationKeys'] ?? null) ? $state['rotationKeys'] : [], true);
	}

	private function isActivated(Move $move): bool {
		return in_array($move->step, [Move::STEP_FOLLOWS, Move::STEP_DONE], true);
	}

	/**
	 * @throws XrpcException
	 */
	private function signingKeyOf(string $did): PublicKey {
		try {
			$didKey = (string)($this->plc->data($did)['verificationMethods']['atproto'] ?? '');

			return PublicKey::fromDidKey($didKey);
		} catch (Throwable) {
			throw new XrpcException(400, 'UnresolvableDid', 'The DID\'s signing key could not be read from the directory');
		}
	}

	private function handleHere(Move $move): string {
		try {
			return $this->identities->forActor($this->actors->getFromUserId($move->userId), false)?->handle ?? $move->handle;
		} catch (Throwable) {
			return $move->handle;
		}
	}

	/**
	 * @throws XrpcException
	 */
	private function refresh(string $authorization): array {
		$claims = $this->sessions->claims($authorization, SessionService::REFRESH_SCOPE);
		$move = $this->moves->get((int)($claims['mv'] ?? 0));
		$sealed = $move === null ? [] : $this->unseal($move->session);
		if ($move === null || !$this->isCurrent($move, $sealed, (string)($claims['sub'] ?? ''), (string)($claims['jti'] ?? ''))) {
			throw new XrpcException(400, 'ExpiredToken', 'Token has been revoked');
		}
		$sid = bin2hex(random_bytes(16));
		$move->session = $this->seal(['sid' => $sid] + $sealed);
		$this->moves->update($move);

		return $this->open($move, $sid, (int)$sealed['until']);
	}

	/**
	 * The move an access token is a session of.
	 *
	 * @throws XrpcException
	 */
	private function authenticate(string $authorization): Move {
		$claims = $this->sessions->claims($authorization, SessionService::ACCESS_SCOPE);
		$move = $this->moves->get((int)($claims['mv'] ?? 0));
		if ($move === null || !$this->isCurrent($move, $this->unseal($move->session), (string)($claims['sub'] ?? ''), (string)($claims['sid'] ?? ''))) {
			throw new XrpcException(401, 'ExpiredToken', 'Token has been revoked');
		}

		return $move;
	}

	private function isCurrent(Move $move, array $sealed, string $did, string $sid): bool {
		return $move->direction === Move::INBOUND
			&& $move->state !== Move::FAILED
			&& $move->did === $did
			&& $sid !== ''
			&& hash_equals((string)($sealed['sid'] ?? ''), $sid)
			&& (int)($sealed['until'] ?? 0) >= $this->time->getTime();
	}

	private function open(Move $move, string $sid, int $until): array {
		$now = $this->time->getTime();

		return [
			'accessJwt' => $this->sessions->sign(['scope' => SessionService::ACCESS_SCOPE, 'sub' => $move->did, 'mv' => $move->id, 'sid' => $sid, 'iat' => $now, 'exp' => min($now + SessionService::ACCESS_LIFETIME, $until)]),
			'refreshJwt' => $this->sessions->sign(['scope' => SessionService::REFRESH_SCOPE, 'sub' => $move->did, 'mv' => $move->id, 'jti' => $sid, 'iat' => $now, 'exp' => $until]),
			'handle' => $this->handleHere($move),
			'did' => $move->did,
			'active' => $this->isActivated($move),
		];
	}

	/**
	 * Ends a move that did not finish; what arrived of the repository and its
	 * blobs is dropped, unless the DID is an account's here after all.
	 */
	private function end(Move $move, string $why): void {
		$move->state = Move::FAILED;
		$move->error = $why;
		$move->session = '';
		$this->moves->update($move);
		try {
			$this->identities->getByDid($move->did);
		} catch (AtprotoIdentityNotFoundException) {
			$this->repositories->delete($move->did);
			$this->blobs->deleteByDid($move->did);
		}
	}

	private function sessionFor(Move $move): ClientSession {
		return new ClientSession($move->userId, new Identity(0, '', $move->did, $move->handle, '', '', '', Identity::STATE_DEACTIVATED, $move->pds, 0), '');
	}

	/**
	 * @throws XrpcException
	 */
	private function key(Move $move): PrivateKey {
		$secret = (string)($this->unseal($move->session)['key'] ?? '');
		if ($secret === '') {
			throw XrpcException::invalidRequest('The move has ended');
		}

		return new PrivateKey(Curve::K256, (string)hex2bin($secret));
	}

	/** the key made for the account here, as a did:key */
	private function publicKey(Move $move): string {
		return (string)($this->unseal($move->session)['public'] ?? '');
	}

	private static function onlyMoving(): XrpcException {
		return new XrpcException(403, 'InsufficientScope', 'This session only moves the account here; sign in with an app password for anything else');
	}

	private function seal(array $session): string {
		return $this->crypto->encrypt((string)json_encode($session));
	}

	private function unseal(string $sealed): array {
		if ($sealed === '') {
			return [];
		}
		try {
			$session = json_decode($this->crypto->decrypt($sealed), true);
		} catch (Throwable) {
			return [];
		}

		return is_array($session) ? $session : [];
	}
}
