<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\ModerationRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Mention;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\NotificationPolicy;
use OCA\Social\Model\Client\NotificationRequest;
use OCA\Social\Model\Moderation;

/**
 * Mastodon 4.3's notification policy and the requests inbox it fills.
 *
 * What it is for: an account that is mentioned by strangers can either read
 * every notification or turn notifications off. The policy is the third
 * answer — notifications from accounts the reader has no relationship with are
 * *held* rather than shown or lost, gathered per sender, and the reader
 * decides about the sender once.
 *
 * An account that has never stored a policy holds nothing back: every one of
 * the five questions answers `accept`, which is what every account had before
 * this existed, so accounts from before new ones started calm keep receiving
 * everything until their owner chooses. An account created here is given
 * a stored policy at creation (`startCalm()`): the administrator's defaults,
 * else `NotificationPolicy::CALM`.
 *
 * Held, not deleted. `drop` is Mastodon's third answer and this app treats it
 * as `filter` at read time for one reason: the notification row is written by
 * the inbox, long before anybody reads it, and dropping it there would mean
 * the policy decides what is *stored* — so a policy loosened on Tuesday could
 * not show what was refused on Monday. The reader sees the same thing either
 * way; what differs is whether the decision can be taken back.
 *
 * The policy applies in two places, with the same rules (`isHeld()`): when
 * the list is read, so what is held is not listed, and when a notification is
 * stored, so what is held raises no Nextcloud notification — no bell, push or
 * mail (`isHeldFor()`, called from `NotificationService::emit()`).
 *
 * Reading is where the list is filtered, not writing, and that has a cost
 * worth naming: `/api/v1/notifications` reads a page and then removes what is held,
 * so a page can come back short. It is the same trade `FilterService` makes,
 * and the paging cursor is taken from what the query returned rather than from
 * what survived, so a shortened page never ends the client's paging early.
 */
class NotificationPolicyService {
	/** Where the five decisions are stored, as JSON, per account. */
	public const CONFIG_KEY = 'notification_policy';

	/**
	 * The administrator's choice of what a new account starts with, as JSON
	 * with the same five keys; a key left out is `NotificationPolicy::CALM`'s.
	 */
	public const DEFAULTS_KEY = 'notification_policy_defaults';

	/** Set to `1` once the reader has put away the one-time pointer to the policy. */
	public const NOTICE_KEY = 'notification_policy_notice_dismissed';

	/** The two answers an administrator may give for new accounts. */
	public const DEFAULT_DECISIONS = [NotificationPolicy::ACCEPT, NotificationPolicy::FILTER];

	/** Younger than this and an account is "new" for `for_new_accounts`. */
	public const NEW_ACCOUNT_DAYS = 30;

	/** The most senders one requests page describes. Mastodon's own default. */
	public const REQUESTS_LIMIT = 40;
	public const REQUESTS_MAX_LIMIT = 80;

	/**
	 * How many notifications the requests inbox looks back over.
	 *
	 * The inbox is derived rather than stored, so "everything held" means
	 * reading notifications until there are none left. This bounds that: a
	 * sender whose last notification is further back than this is not listed,
	 * and a reader who wants it can still find the notification itself.
	 */
	public const LOOKBACK = 400;

	public function __construct(
		private ConfigService $configService,
		private FollowsRequest $followsRequest,
		private ModerationRequest $moderationRequest,
		private AccountRelationService $accountRelationService,
		private TimelineRevisionService $timelineRevisionService,
	) {
	}

	/** The account's policy, with nothing filled in for the summary. */
	public function of(string $userId): NotificationPolicy {
		$stored = $this->configService->getUserValue(self::CONFIG_KEY, $userId);
		$policy = $this->decode($stored);

		return $policy->setNotice(
			$stored === '' && $this->configService->getUserValue(self::NOTICE_KEY, $userId) !== '1'
		);
	}

	/**
	 * Stores the policy a new account starts with, unless the account already
	 * has one — a Nextcloud user whose earlier account was deleted keeps what
	 * they chose then.
	 */
	public function startCalm(string $userId): void {
		if ($userId === '' || $this->configService->getUserValue(self::CONFIG_KEY, $userId) !== '') {
			return;
		}

		$this->configService->setValueForUser(
			$userId, self::CONFIG_KEY, (string)json_encode($this->defaults()->getDecisions())
		);
	}

