<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Move;

use InvalidArgumentException;
use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Client\ClientSession;
use OCA\Social\Atproto\Client\Preferences;
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
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Atproto\Reader\BlueskyActorService;
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
use OCA\Social\Service\FollowService;
use OCP\BackgroundJob\IJobList;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Moving a Bluesky account here (§13.1), driven from here: the person names
 * their Bluesky account and its password, and this server signs in to the
 * old PDS as them and does what a migration tool would.
 *
 * The password is the account's own, not an app password: a PDS signs a
 * change of the DID only for a full session. It is used to sign in when the
 * move starts and not kept; the session is, sealed, while the move runs.
 *
 * A background job copies the repository — checked against the DID's
 * signing key, written here byte for byte under a commit signed with a key
 * made for the account here — the blobs, the preferences and the follows.
 * Then the old PDS e-mails the person a code; with it, it signs the
 * operation that points the DID here, and the account takes the DID: the
 * one this server had made for it is retired. Last, the account is switched
 * off on the old PDS.
 */
class MoveInService {
	private const PAGE = 500;

	public function __construct(
		private PdsClient $pds,
		private AtprotoConfig $config,
		private AppViewClient $appView,
		private PlcClient $plc,
		private IdentityService $identities,
		private InstanceKeyService $instanceKeys,
		private RepositoryService $repositories,
		private AtprotoRepoRequest $repoRequest,
		private AtprotoBlobRequest $blobs,
		private DocumentService $documents,
		private Preferences $preferences,
		private BlueskyActorService $blueskyActors,
		private FollowService $follows,
		private ActorsRequest $actors,
		private AtprotoMoveRequest $moves,
		private ICrypto $crypto,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Signs in to the old PDS and queues the copy.
	 *
	 * @param string $authFactorToken the code a PDS e-mails for a sign-in it wants confirmed
	 * @throws InvalidArgumentException with what to tell the person
	 * @throws AtprotoException
	 */
	public function start(string $userId, string $typed, string $password, string $authFactorToken = ''): Move {
		$latest = $this->moves->latestOfUser($userId);
		if ($latest !== null && in_array($latest->state, [Move::RUNNING, Move::WAITING], true)) {
			throw new InvalidArgumentException('A move is already under way');
		}
		$did = $this->didOf($typed);
		try {
			$this->identities->getByDid($did);
			throw new InvalidArgumentException('That Bluesky account is on this server already');
		} catch (AtprotoIdentityNotFoundException) {
		}
		$data = $this->plc->data($did);
		$endpoint = (string)($data['services']['atproto_pds']['endpoint'] ?? '');
		if ($endpoint === '') {
			throw new InvalidArgumentException('The directory names no server for that account');
		}
		$origin = $this->pds->origin($endpoint);

		$login = ['identifier' => $did, 'password' => $password];
		if (trim($authFactorToken) !== '') {
			$login['authFactorToken'] = trim($authFactorToken);
		}
		$answer = $this->pds->call($origin, 'com.atproto.server.createSession', 'POST', [], $login);
		if (($answer['body']['error'] ?? '') === 'AuthFactorTokenRequired') {
			throw new InvalidArgumentException('The Bluesky server e-mailed you a sign-in code: enter it and start again');
		}
		if ($answer['status'] !== 200 || ($answer['body']['did'] ?? '') !== $did) {
			throw new InvalidArgumentException('The Bluesky server did not let you sign in: ' . trim((string)($answer['body']['message'] ?? 'status ' . $answer['status'])));
		}

		$key = PrivateKey::generate(Curve::K256);
		$move = new Move(0, $did, $userId, Move::IN, $origin, '', (string)($answer['body']['handle'] ?? $typed), Move::STEP_REPO, Move::RUNNING);
		$move->session = $this->seal([
			'accessJwt' => (string)($answer['body']['accessJwt'] ?? ''),
			'refreshJwt' => (string)($answer['body']['refreshJwt'] ?? ''),
			'key' => bin2hex($key->secret()),
		]);
		$id = $this->moves->add($move);
		$this->jobList->add(AtprotoMove::class, ['move' => $id]);

		return $this->moves->get($id) ?? $move;
	}

	/**
	 * The code the old PDS e-mailed: the move goes on with it.
	 *
	 * @throws InvalidArgumentException
	 */
	public function code(string $userId, string $code): Move {
		$move = $this->moves->latestOfUser($userId);
		if ($move === null || $move->direction !== Move::IN || $move->state !== Move::WAITING) {
			throw new InvalidArgumentException('No move is waiting for a code');
		}
		$code = trim($code);
		if ($code === '' || strlen($code) > 64) {
			throw new InvalidArgumentException('That is not a code');
		}
		$move->session = $this->seal(['code' => $code] + $this->unseal($move->session));
		$move->step = Move::STEP_IDENTITY;
		$move->state = Move::RUNNING;
		$move->error = '';
		$this->moves->update($move);
		$this->jobList->add(AtprotoMove::class, ['move' => $move->id]);

		return $move;
	}

	/**
	 * Starts a failed move again from its step; a wrong code asks for a new one.
	 *
	 * @throws InvalidArgumentException
	 */
	public function retry(Move $move): Move {
		if ($move->direction !== Move::IN || $move->state !== Move::FAILED) {
			throw new InvalidArgumentException('There is no failed move to start again');
		}
		if ($move->step === Move::STEP_IDENTITY) {
			$move->step = Move::STEP_CODE;
		}
		$move->state = Move::RUNNING;
		$move->error = '';
		$this->moves->update($move);
		$this->jobList->add(AtprotoMove::class, ['move' => $move->id]);

		return $move;
	}

	/**
	 * Runs the steps left, until the move is done, waits for the code, or a
	 * step fails.
	 */
	public function run(Move $move): void {
		if ($move->state !== Move::RUNNING || $move->direction !== Move::IN) {
			return;
		}
		try {
			while ($move->step !== Move::STEP_DONE) {
				if (!$this->step($move)) {
					$move->state = Move::WAITING;
					$this->moves->update($move);

					return;
				}
				$move->step = Move::nextIn($move->step);
				$this->moves->update($move);
			}
			$move->state = Move::DONE;
			$move->session = '';
			$this->moves->update($move);
		} catch (Throwable $e) {
			$move->state = Move::FAILED;
			$move->error = $e->getMessage();
			$this->moves->update($move);
			$this->logger->warning('Bluesky account move here stopped', ['did' => $move->did, 'step' => $move->step, 'exception' => $e]);
		}
	}

	/**
	 * @return bool false when the move waits for the person
	 * @throws AtprotoException
	 */
	private function step(Move $move): bool {
		switch ($move->step) {
			case Move::STEP_REPO:
				$this->copyRepository($move);
				break;
			case Move::STEP_BLOBS:
				$this->copyBlobs($move);
				break;
			case Move::STEP_PREFERENCES:
				$preferences = $this->authed($move, 'app.bsky.actor.getPreferences');
				try {
					$this->preferences->put($this->sessionFor($move), ['preferences' => $preferences['preferences'] ?? []]);
				} catch (XrpcException $e) {
					$this->logger->info('Preferences not copied', ['did' => $move->did, 'error' => $e->getMessage()]);
				}
				break;
			case Move::STEP_FOLLOWS:
				$this->adoptFollows($move);
				break;
			case Move::STEP_CODE:
				$this->authed($move, 'com.atproto.identity.requestPlcOperationSignature', 'POST');

				return false;
			case Move::STEP_IDENTITY:
				$this->takeDid($move);
				break;
			case Move::STEP_ACTIVATE:
				$this->authed($move, 'com.atproto.server.deactivateAccount', 'POST', [], []);
				break;
		}

		return true;
	}

	/**
	 * @throws AtprotoException
	 */
	private function copyRepository(Move $move): void {
		if ($this->repositories->getHead($move->did) !== null) {
			return;
		}
		$signing = (string)($this->plc->data($move->did)['verificationMethods']['atproto'] ?? '');
		$car = $this->pds->bytes($move->pds, 'com.atproto.sync.getRepo', ['did' => $move->did]);
		if ($car['status'] !== 200 || $signing === '') {
			throw new AtprotoException('The old server did not hand out the repository (' . $car['status'] . ')');
		}
		$archive = RepoArchive::read($car['bytes'], $move->did, PublicKey::fromDidKey($signing));
		$this->repositories->import($move->did, $this->key($move), $archive['records'], $archive['rev']);
		$move->progress = ['records' => count($archive['records'])];
	}

	/**
	 * Every blob of the old repository, byte for byte.
	 *
	 * @throws AtprotoException
	 */
	private function copyBlobs(Move $move): void {
		$actor = $this->actors->getFromUserId($move->userId);
		$cursor = '';
		$copied = (int)($move->progress['blobs'] ?? 0);
		do {
			$page = $this->pds->expect($move->pds, 'com.atproto.sync.listBlobs', 'GET', ['did' => $move->did, 'limit' => self::PAGE] + ($cursor !== '' ? ['cursor' => $cursor] : []));
			foreach (is_array($page['cids'] ?? null) ? $page['cids'] : [] as $cid) {
				if (!is_string($cid) || !Cid::isValid($cid) || $this->blobs->get($move->did, $cid) !== null) {
					continue;
				}
				$blob = $this->pds->bytes($move->pds, 'com.atproto.sync.getBlob', ['did' => $move->did, 'cid' => $cid]);
				if ($blob['status'] !== 200 || !Cid::forRaw($blob['bytes'])->equals(Cid::parse($cid))) {
					$this->logger->info('Blob not copied', ['did' => $move->did, 'cid' => $cid, 'status' => $blob['status']]);
					continue;
				}
				$path = (string)tempnam(sys_get_temp_dir(), 'social-atproto-move-');
				try {
					file_put_contents($path, $blob['bytes']);
					$document = $this->documents->storeAsIs($actor, $path);
				} finally {
					@unlink($path);
				}
				$this->blobs->put(new BlobRef($move->did, Cid::parse($cid), $document->getId(), $document->getMimeType(), strlen($blob['bytes'])));
				$this->repoRequest->addBlobBytes($move->did, strlen($blob['bytes']));
				$copied++;
			}
			$cursor = (string)($page['cursor'] ?? '');
			$move->progress = ['blobs' => $copied] + $move->progress;
			$this->moves->update($move);
		} while ($cursor !== '');
	}

	/**
	 * The account's follows on Bluesky become follows here, each tied to the
	 * record it came with. An account that cannot be read now is skipped.
	 */
	public function adoptFollows(Move $move): void {
		$actor = $this->actors->getFromUserId($move->userId);
		$adopted = 0;
		$cursor = '';
		do {
			$page = $this->repositories->listRecords($move->did, RecordMapper::FOLLOW, 100, $cursor);
			foreach ($page as $record) {
				$value = DagCbor::decode($record->bytes);
				$subject = is_array($value) ? (string)($value['subject'] ?? '') : '';
				if (!Syntax::isDid($subject)) {
					continue;
				}
				try {
					$adopted += $this->follows->adoptBlueskyFollow($actor, $this->blueskyActors->resolve($subject), $move->did, $record->rkey) ? 1 : 0;
				} catch (Throwable $e) {
					$this->logger->info('Follow not taken over', ['did' => $move->did, 'subject' => $subject, 'exception' => $e]);
				}
			}
			$cursor = count($page) === 100 ? end($page)->rkey : '';
		} while ($cursor !== '');
		$move->progress = ['follows' => $adopted] + $move->progress;
	}

	/**
	 * The old PDS signs the operation that points the DID here, and the
	 * account takes it.
	 *
	 * @throws AtprotoException
	 */
	private function takeDid(Move $move): void {
		$session = $this->unseal($move->session);
		$actor = $this->actors->getFromUserId($move->userId);
		$current = $this->identities->forActor($actor) ?? throw new AtprotoException('This account has no Bluesky identity to move into');
		$key = $this->key($move);
		$signed = $this->authed($move, 'com.atproto.identity.signPlcOperation', 'POST', [], [
			'token' => (string)($session['code'] ?? ''),
			'rotationKeys' => [$this->instanceKeys->rotationKey()->didKey()],
			'alsoKnownAs' => ['at://' . $current->handle],
			'verificationMethods' => ['atproto' => $key->didKey()],
			'services' => ['atproto_pds' => ['type' => PlcOperation::PDS_SERVICE_TYPE, 'endpoint' => $this->config->pdsEndpoint()]],
		]);
		$operation = is_array($signed['operation'] ?? null) ? $signed['operation'] : [];
		if (($operation['services']['atproto_pds']['endpoint'] ?? '') !== $this->config->pdsEndpoint()
			|| ($operation['verificationMethods']['atproto'] ?? '') !== $key->didKey()) {
			throw new AtprotoException('The old server signed something else than was asked');
		}
		$this->identities->adopt($current, $move->did, $key, $move->pds, $operation);
	}

	/**
	 * A call to the old PDS as the account, its session refreshed once when
	 * it has expired.
	 *
	 * @throws AtprotoException
	 */
	private function authed(Move $move, string $method, string $verb = 'GET', array $query = [], ?array $json = null): array {
		$session = $this->unseal($move->session);
		$answer = $this->pds->call($move->pds, $method, $verb, $query, $json, null, '', (string)($session['accessJwt'] ?? ''));
		if (in_array($answer['status'], [400, 401], true) && in_array($answer['body']['error'] ?? '', ['ExpiredToken', 'InvalidToken'], true) && ($session['refreshJwt'] ?? '') !== '') {
			$refreshed = $this->pds->expect($move->pds, 'com.atproto.server.refreshSession', 'POST', [], null, null, '', (string)$session['refreshJwt']);
			$move->session = $this->seal(['accessJwt' => (string)($refreshed['accessJwt'] ?? ''), 'refreshJwt' => (string)($refreshed['refreshJwt'] ?? '')] + $session);
			$this->moves->update($move);
			$answer = $this->pds->call($move->pds, $method, $verb, $query, $json, null, '', (string)($refreshed['accessJwt'] ?? ''));
		}
		if ($answer['status'] !== 200) {
			$error = trim((string)($answer['body']['error'] ?? '') . ': ' . (string)($answer['body']['message'] ?? ''), ': ');
			throw new AtprotoException($method . ' answered ' . $answer['status'] . ($error !== '' ? ' (' . $error . ')' : ''));
		}

		return $answer['body'];
	}

	/**
	 * The did:plc a handle or DID the person typed names.
	 *
	 * @throws InvalidArgumentException
	 */
	public function didOf(string $typed): string {
		$typed = strtolower(trim(ltrim(trim($typed), '@')));
		if (Syntax::isDid($typed)) {
			if (!str_starts_with($typed, 'did:plc:')) {
				throw new InvalidArgumentException('Only accounts with a did:plc can move here');
			}

			return $typed;
		}
		if (!Syntax::isHandle($typed)) {
			throw new InvalidArgumentException('That is not a Bluesky handle');
		}
		try {
			$did = (string)($this->appView->query('com.atproto.identity.resolveHandle', ['handle' => $typed])['did'] ?? '');
		} catch (Throwable) {
			$did = '';
		}
		if (!str_starts_with($did, 'did:plc:')) {
			throw new InvalidArgumentException('No Bluesky account with a did:plc goes by ' . $typed);
		}

		return $did;
	}

	private function sessionFor(Move $move): ClientSession {
		$identity = $this->identities->forActor($this->actors->getFromUserId($move->userId), false)
			?? new Identity(0, '', $move->did, $move->handle, '', '', '', Identity::STATE_DEACTIVATED, $move->pds, 0);

		return new ClientSession($move->userId, $identity, '');
	}

	/**
	 * @throws AtprotoException
	 */
	private function key(Move $move): PrivateKey {
		$secret = (string)($this->unseal($move->session)['key'] ?? '');
		if ($secret === '') {
			throw new AtprotoException('The move lost its signing key; start it again');
		}

		return new PrivateKey(Curve::K256, (string)hex2bin($secret));
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
