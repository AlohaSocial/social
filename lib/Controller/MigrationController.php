<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Atproto\Move\BlueskyAfterMoveOut;
use OCA\Social\Exceptions\AccountNotSetUpException;
use OCA\Social\Exceptions\AccountSuspendedException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\MoveErrorException;
use OCA\Social\Model\ImportJob;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ImportQueueService;
use OCA\Social\Service\MigrationArchiveService;
use OCA\Social\Service\MigrationService;
use OCA\Social\Service\MoveFinishService;
use OCA\Social\Service\MoveInService;
use OCA\Social\Service\PostImportService;
use OCA\Social\Service\SwitchService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Taking an account's data out and putting it back: the buttons of the
 * Migration section of Settings.
 *
 * Exports, imports and moving in are client-API routes, so a mobile client can
 * use them: the whole archive needs the broad `read`, one list as CSV the scope
 * that reads it, and anything writing into the account the broad `write`.
 * Moving away and undoing it, the move-in callback, the aliases and the switch
 * wizard's helpers need the Nextcloud session, as moving away asks for the
 * password. The work is `MigrationArchiveService`, which uses the same
 * `SocialMigrator` as Nextcloud's account export.
 */
class MigrationController extends ClientApiController {
	/** A refused upload says which limit it hit rather than "failed". */
	private const IMPORT_MAX_SIZE = 100 * 1024 * 1024;
	/**
	 * The largest list of accounts, posts or domains taken: the run reads
	 * the whole file into memory, and the most entries it works through
	 * (`MigrationService::MAX_IMPORT_ENTRIES`) fit in a fraction of this.
	 */
	private const LIST_MAX_SIZE = 10 * 1024 * 1024;

	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		AccountService $accountService,
		ClientService $clientService,
		private ?string $userId,
		private MigrationArchiveService $archiveService,
		private MigrationService $migrationService,
		private PostImportService $postImportService,
		private ImportQueueService $importQueueService,
		private MoveInService $moveInService,
		private MoveFinishService $moveFinishService,
		private IURLGenerator $urlGenerator,
		private SwitchService $switchService,
	) {
		parent::__construct($request, $userSession, $logger, $accountService, $clientService);
	}

	/** The account's own Social data, as a zip file. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 6, period: 3600)]
	#[UserRateLimit(limit: 6, period: 3600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/migration/export')]
	public function export(): Response {
		try {
			$userId = $this->caller(['read'], true);
		} catch (Throwable $e) {
			return $this->error($e);
		}

		try {
			$path = $this->archiveService->export($userId);
			$contents = file_get_contents($path);
			if ($contents === false) {
				throw new \RuntimeException('the finished archive could not be read back');
			}

			// Read into memory because the temp file is removed at the end of the
			// request, which a stream response outlives. `DataDisplayResponse` instead
			// of `DataDownloadResponse`, which needs Symfony's HeaderUtils that this
			// app does not depend on; `filename()` reduces the name to
			// `[A-Za-z0-9._-]`, so it cannot break out of the quotes.
			$response = new DataDisplayResponse($contents, Http::STATUS_OK, [
				'Content-Type' => 'application/zip',
			]);
			// after construction, not as a constructor header: the constructor
			// sets `inline; filename=""` itself, and does it last
			$response->addHeader(
				'Content-Disposition',
				'attachment; filename="' . $this->archiveService->filename($userId) . '"'
			);

			return $response;
		} catch (Throwable $e) {
			return $this->refused($e, Http::STATUS_INTERNAL_SERVER_ERROR, 'Social export failed', ['userId' => $userId]);
		}
	}

	/**
	 * Reads an archive from the export button back into this account. Additive:
	 * nothing is deleted and the outbox is reported, not re-posted (see
	 * `SocialMigrator::import()`).
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 6, period: 3600)]
	#[UserRateLimit(limit: 6, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/migration/import')]
	public function import(): DataResponse {
		try {
			$userId = $this->caller(['write']);
		} catch (Throwable $e) {
			return $this->error($e);
		}

		$file = $_FILES['file'] ?? [];
		if ($file === [] || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return self::refusal('no archive was uploaded', 'no-file', Http::STATUS_BAD_REQUEST);
		}

		if (($file['size'] ?? 0) > self::IMPORT_MAX_SIZE) {
			return self::refusal(
				'this archive is larger than ' . (self::IMPORT_MAX_SIZE / 1024 / 1024) . ' MB',
				'file-too-large',
				Http::STATUS_REQUEST_ENTITY_TOO_LARGE
			);
		}

		try {
			$log = $this->archiveService->import($userId, $file['tmp_name']);

			return new DataResponse(['imported' => true, 'log' => $log], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->refused($e, Http::STATUS_BAD_REQUEST, 'Social import failed', ['userId' => $userId]);
		}
	}

	/**
	 * Follows from another server's `following_accounts.csv` (Mastodon,
	 * Pixelfed, GoToSocial, Akkoma, or this app's own archive); each follow is
	 * requested again from here. Queued, like every import below, as each row
	 * is a WebFinger lookup and a delivery; `GET /api/v1/migration/imports`
	 * reports the progress.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 30, period: 3600)]
	#[UserRateLimit(limit: 30, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/migration/follows')]
	public function importFollows(): DataResponse {
		return $this->queueUpload(ImportJob::KIND_FOLLOWS);
	}

	/**
	 * The old account, by handle, and what its server lets this one read, so
	 * the person sees who they are copying and whether the follows or posts
	 * are hidden and the file is the way instead.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 30, period: 3600)]
	#[UserRateLimit(limit: 30, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/migration/move-in/inspect')]
	public function moveInInspect(string $handle = ''): DataResponse {
		try {
			$this->caller(['write']);
		} catch (Throwable $e) {
			return $this->error($e);
		}

		try {
			return new DataResponse(['account' => $this->moveInService->inspect($handle)], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->refused($e, Http::STATUS_UNPROCESSABLE_ENTITY, 'inspecting the old account failed');
		}
	}

	/**
	 * Moving here from the old account by its handle: the alias is set now,
	 * and following its follows and bringing its public posts over is queued.
	 * The followers come with the old server's `Move`.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 10, period: 3600)]
	#[UserRateLimit(limit: 10, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/migration/move-in')]
	public function moveIn(string $handle = '', string $follows = '1', string $posts = '1', string $fetch_media = '1'): DataResponse {
		try {
			$userId = $this->caller(['write']);
		} catch (Throwable $e) {
			return $this->error($e);
		}
		if ($this->importQueueService->hasActive($userId, ImportJob::KIND_MOVE_IN)) {
			return self::refusal('a move is already running; wait for it to finish', 'import-running', Http::STATUS_CONFLICT);
		}

		try {
			$options = $this->moveInService->prepare(
				$userId, $handle, self::flag($follows), self::flag($posts), self::flag($fetch_media)
			);
			$job = $this->importQueueService->queue($userId, ImportJob::KIND_MOVE_IN, null, $options);
		} catch (Throwable $e) {
			return $this->refused($e, Http::STATUS_UNPROCESSABLE_ENTITY, 'moving in failed', ['userId' => $userId]);
		}

		return new DataResponse(['import' => $job], Http::STATUS_ACCEPTED);
	}

	/**
	 * Where this account stands: whether it moved, where, when, and when it
	 * may move (again).
	 */
	/**
	 * Starts finishing the move from here: the old server, when it is this
	 * app too, is asked to register this one and the person is sent to consent
	 * there. Answers the URL to send them to.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 10, period: 3600)]
	#[UserRateLimit(limit: 10, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/migration/move-in/finish')]
	public function moveInFinishStart(string $handle = '', string $bluesky = BlueskyAfterMoveOut::DEACTIVATE): DataResponse {
		try {
			$userId = $this->caller(['write']);
		} catch (Throwable $e) {
			return $this->error($e);
		}
		try {
			return new DataResponse(
				['authorize_url' => $this->moveFinishService->start($userId, $handle, $bluesky)],
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->refused($e, Http::STATUS_UNPROCESSABLE_ENTITY, 'starting to finish a move failed', ['userId' => $userId]);
		}
	}

	/** Where a finish started from here stands. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/migration/move-in/finish')]
	public function moveInFinishStatus(): DataResponse {
		try {
			return new DataResponse($this->moveFinishService->status($this->caller(['read'])), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Where the old server sends the person back with the code. The outcome
	 * is kept for the Migration settings, which this lands on either way; the
	 * code is spent here and never shown.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/migration/move-in/callback')]
	public function moveInFinishCallback(string $code = '', string $state = '', string $error = ''): RedirectResponse {
		if ($this->sessionRefusal() === null) {
			try {
				if ($error !== '') {
					throw new InvalidResourceException('the old server answered: ' . $error);
				}
				$this->moveFinishService->finish($this->userId, $code, $state);
			} catch (Throwable $e) {
				// recorded by the service for the page; nothing to add here
			}
		}

		return new RedirectResponse($this->urlGenerator->linkToRoute('social.Navigation.personalmigration'));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/migration/move')]
	public function moveStatus(): DataResponse {
		$signedOut = $this->sessionRefusal();
		if ($signedOut !== null) {
			return $signedOut;
		}

		try {
			return new DataResponse($this->migrationService->moveStatus($this->userId), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->refused($e, Http::STATUS_UNPROCESSABLE_ENTITY, 'reading the move status failed', ['userId' => $this->userId]);
		}
	}

	/**
	 * Moves this account away: every follower's server is told to follow the
	 * new account instead, and this one is marked as moved.
	 *
	 * Irreversible, so it needs the password confirmation, the handle typed
	 * back as `confirm`, and the new account naming this one in its
	 * `alsoKnownAs` (`MigrationService::move()`); thirty days between moves, as
	 * in Mastodon. `bluesky` says what becomes of the Bluesky identity
	 * (BlueskyAfterMoveOut), switched off by default.
	 */
	#[NoAdminRequired]
	#[PasswordConfirmationRequired]
	#[UserRateLimit(limit: 5, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/migration/move')]
	public function moveOut(string $target = '', string $confirm = '', string $bluesky = BlueskyAfterMoveOut::DEACTIVATE): DataResponse {
		$signedOut = $this->sessionRefusal();
		if ($signedOut !== null) {
			return $signedOut;
		}

		try {
			$handle = $this->ownAccountOf($this->userId)->getAccount();
			if ($handle === '' || strcasecmp(ltrim(trim($confirm), '@'), $handle) !== 0) {
				return self::refusal(
					'type your own handle, ' . $handle . ', to confirm the move', 'confirm-handle', Http::STATUS_UNPROCESSABLE_ENTITY
				);
			}

			$moved = $this->migrationService->move($this->userId, $target, true, $bluesky);
		} catch (Throwable $e) {
			return $this->refused($e, Http::STATUS_UNPROCESSABLE_ENTITY, 'moving away failed', ['userId' => $this->userId]);
		}

		return new DataResponse(
			$this->migrationService->moveStatus($this->userId)
				+ ['target' => ['acct' => $moved->getAccount(), 'url' => $moved->getUrl() !== '' ? $moved->getUrl() : $moved->getId()]],
			Http::STATUS_OK
		);
	}

	/**
	 * Takes the redirect off again. The followers do not come back by
	 * themselves, and the page says so; what this restores is the right to
	 * post and follow from here.
	 */
	#[NoAdminRequired]
	#[PasswordConfirmationRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/migration/move')]
	public function undoMove(): DataResponse {
		$signedOut = $this->sessionRefusal();
		if ($signedOut !== null) {
			return $signedOut;
		}

		try {
			$this->migrationService->undoMove($this->userId);

			return new DataResponse($this->migrationService->moveStatus($this->userId), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->refused($e, Http::STATUS_UNPROCESSABLE_ENTITY, 'undoing the move failed', ['userId' => $this->userId]);
		}
	}

	/** A form's yes: anything but an explicit no. */
	private static function flag(string $value): bool {
		return !in_array(strtolower(trim($value)), ['0', 'false', 'no', 'off'], true);
	}

	/**
	 * The imports this account asked for, newest first, with where each one
	 * got to: the list the Migration settings poll while one runs.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/migration/imports')]
	public function imports(): DataResponse {
		try {
			return new DataResponse(['imports' => $this->importQueueService->listFor($this->caller(['read']))], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** Takes a finished import off the list; one still running stays, and says so. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/migration/imports/{id}', requirements: ['id' => '\\d+'])]
	public function dismissImport(int $id): DataResponse {
		try {
			$userId = $this->caller(['write']);
		} catch (Throwable $e) {
			return $this->error($e);
		}

		if (!$this->importQueueService->dismiss($userId, $id)) {
			return self::refusal('no finished import of yours has that id', 'import-not-found', Http::STATUS_NOT_FOUND);
		}

		return new DataResponse(['imports' => $this->importQueueService->listFor($userId)], Http::STATUS_OK);
	}

	/**
	 * The accounts an export blocks, Mastodon's `blocked_accounts.csv`. A route
	 * of its own, so a client that got the kind wrong cannot follow the people
	 * it meant to block.
	 */
	/**
	 * The accounts an Instagram archive says you followed, as Threads handles
	 * to try (`threads.net`). Nothing is followed or stored.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/migration/people')]
	public function switchPeople(): DataResponse {
		return $this->fromUpload(
			fn (string $userId, string $body): array
				=> ['handles' => $this->switchService->candidates($body)],
			'reading the follow list failed'
		);
	}

	/**
	 * Which of those accounts exist, one batch (`SwitchService::PROBE_BATCH`)
	 * at a time. The answer counts the names checked, which the client's cursor
	 * moves on, so a list where nobody is found still ends.
	 *
	 * @param string[] $handles the names to look up
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/migration/people/find')]
	public function switchFind(array $handles = []): DataResponse {
		$signedOut = $this->sessionRefusal();
		if ($signedOut !== null) {
			return $signedOut;
		}

		try {
			return new DataResponse($this->switchService->probe($handles), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->refused($e, Http::STATUS_BAD_REQUEST, 'looking up names failed', ['userId' => $this->userId]);
		}
	}

	/**
	 * The handle and address to leave behind on the network being left, from
	 * the actor, whose name can differ from the Nextcloud user id.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/migration/announcement')]
	public function switchAnnouncement(): DataResponse {
		$signedOut = $this->sessionRefusal();
		if ($signedOut !== null) {
			return $signedOut;
		}

		try {
			$actor = $this->ownAccountOf($this->userId);

			return new DataResponse($this->switchService->announcement($actor), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->refused($e, Http::STATUS_INTERNAL_SERVER_ERROR, 'building the announcement failed', ['userId' => $this->userId]);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 30, period: 3600)]
	#[UserRateLimit(limit: 30, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/migration/blocks')]
	public function importBlocks(): DataResponse {
		return $this->queueUpload(ImportJob::KIND_BLOCKS);
	}

	/** The posts an export bookmarked — Mastodon's `bookmarks.csv`, one address a line. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 30, period: 3600)]
	#[UserRateLimit(limit: 30, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/migration/bookmarks')]
	public function importBookmarks(): DataResponse {
		return $this->queueUpload(ImportJob::KIND_BOOKMARKS);
	}

	/** The servers an export blocks — Mastodon's `blocked_domains.csv`, one domain a line. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 30, period: 3600)]
	#[UserRateLimit(limit: 30, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/migration/domain_blocks')]
	public function importDomainBlocks(): DataResponse {
		return $this->queueUpload(ImportJob::KIND_DOMAIN_BLOCKS);
	}

	/** The accounts an export mutes — Mastodon's `muted_accounts.csv`. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 30, period: 3600)]
	#[UserRateLimit(limit: 30, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/migration/mutes')]
	public function importMutes(): DataResponse {
		return $this->queueUpload(ImportJob::KIND_MUTES);
	}

	/**
	 * The lists an export names, Mastodon's `lists.csv`. Run after the follows:
	 * a list holds followed accounts only, and an account not followed yet is
	 * counted as skipped, not followed.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 30, period: 3600)]
	#[UserRateLimit(limit: 30, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/migration/lists')]
	public function importLists(): DataResponse {
		return $this->queueUpload(ImportJob::KIND_LISTS);
	}

	/**
	 * One of this account's lists as a CSV, in the shape the network it is
	 * named after writes it. `{kind}` is `following`, `followers`, `blocks`,
	 * `mutes`, `lists`, `bookmarks` or `domain_blocks`.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 30, period: 3600)]
	#[UserRateLimit(limit: 30, period: 3600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/migration/export/{kind}')]
	public function exportCsv(string $kind): Response {
		// the scope that reads the list on the Mastodon API; a kind that
		// names no list asks for all of `read`, and is a 404 after that
		try {
			$userId = match ($kind) {
				'following', 'followers' => $this->caller(['read:follows'], true),
				'blocks', 'domain_blocks' => $this->caller(['read:blocks'], true),
				'mutes' => $this->caller(['read:mutes'], true),
				'lists' => $this->caller(['read:lists'], true),
				'bookmarks' => $this->caller(['read:bookmarks'], true),
				default => $this->caller(['read'], true),
			};
		} catch (Throwable $e) {
			return $this->error($e);
		}

		try {
			[$name, $csv] = $this->migrationService->exportCsv($userId, $kind);

			// The name is one the service chooses, never the caller's, so it cannot
			// break out of the quotes.
			$response = new DataDisplayResponse($csv, Http::STATUS_OK, [
				'Content-Type' => 'text/csv; charset=utf-8',
			]);
			$response->addHeader('Content-Disposition', 'attachment; filename="' . $name . '"');

			return $response;
		} catch (Throwable $e) {
			return $this->refused($e, Http::STATUS_NOT_FOUND, 'a CSV export failed', ['userId' => $userId, 'kind' => $kind]);
		}
	}

	/**
	 * Keeps the uploaded file and queues its import, answering **202**. One
	 * import of a kind at a time per account: a second while the first runs
	 * is a **409**.
	 *
	 * @param array<string, mixed> $options what the run should know
	 */
	private function queueUpload(string $kind, array $options = []): DataResponse {
		try {
			$userId = $this->caller(['write']);
		} catch (Throwable $e) {
			return $this->error($e);
		}

		$file = $_FILES['file'] ?? [];
		if ($file === [] || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return self::refusal('no file was uploaded', 'no-file', Http::STATUS_BAD_REQUEST);
		}
		$maxSize = $kind === ImportJob::KIND_POSTS ? self::IMPORT_MAX_SIZE : self::LIST_MAX_SIZE;
		if (($file['size'] ?? 0) > $maxSize) {
			return self::refusal(
				'this file is larger than ' . ($maxSize / 1024 / 1024) . ' MB',
				'file-too-large',
				Http::STATUS_REQUEST_ENTITY_TOO_LARGE
			);
		}
		if ($this->importQueueService->hasActive($userId, $kind)) {
			return self::refusal(
				'an import of this kind is already running; wait for it to finish', 'import-running', Http::STATUS_CONFLICT
			);
		}

		try {
			$job = $this->importQueueService->queue($userId, $kind, $file['tmp_name'], $options);
		} catch (Throwable $e) {
			return $this->refused($e, Http::STATUS_CONFLICT, 'queueing an import failed', ['userId' => $userId, 'kind' => $kind]);
		}

		return new DataResponse(['import' => $job], Http::STATUS_ACCEPTED);
	}

	/**
	 * Reads an upload inside the request (the Instagram archive the switch
	 * wizard mines for names) and answers what the service counted. An
	 * unreadable file is the client's error; anything the service throws is
	 * logged and answered `server-error`.
	 *
	 * @param callable(string, string): array $import
	 */
	private function fromUpload(callable $import, string $failure): DataResponse {
		$signedOut = $this->sessionRefusal();
		if ($signedOut !== null) {
			return $signedOut;
		}

		$file = $_FILES['file'] ?? [];
		if ($file === [] || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return self::refusal('no file was uploaded', 'no-file', Http::STATUS_BAD_REQUEST);
		}

		$csv = file_get_contents($file['tmp_name']);
		if ($csv === false) {
			return self::refusal('the uploaded file could not be read', 'file-unreadable', Http::STATUS_BAD_REQUEST);
		}

		try {
			return new DataResponse($import($this->userId, $csv), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->refused($e, Http::STATUS_BAD_REQUEST, $failure, ['userId' => $this->userId]);
		}
	}

	/**
	 * Brings one video over by its address, as PeerTube does. Ten an hour, as
	 * each call has this instance fetch a video file from anywhere; see
	 * `PostImportService::importVideo()` for what it refuses.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 10, period: 3600)]
	#[UserRateLimit(limit: 10, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/migration/video')]
	public function importVideo(string $url = '', string $fetch_media = '1'): DataResponse {
		try {
			$userId = $this->caller(['write']);
		} catch (Throwable $e) {
			return $this->error($e);
		}

		try {
			return new DataResponse(
				$this->postImportService->importVideo(
					$this->viewer(),
					$url,
					!in_array(strtolower(trim($fetch_media)), ['0', 'false', 'no'], true)
				),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->refused($e, Http::STATUS_UNPROCESSABLE_ENTITY, 'importing a video failed', ['userId' => $userId]);
		}
	}

	/**
	 * Brings an account's own posts over from the server it wrote them on.
	 * Queued, as it may fetch a photo per post; nothing it writes is federated
	 * (see `PostImportService`).
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 30, period: 3600)]
	#[UserRateLimit(limit: 30, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/migration/posts')]
	public function importPosts(): DataResponse {
		// the photos are fetched from the old server unless the person says not
		// to, as that server can see the requests
		$fetchMedia = !in_array(
			strtolower(trim((string)$this->request->getParam('fetch_media', '1'))),
			['0', 'false', 'no'],
			true
		);

		return $this->queueUpload(ImportJob::KIND_POSTS, ['fetch_media' => $fetchMedia]);
	}

	/**
	 * The accounts this one also answers to, its `alsoKnownAs`: what the old
	 * server demands before it accepts a `Move` to this one. Setting it
	 * federates nothing.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/migration/aliases')]
	public function aliases(): DataResponse {
		return $this->aliasAction(fn (string $userId): array => $this->migrationService->listAliases($userId));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/migration/aliases')]
	public function aliasAdd(string $alias = ''): DataResponse {
		return $this->aliasAction(
			fn (string $userId): array => $this->migrationService->addAlias($userId, $alias)
		);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 3600)]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/migration/aliases')]
	public function aliasRemove(string $alias = ''): DataResponse {
		return $this->aliasAction(
			fn (string $userId): array => $this->migrationService->removeAlias($userId, $alias)
		);
	}

	/**
	 * The three alias routes differ by one call, and each of them answers with
	 * the list as it now stands — a client that has just changed it should not
	 * have to ask again to find out what it changed.
	 *
	 * @param callable(string): string[] $action
	 */
	private function aliasAction(callable $action): DataResponse {
		$signedOut = $this->sessionRefusal();
		if ($signedOut !== null) {
			return $signedOut;
		}

		try {
			return new DataResponse(['aliases' => $action($this->userId)], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->refused($e, Http::STATUS_UNPROCESSABLE_ENTITY, 'changing the aliases failed', ['userId' => $this->userId]);
		}
	}

	/**
	 * The refusal for a route that takes the Nextcloud session, or null when
	 * somebody is signed in whose account may use it: an account suspended
	 * here keeps only the export.
	 */
	private function sessionRefusal(): ?DataResponse {
		if ($this->userId === null) {
			return self::refusal('not logged in', 'not-signed-in', Http::STATUS_UNAUTHORIZED);
		}
		if ($this->clientService->isSuspendedUser($this->userId)) {
			return self::refusal((new AccountSuspendedException())->getMessage(), 'account-suspended', Http::STATUS_FORBIDDEN);
		}

		return null;
	}

	/** A refusal this controller knows by itself, as the Migration settings read it. */
	private static function refusal(string $error, string $code, int $status): DataResponse {
		return new DataResponse(['error' => $error, 'code' => $code], $status);
	}

	/**
	 * What a route answers when the work threw: the app's own refusal with its
	 * code and `$status`, anything else logged and answered `server-error`, as
	 * its message may name what only the logs should.
	 *
	 * @param array<string, mixed> $context for the log
	 */
	private function refused(Throwable $e, int $status, string $failure, array $context = []): DataResponse {
		if ($e instanceof MoveErrorException) {
			return new DataResponse($e->toArray(), $status);
		}
		if ($e instanceof AccountNotSetUpException) {
			return self::notSetUp($e);
		}
		if ($e instanceof AccountSuspendedException) {
			return self::refusal($e->getMessage(), 'account-suspended', Http::STATUS_FORBIDDEN);
		}

		$this->logger->error($failure, $context + ['exception' => $e]);

		return self::refusal('Something went wrong on this server', 'server-error', Http::STATUS_INTERNAL_SERVER_ERROR);
	}

	/**
	 * The account behind a client-API route of this controller, held to the
	 * scopes the route needs.
	 *
	 * @param string[] $scopes
	 * @param bool $whileSuspended a route a suspended account keeps: the
	 *                             export of its own data
	 */
	private function caller(array $scopes, bool $whileSuspended = false): string {
		$this->initViewer($scopes, true, $whileSuspended);

		return $this->viewer()->getUserId();
	}
}
