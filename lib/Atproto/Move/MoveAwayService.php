<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Move;

use Closure;
use OCA\Social\Atproto\AppView\ServiceAuth;
use OCA\Social\Atproto\Client\ClientSession;
use OCA\Social\Atproto\Client\Preferences;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\Move;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Publisher\PictureService;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Publisher\VideoBlobService;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Cron\AtprotoMove;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoMoveRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Exceptions\AtprotoKeysSealedException;
use OCA\Social\Exceptions\AtprotoPlcRefusedException;
use OCA\Social\Exceptions\MoveErrorException;
use OCP\BackgroundJob\IJobList;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Moves a Bluesky account from this server to another PDS (§13.2), doing
 * what a migration tool would with the signing and rotation keys held here.
 *
 * The request makes the deactivated account on the other PDS, so its refusal
 * is shown at once; a background job copies the repository, blobs and
 * preferences, points the DID there, activates it there and deactivates it
 * here. A failed step can be retried; the DID moves only once everything is
 * there.
 */
class MoveAwayService {
	private const CREATE_ACCOUNT = 'com.atproto.server.createAccount';
	private const MAX_BLOBS_PER_PAGE = 500;
	/** the most pages of missing blobs one copy reads from the other PDS */
	private const MAX_BLOB_PAGES = 1000;
	/** how often calling off is tried while the job moves the move on between the read and the write */
	public const CALL_OFF_TRIES = 3;
	/** what stops a move for the person to decide on, rather than failing it */
	private const DECISIONS = ['blobs-missing', 'repo-incomplete'];
	/** what the other PDS's `createAccount` errors mean, as codes for the page */
	private const CREATE_ACCOUNT_ERRORS = [
		'HandleNotAvailable' => 'handle-taken',
		'InvalidHandle' => 'handle-not-allowed',
		'UnsupportedDomain' => 'handle-not-allowed',
		'InvalidInviteCode' => 'invite-code-invalid',
		'InvalidPassword' => 'password-not-accepted',
		'IncompatibleDidDoc' => 'account-not-created',
		'RateLimitExceeded' => 'rate-limited',
	];

