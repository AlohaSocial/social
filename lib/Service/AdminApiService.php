<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\ReportsRequest;
use OCA\Social\Db\ServerBlockRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\ModerationNotAllowedException;
use OCA\Social\Exceptions\ReportNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\AdminAccount;
use OCA\Social\Model\Client\AdminDomainBlock;
use OCA\Social\Model\Client\AdminReport;
use OCA\Social\Model\Moderation;
use OCA\Social\Model\Report;
use OCA\Social\Settings\AdminSection;
use OCA\Social\Tools\DbTime;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Settings\IManager as ISettingsManager;
use Psr\Log\LoggerInterface;

/**
 * What `/api/v1/admin/*` reads and decides. Decisions go through
 * `ModerationService`, reports through `ReportService` and the access list
 * through `FediverseService`; this adds the reads a moderation client needs.
 *
 * The SQL is in `protected` methods against `IDBConnection` so tests can
 * double them; the standalone suite cannot load DBAL to mock `IQueryBuilder`.
 *
 * A moderator is whoever may open the Social admin settings section: a
 * Nextcloud administrator or a member of a group it is delegated to
 * (`IManager::getAllowedAdminSettings()`), so this API and the panel agree.
 * OAuth scopes do not count: this app grants any scope a client asks for.
 */
class AdminApiService {
	/** What Mastodon defaults and caps a page of the admin account list at. */
	public const LIMIT = 40;
	public const MAX_LIMIT = 200;

	/** Mastodon's `status` filter. Two of them are states this app has not. */
	public const STATUS_ACTIVE = 'active';
	public const STATUS_SILENCED = 'silenced';
	public const STATUS_SUSPENDED = 'suspended';
	public const STATUS_PENDING = 'pending';
	public const STATUS_DISABLED = 'disabled';

	/** Mastodon's account actions. `sensitive` and `disable` have no meaning here. */
	public const ACTION_NONE = 'none';
	public const ACTION_SILENCE = 'silence';
	public const ACTION_SUSPEND = 'suspend';

	/**
	 * Mastodon's role permission flags, the bits of `role.permissions`.
	 * Only the ones a role here can carry are named.
	 */
	public const PERMISSION_ADMINISTRATOR = 1 << 0;
	public const PERMISSION_VIEW_DASHBOARD = 1 << 3;
	public const PERMISSION_MANAGE_REPORTS = 1 << 4;
	public const PERMISSION_MANAGE_FEDERATION = 1 << 5;
	public const PERMISSION_MANAGE_BLOCKS = 1 << 7;
	public const PERMISSION_MANAGE_TAXONOMIES = 1 << 8;
	public const PERMISSION_MANAGE_USERS = 1 << 10;
	public const PERMISSION_INVITE_USERS = 1 << 16;
	public const PERMISSION_DELETE_USER_DATA = 1 << 19;

	/** Every flag Mastodon defines, which is what it reports for an administrator. */
	public const PERMISSIONS_ALL = (1 << 23) - 1;

	/** What Mastodon's default role grants every account. */
	public const PERMISSIONS_EVERYONE = self::PERMISSION_INVITE_USERS;

	/** What this app's admin API lets a moderator do: no more, so a client hides nothing they can use. */
	public const PERMISSIONS_MODERATOR = self::PERMISSIONS_EVERYONE
		| self::PERMISSION_VIEW_DASHBOARD
		| self::PERMISSION_MANAGE_REPORTS
		| self::PERMISSION_MANAGE_FEDERATION
		| self::PERMISSION_MANAGE_BLOCKS
		| self::PERMISSION_MANAGE_TAXONOMIES
		| self::PERMISSION_MANAGE_USERS
		| self::PERMISSION_DELETE_USER_DATA;

	public function __construct(
		private IDBConnection $dbConnection,
		private IGroupManager $groupManager,
		private AccountService $accountService,
		private CacheActorService $cacheActorService,
		private ConfigService $configService,
		private FediverseService $fediverseService,
		private ModerationService $moderationService,
		private ReportService $reportService,
		private ReportsRequest $reportsRequest,
		private StreamRequest $streamRequest,
		private IUserManager $userManager,
		private ISettingsManager $settingsManager,
		private LoggerInterface $logger,
		private ServerBlockRequest $serverBlockRequest,
	) {
	}

	/**
	 * Whether the Nextcloud user behind the request may use any of this,
	 * asked of the user, never of the token: an administrator, or whoever the
	 * Social settings section is delegated to.
	 */
	public function isAdministrator(string $userId): bool {
		if ($userId === '') {
			return false;
		}

		if ($this->groupManager->isAdmin($userId)) {
			return true;
		}

		$user = $this->userManager->get($userId);
		if ($user === null) {
			return false;
		}

		return $this->settingsManager->getAllowedAdminSettings(
			AdminSection::SECTION_ID, $user
		) !== [];
	}

