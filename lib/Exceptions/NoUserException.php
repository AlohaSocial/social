<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Exceptions;

use Exception;

/**
 * No Nextcloud account behind the user id this app was handed.
 *
 * The app used to throw `OC\User\NoUserException` for this — a class from the
 * server's lib/private/ that it threw at itself and caught again. Every throw
 * and every catch is in this app.
 */
class NoUserException extends Exception {
}
