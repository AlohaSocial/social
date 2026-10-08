<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Exceptions;

use Exception;

/**
 * The moderator is a moderator, but not of this account: their own, or a
 * Nextcloud administrator's when they are not one themselves. Answered with a
 * 403 and the message, which is the moderator's to read.
 */
class ModerationNotAllowedException extends Exception {
}