	/**
	 * Refuses a moderator's action against an account they may not act on: a
	 * non-administrator may not act on an administrator's account, and nobody
	 * on their own. Remote accounts are never refused here.
	 *
	 * @throws ModerationNotAllowedException
	 */
	public function assertMayActOn(string $actorId, string $callerId): void {
		$userId = $this->localUserOf($actorId);
		if ($userId === '') {
			return;
		}

		if ($userId === $callerId) {
			throw new ModerationNotAllowedException('a moderator cannot act on their own account');
		}

		if ($this->groupManager->isAdmin($userId) && !$this->groupManager->isAdmin($callerId)) {
			throw new ModerationNotAllowedException(
				'only a Nextcloud administrator can act on an administrator\'s account'
			);
		}
	}

	/**
	 * Refuses a report that is not about the account being acted on, so that
	 * a decision cannot close an unrelated report as a side effect.
	 *
	 * @throws ReportNotFoundException no such report, or not about this account
	 */
	public function assertReportConcerns(int $reportId, string $actorId): void {
		if ($this->reportsRequest->getById($reportId)->getAccountId() !== $actorId) {
			throw new ReportNotFoundException('report ' . $reportId . ' is not about this account');
		}
	}

	/** The Nextcloud user behind a local account, or '' for anything else. */
	private function localUserOf(string $actorId): string {
		try {
			return $this->accountService->getFromId($actorId)->getUserId();
		} catch (Exception $e) {
			return '';
		}
	}

	/**
	 * The `role` of Mastodon's CredentialAccount: what a client reads to decide
	 * whether to offer moderation at all, before it asks for an admin scope.
	 *
	 * A Nextcloud administrator is Mastodon's administrator, a user the Social
	 * section is delegated to is a moderator with exactly what the admin API
	 * lets them do, and everybody else has Mastodon's default role. It is
	 * worked out on every call, so a change of group shows on the next one.
	 *
	 * @return array{id: string, name: string, color: string, permissions: string, highlighted: bool}
	 */
	public function credentialRole(string $userId): array {
		if ($userId !== '' && $this->groupManager->isAdmin($userId)) {
			return $this->role('3', 'Admin', self::PERMISSIONS_ALL, true);
		}

		if ($this->isAdministrator($userId)) {
			return $this->role('1', 'Moderator', self::PERMISSIONS_MODERATOR, true);
		}

		return $this->role('-99', '', self::PERMISSIONS_EVERYONE, false);
	}

	/**
	 * @return array{id: string, name: string, color: string, permissions: string, highlighted: bool}
	 */
	private function role(string $id, string $name, int $permissions, bool $highlighted): array {
		return [
			'id' => $id,
			'name' => $name,
			'color' => '',
			'permissions' => (string)$permissions,
			'highlighted' => $highlighted,
		];
	}

	/**
	 * A page of accounts, newest first, with the cursor ids that page it.
	 *
	 * `pending` and `disabled` are states this app does not have, so they
	 * answer no accounts rather than all of them (the caller does the same for
	 * `email` and `ip`). `silenced` and `suspended` are read from the
	 * decisions, as a suspension deletes the cached actor; they are a handful
	 * of rows, unpaged beyond `limit` and without cursors. Cursors are row
	 * nids, so an unreadable account cannot stall paging.
	 *
	 * @param bool|null $local true for local accounts, false for remote, null
	 *                         for both — Mastodon's `origin`
	 *
	 * @return array{accounts: AdminAccount[], cursors: array<int, int|string>}
	 */
	public function accountPage(
		?bool $local = null,
		string $username = '',
		string $displayName = '',
		string $domain = '',
		string $status = '',
		int $limit = self::LIMIT,
		int $maxId = 0,
		int|string $minId = 0,
	): array {
		$limit = max(1, min(self::MAX_LIMIT, $limit));

		if (in_array($status, [self::STATUS_PENDING, self::STATUS_DISABLED], true)) {
			return ['accounts' => [], 'cursors' => []];
		}

		if ($status === self::STATUS_SILENCED || $status === self::STATUS_SUSPENDED) {
			return [
				'accounts' => $this->moderatedAccounts(
					($status === self::STATUS_SILENCED) ? Moderation::SILENCE : Moderation::SUSPEND,
					$local, $username, $domain, $limit
				),
				'cursors' => [],
			];
		}

		$rows = $this->accountRows(
			$local, $username, $displayName, $domain,
			($status === self::STATUS_ACTIVE), $limit, $maxId, $minId
		);

		$actors = $this->cacheActorService->getCachedFromIds(array_column($rows, 'id'));

		$accounts = [];
		foreach ($rows as $row) {
			$actor = $actors[(string)$row['id']] ?? null;
			if ($actor === null) {
				// the row was read and its account was not: rather than send a
				// half-filled entity, leave it out — its nid still decides the
				// cursor, so the page does not stall on it
				continue;
			}

			$accounts[] = AdminAccount::fromPerson($actor, (string)($row['level'] ?? ''));
		}

		return [
			'accounts' => $accounts,
			'cursors' => array_map(static fn (array $row): int|string => \OCA\Social\Tools\Nid::fromStorage((string)$row['nid']), $rows),
		];
	}

