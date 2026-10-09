<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Client;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\NotificationPolicy;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\NotificationPolicyService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A Bluesky app's notification settings and this person's here, one
 * setting where they mean the same thing: whether anybody, or only the
 * people they follow, may notify them. Here that is the policy for people
 * they do not follow (`NotificationPolicy::NOT_FOLLOWING`); in a Bluesky app
 * it is `include` on each kind of notification that has one. The app reads
 * it from the policy here; what the app saves goes to the AppView, which
 * sends the app's push notifications by it, and becomes the policy here;
 * and a policy changed here is told to the AppView. Bluesky's per-kind
 * `list` and `push` switches have nothing here to be, and are kept by the
 * AppView alone.
 */
class NotificationSettings {
	public const GET = 'app.bsky.notification.getPreferences';
	public const PUT = 'app.bsky.notification.putPreferencesV2';
	/** the kinds of notification whose `include` is "all" or "follows" */
	public const FILTERABLE = ['follow', 'like', 'likeViaRepost', 'mention', 'quote', 'reply', 'repost', 'repostViaRepost'];

	public function __construct(
		private AppViewClient $appView,
		private IdentityService $identities,
		private NotificationPolicyService $policies,
		private AccountService $accounts,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * `getPreferences`: the AppView's, with who may notify as the policy here
	 * says it.
	 */
	public function get(ClientSession $session): array {
		$identity = $session->identity;
		$answer = $this->appView->queryAs($identity->did, $this->identities->signingKey($identity), self::GET);
		$preferences = is_array($answer['preferences'] ?? null) ? $answer['preferences'] : [];

		return ['preferences' => self::withInclude($preferences, $this->include($session->userId))];
	}

	/**
	 * `putPreferencesV2`: kept by the AppView, and who may notify becomes the
	 * policy here when the app changed it.
	 */
	public function put(ClientSession $session, array $body): array {
		$identity = $session->identity;
		$answer = $this->appView->procedureAs($identity->did, $this->identities->signingKey($identity), self::PUT, $body);
		$wanted = self::includeOf($body);
		if ($wanted !== null && $wanted !== $this->include($session->userId)) {
			$this->policies->save($session->userId, [
				NotificationPolicy::NOT_FOLLOWING => $wanted === 'follows' ? NotificationPolicy::FILTER : NotificationPolicy::ACCEPT,
			]);
		}
		$preferences = is_array($answer['preferences'] ?? null) ? $answer['preferences'] : [];

		return ['preferences' => self::withInclude($preferences, $this->include($session->userId))];
	}

	/**
	 * The policy here just changed: who may notify, told to the AppView, for
	 * a person with a Bluesky identity.
	 */
	public function policyChanged(string $userId): void {
		try {
			$viewer = $this->accounts->getActorFromUserId($userId);
			$identity = $this->identities->activeForActor($viewer);
			if ($identity === null) {
				return;
			}
			$include = $this->include($userId);
			$this->appView->procedureAs($identity->did, $this->identities->signingKey($identity), self::PUT, self::withInclude(
				$this->current($viewer), $include, true,
			));
		} catch (Throwable $e) {
			$this->logger->info('Notification settings not told to Bluesky', ['user' => $userId, 'exception' => $e]);
		}
	}

	/** "all" or "follows", as the policy here says it. */
	private function include(string $userId): string {
		return $this->policies->of($userId)->get(NotificationPolicy::NOT_FOLLOWING) === NotificationPolicy::ACCEPT ? 'all' : 'follows';
	}

	/**
	 * @return array<string, mixed>
	 */
	private function current(Person $viewer): array {
		$identity = $this->identities->activeForActor($viewer);
		if ($identity === null) {
			return [];
		}
		$answer = $this->appView->queryAs($identity->did, $this->identities->signingKey($identity), self::GET);

		return is_array($answer['preferences'] ?? null) ? $answer['preferences'] : [];
	}

	/**
	 * The preferences with `include` set on every filterable kind; only those
	 * kinds, for a write.
	 */
	public static function withInclude(array $preferences, string $include, bool $onlyFilterable = false): array {
		$out = $onlyFilterable ? [] : $preferences;
		foreach (self::FILTERABLE as $kind) {
			$current = is_array($preferences[$kind] ?? null) ? $preferences[$kind] : ['list' => true, 'push' => true];
			$out[$kind] = array_merge($current, ['include' => $include]);
		}

		return $out;
	}

	/**
	 * What the app asked for: "follows" when any kind it named is limited to
	 * the people followed, "all" when every kind it named is open, null when
	 * it named none.
	 */
	public static function includeOf(array $body): ?string {
		$named = [];
		foreach (self::FILTERABLE as $kind) {
			$include = $body[$kind]['include'] ?? null;
			if (is_string($include)) {
				$named[] = $include;
			}
		}
		if ($named === []) {
			return null;
		}

		return in_array('follows', $named, true) ? 'follows' : 'all';
	}
}
