<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use InvalidArgumentException;
use OCA\Social\AppInfo\Application;
use OCA\Social\Db\DiscoverCategoriesRequest;
use OCA\Social\Db\MediaBlocksRequest;
use OCA\Social\Db\TrendReviewRequest;
use OCA\Social\Exceptions\ModerationNotAllowedException;
use OCA\Social\Exceptions\ReportNotFoundException;
use OCA\Social\Model\Client\AdminAccount;
use OCA\Social\Model\Report;
use OCA\Social\Model\Strike;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\AdminApiService;
use OCA\Social\Service\BlocklistImportService;
use OCA\Social\Service\BlocklistSubscriptionService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\EmojiService;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\ModerationService;
use OCA\Social\Service\PostReviewService;
use OCA\Social\Service\ReportService;
use OCA\Social\Service\TrendReviewService;
use OCA\Social\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Moderation actions behind the Social section of the admin settings. Every
 * route requires a session and a CSRF token — none of this is part of the
 * client API.
 *
 * `AuthorizedAdminSetting` rather than the admin-by-default a controller has
 * without `NoAdminRequired`: an administrator passes, and so does a group the
 * administrator has handed `AdminSettings` to under Administration
 * privileges, which is what lets somebody moderate without administering the
 * whole server. The page itself is reachable to exactly the same people,
 * because core gates it on the same delegation.
 */
class ModerationController extends Controller {
	/** What one page of the account browser holds. */
	private const ACCOUNTS_PER_PAGE = 40;

	/** What one page of the refused files holds. */
	private const MEDIA_BLOCKS_PER_PAGE = 100;

	/** What one page of the review queue holds. */
	private const REVIEW_PER_PAGE = 50;

