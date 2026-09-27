<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Exceptions;

use Exception;

/**
 * The bytes sniffed as an image cannot be turned into one — truncated, corrupt,
 * or so large that decoding it would cost more memory than the instance has.
 */
class CacheContentDecodeException extends Exception {
}
