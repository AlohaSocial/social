<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ImportJob;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ImportQueueService;
use OCA\Social\Service\MigrationArchiveService;
use OCA\Social\Service\MigrationService;
use OCA\Social\Service\MoveInService;
use OCA\Social\Service\PostImportService;
use OCA\Social\Service\SwitchService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Taking your account's data out, and putting it back.
 *
 * These are the Migration page's buttons: the archive out and back in, the
 * lists of accounts another network exported as CSV — follows, blocks, mutes
 * and lists — and the same four written back out one file at a time. They are
 * session routes rather than client-API ones on purpose: they are for the
 * person sitting in front of the browser, not for a Mastodon client, and an
 * archive of everything an account ever wrote is not something a third-party
 * token should be able to ask for.
 *
 * The work is `MigrationArchiveService`, which drives the same
 * `SocialMigrator` that Nextcloud's whole-account export uses — so what
 * travels, and what deliberately does not (the private key, above all), is
 * decided in one place for both.
 */
class MigrationController extends Controller {
	/** A refused upload says which limit it hit rather than "failed". */
	private const IMPORT_MAX_SIZE = 100 * 1024 * 1024;

	public function __construct(
		IRequest $request,
		private ?string $userId,
		private MigrationArchiveService $archiveService,
		private MigrationService $migrationService,
		private PostImportService $postImportService,
		private ImportQueueService $importQueueService,
		private MoveInService $moveInService,
		private AccountService $accountService,
		private SwitchService $switchService,
		private LoggerInterface $logger,
	) {
		parent::__construct('social', $request);
	}

