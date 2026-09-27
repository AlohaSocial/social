<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Middleware;

use Exception;

/**
 * An external user asked for something outside the Social app.
 *
 * Carries where a browser should be sent instead, for a page; an API call is
 * refused outright.
 */
class ExternalScopeException extends Exception {
	public function __construct(
		private string $redirect,
	) {
		parent::__construct('This account can only use the Social app.');
	}

	public function getRedirect(): string {
		return $this->redirect;
	}
}