	/**
	 * One account, by the numeric id this API emits, its ActivityPub id, or a
	 * handle. The ActivityPub id is the only handle left on an account whose
	 * cached actor a suspension purged, so it is needed to lift one.
	 *
	 * @throws ItemNotFoundException
	 */
	public function account(string $reference): AdminAccount {
		$reference = trim($reference);
		if ($reference === '') {
			throw new ItemNotFoundException('Record not found');
		}

		if (is_numeric($reference)) {
			$actors = $this->cacheActorService->getFromNids([(int)$reference]);
			if ($actors === []) {
				throw new ItemNotFoundException('Record not found');
			}

			return $this->withDecision($actors[0]);
		}

		if (str_starts_with($reference, 'http://') || str_starts_with($reference, 'https://')) {
			$cached = $this->cacheActorService->getCachedFromIds([$reference]);
			if (isset($cached[$reference])) {
				return $this->withDecision($cached[$reference]);
			}

			$level = $this->moderationService->levelOf($reference);
			if ($level === '') {
				throw new ItemNotFoundException('Record not found');
			}

			return AdminAccount::fromDecision(
				new Moderation($reference, $level), $this->isLocalActor($reference)
			);
		}

		try {
			// never fetched from the other server: a moderator looking an
			// account up must not be able to make this instance pull a profile
			// it has never seen
			return $this->withDecision(
				$this->cacheActorService->getFromAccount(ltrim($reference, '@'), false)
			);
		} catch (Exception $e) {
			throw new ItemNotFoundException('Record not found');
		}
	}

	/**
	 * Applies a moderator's decision. `silence` and `suspend` are
	 * `ModerationService`'s, the same calls the admin panel makes. `none` is
	 * Mastodon's: record a warning, tell the account, and leave whatever
	 * stands. `$text` is the comment on the decision, or the whole warning.
	 *
	 * @param string $type one of ACTION_NONE, ACTION_SILENCE, ACTION_SUSPEND
	 * @param int $reportId the report this came from, or 0
	 *
	 * @throws \InvalidArgumentException `sensitive` and `disable`, which this
	 *                                   app has no state for, and anything
	 *                                   that is not an action at all
	 */
	public function act(
		AdminAccount $account, string $type, string $text = '', int $reportId = 0,
	): AdminAccount {
		switch ($type) {
			case self::ACTION_NONE:
				$this->moderationService->warn($account->getActorId(), $text, $reportId);

				return $account;
			case self::ACTION_SILENCE:
			case self::ACTION_SUSPEND:
				$this->moderationService->decide($account->getActorId(), $type, $text, $reportId);

				return $account->setLevel($type);
			case 'sensitive':
				// the tier between doing nothing and silencing: every post this
				// account makes from now on is marked sensitive
				$this->moderationService->forceSensitive($account->getActorId(), true);

				return $account;
			case 'disable':
				throw new \InvalidArgumentException(
					'this instance has no "' . $type . '" state for an account: an account here'
					. ' is a Nextcloud account, and disabling one is the server\'s to do'
				);

			default:
				throw new \InvalidArgumentException('unknown action: ' . $type);
		}
	}

	/**
	 * Lifts a silence, and only a silence: a suspension has its own route, as
	 * on Mastodon.
	 */
	public function unsilence(AdminAccount $account): AdminAccount {
		if ($account->isSilenced()) {
			$this->moderationService->lift($account->getActorId());
			$account->setLevel('');
		}

		return $account;
	}

	/** Lifts a suspension, and only a suspension. */
	public function unsuspend(AdminAccount $account): AdminAccount {
		if ($account->isSuspended()) {
			$this->moderationService->lift($account->getActorId());
			$account->setLevel('');
		}

		return $account;
	}

	/**
	 * Files a report under a different category.
	 *
	 * @throws \InvalidArgumentException a category this app has no name for
	 * @throws ReportNotFoundException
	 */
	public function recategoriseReport(int $id, string $category): AdminReport {
		$category = trim($category);
		if (!in_array($category, Report::CATEGORIES, true)) {
			throw new \InvalidArgumentException(
				'category must be one of ' . implode(', ', Report::CATEGORIES)
			);
		}

		// read first, so an unknown id is a 404 rather than an update that
		// quietly matched nothing — and the entity that comes back is the one
		// this returns, with the new category on it, rather than a second read
		$report = $this->report($id);
		$this->reportsRequest->setCategory($id, $category);
		$report->getReport()->setCategory($category);

		return $report;
	}

