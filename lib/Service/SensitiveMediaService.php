<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\AppInfo\Application;
use OCP\IConfig;

/**
 * What happens to a post somebody marked sensitive: PeerTube's three NSFW
 * policies under Mastodon's names (`reading:expand:media` on
 * `/api/v1/preferences`), so clients can act on them:
 *
 * - `show_all` (PeerTube's display): drawn like other media; a content
 *   warning still covers its post.
 * - `default` (blur): covered, blurhash showing, one press away.
 * - `hide_all` (hide): not drawn; opening the post is what it takes.
 *
 * An account without a preference follows the instance, whose default is
 * `default`. The choice is stored per Nextcloud user, as it is about the
 * reader, not an identity other servers see.
 */
class SensitiveMediaService {
	public const SHOW_ALL = 'show_all';
	public const COVERED = 'default';
	public const HIDE_ALL = 'hide_all';

	public const POLICIES = [self::SHOW_ALL, self::COVERED, self::HIDE_ALL];

	/** What the account's row says when it has not chosen. */
	public const FOLLOW_INSTANCE = '';

	/** The Nextcloud user-config key an account's own choice is kept under. */
	public const USER_KEY = 'nsfw_policy';

	public function __construct(
		private ConfigService $configService,
		private IConfig $config,
	) {
	}

	/** What this instance does for somebody who has not chosen. */
	public function instancePolicy(): string {
		return $this->clean(
			(string)$this->configService->getAppValue(ConfigService::SOCIAL_NSFW_POLICY)
		);
	}

	/**
	 * What to do for this reader.
	 *
	 * @param string $userId the Nextcloud user, or '' for nobody signed in
	 */
	public function policyFor(string $userId): string {
		if ($userId === '') {
			return $this->instancePolicy();
		}

		$own = (string)$this->config->getUserValue(
			$userId, Application::APP_ID, self::USER_KEY, self::FOLLOW_INSTANCE
		);

		return ($own === self::FOLLOW_INSTANCE) ? $this->instancePolicy() : $this->clean($own);
	}

	/**
	 * What this account has chosen, `''` where it has not; distinct from
	 * `policyFor()` so a settings page can show "follow the instance".
	 */
	public function choiceOf(string $userId): string {
		if ($userId === '') {
			return self::FOLLOW_INSTANCE;
		}

		$own = (string)$this->config->getUserValue(
			$userId, Application::APP_ID, self::USER_KEY, self::FOLLOW_INSTANCE
		);

		return in_array($own, self::POLICIES, true) ? $own : self::FOLLOW_INSTANCE;
	}

	/**
	 * Records what an account chose; `''` follows the instance.
	 *
	 * @return bool whether the value was one of the four things it may be
	 */
	public function choose(string $userId, string $policy): bool {
		if ($userId === '') {
			return false;
		}

		if ($policy !== self::FOLLOW_INSTANCE && !in_array($policy, self::POLICIES, true)) {
			return false;
		}

		$this->config->setUserValue($userId, Application::APP_ID, self::USER_KEY, $policy);

		return true;
	}

	/** Anything that is not one of the three is `default`. */
	private function clean(string $policy): string {
		return in_array($policy, self::POLICIES, true) ? $policy : self::COVERED;
	}
}
