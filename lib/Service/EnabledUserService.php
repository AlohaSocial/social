<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCP\IUserManager;

/**
 * Whether a Nextcloud account may still sign in, for the credentials this
 * app issues itself: Mastodon tokens, Bluesky app-password and OAuth
 * sessions, service-auth tokens and the sessions of an account moving
 * here. Nextcloud refuses a disabled account its own sessions and app
 * passwords; these are checked here, on every use, so that enabling the
 * account again brings its apps back.
 */
class EnabledUserService {
	public function __construct(
		private IUserManager $userManager,
	) {
	}

	/** False for an account that is disabled or does not exist. */
	public function isEnabled(string $userId): bool {
		if ($userId === '') {
			return false;
		}

		return $this->userManager->get($userId)?->isEnabled() === true;
	}
}