	/** Lifts "everything this account posts is sensitive", and only that. */
	public function unsensitive(AdminAccount $account): AdminAccount {
		$this->moderationService->forceSensitive($account->getActorId(), false);

		return $account;
	}

	/**
	 * Deletes what an account posted here, leaving the account itself: the
	 * posts and cached actor go, and a local account's deletion is federated.
	 * This is what Mastodon's `DELETE /admin/accounts/{id}` means; the
	 * Nextcloud login is the server's.
	 */
	public function purge(AdminAccount $account): AdminAccount {
		$this->moderationService->purgeActor($account->getActorId());

		return $account;
	}

	/**
	 * A page of reports, newest first.
	 *
	 * @param bool|null $resolved Mastodon's `resolved`; null for both
	 * @param string $reporter an account reference, Mastodon's `account_id`
	 * @param string $target an account reference, Mastodon's `target_account_id`
	 *
	 * @return AdminReport[]
	 */
	public function reports(
		?bool $resolved = null,
		string $reporter = '',
		string $target = '',
		int $limit = self::LIMIT,
		int $maxId = 0,
		int|string $minId = 0,
	): array {
		$rows = $this->reportRows(
			$resolved,
			$this->actorIdOf($reporter),
			$this->actorIdOf($target),
			max(1, min(self::MAX_LIMIT, $limit)),
			$maxId,
			$minId
		);

		return $this->adminReports($rows);
	}

	/**
	 * @throws ReportNotFoundException
	 */
	public function report(int $id): AdminReport {
		$rows = $this->reportRows(null, '', '', 1, 0, 0, $id);
		if ($rows === []) {
			throw new ReportNotFoundException('report ' . $id . ' not found');
		}

		return $this->adminReports([$rows[0]])[0];
	}

	/**
	 * Marks a report handled, and records who handled it.
	 *
	 * The resolving itself is `ReportService`'s, so a report resolved from a
	 * client and one resolved from the admin panel are the same write.
	 *
	 * @throws ReportNotFoundException
	 */
	public function resolveReport(int $id, string $userId): AdminReport {
		$this->reportService->setResolved($id, true);
		$this->setActionTaken($id, $userId);

		return $this->report($id);
	}

	/**
	 * Puts a report back in the queue: unresolved, and with the record of who
	 * had acted on it cleared — it describes a decision that no longer stands.
	 *
	 * @throws ReportNotFoundException
	 */
	public function reopenReport(int $id): AdminReport {
		$this->reportService->setResolved($id, false);
		$this->setActionTaken($id, null);

		return $this->report($id);
	}

	/**
	 * @throws ReportNotFoundException
	 */
	public function assignReport(int $id, ?string $userId): AdminReport {
		$this->reportsRequest->getById($id); // throws when there is no such report
		$this->setAssignment($id, $userId);

		return $this->report($id);
	}

	/**
	 * The instance-wide access list, as domain blocks.
	 *
	 * @return AdminDomainBlock[]
	 *
	 * @throws \InvalidArgumentException the list is an allow list, so every
	 *                                   entry on it means the opposite of a
	 *                                   block
	 */
	/**
	 * Every domain this instance holds at arm's length: the deny list as
	 * `suspend`, the silenced list as `silence`.
	 *
	 * @return AdminDomainBlock[]
	 */
	public function domainBlocks(): array {
		$this->assertBlockList();

		$severities = [];
		foreach ($this->fediverseService->getListedAddresses() as $entry) {
			$domain = strtolower(trim((string)$entry));
			if ($domain !== '') {
				$severities[$domain] = AdminDomainBlock::SEVERITY;
			}
		}
		foreach ($this->fediverseService->getSilencedAddresses() as $entry) {
			$domain = strtolower(trim($entry));
			// a domain on both lists is suspended: the stronger tier is the
			// one that is actually in force
			if ($domain !== '' && !isset($severities[$domain])) {
				$severities[$domain] = AdminDomainBlock::SEVERITY_SILENCE;
			}
		}

		$recorded = ($severities === []) ? [] : $this->serverBlockRequest->all();
		$blocks = [];
		foreach ($severities as $domain => $severity) {
			$row = $recorded[FediverseService::normalizeHost($domain)] ?? null;
			$blocks[] = new AdminDomainBlock(
				$domain, $severity,
				$row['private_comment'] ?? null,
				$row['public_comment'] ?? null,
				$row['created'] ?? 0,
			);
		}

		return $blocks;
	}