	/** What a new account starts with: the administrator's choice over `NotificationPolicy::CALM`. */
	public function defaults(): NotificationPolicy {
		$policy = new NotificationPolicy();
		foreach (NotificationPolicy::CALM as $key => $decision) {
			$policy->set($key, $decision);
		}

		$decoded = json_decode((string)$this->configService->getAppValue(self::DEFAULTS_KEY), true);
		if (is_array($decoded)) {
			foreach (NotificationPolicy::KEYS as $key) {
				if (isset($decoded[$key]) && in_array($decoded[$key], self::DEFAULT_DECISIONS, true)) {
					$policy->set($key, $decoded[$key]);
				}
			}
		}

		return $policy;
	}

	/**
	 * Changes what new accounts start with. Only `accept` and `filter`: the
	 * administrator decides whether something waits for review, never that it
	 * is thrown away for somebody who has not chosen that. Accounts that exist
	 * already are not touched.
	 *
	 * @param array<string, mixed> $changes
	 *
	 * @throws InvalidResourceException a named key with any other value; nothing is written
	 */
	public function saveDefaults(array $changes): NotificationPolicy {
		$policy = $this->defaults();
		foreach (NotificationPolicy::KEYS as $key) {
			if (!array_key_exists($key, $changes)) {
				continue;
			}
			if (!in_array($changes[$key], self::DEFAULT_DECISIONS, true)) {
				throw new InvalidResourceException($key . ' must be accept or filter');
			}

			$policy->set($key, $changes[$key]);
		}

		$this->configService->setAppValue(self::DEFAULTS_KEY, (string)json_encode($policy->getDecisions()));

		return $policy;
	}

	/** Puts the one-time pointer to the policy away for good. */
	public function dismissNotice(string $userId): void {
		$this->configService->setValueForUser($userId, self::NOTICE_KEY, '1');
	}

	private function decode(string $stored): NotificationPolicy {
		$policy = new NotificationPolicy();
		if ($stored === '') {
			return $policy;
		}

		$decoded = json_decode($stored, true);
		if (!is_array($decoded)) {
			return $policy;
		}

		foreach (NotificationPolicy::KEYS as $key) {
			if (isset($decoded[$key]) && is_string($decoded[$key])) {
				$policy->set($key, $decoded[$key]);
			}
		}

		return $policy;
	}

	/**
	 * Writes the decisions that were named and leaves the rest as they are: a
	 * client that changes one of the five must not reset the other four.
	 *
	 * @param array<string, mixed> $changes
	 */
	public function save(string $userId, array $changes): NotificationPolicy {
		$policy = $this->of($userId);
		foreach (NotificationPolicy::KEYS as $key) {
			if (isset($changes[$key]) && is_string($changes[$key])) {
				$policy->set($key, $changes[$key]);
			}
		}

		$this->configService->setValueForUser(
			$userId, self::CONFIG_KEY, (string)json_encode($policy->getDecisions())
		);

		return $policy;
	}

	/**
	 * Splits a page of notifications into the ones to show and the ones the
	 * policy holds back.
	 *
	 * Every fact the five questions need is gathered once for the whole page
	 * rather than per notification: a page is tens of rows from a handful of
	 * senders, and asking "do I follow this account" per row would be tens of
	 * queries for an answer that does not change inside one page.
	 *
	 * @param Stream[] $notifications
	 *
	 * @return array{shown: Stream[], held: Stream[]}
	 */
	public function partition(Person $viewer, array $notifications): array {
		$policy = $this->of($viewer->getUserId());
		if ($policy->isEverythingAccepted() || $notifications === []) {
			// the common case, and it costs nothing: no account that has not
			// touched the policy pays a query for it
			return ['shown' => array_values($notifications), 'held' => []];
		}

		$senders = $this->sendersOf($notifications);
		if ($senders === []) {
			return ['shown' => array_values($notifications), 'held' => []];
		}

		$facts = $this->factsAbout($viewer, $senders);

		$shown = [];
		$held = [];
		foreach ($notifications as $notification) {
			$sender = $this->senderOf($notification);
			$senderId = $sender?->getId() ?? '';
			if ($senderId === ''
				|| !$this->isHeld(
					$policy, $senderId, $sender?->getCreation() ?? 0, $this->isPrivateMention($notification), $facts
				)) {
				$shown[] = $notification;

				continue;
			}

			$held[] = $notification;
		}

		return ['shown' => $shown, 'held' => $held];
	}