	public function __construct(
		IRequest $request,
		private ReportService $reportService,
		private FediverseService $fediverseService,
		private ConfigService $configService,
		private ModerationService $moderationService,
		private AdminApiService $adminApiService,
		private PostReviewService $postReviewService,
		private AccountService $accountService,
		private MediaBlocksRequest $mediaBlocksRequest,
		private DiscoverCategoriesRequest $discoverCategoriesRequest,
		private TrendReviewService $trendReviewService,
		private HashtagService $hashtagService,
		private EmojiService $emojiService,
		private IUserSession $userSession,
		private BlocklistImportService $blocklistImportService,
		private BlocklistSubscriptionService $blocklistSubscriptionService,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** Who decided, for a list nobody dares remove anything from a year later. */
	private function moderatorName(): string {
		return $this->userSession->getUser()?->getUID() ?? '';
	}

	/**
	 * A 403 when the signed-in moderator may not act on this account, which
	 * is `AdminApiService::assertMayActOn()`'s rule; null when they may.
	 */
	private function refusal(string $actorId): ?DataResponse {
		try {
			$this->adminApiService->assertMayActOn($actorId, $this->moderatorName());
		} catch (ModerationNotAllowedException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		return null;
	}

	/**
	 * Silences or suspends an account, or lifts whatever stands against it.
	 *
	 * @param string $actorId the account
	 * @param string $level 'silence', 'suspend', or '' to lift
	 * @param string $comment why, for whoever reads the list later
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'POST', url: '/moderation/accounts')]
	public function accountModerate(string $actorId, string $level, string $comment = ''): DataResponse {
		$actorId = trim($actorId);
		if ($actorId === '') {
			return new DataResponse(['error' => 'no account given'], Http::STATUS_BAD_REQUEST);
		}

		$refused = $this->refusal($actorId);
		if ($refused !== null) {
			return $refused;
		}

		if ($level === '') {
			$this->moderationService->lift($actorId, $comment);

			return new DataResponse(['actor_id' => $actorId, 'level' => '']);
		}

		try {
			return new DataResponse($this->moderationService->decide($actorId, $level, $comment));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * A page of the accounts this instance knows, for the browser on the
	 * settings page.
	 *
	 * The same read the Mastodon admin API answers `GET
	 * /api/v1/admin/accounts` with, narrowed to what a table needs: until
	 * this, only a *reported* account could be acted on from the web, and
	 * everything else needed a moderation client and a token.
	 *
	 * `query` is what a moderator would type — a username, a handle, or an
	 * instance — and is tried as all three, because asking which of them it
	 * was is a question the person already answered by typing it.
	 *
	 * @param string $query username, handle or instance
	 * @param string $origin 'local', 'remote', or '' for both
	 * @param string $status one of AdminApiService's statuses, or '' for any
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'GET', url: '/moderation/accounts')]
	public function accounts(
		string $query = '',
		string $origin = '',
		string $status = '',
		int $maxId = 0,
	): DataResponse {
		$query = trim($query);
		$local = match ($origin) {
			'local' => true,
			'remote' => false,
			default => null,
		};

		[$username, $domain] = $this->splitQuery($query);

		$page = $this->adminApiService->accountPage(
			$local,
			$username,
			'',
			$domain,
			$status,
			self::ACCOUNTS_PER_PAGE,
			$maxId,
		);

		// one query for the page rather than one an account: the column is a
		// number, and forty of them are not worth forty round trips
		$strikes = $this->moderationService->strikeCounts(array_map(
			static fn (AdminAccount $account): string => $account->getActorId(), $page['accounts']
		));

		return new DataResponse([
			'accounts' => array_map(
				static fn (AdminAccount $account): array => [
					'actor_id' => $account->getActorId(),
					'handle' => $account->getAccount()?->getAccount() ?? '',
					'username' => $account->getUsername(),
					'domain' => $account->getDomain(),
					'local' => $account->isLocal(),
					'level' => $account->getLevel(),
					'strikes' => $strikes[$account->getActorId()] ?? 0,
				],
				$page['accounts']
			),
			'cursors' => $page['cursors'],
		]);
	}

	/**
	 * What was typed, as the two halves the query takes.
	 *
	 * `@bob@noisy.test` and `bob@noisy.test` are an account on an instance,
	 * `noisy.test` is the instance, and a bare `bob` is a username anywhere —
	 * which is what somebody typing each of those means by it.
	 *
	 * @return array{0: string, 1: string} username, instance
	 */
	private function splitQuery(string $query): array {
		$query = ltrim($query, '@');
		if ($query === '') {
			return ['', ''];
		}

		$at = strrpos($query, '@');
		if ($at !== false) {
			return [substr($query, 0, $at), strtolower(substr($query, $at + 1))];
		}

		return str_contains($query, '.') ? ['', strtolower($query)] : [$query, ''];
	}

	/**
	 * What has been decided about one account before now, newest first.
	 *
	 * Reached from the strike count in the browser: a number is what a
	 * moderator scans a page for, and the history is what they need once one
	 * of them is not zero.
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'GET', url: '/moderation/accounts/history')]
	public function accountHistory(string $actorId): DataResponse {
		$actorId = trim($actorId);
		if ($actorId === '') {
			return new DataResponse(['error' => 'no account given'], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse([
			'strikes' => array_map(
				static fn (Strike $strike): array => [
					'action' => $strike->getAction(),
					'text' => $strike->getText(),
					'moderator' => $strike->getModerator(),
					'report_id' => $strike->getReportId(),
					'creation' => $strike->getCreation(),
				],
				$this->moderationService->history($actorId)
			),
		]);
	}

	/** Takes one post down, whoever wrote it. */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'POST', url: '/moderation/statuses/remove')]
	public function statusRemove(string $streamId): DataResponse {
		$streamId = trim($streamId);
		if ($streamId === '') {
			return new DataResponse(['error' => 'no post given'], Http::STATUS_BAD_REQUEST);
		}

		$this->moderationService->removeStream($streamId);

		return new DataResponse(['stream_id' => $streamId]);
	}