	public function domainBlock(string $reference): AdminDomainBlock {
		foreach ($this->domainBlocks() as $block) {
			if ($block->isNamedBy($reference)) {
				return $block;
			}
		}

		throw new ItemNotFoundException('Record not found');
	}

	/**
	 * Blocks or silences a domain, with the reasons for it.
	 *
	 * A null reason leaves what is recorded alone, so a client retrying a
	 * block without them does not erase what a moderator wrote; '' clears it.
	 */
	public function blockDomain(
		string $domain,
		string $severity = AdminDomainBlock::SEVERITY,
		?string $privateComment = null,
		?string $publicComment = null,
	): AdminDomainBlock {
		$this->assertBlockList();
		$severity = $this->severity($severity);
		$privateComment = $this->comment($privateComment);
		$publicComment = $this->comment($publicComment);

		$domain = strtolower(trim($domain));
		if ($domain === '' || preg_match('/^[a-z0-9.:\[\]-]+$/', $domain) !== 1) {
			throw new \InvalidArgumentException('invalid domain');
		}

		$this->applySeverity($domain, $severity);
		$this->serverBlockRequest->save(FediverseService::normalizeHost($domain), $privateComment, $publicComment);

		return $this->domainBlock($domain);
	}

	/**
	 * Moves a block between the two tiers, and rewrites its reasons.
	 *
	 * A change of severity is a move from one list to the other; the same
	 * severity again, or none, leaves the tier as it is. A null reason is
	 * left as it is, '' clears it.
	 */
	public function updateDomainBlock(
		string $reference,
		string $severity,
		?string $privateComment = null,
		?string $publicComment = null,
	): AdminDomainBlock {
		$block = $this->domainBlock($reference);
		$severity = ($severity === '') ? $block->getSeverity() : $this->severity($severity);
		$privateComment = $this->comment($privateComment);
		$publicComment = $this->comment($publicComment);

		if ($severity !== $block->getSeverity()) {
			$this->applySeverity($block->getDomain(), $severity);
		}
		if ($privateComment !== null || $publicComment !== null) {
			$this->serverBlockRequest->save(
				FediverseService::normalizeHost($block->getDomain()), $privateComment, $publicComment
			);
		}

		return $this->domainBlock($block->getDomain());
	}

	/**
	 * Lifts a block at whichever tier it is. Its recorded reasons go with it,
	 * which `FediverseService` sees to once the domain is on neither list.
	 */
	public function unblockDomain(string $reference): AdminDomainBlock {
		$block = $this->domainBlock($reference);
		$this->fediverseService->removeAddress($block->getDomain());
		$this->fediverseService->unsilenceAddress($block->getDomain());

		return $block;
	}

	/**
	 * A reason as it is stored: trimmed, null left as null.
	 *
	 * @throws \InvalidArgumentException it is longer than a reason is let be
	 */
	private function comment(?string $comment): ?string {
		if ($comment === null) {
			return null;
		}

		$comment = trim($comment);
		if (mb_strlen($comment) > AdminDomainBlock::MAX_COMMENT) {
			throw new \InvalidArgumentException(
				'a reason is at most ' . AdminDomainBlock::MAX_COMMENT . ' characters'
			);
		}

		return $comment;
	}

	/**
	 * The severity as `suspend` or `silence`, or a refusal: Mastodon's `noop`
	 * has no list here.
	 */
	/**
	 * The instances this server talks to in allowlist mode (`access_type:
	 * none_but`), as Mastodon's `domain_allows`.
	 *
	 * @return array<int, array{id: string, domain: string, created_at: string}>
	 */
	public function domainAllows(): array {
		$this->assertAllowList();

		$allows = [];
		foreach ($this->fediverseService->getListedAddresses() as $domain) {
			$allows[] = $this->asDomainAllow($domain);
		}

		return $allows;
	}

	/**
	 * @return array{id: string, domain: string, created_at: string}
	 * @throws ItemNotFoundException
	 */
	public function domainAllow(string $reference): array {
		// through domainAllows(), which is where the mode is checked
		foreach ($this->domainAllows() as $allow) {
			if ($allow['id'] === $reference || $allow['domain'] === $reference) {
				return $allow;
			}
		}

		throw new ItemNotFoundException('no such allowed domain');
	}

	/**
	 * @return array{id: string, domain: string, created_at: string}
	 * @throws Exception
	 */
	public function allowDomain(string $domain): array {
		$this->assertAllowList();

		$domain = strtolower(trim($domain));
		if ($domain === '') {
			throw new \InvalidArgumentException('domain is required');
		}

		$this->fediverseService->addAddress($domain);

		return $this->asDomainAllow($domain);
	}