	/** The account's own Social data, as a zip file. */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 6, period: 3600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/migration/export')]
	public function export(): Response {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$path = $this->archiveService->export($this->userId);
			$contents = file_get_contents($path);
			if ($contents === false) {
				throw new \RuntimeException('the finished archive could not be read back');
			}

			// Read into memory and handed over rather than streamed from disk:
			// the temp file is cleaned up at the end of the request, and a
			// stream response outlives that.
			//
			// `DataDisplayResponse` with the disposition written out, rather
			// than `DataDownloadResponse`, which builds that header through
			// Symfony's HeaderUtils — a class the server has and this app does
			// not depend on, so the download would work on a server and be
			// unreachable from a test. The name is already reduced to
			// `[A-Za-z0-9._-]` by `filename()`, so there is nothing in it that
			// could break out of the quotes.
			$response = new DataDisplayResponse($contents, Http::STATUS_OK, [
				'Content-Type' => 'application/zip',
			]);
			// after construction, not as a constructor header: the constructor
			// sets `inline; filename=""` itself, and does it last
			$response->addHeader(
				'Content-Disposition',
				'attachment; filename="' . $this->archiveService->filename($this->userId) . '"'
			);

			return $response;
		} catch (Throwable $e) {
			$this->logger->error('Social export failed', ['userId' => $this->userId, 'exception' => $e]);

			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Reads an archive from the export button back into this account.
	 *
	 * Additive: it restores the profile, the follows, the blocks, the mutes
	 * and the marks, and reports what the outbox holds rather than re-posting
	 * it — see `SocialMigrator::import()`. Nothing here deletes anything.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 6, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/migration/import')]
	public function import(): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$file = $_FILES['file'] ?? [];
		if ($file === [] || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return new DataResponse(['error' => 'no archive was uploaded'], Http::STATUS_BAD_REQUEST);
		}

		if (($file['size'] ?? 0) > self::IMPORT_MAX_SIZE) {
			return new DataResponse(
				['error' => 'this archive is larger than ' . (self::IMPORT_MAX_SIZE / 1024 / 1024) . ' MB'],
				Http::STATUS_REQUEST_ENTITY_TOO_LARGE
			);
		}

		try {
			$log = $this->archiveService->import($this->userId, $file['tmp_name']);

			return new DataResponse(['imported' => true, 'log' => $log], Http::STATUS_OK);
		} catch (Throwable $e) {
			$this->logger->warning('Social import failed', ['userId' => $this->userId, 'exception' => $e]);

			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Follows from another server's export.
	 *
	 * Mastodon, Pixelfed, GoToSocial and Akkoma all export the people you
	 * follow as `following_accounts.csv`, and this reads that file — which is
	 * also the one in the archive the export button produces. The follows are
	 * the only part of an account that cannot be carried in a file: a follow
	 * is a relationship two servers have to agree on, so each one is requested
	 * again from here.
	 *
	 * Queued, like every import below: each row is a WebFinger lookup and a
	 * delivery, and a few hundred of them outlive a web request. The answer
	 * is the import as queued; `GET /api/v1/migration/imports` is where it
	 * gets to.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/migration/follows')]
	public function importFollows(): DataResponse {
		return $this->queueUpload(ImportJob::KIND_FOLLOWS);
	}

	/**
	 * The old account, by handle, and what its server lets this one read off
	 * it — so the person sees who they are about to copy before anything
	 * happens, and is told when the follows or the posts are hidden and the
	 * file is the way instead.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/migration/move-in/inspect')]
	public function moveInInspect(string $handle = ''): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new DataResponse(['account' => $this->moveInService->inspect($handle)], Http::STATUS_OK);
		} catch (Throwable $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	/**
	 * Moving here from the old account, from nothing but its handle: the
	 * alias is set now, and following who it follows and bringing its public
	 * posts over is queued. The followers themselves are the old server's
	 * `Move`, which the page points at once this has run.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/migration/move-in')]
	public function moveIn(string $handle = '', string $follows = '1', string $posts = '1', string $fetch_media = '1'): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'not logged in'], Http::STATUS_UNAUTHORIZED);
		}
		if ($this->importQueueService->hasActive($this->userId, ImportJob::KIND_MOVE_IN)) {
			return new DataResponse(
				['error' => 'a move is already running; wait for it to finish'], Http::STATUS_CONFLICT
			);
		}

		try {
			$options = $this->moveInService->prepare(
				$this->userId, $handle, self::flag($follows), self::flag($posts), self::flag($fetch_media)
			);
			$job = $this->importQueueService->queue($this->userId, ImportJob::KIND_MOVE_IN, null, $options);
		} catch (Throwable $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new DataResponse(['import' => $job], Http::STATUS_ACCEPTED);
	}

	/** A form's yes: anything but an explicit no. */
	private static function flag(string $value): bool {
		return !in_array(strtolower(trim($value)), ['0', 'false', 'no', 'off'], true);
	}

	/**
	 * The imports this account asked for, newest first, with where each one
	 * got to: the list the Migration page polls while one runs.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/migration/imports')]
	public function imports(): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		return new DataResponse(['imports' => $this->importQueueService->listFor($this->userId)], Http::STATUS_OK);
	}

	/** Takes a finished import off the list; one still running stays, and says so. */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/migration/imports/{id}', requirements: ['id' => '\\d+'])]
	public function dismissImport(int $id): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		if (!$this->importQueueService->dismiss($this->userId, $id)) {
			return new DataResponse(
				['error' => 'no finished import of yours has that id'], Http::STATUS_NOT_FOUND
			);
		}

		return new DataResponse(['imports' => $this->importQueueService->listFor($this->userId)], Http::STATUS_OK);
	}

	/**
	 * The accounts an export blocks — Mastodon's `blocked_accounts.csv`.
	 *
	 * A separate route from the follows one rather than a `kind` on it: the
	 * two do different things (one asks another server for a relationship, one
	 * records a decision of this account's own), they carry different rate
	 * limits, and a client that got the kind wrong would otherwise follow the
	 * people it meant to block.
	 */
	/**
	 * The accounts an Instagram archive says you followed, as handles to try.
	 *
	 * Instagram exports no fediverse names — it has none — but Threads
	 * federates and a Threads handle is the Instagram one at `threads.net`, so
	 * the list is worth a lookup each. Nothing is followed here and nothing is
	 * stored: this answers who might be there.
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
	 * Which of those accounts exist, one batch at a time.
	 *
	 * Each name is a webfinger against another server, so the batch is bounded
	 * by `SwitchService::PROBE_BATCH` and the answer says how many were
	 * *checked* — the client's cursor moves on that rather than on how many
	 * were found, or a list where nobody is there would be asked about for
	 * ever.
	 *
	 * @param string[] $handles the names to look up
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/migration/people/find')]
	public function switchFind(array $handles = []): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new DataResponse($this->switchService->probe($handles), Http::STATUS_OK);
		} catch (Throwable $e) {
			$this->logger->warning('looking up names failed', ['exception' => $e]);

			return new DataResponse(['error' => 'those names could not be looked up'], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * The handle and address to leave behind on the network being left.
	 *
	 * Built from the actor rather than from the Nextcloud user id, which is
	 * not the same thing for any account whose fediverse name differs from it.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/migration/announcement')]
	public function switchAnnouncement(): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$actor = $this->accountService->getActorFromUserId($this->userId);

			return new DataResponse($this->switchService->announcement($actor), Http::STATUS_OK);
		} catch (Throwable $e) {
			return new DataResponse(['error' => 'no account to announce'], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/migration/blocks')]
	public function importBlocks(): DataResponse {
		return $this->queueUpload(ImportJob::KIND_BLOCKS);
	}

	/** The accounts an export mutes — Mastodon's `muted_accounts.csv`. */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/migration/mutes')]
	public function importMutes(): DataResponse {
		return $this->queueUpload(ImportJob::KIND_MUTES);
	}

	/**
	 * The lists an export names — Mastodon's `lists.csv`.
	 *
	 * Run after the follows: a list here holds accounts this one follows, as
	 * Mastodon's do, and an account not followed yet is counted as skipped
	 * rather than followed on the quiet by a button that says "lists".
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/migration/lists')]
	public function importLists(): DataResponse {
		return $this->queueUpload(ImportJob::KIND_LISTS);
	}

	/**
	 * One of this account's lists of accounts as a CSV, to carry on elsewhere.
	 *
	 * `{kind}` is `following`, `followers`, `blocks`, `mutes` or `lists`, and
	 * each is written in the shape the network it is named after writes it —
	 * the archive from the Export button holds the same files, and this is for
	 * the person who wants one of them without the whole thing.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 3600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/migration/export/{kind}')]
	public function exportCsv(string $kind): Response {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			[$name, $csv] = $this->migrationService->exportCsv($this->userId, $kind);

			// `text/csv` with the name spelled out, the way export() hands the
			// archive over. The name is one of the five the service chooses
			// from and never anything the caller sent, so there is nothing in
			// it that could break out of the quotes.
			$response = new DataDisplayResponse($csv, Http::STATUS_OK, [
				'Content-Type' => 'text/csv; charset=utf-8',
			]);
			$response->addHeader('Content-Disposition', 'attachment; filename="' . $name . '"');

			return $response;
		} catch (InvalidResourceException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			$this->logger->error('a CSV export failed', [
				'userId' => $this->userId, 'kind' => $kind, 'exception' => $e,
			]);

			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Keeps the uploaded file and queues the import of it, answering the
	 * import as queued (**202**). One import of a kind at a time per account:
	 * a second press while the first runs is a **409**, not a second run of
	 * the same file.
	 *
	 * @param array<string, mixed> $options what the run should know
	 */
	private function queueUpload(string $kind, array $options = []): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$file = $_FILES['file'] ?? [];
		if ($file === [] || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return new DataResponse(['error' => 'no file was uploaded'], Http::STATUS_BAD_REQUEST);
		}
		if (($file['size'] ?? 0) > self::IMPORT_MAX_SIZE) {
			return new DataResponse(
				['error' => 'this file is larger than ' . (self::IMPORT_MAX_SIZE / 1024 / 1024) . ' MB'],
				Http::STATUS_REQUEST_ENTITY_TOO_LARGE
			);
		}
		if ($this->importQueueService->hasActive($this->userId, $kind)) {
			return new DataResponse(
				['error' => 'an import of this kind is already running; wait for it to finish'],
				Http::STATUS_CONFLICT
			);
		}

		try {
			$job = $this->importQueueService->queue($this->userId, $kind, $file['tmp_name'], $options);
		} catch (Throwable $e) {
			$this->logger->warning('queueing an import failed', ['userId' => $this->userId, 'kind' => $kind, 'exception' => $e]);

			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse(['import' => $job], Http::STATUS_ACCEPTED);
	}

	/**
	 * The one upload still read inside the request — the Instagram archive
	 * the switch wizard mines for names — differs by the one call it makes:
	 * an uploaded file, read, handed to the service, and whatever it counted
	 * answered back. A file that could not be read is the client's problem and says so;
	 * anything the service throws is reported with its own message, which is
	 * the whole of the help there is.
	 *
	 * @param callable(string, string): array $import
	 */
	private function fromUpload(callable $import, string $failure): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$file = $_FILES['file'] ?? [];
		if ($file === [] || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return new DataResponse(['error' => 'no file was uploaded'], Http::STATUS_BAD_REQUEST);
		}

		$csv = file_get_contents($file['tmp_name']);
		if ($csv === false) {
			return new DataResponse(['error' => 'the uploaded file could not be read'], Http::STATUS_BAD_REQUEST);
		}

		try {
			return new DataResponse($import($this->userId, $csv), Http::STATUS_OK);
		} catch (Throwable $e) {
			$this->logger->warning($failure, ['userId' => $this->userId, 'exception' => $e]);

			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Brings one video over, by its address.
	 *
	 * The single-video half of the same job the archive import does: PeerTube
	 * has this and people use it, because an export is a heavy tool for one
	 * video and somebody who has lost their account on the old server cannot
	 * take one at all while the video is still there to be fetched.
	 *
	 * Ten an hour. One call fetches a document and a video file from another
	 * server, and a limit sized for an API call would make this a way to have
	 * this instance pull gigabytes from anywhere. See
	 * `PostImportService::importVideo()` for what it refuses and what it
	 * cannot check.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/migration/video')]
	public function importVideo(string $url = '', string $fetch_media = '1'): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$actor = $this->accountService->getActorFromUserId($this->userId);

			return new DataResponse(
				$this->postImportService->importVideo(
					$actor,
					$url,
					!in_array(strtolower(trim($fetch_media)), ['0', 'false', 'no'], true)
				),
				Http::STATUS_OK
			);
		} catch (InvalidResourceException $e) {
			// the message says which of the refusals it was, and that is the
			// whole of the help there is
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->warning('importing a video failed', [
				'userId' => $this->userId, 'exception' => $e,
			]);

			return new DataResponse(['error' => 'the video could not be brought over'], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Brings an account's own posts over from the server it wrote them on.
	 *
	 * Queued: the run reads an archive, writes every post in it and may fetch
	 * a picture for each, which is hours for a long history and no business
	 * of a web request. Nothing it writes is federated; see
	 * `PostImportService`.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/migration/posts')]
	public function importPosts(): DataResponse {
		// the pictures are fetched from the old server unless the person says
		// not to: it is their own archive and their own old account, but it is
		// a request that server can see, so it is a decision rather than a
		// default nobody was told about
		$fetchMedia = !in_array(
			strtolower(trim((string)$this->request->getParam('fetch_media', '1'))),
			['0', 'false', 'no'],
			true
		);

		return $this->queueUpload(ImportJob::KIND_POSTS, ['fetch_media' => $fetchMedia]);
	}

	/**
	 * The accounts this one also answers to — its `alsoKnownAs`.
	 *
	 * Setting an alias on your **own** account is the half of moving here that
	 * federates nothing: it is a statement this server makes about an account
	 * it owns, and it is what the *old* server demands before it will accept a
	 * `Move` pointing at this one. Until now it was `occ social:account:alias`,
	 * so arriving from Pixelfed needed an administrator for a field the person
	 * could have filled in themselves.
	 *
	 * The dangerous direction is still not a button. Moving your followers
	 * *away* sends a `Move` to every server that knows you and cannot be taken
	 * back; that stays `occ social:account:move`.
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
		if ($this->userId === null) {
			return new DataResponse(['error' => 'not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new DataResponse(['aliases' => $action($this->userId)], Http::STATUS_OK);
		} catch (Throwable $e) {
			// what is refused here is an address that is not an actor id, and
			// the message says which — it is the whole of the help there is
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}
}
