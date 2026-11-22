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
use OCA\Social\Atproto\Identity\HandleVerifier;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\InstanceKeyService;
use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Atproto\Identity\PlcOperation;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\Move;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Atproto\Reader\BlueskyListImport;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Atproto\Xrpc\XrpcException;
use OCA\Social\Cron\AtprotoMove;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoIdentityRequest;
use OCA\Social\Db\AtprotoMoveRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCA\Social\Exceptions\AtprotoPlcRefusedException;
use OCA\Social\Exceptions\MoveErrorException;
use OCA\Social\Service\FollowService;
use OCP\BackgroundJob\IJobList;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Moves a Bluesky account here (§13.1): this server signs in to the old PDS
 * with the account's own password, as a PDS signs a DID change only for a
 * full session, and does what a migration tool would. The password is not
 * kept; the session is, sealed, while the move runs. Follows are taken over
 * only after the old account is switched off, so a move called off leaves
 * none behind.
 */
class MoveInService {
	/** the most blobs one run of the copy asks the old PDS for */
	private const BLOBS = 50000;
	/** the domains the big hosts give handles under, for an old server that does not say */
	private const HOSTED_HANDLES = ['.bsky.social', '.brid.gy'];

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
		private Preferences $preferences,
		private BlueskyActorService $blueskyActors,
		private FollowService $follows,
		private PostHistory $history,
		private ActorsRequest $actors,
		private AtprotoMoveRequest $moves,
		private ICrypto $crypto,
		private IJobList $jobList,
		private LoggerInterface $logger,
		private BlueskyListImport $lists,
		private AtprotoIdentityRequest $identityRequest,
		private HandleVerifier $handles,
		private MovedBlobs $movedBlobs,
		private PostRepublisher $republisher,
	) {
	}

	/**
	 * Signs in to the old PDS and queues the copy.
	 *
	 * @param string $authFactorToken the code a PDS e-mails for a sign-in it wants confirmed
	 * @throws MoveErrorException with what to tell the person
	 * @throws AtprotoException
	 */
	public function start(string $userId, string $typed, string $password, string $authFactorToken = ''): Move {
		if ($this->moves->latestOfUser($userId)?->isUnderWay() === true) {
			throw new MoveErrorException('A move is already under way', 'move-in-progress');
		}
		$did = $this->didOf($typed);
		try {
			$this->identities->getByDid($did);
			throw new MoveErrorException('That Bluesky account is on this server already', 'account-here-already');
		} catch (AtprotoIdentityNotFoundException) {
		}
		$data = $this->didState($did);
		$endpoint = (string)($data['services']['atproto_pds']['endpoint'] ?? '');
		if ($endpoint === '') {
			throw new MoveErrorException('The directory names no server for that account', 'pds-not-named');
		}
		$origin = $this->pds->origin($endpoint);

		$login = ['identifier' => $did, 'password' => $password];
		if (trim($authFactorToken) !== '') {
			$login['authFactorToken'] = trim($authFactorToken);
		}
		$answer = $this->pds->call($origin, 'com.atproto.server.createSession', 'POST', [], $login);
		if ($answer['status'] !== 200 || ($answer['body']['did'] ?? '') !== $did) {
			throw self::signInRefused($answer);
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
	 * @throws MoveErrorException
	 */
	public function code(string $userId, string $code): Move {
		$move = $this->moves->latestOfUser($userId);
		if ($move === null || $move->direction !== Move::IN || $move->state !== Move::WAITING) {
			throw new MoveErrorException('No move is waiting for a code', 'no-move-waiting');
		}
		$code = trim($code);
		if ($code === '' || strlen($code) > 64) {
			throw new MoveErrorException('That is not a code', 'code-invalid');
		}
		$move->session = $this->seal(['code' => $code] + $this->unseal($move->session));
		$move->step = Move::STEP_IDENTITY;
		$move->resume();
		$this->moves->update($move);
		$this->jobList->add(AtprotoMove::class, ['move' => $move->id]);

		return $move;
	}

	/**
	 * Starts a failed move again from its step; one that failed at the
	 * identity asks for a new code, unless the directory points the DID
	 * here already.
	 *
	 * @throws MoveErrorException
	 */
	public function retry(Move $move): Move {
		if ($move->direction !== Move::IN || $move->state !== Move::FAILED) {
			throw new MoveErrorException('There is no failed move to start again', 'no-failed-move');
		}
		if ($move->step === Move::STEP_IDENTITY && !$this->switchedHere($move)) {
			$move->step = Move::STEP_CODE;
		}
		$move->resume();
		$this->moves->update($move);
		$this->jobList->add(AtprotoMove::class, ['move' => $move->id]);

		return $move;
	}

	/**
	 * How many of the person's posts are on Bluesky now, under the identity
	 * a move here would retire.
	 */
	public function postsOnBluesky(string $userId): int {
		try {
			return $this->republisher->onBluesky($this->identities->forActor($this->actors->getFromUserId($userId), false));
		} catch (Throwable) {
			return 0;
		}
	}

	/**
	 * Queues the posts the person's finished move here took off Bluesky to
	 * be published again, into the moved repository.
	 *
	 * @throws MoveErrorException
	 */
	public function republish(string $userId): Move {
		$move = $this->moves->latestOfUser($userId);

		return $this->republisher->queue($move !== null && in_array($move->direction, [Move::IN, Move::INBOUND], true) ? $move : null);
	}

	/**
	 * The person's decision on a move that stopped because blobs are
	 * missing: go on without them, or copy them again first.
	 *
	 * @throws MoveErrorException
	 */
	public function decide(Move $move, bool $withoutMissing): Move {
		if ($move->direction !== Move::IN || $move->state !== Move::NEEDS_DECISION) {
			throw new MoveErrorException('The move does not wait for a decision', 'no-decision-pending');
		}
		if ($withoutMissing) {
			$move->progress['missing_accepted'] = (int)($move->progress['missing']['count'] ?? 0);
		} else {
			$move->step = Move::STEP_BLOBS;
		}
		$move->resume();
		$this->moves->update($move);
		$this->jobList->add(AtprotoMove::class, ['move' => $move->id]);

		return $move;
	}

	/**
	 * Calls off a move whose DID has not switched yet: it ends, the session
	 * on the old PDS is ended and dropped, and what was copied here is
	 * deleted.
	 *
	 * @throws MoveErrorException `move-past-switch` once the DID may have moved
	 */
	public function cancel(string $userId): Move {
		for ($try = 1; ($called = $this->callOff($userId)) === null; $try++) {
			if ($try === MoveAwayService::CALL_OFF_TRIES) {
				throw new MoveErrorException('The move went on while it was called off', 'no-move-to-call-off');
			}
		}
		['move' => $move, 'refresh' => $refresh] = $called;
		if ($refresh !== '') {
			try {
				$this->pds->call($move->pds, 'com.atproto.server.deleteSession', 'POST', [], null, null, '', $refresh);
			} catch (Throwable $e) {
				$this->logger->info('Session on the old PDS not ended', ['did' => $move->did, 'exception' => $e]);
			}
		}
		$this->dropCopy($move);

		return $move;
	}

	/**
	 * Marks the person's move as called off, unless it may not be, and returns
	 * it with the old server's refresh token; null when the job moved it on
	 * between the read and the write, so it is looked at again.
	 *
	 * @return array{move: Move, refresh: string}|null
	 * @throws MoveErrorException
	 */
	private function callOff(string $userId): ?array {
		$move = $this->moves->latestOfUser($userId);
		if ($move === null || $move->direction !== Move::IN || in_array($move->state, [Move::DONE, Move::CANCELLED], true)) {
			throw new MoveErrorException('There is no move here to call off', 'no-move-to-call-off');
		}
		if ($this->pastSwitch($move)) {
			throw new MoveErrorException('The account has been pointed at this server already; the move can no longer be called off', 'move-past-switch');
		}
		$refresh = (string)($this->unseal($move->session)['refreshJwt'] ?? '');
		$read = $move->state;
		$move->state = Move::CANCELLED;
		$move->error = 'Called off';
		$move->session = '';

		return $this->moves->updateIf($move, $read, $move->step) ? ['move' => $move, 'refresh' => $refresh] : null;
	}

	/**
	 * Whether the DID may already point here: from the identity step on,
	 * unless that step failed before the directory took anything.
	 */
	private function pastSwitch(Move $move): bool {
		if (!in_array($move->step, [Move::STEP_REPO, Move::STEP_BLOBS, Move::STEP_PREFERENCES, Move::STEP_CODE, Move::STEP_IDENTITY], true)) {
			return true;
		}
		if ($move->step !== Move::STEP_IDENTITY) {
			return false;
		}
		if ($move->state === Move::RUNNING || $this->isHere($move->did)) {
			return true;
		}
		try {
			$state = $this->plc->data($move->did);
		} catch (AtprotoException) {
			return true;
		}

		return rtrim((string)($state['services']['atproto_pds']['endpoint'] ?? ''), '/') === $this->config->pdsEndpoint();
	}

	/**
	 * Asks the old PDS again to e-mail a code, for a move waiting for one.
	 *
	 * @return array{move: Move, sent_to: string} the move, and the address the old PDS says it e-mailed, '' when it does not say
	 * @throws MoveErrorException
	 * @throws AtprotoException
	 */
	public function newCode(string $userId): array {
		$move = $this->moves->latestOfUser($userId);
		if ($move === null || $move->direction !== Move::IN || $move->state !== Move::WAITING || $move->step !== Move::STEP_CODE) {
			throw new MoveErrorException('No move is waiting for a code', 'no-move-waiting');
		}
		$this->requestCode($move);
		$this->moves->update($move);

		return ['move' => $move, 'sent_to' => (string)($move->progress['code_sent_to'] ?? '')];
	}

	/**
	 * The old PDS e-mails the person a code (`requestPlcOperationSignature`
	 * answers nothing); the address its session names is kept as
	 * `code_sent_to`, '' when it names none.
	 *
	 * @throws AtprotoException
	 */
	private function requestCode(Move $move): void {
		$this->authed($move, 'com.atproto.identity.requestPlcOperationSignature', 'POST');
		try {
			$email = (string)($this->authed($move, 'com.atproto.server.getSession')['email'] ?? '');
		} catch (AtprotoException) {
			$email = '';
		}
		$move->progress['code_sent_to'] = filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
	}

	/**
	 * Whether the move was called off while the job ran; what the job
	 * copied since is dropped then, unless $drop is false.
	 */
	private function calledOff(Move $move, bool $drop = true): bool {
		$stored = $this->moves->get($move->id);
		if ($stored === null || $stored->state === Move::RUNNING) {
			return false;
		}
		if ($drop && $stored->state === Move::CANCELLED) {
			$this->dropCopy($stored);
		}

		return true;
	}

	/**
	 * Writes where the job got, unless the move was called off meanwhile;
	 * what the job copied is dropped then.
	 */
	private function written(Move $move): bool {
		if ($this->moves->updateIfRunning($move)) {
			return true;
		}
		$this->calledOff($move);

		return false;
	}

	/**
	 * Deletes what a move copied here, unless the DID is an account's here.
	 */
	private function dropCopy(Move $move): void {
		if (!$this->isHere($move->did)) {
			$this->repositories->delete($move->did);
			$this->blobs->deleteByDid($move->did);
		}
	}

	private function isHere(string $did): bool {
		try {
			$this->identities->getByDid($did);

			return true;
		} catch (AtprotoIdentityNotFoundException) {
			return false;
		}
	}

	/**
	 * Runs the steps left, until the move is done, waits for the code, or a
	 * step fails. A move called off meanwhile is left as it is.
	 */
	public function run(Move $move): void {
		if ($move->state !== Move::RUNNING || $move->direction !== Move::IN) {
			return;
		}
		if ($move->step === Move::STEP_FOLLOWS && !isset($this->unseal($move->session)['code'])) {
			// stopped at the follows when they still came before the code
			$move->step = Move::STEP_CODE;
		}
		try {
			while ($move->step !== Move::STEP_DONE) {
				if ($this->calledOff($move)) {
					return;
				}
				$waitsIn = $this->step($move);
				if ($this->calledOff($move)) {
					return;
				}
				if ($waitsIn !== '') {
					$move->state = $waitsIn;
					$this->written($move);

					return;
				}
				$move->step = Move::nextIn($move->step);
				if (!$this->written($move)) {
					return;
				}
			}
			$move->state = Move::DONE;
			$move->session = '';
			$this->written($move);
		} catch (Throwable $e) {
			if ($this->calledOff($move)) {
				return;
			}
			$move->fail($e->getMessage(), MoveFailure::codeOf($e));
			if (!$this->written($move)) {
				return;
			}
			$this->logger->warning('Bluesky account move here stopped', ['did' => $move->did, 'step' => $move->step, 'exception' => $e]);
		}
	}

	/**
	 * @return string the state the move waits for the person in, '' when it goes on
	 * @throws AtprotoException
	 */
	private function step(Move $move): string {
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
				// the DID is the account's by now: a follow not taken over
				// does not undo the move
				try {
					$this->adoptFollows($move);
				} catch (Throwable $e) {
					$this->logger->warning('Follows of a Bluesky account that moved here not taken over', ['did' => $move->did, 'exception' => $e]);
				}
				break;
			case Move::STEP_CODE:
				if (!$this->movedBlobs->complete($move)) {
					return Move::NEEDS_DECISION;
				}
				$this->settleHandle($move);
				$this->requestCode($move);

				return Move::WAITING;
			case Move::STEP_IDENTITY:
				if (!$this->catchUp($move)) {
					return Move::NEEDS_DECISION;
				}
				$this->takeDid($move);
				break;
			case Move::STEP_ACTIVATE:
				$this->authed($move, 'com.atproto.server.deactivateAccount', 'POST', [], []);
				break;
			case Move::STEP_POSTS:
				$this->importPosts($move);
				break;
		}

		return '';
	}

	/**
	 * @throws AtprotoException
	 */
	private function copyRepository(Move $move): void {
		if ($this->repositories->getHead($move->did) !== null) {
			return;
		}
		$signing = (string)($this->didState($move->did)['verificationMethods']['atproto'] ?? '');
		$car = $this->pds->bytes($move->pds, 'com.atproto.sync.getRepo', ['did' => $move->did], RepoArchive::MAX_BYTES);
		try {
			if ($car['status'] !== 200 || $signing === '') {
				throw new MoveFailure('repo-unavailable', 'The old server did not hand out the repository (' . $car['status'] . ')');
			}
			$archive = RepoArchive::read((string)file_get_contents($car['path']), $move->did, PublicKey::fromDidKey($signing));
		} catch (MoveFailure $e) {
			throw $e;
		} catch (AtprotoException|InvalidArgumentException $e) {
			throw new MoveFailure('repo-invalid', $e->getMessage(), $e);
		} finally {
			if ($car['path'] !== '') {
				@unlink($car['path']);
			}
		}
		$this->repositories->import($move->did, $this->key($move), $archive['records'], $archive['rev']);
		$move->progress = ['records' => count($archive['records']), 'rev' => $archive['rev']] + $move->progress;
	}

	/**
	 * The blobs the copied records name, byte for byte, as far as the
	 * move's budget goes (MovedBlobs::BUDGET) and at most BLOBS in one run;
	 * what is not copied stays missing, for the person to decide on.
	 *
	 * @throws AtprotoException
	 */
	private function copyBlobs(Move $move): void {
		$copied = (int)($move->progress['blobs'] ?? 0);
		foreach (array_slice($this->movedBlobs->missing($move->did), 0, self::BLOBS) as $cid) {
			if (MovedBlobs::overBudget($move)) {
				break;
			}
			$copied += $this->movedBlobs->copy($move, $cid) > 0 ? 1 : 0;
			$move->progress = ['blobs' => $copied] + $move->progress;
			if ($this->calledOff($move, false)) {
				return;
			}
			$this->moves->update($move);
		}
	}

	/**
	 * The account's posts become posts in its timeline here, its latest
	 * likes and reposts likes and boosts, and its curate lists public lists
	 * here (`BlueskyListImport`). The account has moved by then, so a
	 * failure here does not fail the move.
	 */
	public function importPosts(Move $move): void {
		// written as it goes, so a long run is not taken for one that died (StaleMoves)
		$onPage = function () use ($move): void {
			$this->moves->update($move);
		};
		try {
			$actor = $this->actors->getFromUserId($move->userId);
			$move->progress = ['posts' => $this->history->import($actor, $move->did, $onPage)] + $move->progress;
			$move->progress = $this->history->importActions($actor, $move->did, $onPage) + $move->progress;
		} catch (Throwable $e) {
			$this->logger->warning('Posts of a Bluesky account that moved here not imported', ['did' => $move->did, 'exception' => $e]);
		}
		try {
			$move->progress = ['lists' => $this->lists->adopt($this->actors->getFromUserId($move->userId), $move->did)] + $move->progress;
		} catch (Throwable $e) {
			$this->logger->warning('Lists of a Bluesky account that moved here not taken over', ['did' => $move->did, 'exception' => $e]);
		}
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
			// written as it goes, so a long run is not taken for one that died (StaleMoves)
			$move->progress = ['follows' => $adopted] + $move->progress;
			$this->moves->update($move);
		} while ($cursor !== '');
	}

	/**
	 * What was posted on the old PDS since the repository was copied comes
	 * along: when its latest commit is newer than the copy, the repository
	 * is copied again, and the blobs new to it.
	 *
	 * @return bool false when blobs are missing after that, and the person decides
	 * @throws AtprotoException
	 */
	private function catchUp(Move $move): bool {
		try {
			$latest = $this->pds->call($move->pds, 'com.atproto.sync.getLatestCommit', 'GET', ['did' => $move->did]);
		} catch (AtprotoException $e) {
			$this->logger->info('Latest commit of a Bluesky account moving here not read', ['did' => $move->did, 'exception' => $e]);

			return true;
		}
		$rev = (string)($latest['body']['rev'] ?? '');
		$copied = (string)($move->progress['rev'] ?? '');
		if ($latest['status'] !== 200 || $rev === '' || strcmp($rev, $copied) <= 0) {
			return true;
		}
		$this->logger->info('Bluesky account moving here changed since it was copied; copying it again', ['did' => $move->did, 'copied' => $copied, 'latest' => $rev]);
		$this->repositories->delete($move->did);
		$this->copyRepository($move);
		$this->copyBlobs($move);
		$this->moves->update($move);

		return $this->movedBlobs->complete($move);
	}

	/**
	 * The old PDS signs the operation that points the DID here, and the account
	 * takes it, with the person's rotation keys first and this server's after
	 * (`RotationKeys`). A DID the directory already points here with the move's
	 * key is taken as it is: an earlier run stopped after sending the operation.
	 *
	 * @throws AtprotoException
	 */
	private function takeDid(Move $move): void {
		$session = $this->unseal($move->session);
		$actor = $this->actors->getFromUserId($move->userId);
		$current = $this->identities->forActor($actor) ?? throw new MoveFailure('no-identity', 'This account has no Bluesky identity to move into');
		if ($current->did === $move->did) {
			return;
		}
		$key = $this->key($move);
		$state = $this->didState($move->did);
		$kept = $this->settleHandle($move);
		if ($this->pointsHere($move, $state)) {
			$this->logger->info('A DID moving here points here already; the account takes it', ['did' => $move->did]);
			$this->becomeAccount($move, $current, $key, $kept, null, RotationKeys::didKeys($state['rotationKeys'] ?? null));

			return;
		}
		$here = $this->instanceKeys->rotationKey()->didKey();
		$rotation = RotationKeys::forMoveHere($state['rotationKeys'] ?? null, $this->oldServerKeys($move), $current->recoveryPublic, $here);
		$signed = $this->authed($move, 'com.atproto.identity.signPlcOperation', 'POST', [], [
			'token' => (string)($session['code'] ?? ''),
			'rotationKeys' => $rotation,
			'alsoKnownAs' => ['at://' . ($kept !== '' ? $kept : $current->handle)],
			'verificationMethods' => ['atproto' => $key->didKey()],
			'services' => ['atproto_pds' => ['type' => PlcOperation::PDS_SERVICE_TYPE, 'endpoint' => $this->config->pdsEndpoint()]],
		]);
		$operation = is_array($signed['operation'] ?? null) ? $signed['operation'] : [];
		if (($operation['services']['atproto_pds']['endpoint'] ?? '') !== $this->config->pdsEndpoint()
			|| ($operation['verificationMethods']['atproto'] ?? '') !== $key->didKey()
			|| RotationKeys::didKeys($operation['rotationKeys'] ?? null) !== $rotation) {
			throw new MoveFailure('operation-mismatch', 'The old server signed something else than was asked');
		}
		$this->becomeAccount($move, $current, $key, $kept, $operation, $rotation);
	}

	/**
	 * The account takes the DID: with the signed operation, sent to the
	 * directory first; without, as the directory names it already.
	 *
	 * @param list<string> $rotation the DID's rotation keys after the move
	 * @throws AtprotoException
	 */
	private function becomeAccount(Move $move, Identity $current, PrivateKey $key, string $kept, ?array $operation, array $rotation): void {
		$this->republisher->remember($move, $current);
		try {
			if ($kept !== '') {
				// set before the DID moves, so the identity is announced under it
				$this->identityRequest->setCustomHandle($current->did, $kept);
			}
			if ($operation !== null) {
				$this->identities->adopt($current, $move->did, $key, $move->pds, $operation);
			} else {
				$this->identities->receive($current, $move->did, $key, $move->pds);
			}
		} catch (AtprotoException $e) {
			if ($kept !== '') {
				$this->identityRequest->setCustomHandle($current->did, $current->customHandle);
			}
			throw $e instanceof AtprotoPlcRefusedException || !str_starts_with($e->getMessage(), 'PLC directory') ? $e : new MoveFailure('plc-unreachable', $e->getMessage(), $e);
		}
		if ($current->recoveryPublic !== '' && in_array($current->recoveryPublic, $rotation, true)) {
			// the recovery key is one of the moved DID's now, as it was of the one retired
			$this->identityRequest->setRecoveryPublic($move->did, $current->recoveryPublic);
		}
	}

	/**
	 * Whether the directory points the DID here, at the key made for the
	 * move.
	 *
	 * @throws AtprotoException
	 */
	private function pointsHere(Move $move, array $state): bool {
		return rtrim((string)($state['services']['atproto_pds']['endpoint'] ?? ''), '/') === $this->config->pdsEndpoint()
			&& ($state['verificationMethods']['atproto'] ?? '') === $this->key($move)->didKey();
	}

	/** pointsHere(), asked of the directory now; false when it cannot be asked. */
	private function switchedHere(Move $move): bool {
		try {
			return $this->pointsHere($move, $this->didState($move->did));
		} catch (AtprotoException|MoveErrorException) {
			return false;
		}
	}

	/**
	 * Decides once which handle the account has after the move, and keeps
	 * it in the move's progress: `handle_kept`, the person's own domain, or
	 * '' when the handle becomes the one here.
	 */
	private function settleHandle(Move $move): string {
		if (!array_key_exists('handle_kept', $move->progress)) {
			$identity = $this->identities->forActor($this->actors->getFromUserId($move->userId), false);
			$move->progress['handle_kept'] = $this->keptHandle($move->did, $move->pds, $this->didState($move->did), $identity?->handle ?? '');
			$this->moves->update($move);
		}

		return (string)$move->progress['handle_kept'];
	}

	/**
	 * The handle a DID moving here keeps: one on a domain of the person's own
	 * that names the DID (HandleVerifier); '' when it is under a domain the
	 * old server or this server gives handles under, is another account's
	 * here, or does not name the DID.
	 *
	 * @param array $state the DID's state in the directory
	 * @param string $own the handle the account has here now
	 */
	public function keptHandle(string $did, string $pds, array $state, string $own): string {
		$handle = HandleVerifier::normal((string)preg_replace('#^at://#', '', (string)($state['alsoKnownAs'][0] ?? '')));
		if ($handle === '' || $this->handles->refusal($handle) !== '' || ($handle !== $own && $this->identityRequest->handleExists($handle))) {
			return '';
		}
		$domains = [...self::HOSTED_HANDLES, '.' . strtolower((string)parse_url($pds, PHP_URL_HOST))];
		try {
			$described = $this->pds->call($pds, 'com.atproto.server.describeServer');
			foreach (is_array($described['body']['availableUserDomains'] ?? null) ? $described['body']['availableUserDomains'] : [] as $domain) {
				if (is_string($domain) && trim($domain, '.') !== '') {
					$domains[] = '.' . strtolower(trim($domain, '.'));
				}
			}
		} catch (AtprotoException $e) {
			$this->logger->info('The old PDS did not say which domains it gives handles under', ['pds' => $pds, 'exception' => $e]);
		}
		foreach ($domains as $domain) {
			if (str_ends_with('.' . $handle, $domain)) {
				return '';
			}
		}
		if ($this->handles->verify($handle, $did) === '') {
			$this->logger->info('Handle of a DID moving here not kept: it does not name the DID', ['did' => $did, 'handle' => $handle]);

			return '';
		}

		return $handle;
	}

	/**
	 * The old PDS's own rotation keys, as it recommends them for an account
	 * it holds; none when it does not say, and then every key the DID lists
	 * is kept.
	 *
	 * @return list<string>
	 */
	private function oldServerKeys(Move $move): array {
		try {
			return RotationKeys::didKeys($this->authed($move, 'com.atproto.identity.getRecommendedDidCredentials')['rotationKeys'] ?? null);
		} catch (AtprotoException $e) {
			$this->logger->info('The old PDS did not name its rotation keys; every key the DID lists is kept', ['did' => $move->did, 'exception' => $e]);

			return [];
		}
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
			try {
				$refreshed = $this->pds->expect($move->pds, 'com.atproto.server.refreshSession', 'POST', [], null, null, '', (string)$session['refreshJwt']);
			} catch (MoveFailure $e) {
				// a PLC token that is wrong or old answers as an expired session does
				throw $method === 'com.atproto.identity.signPlcOperation' ? self::codeRefused($answer) : new MoveFailure($e->errorCode === 'pds-refused' ? 'pds-session-expired' : $e->errorCode, $e->getMessage(), $e);
			}
			$move->session = $this->seal(['accessJwt' => (string)($refreshed['accessJwt'] ?? ''), 'refreshJwt' => (string)($refreshed['refreshJwt'] ?? '')] + $session);
			$this->moves->update($move);
			$answer = $this->pds->call($move->pds, $method, $verb, $query, $json, null, '', (string)($refreshed['accessJwt'] ?? ''));
		}
		if ($answer['status'] !== 200) {
			throw $method === 'com.atproto.identity.signPlcOperation' && in_array($answer['body']['error'] ?? '', ['ExpiredToken', 'InvalidToken'], true)
				? self::codeRefused($answer)
				: PdsClient::refused($method, $answer);
		}

		return $answer['body'];
	}

	/**
	 * The old PDS did not take the e-mailed code: `code-expired` or
	 * `code-invalid`.
	 *
	 * @param array{status: int, body: array} $answer
	 */
	private static function codeRefused(array $answer): MoveFailure {
		$failure = PdsClient::refused('com.atproto.identity.signPlcOperation', $answer);

		return new MoveFailure(($answer['body']['error'] ?? '') === 'ExpiredToken' ? 'code-expired' : 'code-invalid', $failure->getMessage());
	}

	/**
	 * The old PDS did not let the person sign in, as a code: a sign-in code
	 * asked for, wrong or old; a wrong password; too many tries.
	 *
	 * @param array{status: int, body: array} $answer
	 */
	private static function signInRefused(array $answer): MoveErrorException {
		$error = (string)($answer['body']['error'] ?? '');
		$slug = match (true) {
			$error === 'AuthFactorTokenRequired' => 'auth-factor-required',
			$error === 'ExpiredToken' => 'sign-in-code-expired',
			$error === 'InvalidToken' => 'sign-in-code-invalid',
			$answer['status'] === 429 || $error === 'RateLimitExceeded' => 'rate-limited',
			$answer['status'] === 401 || $error === 'AuthenticationRequired' => 'wrong-password',
			default => 'sign-in-refused',
		};
		if ($slug === 'auth-factor-required') {
			return new MoveErrorException('The Bluesky server e-mailed you a sign-in code: enter it and start again', $slug);
		}

		return new MoveErrorException('The Bluesky server did not let you sign in: ' . trim((string)($answer['body']['message'] ?? 'status ' . $answer['status'])), $slug);
	}

	/**
	 * The directory's current state of a DID.
	 *
	 * @throws MoveErrorException `did-not-found` when the directory does not know it
	 * @throws MoveFailure `plc-unreachable`
	 */
	public function didState(string $did): array {
		try {
			$data = $this->plc->data($did);
		} catch (AtprotoException $e) {
			throw new MoveFailure('plc-unreachable', $e->getMessage(), $e);
		}

		return $data ?? throw new MoveErrorException('The PLC directory does not know ' . $did, 'did-not-found');
	}

	/**
	 * The did:plc a handle or DID the person typed names.
	 *
	 * @throws MoveErrorException
	 */
	public function didOf(string $typed): string {
		$typed = strtolower(trim(ltrim(trim($typed), '@')));
		if (Syntax::isDid($typed)) {
			if (!str_starts_with($typed, 'did:plc:')) {
				throw new MoveErrorException('Only accounts with a did:plc can move here', 'did-not-supported');
			}

			return $typed;
		}
		if (!Syntax::isHandle($typed)) {
			throw new MoveErrorException('That is not a Bluesky handle', 'handle-invalid');
		}
		try {
			$did = (string)($this->appView->query('com.atproto.identity.resolveHandle', ['handle' => $typed])['did'] ?? '');
		} catch (Throwable) {
			$did = '';
		}
		if (!str_starts_with($did, 'did:plc:')) {
			throw new MoveErrorException('No Bluesky account with a did:plc goes by ' . $typed, 'handle-not-found');
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
			throw new MoveFailure('keys-sealed', 'The move lost its signing key; start it again');
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
