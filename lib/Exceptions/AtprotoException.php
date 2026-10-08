<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Exceptions;

use Exception;

/**
 * Something on the AT Protocol side refused or failed: a PLC operation
 * rejected, a repository write that cannot be made, a relay that did not
 * answer. The message is for the log and the administrator.
 */
class AtprotoException extends Exception {
}