	/**
	 * Whether one notification, as it is being stored, is held: the rules
	 * `partition()` applies to a page, asked about a single sender.
	 *
	 * @param Person $viewer the recipient, with the Nextcloud user it belongs to
	 * @param int $senderCreation the sender's account creation as a unix time; 0 when unknown
	 * @param bool $directMention a mention in a post addressed to its readers alone
	 */
	public function isHeldFor(Person $viewer, string $senderId, int $senderCreation, bool $directMention): bool {
		if ($senderId === '') {
			return false;
		}

		$policy = $this->of($viewer->getUserId());
		if ($policy->isEverythingAccepted()) {
			return false;
		}

		return $this->isHeld(
			$policy, $senderId, $senderCreation, $directMention, $this->factsAbout($viewer, [$senderId])
		);
	}

	/**
	 * The senders whose notifications are being held, each with what they have
	 * sent, newest first.
	 *
	 * A sender the reader has dismissed is not among them: dismissing is
	 * "stop asking me about this account", and an inbox that offers them again
	 * tomorrow has not done what it was told.
	 *
	 * @param Stream[] $held
	 * @param array<string, bool> $dismissed sender ids the reader said no to
	 *
	 * @return NotificationRequest[]
	 */
	public function requestsFrom(array $held, array $dismissed = []): array {
		/** @var array<string, array{account: Person, count: int, last: int, first: int, status: ?Stream}> $bySender */
		$bySender = [];

		foreach ($held as $notification) {
			$sender = $this->senderOf($notification);
			if ($sender === null) {
				continue;
			}

			$id = $sender->getId();
			if (isset($dismissed[$id])) {
				continue;
			}

			$at = $notification->getPublishedTime();
			if (!isset($bySender[$id])) {
				$bySender[$id] = [
					'account' => $sender,
					'count' => 0,
					'last' => $at,
					'first' => $at,
					'status' => $notification->getObject() instanceof Stream
						? $notification->getObject() : null,
				];
			}

			$bySender[$id]['count']++;
			$bySender[$id]['last'] = max($bySender[$id]['last'], $at);
			$bySender[$id]['first'] = min($bySender[$id]['first'], $at);
		}

		$requests = [];
		foreach ($bySender as $entry) {
			$requests[] = new NotificationRequest(
				$entry['account'], $entry['count'], $entry['last'], $entry['first'], $entry['status']
			);
		}

		// newest first, as every list in this API is
		usort(
			$requests,
			static fn (NotificationRequest $a, NotificationRequest $b): int
				=> $b->getAccount()->getNid() <=> $a->getAccount()->getNid()
		);

		return $requests;
	}

	/**
	 * "Show me this account's notifications after all."
	 *
	 * Stored as a relation, so it settles everything that account has sent and
	 * will send — which is the whole point of deciding about the sender rather
	 * than about each notification. It is not a follow and federates nothing.
	 */
	public function accept(Person $viewer, Person $sender): void {
		$this->accountRelationService->acceptNotifications($viewer, $sender);
		$this->timelineRevisionService->bump($viewer->getUserId());
	}

	/**
	 * "Stop asking me about this account."
	 *
	 * The held notifications stay where they are and stay hidden; what changes
	 * is that the sender is no longer offered as a decision to take. Mastodon
	 * deletes them; here nothing is deleted, so a policy the reader loosens
	 * later still has something to show.
	 */
	public function dismiss(Person $viewer, Person $sender): void {
		$this->accountRelationService->dismissNotifications($viewer, $sender);
		$this->timelineRevisionService->bump($viewer->getUserId());
	}

	/**
	 * The senders this reader accepted from their requests: always allowed,
	 * whatever the policy says, until the reader stops allowing them.
	 *
	 * @return Person[]
	 */
	public function allowed(Person $viewer, int $limit = self::REQUESTS_LIMIT): array {
		return $this->accountRelationService->acceptedSenders(
			$viewer, max(1, min(self::REQUESTS_MAX_LIMIT, $limit))
		);
	}