	/**
	 * One page of the open reports, or of the resolved ones, for the table on
	 * the settings page.
	 *
	 * The page renders the first fifty open reports itself; this is what the
	 * "Show more" button and the collapsed resolved section read. Each row
	 * carries what the table draws, including what stands against the
	 * reported account right now, so a resolved report from last month says
	 * whether the account it named is still suspended.
	 *
	 * @param bool $resolved the resolved ones instead of the open ones
	 * @param int $page 1-based
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'GET', url: '/moderation/reports')]
	public function reports(bool $resolved = false, int $page = 1): DataResponse {
		$result = $this->reportService->page($resolved, $page);

		$decisions = [];
		foreach ($this->moderationService->decisions() as $decision) {
			$decisions[$decision->getActorId()] = $decision->getLevel();
		}

		return new DataResponse([
			'reports' => array_map(
				static fn (Report $report): array => $report->moderationRow($decisions),
				$result['reports']
			),
			'total' => $result['total'],
			'page' => $result['page'],
			'perPage' => $result['perPage'],
		]);
	}

	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'POST', url: '/moderation/reports/{id}/resolve')]
	public function reportResolve(int $id, bool $resolved = true): DataResponse {
		try {
			return new DataResponse($this->reportService->setResolved($id, $resolved));
		} catch (ReportNotFoundException $e) {
			return new DataResponse(['error' => 'report not found'], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * The posts waiting for somebody to look at them, oldest first.
	 *
	 * Text and all: the queue exists to be read, and a row that only said
	 * "a post by @alice was held" would send a moderator looking for a post
	 * that is deliberately nowhere to be found.
	 *
	 * @param int $page 1-based
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'GET', url: '/moderation/review')]
	public function review(int $page = 1): DataResponse {
		$page = max(1, $page);
		$perPage = self::REVIEW_PER_PAGE;

		return new DataResponse([
			'held' => $this->postReviewService->pending($perPage, ($page - 1) * $perPage),
			'total' => $this->postReviewService->countPending(),
			'page' => $page,
			'perPage' => $perPage,
			'reviewFirstPost' => $this->postReviewService->reviewsFirstPost(),
			'autospam' => $this->postReviewService->autospam(),
		]);
	}

	/**
	 * Publishes one. It goes out as its author, down the path it would have
	 * taken, dated now — see `PostReviewService::approve()`.
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'POST', url: '/moderation/review/{id}/approve')]
	public function reviewApprove(int $id): DataResponse {
		try {
			$held = $this->postReviewService->heldPost($id);
			$author = $this->accountService->getFromId($held->getActorId());
			$this->postReviewService->approve($id, $author);

			return new DataResponse(['approved' => (string)$id]);
		} catch (Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}

	/** Refuses one, which tells its author and is recorded against them. */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'POST', url: '/moderation/review/{id}/reject')]
	public function reviewReject(int $id, string $comment = ''): DataResponse {
		try {
			$this->postReviewService->reject($id, trim($comment));

			return new DataResponse(['rejected' => (string)$id]);
		} catch (Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Turns the three rules on and off: a new account's first post, the spam
	 * rules, and videos.
	 *
	 * `reviewVideos` defaults to off in the signature as well as in the config
	 * so that an older card, which sends two fields and not three, does not
	 * silently turn it on — or off, once somebody has turned it on. That is why
	 * the card sends all three together.
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'POST', url: '/moderation/review/settings')]
	public function reviewSettings(
		bool $reviewFirstPost, bool $autospam, bool $reviewVideos = false,
	): DataResponse {
		$this->configService->setAppValue(
			ConfigService::SOCIAL_REVIEW_FIRST_POST, $reviewFirstPost ? '1' : '0'
		);
		$this->configService->setAppValue(ConfigService::SOCIAL_AUTOSPAM, $autospam ? '1' : '0');
		$this->configService->setAppValue(
			ConfigService::SOCIAL_REVIEW_VIDEOS, $reviewVideos ? '1' : '0'
		);

		return new DataResponse([
			'reviewFirstPost' => $this->postReviewService->reviewsFirstPost(),
			'autospam' => $this->postReviewService->autospam(),
			'reviewVideos' => $this->postReviewService->reviewsVideos(),
		]);
	}

	/**
	 * Marks everything an account posts sensitive, or stops.
	 *
	 * The step between doing nothing and silencing: an account can be asked to
	 * put a content warning on its pictures without being taken out of the
	 * timelines. It applies from the next post — rewriting somebody's old
	 * posts is a different and much larger decision.
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'POST', url: '/moderation/accounts/sensitive')]
	public function accountForceSensitive(string $actorId, bool $sensitive = true): DataResponse {
		$actorId = trim($actorId);
		if ($actorId === '') {
			return new DataResponse(['error' => 'no account given'], Http::STATUS_BAD_REQUEST);
		}

		$refused = $this->refusal($actorId);
		if ($refused !== null) {
			return $refused;
		}

		$this->moderationService->forceSensitive($actorId, $sensitive);

		return new DataResponse(['actorId' => $actorId, 'sensitive' => $sensitive]);
	}

	/**
	 * The pictures this instance refuses, by what is in them.
	 *
	 * Every other tool here acts on an account, and none of them stops a file
	 * coming back: the account is suspended, the picture is posted again by
	 * the next one, and a moderator is deleting the same image for the third
	 * time.
	 */
	/**
	 * The subjects this instance says it is about, as the administration page
	 * edits them.
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'GET', url: '/moderation/discover/categories')]
	public function discoverCategories(): DataResponse {
		return new DataResponse(['categories' => $this->discoverCategoriesRequest->getAll()]);
	}

	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'POST', url: '/moderation/discover/categories')]
	public function discoverCategoryAdd(string $name, string $hashtags = ''): DataResponse {
		$name = trim($name);
		if ($name === '') {
			return new DataResponse(['error' => 'a category needs a name'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		if ($this->discoverCategoriesRequest->count() >= DiscoverCategoriesRequest::MAX_CATEGORIES) {
			return new DataResponse(
				['error' => 'there are already ' . DiscoverCategoriesRequest::MAX_CATEGORIES . ' categories'],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		// written the way somebody writes hashtags: separated by spaces or
		// commas, with or without the hash
		$tags = [];
		foreach (preg_split('/[\s,]+/', $hashtags) ?: [] as $tag) {
			$tag = ltrim(trim($tag), '#');
			if ($tag !== '' && preg_match('/^[\w\x{00C0}-\x{024F}]{1,100}$/u', $tag) === 1) {
				$tags[strtolower($tag)] = $tag;
			}
			if (count($tags) >= DiscoverCategoriesRequest::MAX_TAGS) {
				break;
			}
		}

		if ($tags === []) {
			return new DataResponse(
				['error' => 'a category is a name and the hashtags it means'], Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$this->discoverCategoriesRequest->create(
			mb_substr($name, 0, 64), array_values($tags), $this->discoverCategoriesRequest->count()
		);

		return new DataResponse(['categories' => $this->discoverCategoriesRequest->getAll()]);
	}

	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'DELETE', url: '/moderation/discover/categories')]
	public function discoverCategoryRemove(int $id): DataResponse {
		$this->discoverCategoriesRequest->delete($id);

		return new DataResponse(['categories' => $this->discoverCategoriesRequest->getAll()]);
	}

	/**
	 * A page of the refused files, newest first.
	 *
	 * `next` is the `maxId` of the page after this one and null on the last,
	 * found by reading one row more than the page holds; `total` is how many
	 * there are in all. A refused file is enforced whether or not anybody can
	 * see it, so every one of them has to be reachable from here, and a page
	 * that is not the whole list has to say so.
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'GET', url: '/moderation/media/blocks')]
	public function mediaBlocks(int $maxId = 0): DataResponse {
		return new DataResponse($this->mediaBlockPage($maxId));
	}

	/**
	 * @return array{blocks: array<int, array<string, mixed>>, next: ?int, total: int}
	 */
	private function mediaBlockPage(int $maxId = 0): array {
		$rows = $this->mediaBlocksRequest->getPage(self::MEDIA_BLOCKS_PER_PAGE + 1, $maxId);
		$more = count($rows) > self::MEDIA_BLOCKS_PER_PAGE;
		$rows = array_slice($rows, 0, self::MEDIA_BLOCKS_PER_PAGE);
		$last = end($rows);

		return [
			'blocks' => $rows,
			'next' => ($more && $last !== false) ? $last['id'] : null,
			'total' => $this->mediaBlocksRequest->count(),
		];
	}

	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'POST', url: '/moderation/media/blocks')]
	public function mediaBlockAdd(string $hash, string $reason = ''): DataResponse {
		$hash = strtolower(trim($hash));
		// sha256, hex, as the upload path computes it — anything else would be
		// a row that can never match a file
		if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
			return new DataResponse(
				['error' => 'that is not a sha256 hash'], Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$this->mediaBlocksRequest->block($hash, trim($reason), $this->moderatorName());

		return new DataResponse($this->mediaBlockPage());
	}

	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'DELETE', url: '/moderation/media/blocks')]
	public function mediaBlockRemove(string $hash): DataResponse {
		$this->mediaBlocksRequest->unblock(strtolower(trim($hash)));

		return new DataResponse($this->mediaBlockPage());
	}

	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'POST', url: '/moderation/fediverse/add')]
	public function fediverseAdd(string $address): DataResponse {
		$address = strtolower(trim($address));
		if ($address === '' || !preg_match('/^[a-z0-9.:\[\]-]+$/', $address)) {
			return new DataResponse(['error' => 'invalid address'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$this->fediverseService->addAddress($address);

		return new DataResponse(['list' => $this->fediverseService->getListedAddresses()]);
	}

	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'POST', url: '/moderation/fediverse/remove')]
	public function fediverseRemove(string $address): DataResponse {
		$this->fediverseService->removeAddress(strtolower(trim($address)));

		return new DataResponse(['list' => $this->fediverseService->getListedAddresses()]);
	}

	/**
	 * Reads an uploaded block list, and says what applying it would do.
	 *
	 * Two steps on purpose. Every domain a block list adds deletes what this
	 * instance holds of that server, so the number in front of an
	 * administrator before they agree to it is the whole point of the page —
	 * a file from somebody else naming two hundred servers is not a thing to
	 * apply and then read.
	 *
	 * Fail-closed, as `occ social:fediverse import` is: a row that names no
	 * instance is something to look at rather than something to skip past,
	 * because this is a file the administrator chose.
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'POST', url: '/moderation/fediverse/blocklist/preview')]
	public function blocklistPreview(string $csv = ''): DataResponse {
		try {
			$read = $this->blocklistImportService->parse($csv, BlocklistImportService::FORMAT_CSV);
			$would = $this->blocklistImportService->apply($read['entries'], true);
		} catch (InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new DataResponse($would + [
			'entries' => $this->asRows($read['entries']),
			'rejected' => $read['rejected'],
			'skipped' => $read['skipped'],
		]);
	}

	/** Applies an uploaded block list, having shown what it would do. */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'POST', url: '/moderation/fediverse/blocklist/import')]
	public function blocklistImport(string $csv = ''): DataResponse {
		try {
			$read = $this->blocklistImportService->parse($csv, BlocklistImportService::FORMAT_CSV);
			if ($read['rejected'] !== []) {
				return new DataResponse(
					['error' => 'the list names something that is not an instance', 'rejected' => $read['rejected']],
					Http::STATUS_UNPROCESSABLE_ENTITY
				);
			}

			$applied = $this->blocklistImportService->apply($read['entries']);
		} catch (InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new DataResponse($applied + [
			'list' => $this->fediverseService->getListedAddresses(),
			'silenced' => $applied['silenced'],
		]);
	}

	/** The published lists this instance follows, and what each last did. */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'GET', url: '/moderation/fediverse/blocklist/sources')]
	public function blocklistSources(): DataResponse {
		return new DataResponse(['sources' => $this->blocklistSubscriptionService->sources()]);
	}

	/** Follows a published list, or stops following it. */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'POST', url: '/moderation/fediverse/blocklist/sources')]
	public function blocklistSource(string $id, bool $enabled, ?string $url = null): DataResponse {
		try {
			$sources = $this->blocklistSubscriptionService->configure($id, $enabled, $url);
		} catch (InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new DataResponse(['sources' => $sources]);
	}

	/**
	 * Reads one followed list now, rather than waiting for the daily job.
	 *
	 * `dryRun` is what the administrator presses before turning a source on:
	 * it fetches the list and reports what following it would do, without
	 * following it.
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'POST', url: '/moderation/fediverse/blocklist/fetch')]
	public function blocklistFetch(string $id, bool $dryRun = false): DataResponse {
		try {
			$result = $this->blocklistSubscriptionService->fetch($id, $dryRun);
		} catch (InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new DataResponse([
			'result' => $result,
			'sources' => $this->blocklistSubscriptionService->sources(),
			'list' => $this->fediverseService->getListedAddresses(),
		]);
	}

	/**
	 * A parsed list as the page draws it.
	 *
	 * @param array<string, string> $entries domain => severity
	 *
	 * @return array<array{domain: string, severity: string}>
	 */
	private function asRows(array $entries): array {
		$rows = [];
		foreach ($entries as $domain => $severity) {
			$rows[] = ['domain' => $domain, 'severity' => $severity];
		}

		return $rows;
	}

	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'POST', url: '/moderation/retention')]
	public function retention(int $days): DataResponse {
		if ($days < 0 || $days > 3650) {
			return new DataResponse(['error' => 'invalid retention period'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$this->configService->setAppValue(ConfigService::SOCIAL_RETENTION_DAYS, (string)$days);

		return new DataResponse(['retentionDays' => $days]);
	}

	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'POST', url: '/moderation/fediverse/access')]
	public function fediverseAccess(string $type): DataResponse {
		try {
			$this->fediverseService->setAccessType($type);
		} catch (Exception $e) {
			return new DataResponse(['error' => 'invalid access type'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new DataResponse(['accessType' => $this->fediverseService->getAccessType()]);
	}

	// What may trend, what the instance's emoji are, and what its rules say

	/**
	 * The hashtags, links and posts a moderator has decided about.
	 *
	 * All three kinds in one answer, because the panel shows one list: what is
	 * being kept out of Explore, whatever kind of thing it is.
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'GET', url: '/moderation/trends')]
	public function trendDecisions(): DataResponse {
		return new DataResponse([
			'tags' => $this->trendReviewService->decisions(TrendReviewRequest::KIND_TAG),
			'links' => $this->trendReviewService->decisions(TrendReviewRequest::KIND_LINK),
			'statuses' => $this->trendReviewService->decisions(TrendReviewRequest::KIND_STATUS),
			'trending' => $this->hashtagService->getTrending(20),
		]);
	}

	/** Keeps something out of what is trending. */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'POST', url: '/moderation/trends')]
	public function trendReject(string $kind = '', string $ref = ''): DataResponse {
		try {
			$this->trendReviewService->decide($kind, $ref, false, $this->moderatorName());
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return $this->trendDecisions();
	}

	/** Lets it back in, so it trends on its own merits again. */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'DELETE', url: '/moderation/trends')]
	public function trendForget(string $kind = '', string $ref = ''): DataResponse {
		try {
			$this->trendReviewService->forget($kind, $ref);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return $this->trendDecisions();
	}

	/** The instance's own emoji, which were `occ`-only until now. */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'GET', url: '/moderation/emojis')]
	public function emojis(): DataResponse {
		return new DataResponse(['emojis' => $this->emojiService->all()]);
	}

	/**
	 * Adds one from an uploaded picture.
	 *
	 * The upload is written to a temporary file and handed to the same service
	 * `occ social:emoji` calls, so the checks on the shortcode, the size and
	 * the format are in one place rather than two that could drift.
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'POST', url: '/moderation/emojis')]
	public function emojiAdd(string $shortcode = '', string $category = ''): DataResponse {
		$upload = $this->request->getUploadedFile('picture');
		if (!is_array($upload) || ($upload['tmp_name'] ?? '') === '') {
			return new DataResponse(
				['error' => 'a picture is needed'], Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		try {
			$this->emojiService->add($shortcode, (string)$upload['tmp_name'], $category);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return $this->emojis();
	}

	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'DELETE', url: '/moderation/emojis')]
	public function emojiRemove(string $shortcode = ''): DataResponse {
		if (!$this->emojiService->remove($shortcode)) {
			return new DataResponse(
				['error' => 'there is no emoji with that shortcode'], Http::STATUS_NOT_FOUND
			);
		}

		return $this->emojis();
	}

	/**
	 * The rules this instance asks people to follow.
	 *
	 * One per line in an app value, which is how they were already stored and
	 * how `occ config:app:set social rules` sets them; what was missing was a
	 * place to read and write them without a shell. Every client shows them on
	 * sign-up, and `/api/v1/instance/rules` serves them.
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'GET', url: '/moderation/rules')]
	public function rules(): DataResponse {
		return new DataResponse([
			'rules' => $this->configService->getAppValue('rules'),
		]);
	}

	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'POST', url: '/moderation/rules')]
	public function rulesSave(string $rules = ''): DataResponse {
		// stored as it was typed, trimmed of the blank lines a text area
		// collects; the reader splits on newlines and drops the empties anyway,
		// and storing what somebody typed is easier to explain than storing a
		// normalised version of it
		$this->configService->setAppValue('rules', trim($rules));

		return $this->rules();
	}
}
