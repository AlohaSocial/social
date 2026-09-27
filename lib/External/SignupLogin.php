<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\External;

use OCP\Authentication\IAlternativeLogin;

/** The "Create an account" button under the login form. */
class SignupLogin implements IAlternativeLogin {
	public function __construct(
		private string $label,
		private string $link,
	) {
	}

	#[\Override]
	public function getLabel(): string {
		return $this->label;
	}

	#[\Override]
	public function getLink(): string {
		return $this->link;
	}

	#[\Override]
	public function getClass(): string {
		return 'social-signup-login';
	}

	#[\Override]
	public function load(): void {
	}
}
