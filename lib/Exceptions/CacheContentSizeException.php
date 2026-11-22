<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Exceptions;

use Exception;

/**
 * The content is larger than its sniffed type allows: videos are copied in
 * chunks and may be large, images are read whole and may not, so a file
 * posing as a video at request time is caught here.
 */
class CacheContentSizeException extends Exception {
}