	/**
	 * @return array{id: string, domain: string, created_at: string}
	 * @throws ItemNotFoundException
	 */
	public function disallowDomain(string $reference): array {
		$allow = $this->domainAllow($reference);
		$this->fediverseService->removeAddress($allow['domain']);

		return $allow;
	}

	/**
	 * The id Mastodon's entity needs, derived from the domain as the access
	 * list has no ids; `domainAllow()` also takes the domain itself.
	 *
	 * @return array{id: string, domain: string, created_at: string}
	 */
	private function asDomainAllow(string $domain): array {
		return [
			'id' => substr(md5($domain), 0, 12),
			'domain' => $domain,
			// the list records no times; Mastodon's entity requires the key
			'created_at' => date('c', 0),
		];
	}

	public function assertSeverity(string $severity): void {
		$this->severity($severity);
	}

	private function severity(string $severity): string {
		$severity = strtolower(trim($severity));
		if ($severity === '') {
			return AdminDomainBlock::SEVERITY;
		}
		if (!in_array($severity, AdminDomainBlock::SEVERITIES, true)) {
			throw new \InvalidArgumentException(
				'this instance knows two tiers of domain block, suspend and silence; severity "' . $severity . '" cannot be applied'
			);
		}

		return $severity;
	}

	/**
	 * Puts a domain on the list its severity names, then takes it off the
	 * other, since a domain on neither list loses its recorded reasons.
	 */
	private function applySeverity(string $domain, string $severity): void {
		if ($severity === AdminDomainBlock::SEVERITY_SILENCE) {
			$this->fediverseService->silenceAddress($domain);
			$this->fediverseService->removeAddress($domain);

			return;
		}

		$this->fediverseService->addAddress($domain);
		$this->fediverseService->unsilenceAddress($domain);
	}

	/**
	 * Refuses every domain-block route in allow-list mode, where the shared
	 * list holds the allowed domains and would read as its opposite.
	 *
	 * @throws \InvalidArgumentException
	 */
	/**
	 * The mirror of `assertBlockList()`: in block-list mode the shared list
	 * holds refused domains, which must not be served as `domain_allows`.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function assertAllowList(): void {
		if ($this->fediverseService->getAccessType() !== $this->configService->accessTypeList['WHITELIST']) {
			throw new \InvalidArgumentException(
				'this instance federates by a block list, so its access list is not a list of allowed domains'
			);
		}
	}

	private function assertBlockList(): void {
		if ($this->fediverseService->getAccessType() === $this->configService->accessTypeList['WHITELIST']) {
			throw new \InvalidArgumentException(
				'this instance federates by an allow list, so its access list is not a list of blocked domains'
			);
		}
	}

	/**
	 * Accounts under one decision, from the decisions themselves.
	 *
	 * @return AdminAccount[]
	 */
	private function moderatedAccounts(
		string $level, ?bool $local, string $username, string $domain, int $limit,
	): array {
		$decisions = array_values(array_filter(
			$this->moderationService->decisions(),
			static fn (Moderation $decision): bool => $decision->getLevel() === $level
		));

		$actors = $this->cacheActorService->getCachedFromIds(
			array_map(static fn (Moderation $decision): string => $decision->getActorId(), $decisions)
		);

		$accounts = [];
		foreach ($decisions as $decision) {
			$actor = $actors[$decision->getActorId()] ?? null;
			$account = ($actor === null)
				? AdminAccount::fromDecision($decision, $this->isLocalActor($decision->getActorId()))
				: AdminAccount::fromPerson($actor, $level);

			if (!$this->matches($account, $local, $username, $domain)) {
				continue;
			}

			$accounts[] = $account;
			if (count($accounts) >= $limit) {
				break;
			}
		}

		return $accounts;
	}

	/**
	 * The filters of the account list, applied to an entity rather than in the
	 * statement — which is what the decisions branch has instead of a WHERE.
	 */
	private function matches(AdminAccount $account, ?bool $local, string $username, string $domain): bool {
		if ($local !== null && $account->isLocal() !== $local) {
			return false;
		}

		$username = strtolower(trim($username));
		if ($username !== '' && !str_contains(strtolower($account->getUsername()), $username)) {
			return false;
		}

		$domain = strtolower(trim($domain));

		return ($domain === '') || ($account->getDomain() === $domain);
	}

	/** The account, under whatever this instance has decided about it. */
	private function withDecision(Person $actor): AdminAccount {
		return AdminAccount::fromPerson($actor, $this->moderationService->levelOf($actor->getId()));
	}

	/**
	 * Whether an actor id is one of this instance's own.
	 *
	 * Read off the id because this is asked about accounts whose cached copy
	 * is gone, and the copy is where the `local` flag was.
	 */
	private function isLocalActor(string $actorId): bool {
		try {
			$host = (string)parse_url($actorId, PHP_URL_HOST);

			return ($host !== '') && $this->fediverseService->isLocal(strtolower($host));
		} catch (Exception $e) {
			return false;
		}
	}