	public function __construct(
		private PdsClient $pds,
		private ServiceAuth $serviceAuth,
		private IdentityService $identities,
		private RepositoryService $repositories,
		private AtprotoBlobRequest $blobs,
		private PictureService $pictures,
		private VideoBlobService $videos,
		private Preferences $preferences,
		private AtprotoMoveRequest $moves,
		private ICrypto $crypto,
		private IJobList $jobList,
		private Publisher $publisher,
		private MoveAnnouncement $announcement,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Makes the account on the other PDS and queues the rest.
	 *
	 * @param bool $withoutRecovery the person was told that without a
	 *                              recovery key only the other server will
	 *                              control the account, and goes on
	 * @param string $announce what says where the account went once it
	 *                         moved: a post, a profile field, or nothing
	 *                         (MoveAnnouncement::KINDS)
	 * @throws MoveErrorException with what to tell the person
	 */
	public function start(string $userId, Identity $identity, string $pds, string $handle, string $email, string $password, string $inviteCode = '', bool $withoutRecovery = false, string $announce = MoveAnnouncement::NONE): Move {
		MoveAnnouncement::assertKind($announce);
		if (!$identity->isActive()) {
			throw new MoveErrorException('The Bluesky account is not active here', 'identity-inactive');
		}
		$latest = $this->moves->latestOfUser($userId);
		if ($latest !== null && $latest->isUnderWay()) {
			throw new MoveErrorException('A move is already under way', 'move-in-progress');
		}
		if ($identity->recoveryPublic === '' && !$withoutRecovery) {
			throw new MoveErrorException('There is no recovery key: after the move only the other server controls the account', 'recovery-key-missing');
		}
		$server = $this->target($pds);
		$origin = $server['pds'];
		$pdsDid = $server['did'];
		$handle = strtolower(trim(ltrim(trim($handle), '@')));
		$domains = $server['availableUserDomains'];
		if (!Syntax::isHandle($handle) || ($domains !== [] && !self::underAny($handle, $domains))) {
			throw new MoveErrorException('That server gives out handles ending in ' . implode(', ', $domains), 'handle-not-allowed');
		}
		if ($server['inviteCodeRequired'] && trim($inviteCode) === '') {
			throw new MoveErrorException('That server needs an invite code', 'invite-code-required');
		}

		try {
			$token = $this->serviceAuth->token($this->identities->signingKey($identity), $identity->did, $pdsDid, self::CREATE_ACCOUNT, 300);
			$body = ['did' => $identity->did, 'handle' => $handle, 'email' => trim($email), 'password' => $password];
			if (trim($inviteCode) !== '') {
				$body['inviteCode'] = trim($inviteCode);
			}
			$answer = $this->pds->call($origin, self::CREATE_ACCOUNT, 'POST', [], $body, null, '', $token);
		} catch (AtprotoException $e) {
			throw new MoveErrorException($e->getMessage(), self::codeOf($e), $e);
		}
		if ($answer['status'] !== 200 || !is_string($answer['body']['accessJwt'] ?? null)) {
			throw new MoveErrorException(
				'The other server did not make the account: ' . trim((string)($answer['body']['message'] ?? $answer['body']['error'] ?? 'status ' . $answer['status'])),
				self::CREATE_ACCOUNT_ERRORS[(string)($answer['body']['error'] ?? '')] ?? 'account-not-created',
			);
		}

		$move = new Move(0, $identity->did, $userId, Move::AWAY, $origin, $pdsDid, (string)($answer['body']['handle'] ?? $handle), Move::STEP_REPO, Move::RUNNING);
		$move->session = $this->seal($answer['body']);
		$move->progress = ['stage' => self::stageOf($move), 'announce' => $announce, 'from_handle' => $identity->handle];
		$saved = new Move($this->moves->add($move), $move->did, $userId, Move::AWAY, $origin, $pdsDid, $move->handle, $move->step, $move->state, $move->session, $move->progress);
		$this->jobList->add(AtprotoMove::class, ['move' => $saved->id]);

		return $saved;
	}

	/**
	 * What the PDS an account would move to says of itself
	 * (`describeServer`), for the form before anything is started: its
	 * DID, the handle domains it gives out and whether it needs an invite
	 * code. Asked through PdsClient, with its refusal of local addresses.
	 *
	 * @return array{pds: string, did: string, availableUserDomains: list<string>, inviteCodeRequired: bool}
	 * @throws MoveErrorException
	 */
	public function target(string $pds): array {
		try {
			$origin = $this->pds->origin($pds);
			$server = $this->pds->expect($origin, 'com.atproto.server.describeServer');
		} catch (AtprotoException $e) {
			throw new MoveErrorException($e->getMessage(), self::codeOf($e), $e);
		}
		$did = (string)($server['did'] ?? '');
		if (!Syntax::isDid($did)) {
			throw new MoveErrorException('That server does not say what it is', 'pds-unknown');
		}
		$domains = [];
		foreach (is_array($server['availableUserDomains'] ?? null) ? $server['availableUserDomains'] : [] as $domain) {
			if (is_string($domain) && $domain !== '') {
				$domains[] = $domain;
			}
		}

		return [
			'pds' => $origin,
			'did' => $did,
			'availableUserDomains' => $domains,
			'inviteCodeRequired' => ($server['inviteCodeRequired'] ?? false) === true,
		];
	}

	/**
	 * The stable code of what stopped a call or a step, for the page.
	 */
	public static function codeOf(Throwable $e): string {
		$message = $e->getMessage();

		return match (true) {
			$e instanceof MoveErrorException, $e instanceof MoveFailure => $e->errorCode,
			$e instanceof AtprotoPlcRefusedException => 'plc-refused',
			str_starts_with($message, 'PLC directory could not be reached') => 'plc-unreachable',
			$e instanceof AtprotoKeysSealedException => AtprotoKeysSealedException::ERROR_CODE,
			str_starts_with($message, 'That is not the https address') => 'pds-address-invalid',
			str_contains($message, 'could not be reached') => 'pds-unreachable',
			str_contains($message, '(ExpiredToken') || str_contains($message, '(InvalidToken') => 'pds-session-expired',
			preg_match('/ answered \d{3}/', $message) === 1 => 'pds-refused',
			default => 'move-failed',
		};
	}

	/**
	 * Starts a failed move again, from the step it failed at; one that
	 * failed handing the DID over goes on from the activation when the
	 * directory points the DID at the other PDS already.
	 *
	 * @throws MoveErrorException
	 */
	public function retry(string $userId): Move {
		$move = $this->moves->latestOfUser($userId);
		if ($move === null || $move->state !== Move::FAILED || $move->direction !== Move::AWAY) {
			throw new MoveErrorException('There is no failed move to start again', 'no-failed-move');
		}
		$this->settleHandOver($move);
		$move->resume();
		$this->save($move);
		$this->jobList->add(AtprotoMove::class, ['move' => $move->id]);

		return $move;
	}

	/** The person's latest move, for the settings. */
	public function latest(string $userId): ?Move {
		return $this->moves->latestOfUser($userId);
	}

	/**
	 * Calls off a move away before the DID moved. The account made on the other
	 * server stays there, deactivated: deleting it needs a code that server
	 * e-mails, so the person is told to delete it there. A move whose DID moved
	 * or is moving is finished instead; one that failed handing the DID over is
	 * first checked with the directory.
	 *
	 * @throws MoveErrorException
	 */
	public function cancel(string $userId): Move {
		for ($try = 1; ($move = $this->callOff($userId)) === null; $try++) {
			if ($try === self::CALL_OFF_TRIES) {
				throw new MoveErrorException('The move went on while it was called off', 'no-move-to-call-off');
			}
		}
		$this->logger->info('Bluesky account move called off', ['did' => $move->did, 'pds' => $move->pds, 'step' => $move->step]);
		try {
			$this->publisher->publishBacklog($this->identities->getByDid($move->did)->actorId, $move->creation);
		} catch (Throwable $e) {
			$this->logger->warning('Posts held back for a move not published after it was called off', ['did' => $move->did, 'exception' => $e]);
		}

		return $move;
	}

	/**
	 * The person's move, written as called off unless it may not be; null
	 * when the job moved it on between the read and the write — to the
	 * handover, say — so it is looked at again.
	 *
	 * @throws MoveErrorException
	 */
	private function callOff(string $userId): ?Move {
		$move = $this->moves->latestOfUser($userId);
		if ($move === null || $move->direction !== Move::AWAY || !in_array($move->state, [Move::RUNNING, Move::FAILED, Move::NEEDS_DECISION], true)) {
			throw new MoveErrorException('There is no move away to call off', 'no-move-to-call-off');
		}
		if ($move->step === Move::STEP_IDENTITY && $move->state === Move::RUNNING) {
			throw new MoveErrorException('Your account is being pointed at the new server right now; wait for it', 'move-switching');
		}
		$handedOver = $this->settleHandOver($move);
		if ($handedOver) {
			$this->moves->update($move);
		}
		if ($handedOver || in_array($move->step, [Move::STEP_ACTIVATE, Move::STEP_DONE], true)) {
			throw new MoveErrorException('Your account already points at the new server; the move can only be finished', 'move-past-switch');
		}
		$read = $move->state;
		$move->state = Move::CANCELLED;
		$move->session = '';
		$move->error = 'Called off';
		$move->progress['code'] = 'target-account-left';
		$move->progress['stage'] = self::stageOf($move);

		return $this->moves->updateIf($move, $read, $move->step) ? $move : null;
	}

	/**
	 * Where the account stands in a move away, for the page to say:
	 * `account_created` (made on the other server, deactivated),
	 * `repo_copied` (its repository is there; the DID has not moved),
	 * `identity_switched` (the DID points there; not yet active there),
	 * `activated` (active there, about to be done here) or `done`.
	 */
	public static function stageOf(Move $move): string {
		if ($move->state === Move::DONE) {
			return 'done';
		}

		return match ($move->step) {
			Move::STEP_REPO => 'account_created',
			Move::STEP_ACTIVATE => 'identity_switched',
			Move::STEP_DONE => 'activated',
			default => 'repo_copied',
		};
	}

	/**
	 * Who controls the account once it moved, for the page: whether the
	 * person holds a recovery key, the rotation keys the handover names by
	 * their role — the recovery key first, then the other server's; this
	 * server keeps none — and, once a move away is done, the other
	 * server's host.
	 *
	 * @return array{has_recovery_key: bool, rotation_keys: list<string>, new_host: string|null}
	 */
	public static function control(?Identity $identity, ?Move $move): array {
		$recovery = $identity !== null && $identity->recoveryPublic !== '';
		$done = $move !== null && $move->direction === Move::AWAY && $move->state === Move::DONE;

		return [
			'has_recovery_key' => $recovery,
			'rotation_keys' => $recovery ? ['recovery', 'new_server'] : ['new_server'],
			'new_host' => $done ? (string)parse_url($move->pds, PHP_URL_HOST) : null,
		];
	}

	/**
	 * Runs the steps that are left, in order; stops at the first that fails.
	 */
	public function run(int $moveId): void {
		$move = $this->moves->get($moveId);
		if ($move === null || $move->state !== Move::RUNNING || $move->direction !== Move::AWAY) {
			return;
		}
		try {
			$identity = $this->identities->getByDid($move->did);
			while ($move->step !== Move::STEP_DONE) {
				try {
					$this->step($move, $identity);
					$move->step = Move::next($move->step);
				} catch (Throwable $e) {
					if (!$this->handedOverDespite($move, $e)) {
						throw $e;
					}
				}
				$this->save($move);
			}
			$move->state = Move::DONE;
			$move->session = '';
			$this->save($move);
			$this->logger->info('Bluesky account moved away', ['did' => $move->did, 'pds' => $move->pds]);
			$this->announce($move);
		} catch (Throwable $e) {
			if ($move->state === Move::CANCELLED) {
				$this->logger->info('Bluesky account move stopped: called off', ['did' => $move->did, 'step' => $move->step]);

				return;
			}
			$move->state = $e instanceof MoveErrorException && in_array($e->errorCode, self::DECISIONS, true) ? Move::NEEDS_DECISION : Move::FAILED;
			$move->error = $e->getMessage();
			$move->progress['code'] = self::codeOf($e);
			$move->progress['stage'] = self::stageOf($move);
			if (!$this->moves->updateIfRunning($move)) {
				$this->logger->info('Bluesky account move stopped: called off', ['did' => $move->did, 'step' => $move->step, 'exception' => $e]);

				return;
			}
			$this->logger->warning('Bluesky account move stopped', ['did' => $move->did, 'step' => $move->step, 'exception' => $e]);
		}
	}

	/**
	 * The endpoint the DID of a move is pointed at: the one the other PDS
	 * recommended, once it was asked, else its address.
	 */
	public static function endpointOf(Move $move): string {
		return (string)($move->progress['endpoint'] ?? $move->pds);
	}

	/**
	 * A move that failed handing the DID over continues from the activation when
	 * the directory already points the DID at the other PDS (it took the
	 * operation but the answer was lost). Signing again would be refused for good,
	 * and calling off would leave the account inactive everywhere.
	 *
	 * @return bool whether it goes on from the activation now
	 * @throws MoveErrorException `plc-unreachable` when the directory cannot be asked
	 */
	private function settleHandOver(Move $move): bool {
		if ($move->step !== Move::STEP_IDENTITY) {
			return false;
		}
		try {
			$handedOver = $this->identities->pointsAt($move->did, self::endpointOf($move));
		} catch (AtprotoException $e) {
			throw new MoveErrorException('The directory could not be asked where the account points', 'plc-unreachable', $e);
		}
		if ($handedOver) {
			$move->step = Move::STEP_ACTIVATE;
			$move->progress['stage'] = self::stageOf($move);
			$this->logger->info('Bluesky account move found handed over', ['did' => $move->did, 'pds' => $move->pds]);
		}

		return $handedOver;
	}

	/**
	 * Whether the handover went through although its step failed, as far
	 * as the directory can be asked now; the move is at the activation then.
	 */
	private function handedOverDespite(Move $move, Throwable $e): bool {
		if ($move->state === Move::CANCELLED) {
			return false;
		}
		try {
			if (!$this->settleHandOver($move)) {
				return false;
			}
		} catch (MoveErrorException $unknown) {
			$this->logger->info('Whether the directory took the handover is not known', ['did' => $move->did, 'exception' => $unknown]);

			return false;
		}
		$this->logger->notice('The directory took the handover of a Bluesky account move although the step failed', ['did' => $move->did, 'exception' => $e]);

		return true;
	}

	/**
	 * Says where the account went, as the person chose when the move
	 * started. A move is done whatever comes of it; what failed is kept
	 * for the page (`progress.announce_code`).
	 */
	private function announce(Move $move): void {
		$kind = (string)($move->progress['announce'] ?? MoveAnnouncement::NONE);
		if ($kind === MoveAnnouncement::NONE) {
			return;
		}
		try {
			$this->announcement->announce($move->userId, $kind, (string)($move->progress['from_handle'] ?? $move->did), $move->handle);
			$move->progress['announced'] = true;
		} catch (Throwable $e) {
			$move->progress['announced'] = false;
			$move->progress['announce_code'] = $e instanceof MoveErrorException ? $e->errorCode : 'announce-failed';
			$this->logger->warning('Where the Bluesky account went was not announced', ['did' => $move->did, 'kind' => $kind, 'exception' => $e]);
		}
		$this->moves->update($move);
	}

	/**
	 * Writes where a running move got to, with its stage, unless the person
	 * called it off meanwhile, which stops the run. The write is conditional on
	 * the move still running, so a call-off is never overwritten.
	 *
	 * @throws MoveErrorException when the move was called off
	 */
	private function save(Move $move): void {
		$move->progress['stage'] = self::stageOf($move);
		if ($this->moves->updateIfRunning($move)) {
			return;
		}
		$stored = $this->moves->get($move->id);
		if ($stored !== null && $stored->state === Move::CANCELLED) {
			$move->state = Move::CANCELLED;
			throw new MoveErrorException('The move was called off', 'move-called-off');
		}
		$this->moves->update($move);
	}

	/**
	 * @throws AtprotoException
	 */
	private function step(Move $move, Identity $identity): void {
		match ($move->step) {
			Move::STEP_REPO => $this->importRepository($move),
			Move::STEP_BLOBS => $this->copyBlobs($move),
			Move::STEP_PREFERENCES => $this->authed($move, 'app.bsky.actor.putPreferences', 'POST', [], $this->preferences->get(new ClientSession($move->userId, $identity, ''))),
			Move::STEP_CHECK => $this->check($move, $identity),
			Move::STEP_IDENTITY => $this->handOver($move, $identity),
			Move::STEP_ACTIVATE => $this->activate($move, $identity),
			default => null,
		};
	}

	/**
	 * The repository as it is now, into the other PDS; the head commit it
	 * was exported at is kept, to know later whether it moved on.
	 *
	 * @throws AtprotoException
	 */
	private function importRepository(Move $move): void {
		$head = $this->repositories->getHead($move->did);
		$this->authed($move, 'com.atproto.repo.importRepo', 'POST', [], null, fn () => $this->repositories->carStream($move->did), 'application/vnd.ipld.car');
		$move->progress['exported'] = (string)$head?->commitCid;
	}

	/**
	 * Right before the DID moves: publishes the posts held back during the move,
	 * exports the repository again when its head moved on, and asks the other
	 * PDS whether everything arrived (`checkAccountStatus`). What is missing
	 * stops the move for the person to decide: copy again, or switch without it.
	 *
	 * @throws AtprotoException
	 * @throws MoveErrorException blobs-missing or repo-incomplete
	 */
	private function check(Move $move, Identity $identity): void {
		$waiting = $this->publisher->publishBacklog($identity->actorId, $move->creation);
		if ($waiting > 0) {
			$move->progress['posts_during_move'] = $waiting;
			$this->logger->info('Posts written during the move published before the DID moves', ['did' => $move->did, 'posts' => $waiting]);
		}
		$head = $this->repositories->getHead($move->did);
		if ($head !== null && $head->commitCid !== ($move->progress['exported'] ?? '')) {
			$this->importRepository($move);
			$this->copyBlobs($move);
			$this->save($move);
			$head = $this->repositories->getHead($move->did);
		}
		$status = $this->authed($move, 'com.atproto.server.checkAccountStatus');
		$records = $head?->recordCount ?? 0;
		if (($head !== null && ($status['repoCommit'] ?? '') !== $head->commitCid) || (int)($status['indexedRecords'] ?? 0) < $records) {
			$move->progress['records'] = $records;
			$move->progress['records_there'] = (int)($status['indexedRecords'] ?? 0);
			throw new MoveErrorException('The repository on the other server is not the one here', 'repo-incomplete');
		}
		$missing = max(0, (int)($status['expectedBlobs'] ?? 0) - (int)($status['importedBlobs'] ?? 0));
		$move->progress['missing'] = $missing;
		unset($move->progress['missing_records']);
		if ($missing > 0) {
			$move->progress['missing_records'] = $this->missingRecords($move);
			throw new MoveErrorException($missing . ' pictures or videos did not arrive on the other server', 'blobs-missing');
		}
	}

	/**
	 * The records that lose what did not arrive, as the other PDS names
	 * them on its first page of missing blobs, the way the person is shown
	 * them (MovedBlobs::named()); none when it cannot be asked.
	 *
	 * @return list<array{collection: string, rkey: string, created_at: string, text: string, url: string}>
	 */
	private function missingRecords(Move $move): array {
		try {
			$page = $this->authed($move, 'com.atproto.repo.listMissingBlobs', 'GET', ['limit' => self::MAX_BLOBS_PER_PAGE]);
		} catch (AtprotoException $e) {
			$this->logger->info('The other server did not say which records miss blobs', ['did' => $move->did, 'exception' => $e]);

			return [];
		}
		$records = [];
		foreach (is_array($page['blobs'] ?? null) ? $page['blobs'] : [] as $missing) {
			$uri = Syntax::parseAtUri(is_array($missing) ? (string)($missing['recordUri'] ?? '') : '');
			if ($uri === null || $uri['authority'] !== $move->did || $uri['rkey'] === '' || isset($records[$uri['collection'] . '/' . $uri['rkey']])) {
				continue;
			}
			$record = $this->repositories->getRecord($move->did, $uri['collection'], $uri['rkey']);
			if ($record !== null) {
				$records[$record->path()] = $record;
			}
			if (count($records) >= MovedBlobs::NAMED) {
				break;
			}
		}

		return MovedBlobs::named($records);
	}

	/**
	 * Goes on with a move stopped for the person to decide: without what is
	 * missing (the DID moves now), or copying again.
	 *
	 * @throws MoveErrorException
	 */
	public function proceed(string $userId, bool $withoutMissing): Move {
		$move = $this->moves->latestOfUser($userId);
		if ($move === null || $move->direction !== Move::AWAY || $move->state !== Move::NEEDS_DECISION) {
			throw new MoveErrorException('No move waits for a decision', 'no-decision-pending');
		}
		if ($withoutMissing) {
			$move->progress['accepted_missing'] = (int)($move->progress['missing'] ?? 0);
			$move->step = Move::STEP_IDENTITY;
		} else {
			$move->step = ($move->progress['code'] ?? '') === 'repo-incomplete' ? Move::STEP_REPO : Move::STEP_BLOBS;
		}
		$move->resume();
		$this->save($move);
		$this->jobList->add(AtprotoMove::class, ['move' => $move->id]);

		return $move;
	}

	/**
	 * Points the DID at the other PDS, unless the move was called off
	 * since the last step.
	 *
	 * @throws AtprotoException
	 * @throws MoveErrorException
	 */
	private function handOver(Move $move, Identity $identity): void {
		$credentials = $this->authed($move, 'com.atproto.identity.getRecommendedDidCredentials');
		$endpoint = $credentials['services']['atproto_pds']['endpoint'] ?? null;
		if (is_string($endpoint) && $endpoint !== '') {
			$move->progress['endpoint'] = $endpoint;
		}
		$this->save($move);
		$this->identities->handOver($identity, $credentials);
	}

	/**
	 * Uploads to the other PDS every blob it says the repository names and
	 * it does not have, each once. The list ends at a cursor it named
	 * before, at a page that names no blob it did not name before, or after
	 * MAX_BLOB_PAGES pages.
	 *
	 * @throws AtprotoException
	 */
	private function copyBlobs(Move $move): void {
		$cursor = '';
		$cursors = [];
		$listed = [];
		$copied = 0;
		do {
			$cursors[$cursor] = true;
			$query = ['limit' => self::MAX_BLOBS_PER_PAGE] + ($cursor !== '' ? ['cursor' => $cursor] : []);
			$page = $this->authed($move, 'com.atproto.repo.listMissingBlobs', 'GET', $query);
			$new = 0;
			foreach (is_array($page['blobs'] ?? null) ? $page['blobs'] : [] as $missing) {
				$cid = (string)($missing['cid'] ?? '');
				if (isset($listed[$cid])) {
					continue;
				}
				$listed[$cid] = true;
				$new++;
				$blob = $this->blobs->get($move->did, $cid);
				if ($blob === null) {
					// a record names a blob this server never had; the other PDS
					// lists it as missing, as this one would have
					continue;
				}
				$bytes = str_starts_with($blob->mime, 'video/') ? fn () => $this->videos->open($blob)['stream'] : $this->pictures->read($blob);
				$this->authed($move, 'com.atproto.repo.uploadBlob', 'POST', [], null, $bytes, $blob->mime);
				$move->progress['blobs'] = ++$copied;
				$this->save($move);
			}
			$cursor = (string)($page['cursor'] ?? '');
			$move->progress['blobs'] = $copied;
			$this->save($move);
		} while ($cursor !== '' && $new > 0 && !isset($cursors[$cursor]) && count($cursors) < self::MAX_BLOB_PAGES);
	}

	/**
	 * @throws AtprotoException
	 */
	private function activate(Move $move, Identity $identity): void {
		// a procedure without input: a PDS refuses one that is sent a body
		$this->authed($move, 'com.atproto.server.activateAccount', 'POST');
		$this->identities->markMovedAway($identity);
	}

	/**
	 * A call to the other PDS as the moving account, its session refreshed
	 * once when it has expired.
	 *
	 * A body read from a stream is given as the closure that opens it: the
	 * HTTP client closes a stream once it has sent it, so the call after a
	 * refreshed session opens it again.
	 *
	 * @param string|(Closure(): resource)|null $bytes
	 * @throws AtprotoException
	 */
	private function authed(Move $move, string $method, string $verb = 'GET', array $query = [], ?array $json = null, $bytes = null, string $type = ''): array {
		$session = $this->unseal($move->session);
		$answer = $this->callPds($move, $method, $verb, $query, $json, $bytes, $type, (string)($session['accessJwt'] ?? ''));
		if (in_array($answer['status'], [400, 401], true) && in_array($answer['body']['error'] ?? '', ['ExpiredToken', 'InvalidToken'], true) && is_string($session['refreshJwt'] ?? null)) {
			$refreshed = $this->pds->expect($move->pds, 'com.atproto.server.refreshSession', 'POST', [], null, '', '', $session['refreshJwt']);
			$move->session = $this->seal($refreshed);
			$this->save($move);
			$answer = $this->callPds($move, $method, $verb, $query, $json, $bytes, $type, (string)($refreshed['accessJwt'] ?? ''));
		}
		if ($answer['status'] !== 200) {
			$error = trim((string)($answer['body']['error'] ?? '') . ': ' . (string)($answer['body']['message'] ?? ''), ': ');
			throw new AtprotoException($method . ' answered ' . $answer['status'] . ($error !== '' ? ' (' . $error . ')' : ''));
		}

		return $answer['body'];
	}

	/**
	 * @param string|(Closure(): resource)|null $bytes
	 * @return array{status: int, body: array}
	 */
	private function callPds(Move $move, string $method, string $verb, array $query, ?array $json, $bytes, string $type, string $token): array {
		$body = $bytes instanceof Closure ? $bytes() : $bytes;
		try {
			return $this->pds->call($move->pds, $method, $verb, $query, $json, $body, $type, $token);
		} finally {
			// the client closes what it sent; this closes what it never got to
			if (is_resource($body)) {
				fclose($body);
			}
		}
	}

	private function seal(array $session): string {
		return $this->crypto->encrypt((string)json_encode(['accessJwt' => $session['accessJwt'] ?? '', 'refreshJwt' => $session['refreshJwt'] ?? '']));
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

	/**
	 * @param list<string> $domains
	 */
	private static function underAny(string $handle, array $domains): bool {
		foreach ($domains as $domain) {
			if (str_ends_with($handle, str_starts_with($domain, '.') ? $domain : '.' . $domain)) {
				return true;
			}
		}

		return false;
	}
}
