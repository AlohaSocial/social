<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Exceptions;

use Exception;

/**
 * The caller signed in correctly and their account is suspended here: a 403
 * with Mastodon's wording, which no client reads as its token having ended.
 */
class AccountSuspendedException extends Exception {
	public function __construct(string $message = 'Your login is currently disabled') {
		parent::__construct($message);
	}
}