	/**
	 * The actor id behind an account reference, '' when none was asked for.
	 * An unresolvable account becomes an id nothing equals, so the filter
	 * yields nothing instead of falling away.
	 */
	private function actorIdOf(string $reference): string {
		if (trim($reference) === '') {
			return '';
		}

		try {
			return $this->account($reference)->getActorId();
		} catch (Exception $e) {
			return "\0no such account";
		}
	}

	/**
	 * The reports of a page as the entities a moderation client reads, with
	 * every account resolved in one query. The moderators stay on the row,
	 * not on the `Report` model, which the reporter is shown.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 *
	 * @return AdminReport[]
	 */
	private function adminReports(array $rows): array {
		$reports = array_map([$this, 'reportFromRow'], $rows);

		$ids = [];
		foreach ($reports as $report) {
			$ids[] = $report->getActorId();
			$ids[] = $report->getAccountId();
		}

		$actors = $this->cacheActorService->getCachedFromIds($ids);

		$admin = [];
		foreach ($reports as $index => $report) {
			$row = $rows[$index];

			$entity = new AdminReport($report);
			$entity->setAccount($this->reportAccount($actors, $report->getActorId()))
				->setTargetAccount($this->reportAccount($actors, $report->getAccountId()))
				->setAssignedAccount($this->moderator((string)($row['assigned_to'] ?? '')))
				->setActionTakenByAccount($this->moderator((string)($row['action_taken_by'] ?? '')))
				->setActionTakenAt(DbTime::toTimestamp($row['action_taken_at'] ?? ''))
				->setStatuses($this->reportedStatuses($report));

			$admin[] = $entity;
		}

		return $admin;
	}

	/**
	 * @param Person[] $actors
	 */
	private function reportAccount(array $actors, string $actorId): ?AdminAccount {
		if ($actorId === '') {
			return null;
		}

		$actor = $actors[$actorId] ?? null;
		if ($actor === null) {
			// a report outlives the cached actor it is about, so the entity is
			// built from the id the report was filed against
			return AdminAccount::fromDecision(
				new Moderation($actorId, $this->moderationService->levelOf($actorId)),
				$this->isLocalActor($actorId)
			);
		}

		return AdminAccount::fromPerson($actor, $this->moderationService->levelOf($actorId));
	}

	/**
	 * The Social account of a moderator, by their Nextcloud user id.
	 *
	 * Null when the administrator has no Social account, which is an ordinary
	 * state: moderating this app does not require using it.
	 */
	private function moderator(string $userId): ?AdminAccount {
		if ($userId === '') {
			return null;
		}

		try {
			return AdminAccount::fromPerson($this->accountService->getActorFromUserId($userId));
		} catch (Exception $e) {
			return null;
		}
	}

	/**
	 * The reported posts, as the Status entities Mastodon carries in the
	 * report; posts no longer here (usually taken down) are left out.
	 *
	 * @return array<int, mixed>
	 */
	private function reportedStatuses(Report $report): array {
		$statuses = [];
		foreach ($report->getStatusIds() as $statusId) {
			try {
				$statuses[] = $this->streamRequest->getStreamById($statusId, false, ACore::FORMAT_LOCAL);
			} catch (Exception $e) {
				$this->logger->debug('[AdminApiService] a reported status is no longer here', [
					'status' => $statusId,
					'report' => $report->getId(),
				]);
			}
		}

		return $statuses;
	}

	/** A report row as the model the rest of the app passes around. */
	private function reportFromRow(array $row): Report {
		$statusIds = json_decode((string)($row['status_ids'] ?? '[]'), true);

		$report = new Report();
		$report->setId((int)($row['id'] ?? 0))
			->setActorId((string)($row['actor_id'] ?? ''))
			->setAccountId((string)($row['account_id'] ?? ''))
			->setStatusIds(is_array($statusIds) ? $statusIds : [])
			->setComment((string)($row['comment'] ?? ''))
			->setCategory((string)($row['category'] ?? Report::CATEGORY_OTHER))
			->setRules(Report::decodeRules(isset($row['rules']) ? (string)$row['rules'] : null))
			->setLocal((int)($row['local'] ?? 1) === 1)
			->setResolved((int)($row['resolved'] ?? 0) === 1)
			->setCreation(DbTime::toTimestamp($row['creation'] ?? ''));

		return $report;
	}

