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
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\ReportNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\AdminAccount;
use OCA\Social\Model\Client\AdminDomainBlock;
use OCA\Social\Model\Client\AdminReport;
use OCA\Social\Model\Moderation;
use OCA\Social\Model\Report;
use OCA\Social\Settings\AdminSection;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Settings\IManager as ISettingsManager;
use Psr\Log\LoggerInterface;

/**
 * What `/api/v1/admin/*` reads and decides, over the moderation this app
 * already had.
 *
 * Nothing here is a second implementation of moderation: a decision is taken
 * by `ModerationService`, a report is resolved through `ReportService`, and
 * the instance access list is `FediverseService`'s. What this service adds is
 * the reading a moderation client does — which accounts are there, under which
 * decision, and which reports are open — because none of it existed as a query
 * anybody could ask.
 *
 * ### Why the SQL is in here
 *
 * The three reads below are the only ones in the app that ask their questions
 * (every account under a decision; a page of reports with who is handling it),
 * and they are written against `IDBConnection` in `protected` methods so the
 * policy above them can be tested without a database — the standalone suite
 * cannot even mock `IQueryBuilder`, because DBAL is not loadable in it, so a
 * test doubles these methods instead.
 *
 * ### Who counts as a moderator
 *
 * Whoever may open the Social section of the admin settings, asked of the
 * Nextcloud user behind the request: a Nextcloud administrator, or a member
 * of a group the administrator has handed that section to under
 * Administration privileges. Nextcloud's own delegation answers it
 * (`IManager::getAllowedAdminSettings()`), so this API and the panel cannot
 * disagree about who may act, and moderating no longer means administering
 * the whole server.
 *
 * An OAuth scope is not an answer to the question: this app's OAuth
 * registration accepts any scope string a client asks for, so `admin:write`
 * on a token says only that a client asked for it, never that the user behind
 * it may moderate anything.
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
	) {
	}

	/**
	 * Whether the Nextcloud user behind the request may use any of this.
	 *
	 * Asked of the user id, never of the token: see the class docblock.
	 *
	 * A Nextcloud administrator always may. So may whoever the administrator
	 * has delegated the Social settings section to, which is the whole of the
	 * moderator role — there is no second list to keep in step, and an
	 * administrator stays a moderator because they can delegate the section
	 * to themselves anyway, and pretending otherwise would only hide who
	 * holds what.
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
	 * Two of Mastodon's filters answer nothing here and say so by answering
	 * with no accounts rather than with all of them: `pending` and `disabled`
	 * are states this app does not have (there is no registration to approve
	 * and no login to disable from a fediverse account), and a filter that was
	 * ignored would have shown a moderator the whole instance under the
	 * heading of accounts awaiting approval. `email` and `ip` are the same
	 * case, and are handled by the caller for the same reason.
	 *
	 * `silenced` and `suspended` are read from the decisions rather than from
	 * the accounts: suspending an account deletes its cached actor, so an
	 * account under the decision a moderator is most likely to be looking for
	 * is exactly the one the account table no longer holds. Those two are
	 * therefore unpaged beyond `limit` — the decisions of an instance are a
	 * handful of rows — and come back with no cursors at all rather than with
	 * ones that would page something else.
	 *
	 * The cursors are the nids of the *rows*, not of the entities: an account
	 * whose cached actor cannot be read is left out of the page, and a cursor
	 * that skipped it would send the client back to the same page for ever.
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
	 * handle.
	 *
	 * The ActivityPub id is accepted because it is the only handle left on an
	 * account whose cached actor a suspension purged — without it a suspension
	 * could be applied over this API and never lifted over it.
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
	 * Applies a moderator's decision.
	 *
	 * `silence` and `suspend` are `ModerationService`'s, unchanged — the
	 * suspension that purges the account's posts and relationships here is the
	 * same one the admin panel applies, because it is the same call.
	 *
	 * `none` is Mastodon's own: record a warning and take no action. It used
	 * to lift whatever stood instead, because a warning is a strike in a
	 * history this app did not keep — now that it does, `none` means what a
	 * client sending it means, the account is told, and lifting is what the
	 * `unsilence` and `unsuspend` routes are for. Whatever stands is left
	 * standing, as it is on Mastodon.
	 *
	 * `$text` is what the moderator wrote: the comment on a decision that
	 * stands, and the whole of a warning.
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
				// the tier between doing nothing and silencing: every post
				// this account makes from now on is marked sensitive, and it
				// stays in the timelines. It was refused by name here until
				// the app had it, and the refusal outlived the gap.
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
	 * Lifts a silence, and only a silence.
	 *
	 * A suspended account is left suspended, as it is on Mastodon: the two
	 * decisions are lifted by their own routes, so a client that means to
	 * unsilence cannot lift a suspension by accident.
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
	 * Deletes what an account posted here, leaving the account itself.
	 *
	 * The destructive half of a suspension without the refusal that goes with
	 * one: the posts go, the cached actor goes, and a local account's deletion
	 * is federated. Mastodon's `DELETE /admin/accounts/{id}` means exactly
	 * this — it removes the data, not the login — and here it could not mean
	 * anything else, because an account on this server is a Nextcloud account
	 * that the server owns.
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
	 * Every domain this instance holds at arm's length, at whichever tier.
	 *
	 * Two lists become one answer: the deny list as `suspend`, the silenced
	 * list as `silence`. A Mastodon admin client showed only the first and
	 * called every entry a suspension, while the silence tier was there in
	 * the web UI and in `occ social:fediverse` all along — a feature present
	 * in one door and absent from the other.
	 *
	 * @return AdminDomainBlock[]
	 */
	public function domainBlocks(): array {
		$this->assertBlockList();

		$blocks = [];
		foreach ($this->fediverseService->getListedAddresses() as $entry) {
			$domain = strtolower(trim((string)$entry));
			if ($domain !== '') {
				$blocks[$domain] = new AdminDomainBlock($domain, AdminDomainBlock::SEVERITY);
			}
		}
		foreach ($this->fediverseService->getSilencedAddresses() as $entry) {
			$domain = strtolower(trim($entry));
			// a domain on both lists is suspended: the stronger tier is the
			// one that is actually in force
			if ($domain !== '' && !isset($blocks[$domain])) {
				$blocks[$domain] = new AdminDomainBlock($domain, AdminDomainBlock::SEVERITY_SILENCE);
			}
		}

		return array_values($blocks);
	}

	public function domainBlock(string $reference): AdminDomainBlock {
		foreach ($this->domainBlocks() as $block) {
			if ($block->isNamedBy($reference)) {
				return $block;
			}
		}

		throw new ItemNotFoundException('Record not found');
	}

	public function blockDomain(string $domain, string $severity = AdminDomainBlock::SEVERITY): AdminDomainBlock {
		$this->assertBlockList();
		$severity = $this->severity($severity);

		$domain = strtolower(trim($domain));
		if ($domain === '' || preg_match('/^[a-z0-9.:\[\]-]+$/', $domain) !== 1) {
			throw new \InvalidArgumentException('invalid domain');
		}

		$this->applySeverity($domain, $severity);

		return new AdminDomainBlock($domain, $severity);
	}

	/**
	 * Moves a block between the two tiers.
	 *
	 * A change of severity is a move from one list to the other; the same
	 * severity again is a no-op, and the block comes back as it stands.
	 */
	public function updateDomainBlock(string $reference, string $severity): AdminDomainBlock {
		$block = $this->domainBlock($reference);
		$severity = ($severity === '') ? $block->getSeverity() : $this->severity($severity);
		if ($severity === $block->getSeverity()) {
			return $block;
		}

		$this->applySeverity($block->getDomain(), $severity);

		return new AdminDomainBlock($block->getDomain(), $severity);
	}

	public function unblockDomain(string $reference): AdminDomainBlock {
		$block = $this->domainBlock($reference);
		$this->fediverseService->removeAddress($block->getDomain());
		$this->fediverseService->unsilenceAddress($block->getDomain());

		return $block;
	}

	/**
	 * The severity as one of the two this instance has, or a refusal.
	 *
	 * Mastodon knows a third, `noop`, which records a domain without doing
	 * anything to it; there is no such list here, and a 200 that had quietly
	 * applied something else would tell the client the domain was under a
	 * block it is not.
	 */
	/**
	 * The instances this server will talk to, when it is on an allowlist.
	 *
	 * Mastodon's `domain_allows` is the other half of `domain_blocks`, and it
	 * only means anything in "allowlist federation" — which this app has had
	 * all along as `access_type: none_but`, with no API over it. An admin
	 * client could see the deny list and not the allow list, so on an
	 * allowlisted instance it showed an empty screen and no way to tell why.
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
	 * The id Mastodon's entity needs, derived rather than stored.
	 *
	 * The access list is a config array of domains with no ids of its own, and
	 * giving it a table so an admin client could address a row by number would
	 * be storing something for the client's benefit alone. The hash is stable,
	 * which is all an id has to be, and `domainAllow()` takes the domain
	 * itself as well — so a client that read the list can act on it either way.
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
	 * Puts a domain on the list its severity names and takes it off the other.
	 */
	private function applySeverity(string $domain, string $severity): void {
		if ($severity === AdminDomainBlock::SEVERITY_SILENCE) {
			$this->fediverseService->removeAddress($domain);
			$this->fediverseService->silenceAddress($domain);

			return;
		}

		$this->fediverseService->unsilenceAddress($domain);
		$this->fediverseService->addAddress($domain);
	}

	/**
	 * Refuses every domain-block route while the instance federates by an
	 * allow list.
	 *
	 * The same app value holds both lists, and in allow-list mode its entries
	 * are the only domains this instance talks to. Served as domain blocks
	 * they would read as their own opposite, and a client that then "blocked"
	 * a domain would have added it to the list of the allowed — so the routes
	 * refuse rather than answer something a moderator would act on.
	 *
	 * @throws \InvalidArgumentException
	 */
	/**
	 * The mirror of `assertBlockList()`, and it matters for the same reason.
	 *
	 * One app value holds both lists and only the mode says which it is. In
	 * block-list mode its entries are the domains this instance **refuses** —
	 * served as `domain_allows` they would read as their own opposite, and a
	 * moderator reading "allowed: evil.example" would be reading the exact
	 * inverse of the truth. Worse, a client that then removed an entry to
	 * "disallow" it would have unblocked it.
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
	 * The actor id behind an account reference, and '' when nothing was asked
	 * for.
	 *
	 * An account that cannot be resolved becomes an id nothing can equal,
	 * rather than no filter at all: "the reports about an account this
	 * instance has never heard of" is an empty list, and answering it with
	 * every report on the instance would be the filter silently falling off.
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
	 * every account of the page resolved in one query.
	 *
	 * The two moderators stay on the row rather than on the `Report` model:
	 * that model is the reporter's entity, serialised as Mastodon's `Report`
	 * over `POST /api/v1/reports`, and who is handling a complaint is not
	 * something the person who filed it is shown.
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
				->setActionTakenAt((int)strtotime((string)($row['action_taken_at'] ?? '')))
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
			// a report outlives the account it is about — a suspension purges
			// the cached actor and keeps the report — so the entity is built
			// from the id the report was filed against, which is what a
			// moderator acts on
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
	 * report.
	 *
	 * A post that is no longer here is left out rather than sent as an id with
	 * nothing behind it: the commonest reason for one to be missing is that a
	 * moderator has already taken it down.
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
			->setLocal((int)($row['local'] ?? 1) === 1)
			->setResolved((int)($row['resolved'] ?? 0) === 1)
			->setCreation((int)strtotime((string)($row['creation'] ?? '')));

		return $report;
	}

	/**
	 * A page of accounts, with the decision standing against each.
	 *
	 * A LEFT JOIN rather than a second query filtered in PHP: `status=active`
	 * has to be a predicate of the page, or a page of forty accounts could
	 * come back with three in it and the client would read that as the end of
	 * the list.
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
	 * A page of reports, newest first.
	 *
	 * Read here rather than through `ReportsRequest` because the moderator
	 * columns are not in that builder's select and because none of these
	 * filters exist there; the writes still go through it.
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
			'id', 'actor_id', 'account_id', 'status_ids', 'comment', 'category', 'local', 'resolved',
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
