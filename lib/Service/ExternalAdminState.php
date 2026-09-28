<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

/**
 * Everything the External users cards of the settings page show, in one
 * shape: handed over as initial state when the page renders, and answered
 * by the administration routes after every change.
 */
class ExternalAdminState {
	public function __construct(
		private ExternalUserService $externalUserService,
		private ExternalSignupService $signupService,
		private ExternalTwoFactorService $twoFactorService,
	) {
	}

	/** @return array<string, mixed> */
	public function current(): array {
		$users = $this->externalUserService->listPage('', 50, 0);

		return [
			'settings' => $this->externalUserService->settings(),
			'twoFactor' => $this->twoFactorService->state(),
			'requests' => $this->signupService->awaitingApproval(),
			'invites' => $this->signupService->invites(),
			'users' => $users['users'],
			'usersNextOffset' => $users['nextOffset'],
			'usersHasMore' => $users['hasMore'],
		];
	}
}