	/**
	 * A page of accounts with the decision standing against each, by LEFT
	 * JOIN so `status=active` filters within the page and pages stay full.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function accountRows(
		?bool $local,
		string $username,
		string $displayName,
		string $domain,
		bool $undecided,
		int $limit,
		int $maxId,
		int|string $minId,
	): array {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->select('ca.id', 'ca.nid', 'ca.local', 'ca.account', 'ca.preferred_username')
			->selectAlias('m.level', 'level')
			->from(CoreRequestBuilder::TABLE_CACHE_ACTORS, 'ca')
			->leftJoin(
				'ca', CoreRequestBuilder::TABLE_MODERATION, 'm',
				$qb->expr()->eq('ca.id_prim', 'm.actor_id_prim')
			)
			->orderBy('ca.nid', 'desc')
			->setMaxResults($limit);

		if ($local !== null) {
			$qb->andWhere($qb->expr()->eq(
				'ca.local', $qb->createNamedParameter($local ? 1 : 0, IQueryBuilder::PARAM_INT)
			));
		}

		if ($undecided) {
			$qb->andWhere($qb->expr()->isNull('m.level'));
		}

		if (trim($username) !== '') {
			$qb->andWhere($qb->expr()->like(
				$qb->func()->lower('ca.preferred_username'),
				$qb->createNamedParameter('%' . $this->like($username) . '%')
			));
		}

		if (trim($displayName) !== '') {
			$qb->andWhere($qb->expr()->like(
				$qb->func()->lower('ca.name'),
				$qb->createNamedParameter('%' . $this->like($displayName) . '%')
			));
		}

		if (trim($domain) !== '') {
			// the handle of a remote account is user@host, so the domain is
			// what follows the last @ — a local account has no host in it and
			// is left out of a by_domain page, which is Mastodon's answer too
			$qb->andWhere($qb->expr()->like(
				$qb->func()->lower('ca.account'),
				$qb->createNamedParameter('%@' . $this->like($domain))
			));
		}

		if ($maxId > 0) {
			$qb->andWhere($qb->expr()->lt(
				'ca.nid', $qb->createNamedParameter($maxId)
			));
		}

		if ($minId > 0) {
			$qb->andWhere($qb->expr()->gt(
				'ca.nid', $qb->createNamedParameter($minId)
			));
		}

		$rows = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$rows[] = $row;
		}
		$cursor->closeCursor();

		return $rows;
	}

	/**
	 * A page of reports, newest first, read here because `ReportsRequest`
	 * selects neither the moderator columns nor these filters; writes still
	 * go through it.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function reportRows(
		?bool $resolved,
		string $reporter,
		string $target,
		int $limit,
		int $maxId,
		int|string $minId,
		int $id = 0,
	): array {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->select(
			'id', 'actor_id', 'account_id', 'status_ids', 'comment', 'category', 'rules', 'local', 'resolved',
			'creation', 'assigned_to', 'action_taken_by', 'action_taken_at'
		)
			->from(CoreRequestBuilder::TABLE_REPORTS)
			->orderBy('id', 'desc')
			->setMaxResults($limit);

		if ($id > 0) {
			$qb->andWhere($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		}

		if ($resolved !== null) {
			$qb->andWhere($qb->expr()->eq(
				'resolved', $qb->createNamedParameter($resolved ? 1 : 0, IQueryBuilder::PARAM_INT)
			));
		}

		if ($reporter !== '') {
			$qb->andWhere($qb->expr()->eq('actor_id', $qb->createNamedParameter($reporter)));
		}

		if ($target !== '') {
			$qb->andWhere($qb->expr()->eq('account_id', $qb->createNamedParameter($target)));
		}

		if ($maxId > 0) {
			$qb->andWhere($qb->expr()->lt('id', $qb->createNamedParameter($maxId, IQueryBuilder::PARAM_INT)));
		}

		if ($minId > 0) {
			$qb->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($minId, IQueryBuilder::PARAM_INT)));
		}

		$rows = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$rows[] = $row;
		}
		$cursor->closeCursor();

		return $rows;
	}

	/** Who is dealing with a report; null puts it back in the queue. */
	protected function setAssignment(int $id, ?string $userId): void {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->update(CoreRequestBuilder::TABLE_REPORTS)
			->set('assigned_to', $qb->createNamedParameter($userId))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	/** Who acted on a report and when; null clears both. */
	protected function setActionTaken(int $id, ?string $userId): void {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->update(CoreRequestBuilder::TABLE_REPORTS)
			->set('action_taken_by', $qb->createNamedParameter($userId))
			->set('action_taken_at', $qb->createNamedParameter(
				($userId === null) ? null : new \DateTime('now'), IQueryBuilder::PARAM_DATE
			))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	/** A literal inside a LIKE pattern, lowercased to match `LOWER(column)`. */
	private function like(string $value): string {
		return $this->dbConnection->escapeLikeParameter(strtolower(trim($value)));
	}
}
