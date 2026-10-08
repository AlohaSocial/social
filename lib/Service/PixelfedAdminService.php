<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use InvalidArgumentException;
use OCA\Social\Db\InstanceStatsRequest;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\ModerationNotAllowedException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\AdminAccount;
use OCA\Social\Model\Client\AdminDomainBlock;
use OCA\Social\Model\Client\AdminReport;
use OCA\Social\Model\Moderation;

/**
 * The administration screens of Pixelfed's app, answered from what this
 * instance actually has.
 *
 * Pixelfed's admin routes are its own — not Mastodon's — and its app shows
 * them to anybody the server calls an administrator. They are reshapes of
 * the moderation this instance already does: the account browser, the
 * reports queue, the instances it federates with and its two tiers of
 * domain block. What has no equivalent here is answered as absent rather
 * than invented: there is no per-account "unlisted" or "content warning"
 * flag, so those lists are empty and those actions a 422 that says so. The
 * autospam screen is not one of them any more — it draws this instance's own
 * review queue.
 *
 * Everything here goes through `AdminApiService`, which is where who may
 * moderate is decided and where every moderation decision is recorded, so a
 * takedown from the Pixelfed app is the same takedown as one from the
 * Mastodon API or the settings page.
 */
class PixelfedAdminService {
	/** How many accounts, reports or instances one screen is handed. */
	public const PAGE = 50;

	/** Pixelfed's per-user moderation flags, none of which this instance has. */
	/** What is left after `unlisted` and `cw`, which this instance does have. */
	private const USER_FLAGS = ['no_autolink'];

	public function __construct(
		private AdminApiService $adminApiService,
		private FediverseService $fediverseService,
		private InstanceService $instanceService,
		private InstanceStatsRequest $instanceStatsRequest,
		private ConfigService $configService,
		private PostReviewService $postReviewService,
		private AccountService $accountService,
		private ModerationService $moderationService,
	) {
	}

	/**
	 * The four numbers on the app's admin home.
	 *
	 * @return array<string, mixed>
	 */
	public function stats(): array {
		$stats = $this->instanceService->getLocal()->getStats();

		return [
			'cached_at' => gmdate('Y-m-d\TH:i:s') . '.000Z',
			'users_count' => (int)($stats['user_count'] ?? 0),
			'posts_count' => (int)($stats['status_count'] ?? 0),
			'instances_count' => (int)($stats['domain_count'] ?? 0),
			'autospam_count' => $this->postReviewService->countPending(),
		];
	}

	/**
	 * The switches the app's settings screen draws, each with its real state.
	 *
	 * None of them can be flipped from here — see updateConfig() — but each
	 * is answered truthfully, so the screen describes this instance rather
	 * than a Pixelfed one.
	 *
	 * @return list<array{name: string, description: string, key: string, state: bool}>
	 */
	public function config(): array {
		$whitelist = $this->configService->accessTypeList['WHITELIST'] ?? 'none_but';

		return [
			[
				'name' => 'ActivityPub Federation',
				'description' => 'This instance federates with every other, except the instances on its deny list — or only with the instances on its allow list.',
				'key' => 'federation.activitypub.enabled',
				'state' => $this->fediverseService->getAccessType() !== $whitelist
					|| $this->fediverseService->getListedAddresses() !== [],
			],
			[
				'name' => 'Open Registration',
				'description' => 'Accounts are Nextcloud users, created by the server; this app has no sign-up of its own.',
				'key' => 'pixelfed.open_registration',
				'state' => false,
			],
			[
				'name' => 'Stories',
				'description' => 'A picture that stops existing after a day, for followers only.',
				'key' => 'instance.stories.enabled',
				'state' => true,
			],
			[
				'name' => 'Require Email Verification',
				'description' => 'Belongs to the Nextcloud server, which owns the accounts.',
				'key' => 'pixelfed.enforce_email_verification',
				'state' => false,
			],
			[
				'name' => 'AutoSpam Detection',
				'description' => 'A post that trips one of a small set of rules, and the first post of a new account, wait for a moderator instead of going out.',
				'key' => 'pixelfed.bouncer.enabled',
				'state' => $this->postReviewService->autospam() || $this->postReviewService->reviewsFirstPost(),
			],
		];
	}

	/**
	 * Every one of these is configured in the Nextcloud administration
	 * settings or is not this app's to configure, so the app is told where
	 * to go rather than handed a 200 that changed nothing.
	 *
	 * @throws InvalidArgumentException always
	 */
	public function updateConfig(string $key): never {
		throw new InvalidArgumentException(
			'"' . $key . '" is configured in Administration settings → Social, not from here'
		);
	}

