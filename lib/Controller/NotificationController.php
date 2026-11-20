<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\NotificationPolicy;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\FilterService;
use OCA\Social\Service\MarkerService;
use OCA\Social\Service\NotificationDeliveryService;
use OCA\Social\Service\NotificationGroupService;
use OCA\Social\Service\NotificationInboxService;
use OCA\Social\Service\NotificationPolicyService;
use OCA\Social\Service\NotificationService;
use OCA\Social\Tools\Nid;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * This controller's notification routes: one notification or group rather
 * than the list, the unread count, and the notification policy and request
 * inbox.
 *
 * `#[PublicPage]` with `#[NoCSRFRequired]`, as Mastodon clients use a bearer
 * token. Every route resolves a viewer first (401 without one), and a
 * notification that is not the viewer's is not found.
 */
class NotificationController extends ClientApiController {
	/** What `unread_count` counts to unless asked otherwise: Mastodon's default. */
	public const UNREAD_LIMIT = 100;

	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		AccountService $accountService,
		ClientService $clientService,
		private NotificationService $notificationService,
		private NotificationGroupService $notificationGroupService,
		private NotificationPolicyService $notificationPolicyService,
		private NotificationDeliveryService $notificationDeliveryService,
		private FilterService $filterService,
		private CacheActorService $cacheActorService,
		private NotificationInboxService $notificationInboxService,
		private MarkerService $markerService,
	) {
		parent::__construct($request, $userSession, $logger, $accountService, $clientService);
	}

	/** One notification of the viewer's, as the notification timeline serves it. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/notifications/{id}', requirements: ['id' => '\\d+'])]
	public function get(int $id): DataResponse {
		try {
			$this->initViewer(['read:notifications']);

			return new DataResponse($this->notificationService->get($this->viewer, $id), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Dismisses one notification and answers `{}`, as Mastodon does; one that
	 * is already gone is not an error.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/notifications/{id}/dismiss', requirements: ['id' => '\\d+'])]
	public function dismiss(int $id): DataResponse {
		try {
			$this->initViewer(['write:notifications']);

			try {
				$this->notificationService->dismiss($this->viewer, $id);
			} catch (ItemNotFoundException $e) {
				// Already gone, which is the state asked for.
			}

			return new DataResponse([], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** Dismisses every notification the viewer has, and answers `{}`. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/notifications/clear')]
	public function clear(): DataResponse {
		try {
			$this->initViewer(['write:notifications']);
			$this->notificationService->clear($this->viewer);

			return new DataResponse([], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	// Mastodon 4.3: the same notifications, grouped

	/**
	 * How many groups of notifications the reader has not seen: past the
	 * notifications marker, plus those released behind it, as v1's
	 * `unread_count` counts. Capped at `limit` and tagged, so a polling client
	 * gets `304` until something changed.
	 *
	 * Declared before the `{group_key}` routes, which would otherwise match
	 * `unread_count`: routes are tried in declaration order.
	 *
	 * @param string[]|string $types
	 * @param string[]|string $exclude_types
	 * @param string[]|string $grouped_types
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v2/notifications/unread_count')]
	public function unreadCountV2(
		int $limit = self::UNREAD_LIMIT,
		array|string $types = [],
		array|string $exclude_types = [],
		string $account_id = '',
		array|string $grouped_types = [],
	): Response {
		try {
			$this->initViewer(['read:notifications']);
			$userId = $this->userId();
			$types = self::listParam($types);
			$exclude_types = self::listParam($exclude_types);
			$grouped_types = self::listParam($grouped_types);

			return Revalidation::byTag(
				$this->request,
				$this->notificationInboxService->unreadTag($this->viewer, $userId),
				function () use ($userId, $limit, $types, $exclude_types, $account_id, $grouped_types): array {
					$cap = max(1, min(NotificationPolicyService::LOOKBACK - 1, $limit));
					$marker = $this->markerService->lastReadId($userId, NotificationInboxService::TIMELINE);
					$released = array_flip($this->markerService->unreadBehind($userId, NotificationInboxService::TIMELINE));

					// From the marker, or from the oldest notification released behind it.
					$since = $marker;
					foreach (array_keys($released) as $nid) {
						if (Nid::compare((string)$nid, $since) <= 0) {
							$since = Nid::decrement((string)$nid);
						}
					}
					$page = $this->visible(
						($released === []) ? $cap + 1 : NotificationPolicyService::LOOKBACK,
						'0', 0, $since, $types, $exclude_types, $account_id, true
					);

					$unread = array_filter(
						$page,
						static fn (Stream $notification): bool => Nid::compare($notification->getNid(), $marker) > 0
							|| isset($released[(string)$notification->getNid()])
					);

					// Groups, not rows: one popular post is one thing.
					return [
						'count' => min(
							$cap, count($this->notificationGroupService->group($unread, $grouped_types)['notification_groups'])
						),
					];
				}
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * `GET /api/v2/notifications`: the viewer's notifications, grouped, as
	 * Mastodon's `GroupedNotificationsResults` (groups plus the accounts and
	 * statuses they refer to, each carried once).
	 *
	 * @param string[]|string $types
	 * @param string[]|string $exclude_types
	 * @param string[]|string $grouped_types
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v2/notifications')]
	public function indexV2(
		int $limit = 40,
		int|string $max_id = 0,
		int|string $min_id = 0,
		int|string $since_id = 0,
		array|string $types = [],
		array|string $exclude_types = [],
		array|string $grouped_types = [],
		string $account_id = '',
	): DataResponse {
		try {
			$this->initViewer(['read:notifications']);

			$page = $this->visible(
				$limit, $max_id, $min_id, $since_id, self::listParam($types), self::listParam($exclude_types), $account_id
			);

			return new DataResponse(
				$this->notificationGroupService->group($page, self::listParam($grouped_types)), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * What this account does with notifications from people it has no
	 * relationship with. Declared ahead of `group()`, whose
	 * `/api/v2/notifications/{group_key}` matches this path too.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/notifications/policy')]
	// v2 since Mastodon 4.3; the v1 spelling stays for 4.2 clients.
	#[FrontpageRoute(verb: 'GET', url: '/api/v2/notifications/policy', postfix: 'v2')]
	public function policy(): Response {
		try {
			$this->initViewer(['read:notifications']);

			// The sidebar's Activities badge reads this on every push and poll.
			return Revalidation::byTag(
				$this->request,
				$this->notificationInboxService->pendingTag($this->viewer, $this->userId()),
				fn (): NotificationPolicy => $this->policyWithSummary()
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** One group, in the same shape the list serves it in. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v2/notifications/{group_key}')]
	public function group(string $group_key): DataResponse {
		try {
			$this->initViewer(['read:notifications']);

			$members = $this->notificationGroupService->membersOf(
				$this->visible(NotificationPolicyService::LOOKBACK, lookback: true), $group_key
			);
			if ($members === []) {
				throw new ItemNotFoundException('Record not found');
			}

			return new DataResponse($this->notificationGroupService->group($members), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Everybody in one group ("and 34 others"), not only the sample it carries.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v2/notifications/{group_key}/accounts')]
	public function groupAccounts(string $group_key): DataResponse {
		try {
			$this->initViewer(['read:notifications']);

			$accounts = [];
			foreach ($this->notificationGroupService->membersOf(
				$this->visible(NotificationPolicyService::LOOKBACK, lookback: true), $group_key
			) as $notification) {
				if (!$notification->hasActor()) {
					continue;
				}

				$actor = $notification->getActor();
				$actor->setExportFormat(ACore::FORMAT_LOCAL);
				$accounts[$actor->getId()] = $actor;
			}

			return new DataResponse(array_values($accounts), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Dismisses every notification in one group, since a group is what the
	 * reader sees.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v2/notifications/{group_key}/dismiss')]
	public function groupDismiss(string $group_key): DataResponse {
		try {
			$this->initViewer(['write:notifications']);

			foreach ($this->notificationGroupService->membersOf(
				$this->visible(NotificationPolicyService::LOOKBACK, lookback: true), $group_key
			) as $notification) {
				try {
					$this->notificationService->dismiss($this->viewer, $notification->getNid());
				} catch (ItemNotFoundException $e) {
					// Already gone, which is the state asked for.
				}
			}

			return new DataResponse([], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	// Mastodon 4.3: the policy, and the inbox it fills

	/**
	 * Changes the policy with Mastodon 4.3's `PATCH`. Keys not sent are left as
	 * they are, and an unknown decision leaves its key alone rather than failing
	 * the request.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'PATCH', url: '/api/v1/notifications/policy')]
	#[FrontpageRoute(verb: 'PATCH', url: '/api/v2/notifications/policy', postfix: 'v2')]
	public function policyUpdate(): DataResponse {
		try {
			$this->initViewer(['write:notifications']);

			$this->notificationPolicyService->save($this->userId(), $this->body());

			return new DataResponse($this->policyWithSummary(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * When this account's Nextcloud notifications are raised: at once, or as a
	 * digest at chosen times, with pass-throughs and quiet hours. Not a Mastodon
	 * route, hence the `/social/` path.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/social/notification_delivery')]
	public function delivery(): DataResponse {
		try {
			$this->initViewer(['read:notifications']);

			return new DataResponse($this->notificationDeliveryService->of($this->userId()), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Changes the delivery setting and answers the whole of it. Any subset of
	 * keys may be sent; an invalid value refuses the whole change with a 422
	 * naming it.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'PATCH', url: '/api/v1/social/notification_delivery')]
	public function deliveryUpdate(): DataResponse {
		try {
			$this->initViewer(['write:notifications']);

			return new DataResponse(
				$this->notificationDeliveryService->save($this->userId(), $this->body()), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Whether the held notifications have been merged back into the list:
	 * always `true`, since the policy is applied when the list is read.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/notifications/requests/merged')]
	public function requestsMerged(): DataResponse {
		try {
			$this->initViewer(['read:notifications']);

			return new DataResponse(['merged' => true], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** Accepts several senders at once: `id[]`, as Mastodon sends it. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/notifications/requests/accept')]
	public function requestsAccept(array|string $id = []): DataResponse {
		return $this->decideMany(self::listParam($id), true);
	}

	/** Dismisses several senders at once. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/notifications/requests/dismiss')]
	public function requestsDismiss(array|string $id = []): DataResponse {
		return $this->decideMany(self::listParam($id), false);
	}

	/**
	 * The senders whose notifications the policy is holding, one row each with
	 * how many they have sent, so the reader decides once per sender.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/notifications/requests')]
	public function requests(int $limit = NotificationPolicyService::REQUESTS_LIMIT): DataResponse {
		try {
			$this->initViewer(['read:notifications']);

			$limit = max(1, min(NotificationPolicyService::REQUESTS_MAX_LIMIT, $limit));

			return new DataResponse(array_slice($this->heldRequests(), 0, $limit), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** One of those rows. The id is the sender's account id. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/notifications/requests/{id}', requirements: ['id' => '\\d+'])]
	public function request(int $id): DataResponse {
		try {
			$this->initViewer(['read:notifications']);

			foreach ($this->heldRequests() as $request) {
				if ($request->getAccount()->getNid() === (string)$id) {
					return new DataResponse($request, Http::STATUS_OK);
				}
			}

			throw new ItemNotFoundException('Record not found');
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * "Show me this account's notifications after all": what they already sent
	 * joins the list as unread without raising anything, and the sender is
	 * always allowed from then on.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/notifications/requests/{id}/accept', requirements: ['id' => '\\d+'])]
	public function requestAccept(int $id): DataResponse {
		return $this->decideMany([(string)$id], true);
	}

	/**
	 * "Stop asking me about this account": their notifications stay hidden but
	 * are not deleted (unlike Mastodon), so a later looser policy can show them.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/notifications/requests/{id}/dismiss', requirements: ['id' => '\\d+'])]
	public function requestDismiss(int $id): DataResponse {
		return $this->decideMany([(string)$id], false);
	}

	/**
	 * The senders this account accepted from its requests, most recent first.
	 * Not a Mastodon route, hence `/social/`.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/social/notifications/allowed')]
	public function allowed(int $limit = NotificationPolicyService::REQUESTS_LIMIT): DataResponse {
		try {
			$this->initViewer(['read:notifications']);

			$accounts = [];
			foreach ($this->notificationPolicyService->allowed($this->viewer, $limit) as $account) {
				$account->setExportFormat(ACore::FORMAT_LOCAL);
				$accounts[] = $account;
			}

			return new DataResponse($accounts, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Stops always allowing a sender: their next notification is judged by the
	 * policy again. Answers `{}` also for an account that was not allowed.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/social/notifications/allowed/{account_id}', requirements: ['account_id' => '\\d+'])]
	public function allowedRemove(string $account_id): DataResponse {
		try {
			$this->initViewer(['write:notifications']);

			if (\OCA\Social\Tools\Nid::compare($account_id, '0') > 0) {
				$accounts = $this->cacheActorService->getFromNids([\OCA\Social\Tools\Nid::fromStorage($account_id)]);
				if ($accounts !== []) {
					$this->notificationPolicyService->stopAllowing($this->viewer, $accounts[0]);
				}
			}

			return new DataResponse([], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Dismisses the one-time pointer to the policy (`notice` on the policy),
	 * shown to accounts older than the calm defaults. Saving the policy dismisses
	 * it too. Answers the policy.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/social/notifications/policy/notice/dismiss')]
	public function policyNoticeDismiss(): DataResponse {
		try {
			$this->initViewer(['write:notifications']);

			$this->notificationPolicyService->dismissNotice($this->userId());

			return new DataResponse($this->policyWithSummary(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The page of notifications this viewer is meant to see: what the query
	 * returned, less what a keyword filter hides and what the policy holds.
	 *
	 * @param string[] $types
	 * @param string[] $excludeTypes
	 * @param bool $lookback `$limit` is this server's own, not the client's
	 *                       (`NotificationInboxService::timeline()`)
	 *
	 * @return \OCA\Social\Model\ActivityPub\Stream[]
	 */
	private function visible(
		int $limit,
		int|string $maxId = '0',
		int|string $minId = 0,
		int|string $sinceId = '0',
		array $types = [],
		array $excludeTypes = [],
		string $accountId = '',
		bool $lookback = false,
	): array {
		if ($accountId !== '') {
			// Mastodon's `account_id` is numeric; an unknown one has caused nothing.
			try {
				$accountId = $this->cacheActorService->resolve($accountId)->getId();
			} catch (CacheActorDoesNotExistException) {
				return [];
			}
		}

		if ($this->bearer !== '') {
			// For clients other than the web app, types Mastodon does not define are left
			// out unless named, in the query so the page stays full.
			$excludeTypes = array_merge($excludeTypes, Stream::notificationTypesKeptFromClients($types));
		}

		$page = $this->notificationInboxService->timeline(
			$this->viewer, $limit, $maxId, $minId, $sinceId, $types, $excludeTypes, $accountId, $lookback
		);

		return $this->filterService->applyToNotifications(
			$this->notificationPolicyService->partition($this->viewer, $page)['shown'],
			$this->viewer
		);
	}

	/**
	 * The requests inbox, built from the notifications the policy is holding.
	 *
	 * @return \OCA\Social\Model\Client\NotificationRequest[]
	 */
	private function heldRequests(): array {
		return $this->notificationInboxService->requests($this->viewer);
	}

	/** The policy with the counts a client draws the badge from. */
	private function policyWithSummary(): NotificationPolicy {
		$pending = $this->notificationInboxService->pending($this->viewer);

		return $this->notificationPolicyService->of($this->userId())
			->setSummary($pending['requests'], $pending['notifications']);
	}

	/**
	 * Accepts or dismisses the senders named by account id, and answers `{}`.
	 * An id that names no account is skipped, so one stale entry does not lose
	 * the rest.
	 *
	 * @param array<mixed> $ids
	 */
	private function decideMany(array $ids, bool $accept): DataResponse {
		try {
			$this->initViewer(['write:notifications']);

			foreach ($ids as $id) {
				if ((!is_string($id) && !is_int($id)) || !ctype_digit((string)$id) || \OCA\Social\Tools\Nid::compare($id, '0') < 1) {
					continue;
				}
				$nid = \OCA\Social\Tools\Nid::fromStorage($id);

				$accounts = $this->cacheActorService->getFromNids([$nid]);
				if ($accounts === []) {
					continue;
				}

				if ($accept) {
					$this->notificationInboxService->release($this->viewer, $accounts[0]);
				} else {
					$this->notificationPolicyService->dismiss($this->viewer, $accounts[0]);
				}
			}

			return new DataResponse([], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** The Nextcloud user behind the viewer, which is where the policy is stored. */
	private function userId(): string {
		return $this->viewer->getUserId();
	}

	/**
	 * The request body, whether it arrived as JSON or as a form.
	 *
	 * @return array<string, mixed>
	 */
	private function body(): array {
		return $this->request->getParams();
	}
}
