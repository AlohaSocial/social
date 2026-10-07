<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;

/**
 * Mastodon's NotificationPolicy: what to do with a notification from somebody
 * the reader has no relationship with.
 *
 * Five questions, each answered with `accept` (as before), `filter` (held in
 * the requests inbox, where the reader can look at them together and decide
 * about the sender rather than about each notification) or `drop` (never
 * stored). Everything defaults to `accept`, which is what every account had
 * before this existed: a policy that starts by holding things back would
 * silently swallow notifications for accounts that never asked it to.
 *
 * The questions are about the *sender*, not about the notification, which is
 * why one decision about an account settles every notification it has sent and
 * will send.
 */
class NotificationPolicy implements JsonSerializable {
	public const ACCEPT = 'accept';
	public const FILTER = 'filter';
	public const DROP = 'drop';

	public const DECISIONS = [self::ACCEPT, self::FILTER, self::DROP];

	/** Somebody the reader does not follow. */
	public const NOT_FOLLOWING = 'for_not_following';
	/** Somebody who does not follow the reader. */
	public const NOT_FOLLOWERS = 'for_not_followers';
	/** An account younger than `NotificationPolicyService::NEW_ACCOUNT_DAYS`. */
	public const NEW_ACCOUNTS = 'for_new_accounts';
	/** A direct message from somebody the reader does not follow. */
	public const PRIVATE_MENTIONS = 'for_private_mentions';
	/** An account this instance has silenced. */
	public const LIMITED_ACCOUNTS = 'for_limited_accounts';

	public const KEYS = [
		self::NOT_FOLLOWING,
		self::NOT_FOLLOWERS,
		self::NEW_ACCOUNTS,
		self::PRIVATE_MENTIONS,
		self::LIMITED_ACCOUNTS,
	];

	/**
	 * What an account created here starts with, unless an administrator chose
	 * otherwise: strangers, new accounts, unsolicited private mentions and
	 * limited accounts wait for review; people the reader follows, and anyone
	 * merely not following them back, reach them as before.
	 *
	 * Not the constructor's defaults. Those are what an account *without* a
	 * stored policy has, which is every account that existed before new ones
	 * started calm, and those accounts keep receiving everything.
	 */
	public const CALM = [
		self::NOT_FOLLOWING => self::FILTER,
		self::NOT_FOLLOWERS => self::ACCEPT,
		self::NEW_ACCOUNTS => self::FILTER,
		self::PRIVATE_MENTIONS => self::FILTER,
		self::LIMITED_ACCOUNTS => self::FILTER,
	];

	/** @var array<string, string> key => decision */
	private array $decisions = [];
	private int $pendingRequests = 0;
	private int $pendingNotifications = 0;
	private bool $notice = false;

	public function __construct() {
		$this->decisions = array_fill_keys(self::KEYS, self::ACCEPT);
	}

	/** An unknown key is ignored, and an unknown decision leaves the key as it was. */
	public function set(string $key, string $decision): self {
		if (in_array($key, self::KEYS, true) && in_array($decision, self::DECISIONS, true)) {
			$this->decisions[$key] = $decision;
		}

		return $this;
	}

	public function get(string $key): string {
		return $this->decisions[$key] ?? self::ACCEPT;
	}

	/** Whether any of the five holds anything back. Nothing does the common case's work. */
	public function isEverythingAccepted(): bool {
		foreach ($this->decisions as $decision) {
			if ($decision !== self::ACCEPT) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The summary Mastodon puts on the entity: how many senders are waiting in
	 * the requests inbox, and how many notifications they have sent between
	 * them. A client draws the badge from these.
	 */
	public function setSummary(int $pendingRequests, int $pendingNotifications): self {
		$this->pendingRequests = $pendingRequests;
		$this->pendingNotifications = $pendingNotifications;

		return $this;
	}

	/**
	 * Whether the reader should be pointed at the policy once: an account
	 * from before new accounts started calm, which has neither chosen a
	 * policy nor put the pointer away. Not a Mastodon field.
	 */
	public function setNotice(bool $notice): self {
		$this->notice = $notice;

		return $this;
	}

	public function hasNotice(): bool {
		return $this->notice;
	}

	/** @return array<string, string> */
	public function getDecisions(): array {
		return $this->decisions;
	}

	#[\Override]
	public function jsonSerialize(): array {
		return array_merge(
			$this->decisions,
			[
				'summary' => [
					'pending_requests_count' => $this->pendingRequests,
					'pending_notifications_count' => $this->pendingNotifications,
				],
				'notice' => $this->notice,
			]
		);
	}
}