	/**
	 * The local accounts, as the app's user browser lists them.
	 *
	 * @return array{data: list<array<string, mixed>>, links: array<string, mixed>, meta: array<string, mixed>}
	 */
	public function users(string $q = '', string $sort = 'desc'): array {
		$page = $this->adminApiService->accountPage(true, trim($q), '', '', '', self::PAGE, 0, 0);
		$accounts = $page['accounts'];
		usort($accounts, static fn (AdminAccount $a, AdminAccount $b): int => $a->getCreation() <=> $b->getCreation());
		if (strtolower($sort) !== 'asc') {
			$accounts = array_reverse($accounts);
		}

		return [
			'data' => array_map(fn (AdminAccount $account): array => $this->userRow($account), $accounts),
			'links' => ['first' => null, 'last' => null, 'prev' => null, 'next' => null],
			'meta' => ['current_page' => 1, 'per_page' => self::PAGE, 'total' => count($accounts)],
		];
	}

	/**
	 * One account with what the app's user screen shows about it.
	 *
	 * @return array{data: array<string, mixed>, meta: array<string, mixed>}
	 * @throws ItemNotFoundException
	 */
	public function user(string $reference): array {
		$account = $this->adminApiService->account($reference);
		$person = $account->getAccount();
		$person?->setExportFormat(ACore::FORMAT_LOCAL);

		return [
			'data' => $this->userRow($account),
			'meta' => [
				'cached_at' => gmdate('Y-m-d\TH:i:s') . '.000Z',
				'account' => $person,
				// nobody here counts direct messages sent, and a remote report
				// is a report like any other
				'dms_sent' => 0,
				'report_count' => count($this->adminApiService->reports(null, '', $account->getActorId(), AdminApiService::MAX_LIMIT)),
				'remote_report_count' => 0,
				// the two this instance has, answered truthfully, and the one it
				// does not
				'moderation' => [
					'unlisted' => $this->moderationService->levelOf($account->getActorId()) === Moderation::SILENCE,
					'cw' => in_array($account->getActorId(), $this->moderationService->forcedSensitive(), true),
					'no_autolink' => false,
				],
			],
		];
	}

	/**
	 * The app's per-user actions, of which this instance has one.
	 *
	 * `delete` is the app's word for removing an account; what this instance
	 * does with a local account it removes is suspend it, which takes its
	 * posts down here and sends the same `Delete` to every peer that the
	 * account's own deletion would. The flags — `unlisted`, `cw`,
	 * `no_autolink` — are states an account does not have here, and
	 * `verify_email` and `refresh_stats` are the server's.
	 *
	 * `$callerId` is the moderator, held to `AdminApiService::assertMayActOn()`
	 * before anything is applied.
	 *
	 * @return array<string, mixed>
	 * @throws InvalidArgumentException
	 * @throws ItemNotFoundException
	 * @throws ModerationNotAllowedException
	 */
	public function userAction(string $reference, string $action, string $callerId): array {
		$action = strtolower(trim($action));
		if ($action === 'delete') {
			$account = $this->actionable($reference, $callerId);
			$this->adminApiService->act($account, AdminApiService::ACTION_SUSPEND, 'removed from the Pixelfed admin app');

			return ['status' => 200, 'msg' => 'deleted'];
		}

		// Pixelfed's words for two things this instance does have. `unlisted`
		// is the silence tier — an account out of the public timelines and
		// readable by whoever deliberately follows it — and `cw` is marking
		// everything it posts sensitive. Answered as a 422 until now, which
		// was true of the words and not of the instance.
		if ($action === 'unlisted' || $action === 'unlist') {
			$account = $this->actionable($reference, $callerId);
			$this->adminApiService->act(
				$account, AdminApiService::ACTION_SILENCE, 'unlisted from the Pixelfed admin app'
			);

			return ['status' => 200, 'msg' => 'unlisted'];
		}

		if ($action === 'cw') {
			$account = $this->actionable($reference, $callerId);
			$this->moderationService->forceSensitive($account->getActorId(), true);

			return ['status' => 200, 'msg' => 'cw'];
		}

		if (in_array($action, self::USER_FLAGS, true)) {
			throw new InvalidArgumentException('an account on this instance has no "' . $action . '" state');
		}

		throw new InvalidArgumentException('"' . $action . '" belongs to the Nextcloud server, which owns the accounts');
	}

	/**
	 * @throws ItemNotFoundException
	 * @throws ModerationNotAllowedException
	 */
	private function actionable(string $reference, string $callerId): AdminAccount {
		$account = $this->adminApiService->account($reference);
		$this->adminApiService->assertMayActOn($account->getActorId(), $callerId);

		return $account;
	}

