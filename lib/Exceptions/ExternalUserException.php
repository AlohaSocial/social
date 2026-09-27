<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Exceptions;

use Exception;

/**
 * A request about an external user that cannot be met, with a message that
 * is already translated and safe to show the person who made it.
 */
class ExternalUserException extends Exception {
	public function __construct(
		string $message,
		private string $field = '',
	) {
		parent::__construct($message);
	}

	/** The form field the message is about, or '' for the whole request. */
	public function getField(): string {
		return $this->field;
	}
}
