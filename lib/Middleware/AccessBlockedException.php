<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Middleware;

use Exception;

/**
 * Thrown and caught by AccessBlockMiddleware: the request came from an
 * address this instance answers nothing from. Its own type so
 * `afterException()` re-throws every other failure untouched.
 */
class AccessBlockedException extends Exception {
}