	/**
	 * The open reports, as the app's moderation queue draws them.
	 *
	 * @return array{data: list<array<string, mixed>>}
	 */
	public function modReports(): array {
		$rows = [];
		foreach ($this->adminApiService->reports(false, '', '', self::PAGE) as $report) {
			$rows[] = $this->reportRow($report);
		}

		return ['data' => $rows];
	}

	/**
	 * `ignore` closes a report; the other two apply a per-post flag this
	 * instance does not have.
	 *
	 * @return array{success: true}
	 * @throws InvalidArgumentException
	 */
	public function handleModReport(int $id, string $action, string $moderatorId): array {
		$action = strtolower(trim($action));
		if ($action === 'ignore') {
			$this->adminApiService->resolveReport($id, $moderatorId);

			return ['success' => true];
		}

		if (in_array($action, ['cw', 'unlist'], true)) {
			throw new InvalidArgumentException('a post on this instance has no "' . $action . '" state; take it down or leave it');
		}

		throw new InvalidArgumentException('unknown action: ' . $action);
	}

	/**
	 * The posts waiting for a moderator, as the app's autospam screen draws
	 * them.
	 *
	 * Pixelfed's screen is a list of posts its bouncer caught, with "not spam"
	 * and "delete" against each. That is the same queue this instance keeps —
	 * a first post, or one that tripped a rule — so it is answered from it
	 * rather than left empty. What differs is the vocabulary: Pixelfed scores,
	 * this names the rule, and the rule is what the row says.
	 *
	 * @return array{data: list<array<string, mixed>>}
	 */
	public function autospam(): array {
		$rows = [];
		foreach ($this->postReviewService->pending(self::PAGE) as $held) {
			$rows[] = [
				'id' => (string)$held->getId(),
				'status_id' => (string)$held->getId(),
				'account_id' => $held->getActorId(),
				'username' => $held->getHandle(),
				'content' => $held->paramText(),
				'is_nsfw' => $held->paramBool('sensitive'),
				'scope' => $held->paramString('visibility'),
				// Pixelfed shows a number here; a rule is what a moderator can
				// act on, so the rule is what is sent, in both forms
				'reason' => $held->getReason(),
				'reason_text' => $this->postReviewService->reasonText($held->getReason()),
				'created_at' => gmdate('Y-m-d\TH:i:s', $held->getCreation()) . '.000Z',
			];
		}

		return ['data' => $rows];
	}

	/**
	 * The two buttons on that screen.
	 *
	 * `approve` (Pixelfed calls it "not spam") publishes the post; `delete`
	 * refuses it, which tells its author and is recorded against them. A held
	 * post that is neither is left waiting, which is also what happens when
	 * nobody presses anything.
	 *
	 * @return array{success: true}
	 * @throws InvalidArgumentException
	 * @throws ItemNotFoundException
	 */
	public function handleAutospam(int $id, string $action): array {
		$action = strtolower(trim($action));

		if (in_array($action, ['approve', 'not_spam', 'notspam'], true)) {
			$held = $this->postReviewService->heldPost($id);
			$this->postReviewService->approve($id, $this->accountService->getFromId($held->getActorId()));

			return ['success' => true];
		}

		if (in_array($action, ['delete', 'spam'], true)) {
			$this->postReviewService->reject($id);

			return ['success' => true];
		}

		throw new InvalidArgumentException('unknown action: ' . $action);
	}

	/**
	 * The instances this one has heard of, with what it has decided about
	 * each: Pixelfed's `unlisted` is this app's silence, its `banned` the
	 * deny list. `auto_cw` has no equivalent and is always off.
	 *
	 * @return array{data: list<array<string, mixed>>}
	 */
	public function instances(string $q = '', string $sort = 'desc', string $sortBy = 'id', string $filter = 'all'): array {
		$q = strtolower(trim($q));
		$rows = [];
		foreach ($this->instanceStatsRequest->remoteHostCounts() as $host => $count) {
			if ($q !== '' && !str_contains($host, $q)) {
				continue;
			}
			$row = $this->instanceRow($host, $count);
			$keep = match ($filter) {
				'unlisted' => $row['unlisted'],
				'banned' => $row['banned'],
				'auto_cw' => false,
				default => true,
			};
			if ($keep) {
				$rows[] = $row;
			}
		}

		$key = in_array($sortBy, ['status_count', 'user_count', 'domain'], true) ? $sortBy : 'id';
		usort($rows, static fn (array $a, array $b): int => $a[$key] <=> $b[$key]);
		if (strtolower($sort) !== 'asc') {
			$rows = array_reverse($rows);
		}

		return ['data' => array_slice($rows, 0, self::PAGE)];
	}