	/** Returns an accepted sender to the policy. */
	public function stopAllowing(Person $viewer, Person $sender): void {
		$this->accountRelationService->forgetAcceptedSender($viewer, $sender);
		$this->timelineRevisionService->bump($viewer->getUserId());
	}

	/**
	 * The senders this reader has already decided about: `accepted` are never
	 * held again, `dismissed` are held for good and never listed as a request.
	 *
	 * @param string[] $senders
	 *
	 * @return array{accepted: array<string, bool>, dismissed: array<string, bool>}
	 */
	public function decisionsAbout(string $viewerId, array $senders): array {
		return $this->accountRelationService->notificationDecisions($viewerId, $senders);
	}

	/**
	 * @param Stream[] $notifications
	 *
	 * @return string[] actor ids
	 */
	private function sendersOf(array $notifications): array {
		$senders = [];
		foreach ($notifications as $notification) {
			$sender = $this->senderOf($notification);
			if ($sender !== null) {
				$senders[$sender->getId()] = true;
			}
		}

		return array_keys($senders);
	}

	private function senderOf(Stream $notification): ?Person {
		return $notification->hasActor() ? $notification->getActor() : null;
	}

	/**
	 * Everything the five questions ask about a set of senders: whether the
	 * viewer follows them, whether they follow the viewer, whether this
	 * instance has silenced them, and which of them the viewer has already
	 * decided about.
	 *
	 * @param string[] $senders
	 *
	 * @return array{following: array<string, bool>, followers: array<string, bool>, silenced: array<string, bool>, accepted: array<string, bool>, dismissed: array<string, bool>}
	 */
	private function factsAbout(Person $viewer, array $senders): array {
		$follows = $this->followsRequest->getBetweenMany($viewer->getId(), $senders);

		$silenced = [];
		foreach ($this->moderationRequest->getActorIdsAt(Moderation::SILENCE) as $actorId) {
			$silenced[$actorId] = true;
		}

		$decided = $this->decisionsAbout($viewer->getId(), $senders);

		return [
			'following' => array_fill_keys(array_keys($follows['following'] ?? []), true),
			'followers' => array_fill_keys(array_keys($follows['followedBy'] ?? []), true),
			'silenced' => $silenced,
			'accepted' => $decided['accepted'],
			'dismissed' => $decided['dismissed'],
		];
	}

	/**
	 * @param array{following: array<string, bool>, followers: array<string, bool>, silenced: array<string, bool>, accepted: array<string, bool>, dismissed: array<string, bool>} $facts
	 */
	private function isHeld(
		NotificationPolicy $policy,
		string $senderId,
		int $senderCreation,
		bool $privateMention,
		array $facts,
	): bool {
		// a decision the reader has already taken outranks the policy, in
		// both directions: the policy is about accounts nobody has decided
		// about yet
		if (isset($facts['accepted'][$senderId])) {
			return false;
		}
		if (isset($facts['dismissed'][$senderId])) {
			return true;
		}

		$following = isset($facts['following'][$senderId]);

		foreach ([
			NotificationPolicy::NOT_FOLLOWING => !$following,
			NotificationPolicy::NOT_FOLLOWERS => !isset($facts['followers'][$senderId]),
			NotificationPolicy::NEW_ACCOUNTS => $this->isNewAccount($senderCreation),
			NotificationPolicy::PRIVATE_MENTIONS => !$following && $privateMention,
			NotificationPolicy::LIMITED_ACCOUNTS => isset($facts['silenced'][$senderId]),
		] as $key => $applies) {
			if ($applies && $policy->get($key) !== NotificationPolicy::ACCEPT) {
				return true;
			}
		}

		return false;
	}

	private function isNewAccount(int $creation): bool {
		// an account this instance has no creation date for is not a new
		// account: guessing "new" would hold back every remote account whose
		// profile arrived without one
		return $creation > 0 && $creation > (time() - self::NEW_ACCOUNT_DAYS * 86400);
	}

	/** A mention in a post addressed to the reader alone. */
	private function isPrivateMention(Stream $notification): bool {
		if ($notification->getSubType() !== Mention::TYPE) {
			return false;
		}

		$status = $notification->getObject();

		return $status instanceof Stream && $status->getVisibility() === Stream::TYPE_DIRECT;
	}
}