	/**
	 * @return array{data: array<string, mixed>}
	 * @throws ItemNotFoundException
	 */
	public function instance(string $id): array {
		return ['data' => $this->findInstance($id)];
	}

	/**
	 * Pixelfed's three instance switches: `unlisted` is a silence, `banned`
	 * a deny-list entry, and `auto_cw` a flag this instance does not have.
	 *
	 * @return array{data: array<string, mixed>}
	 * @throws InvalidArgumentException
	 * @throws ItemNotFoundException
	 */
	public function moderateInstance(string $id, string $key, bool $value): array {
		$instance = $this->findInstance($id);
		$host = (string)$instance['domain'];

		switch ($key) {
			case 'unlisted':
				$value ? $this->fediverseService->silenceAddress($host) : $this->fediverseService->unsilenceAddress($host);
				break;
			case 'banned':
				$whitelist = $this->configService->accessTypeList['WHITELIST'] ?? 'none_but';
				if ($this->fediverseService->getAccessType() === $whitelist) {
					throw new InvalidArgumentException(
						'this instance federates by an allow list; take the domain off it instead'
					);
				}
				$value ? $this->fediverseService->addAddress($host) : $this->fediverseService->removeAddress($host);
				break;
			case 'auto_cw':
				throw new InvalidArgumentException('an instance has no "auto_cw" state here');
			default:
				throw new InvalidArgumentException('unknown key: ' . $key);
		}

		return ['data' => $this->instanceRow($host, (int)$instance['user_count'])];
	}

	/**
	 * @return array<string, mixed>
	 * @throws ItemNotFoundException
	 */
	private function findInstance(string $id): array {
		$id = strtolower(trim($id));
		foreach ($this->instanceStatsRequest->remoteHostCounts() as $host => $count) {
			if ($id === $host || $id === AdminDomainBlock::idOf($host)) {
				return $this->instanceRow($host, $count);
			}
		}

		throw new ItemNotFoundException('unknown instance');
	}

	/**
	 * @return array<string, mixed>
	 */
	private function instanceRow(string $host, int $accounts): array {
		$whitelist = $this->configService->accessTypeList['WHITELIST'] ?? 'none_but';
		$denyList = $this->fediverseService->getAccessType() !== $whitelist;

		return [
			'id' => AdminDomainBlock::idOf($host),
			'domain' => $host,
			'user_count' => $accounts,
			// nothing here counts posts per host, and a guess would be worse
			// than a zero the docs explain
			'status_count' => 0,
			'unlisted' => $this->fediverseService->isSilenced($host),
			'auto_cw' => false,
			'banned' => $denyList && $this->fediverseService->isListed($host),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function userRow(AdminAccount $account): array {
		return [
			'id' => $account->getId(),
			'username' => $account->getUsername(),
			// the address belongs to the Nextcloud account, which this app
			// does not read out to a client
			'email' => '',
			'created_at' => gmdate('Y-m-d\TH:i:s', $account->getCreation()) . '.000Z',
			'is_admin' => $this->adminApiService->isAdministrator($account->getUsername()),
			'status' => $account->isSuspended() ? 'disabled' : null,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function reportRow(AdminReport $report): array {
		$statuses = [];
		foreach ($report->getStatuses() as $status) {
			if ($status instanceof Stream) {
				$status->setExportFormat(ACore::FORMAT_LOCAL);
			}
			$statuses[] = $status;
		}
		$first = $statuses[0] ?? null;
		$entity = $report->jsonSerialize();

		return [
			'id' => (string)$report->getId(),
			'type' => ($first === null) ? 'user' : 'post',
			'message' => (string)($entity['comment'] ?? ''),
			'object_id' => ($first === null) ? ($report->getTargetAccount()?->getId() ?? '') : $this->statusId($first),
			'object_type' => ($first === null) ? 'App\\Profile' : 'App\\Status',
			'created_at' => (string)($entity['created_at'] ?? ''),
			'reported_by_account' => $entity['account'] ?? null,
			'reported_account' => $entity['target_account'] ?? null,
			'status' => $first,
			'parent' => null,
		];
	}

	private function statusId(mixed $status): string {
		if ($status instanceof Stream) {
			return (string)$status->getNid();
		}
		if (is_array($status)) {
			return (string)($status['id'] ?? '');
		}

		return '';
	}
}
