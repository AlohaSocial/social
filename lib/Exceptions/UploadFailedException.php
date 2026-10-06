<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Exceptions;

use Exception;

/**
 * An upload this server could not receive for a reason of its own: no
 * temporary directory, a disk it cannot write to, an extension that stopped
 * it. Nothing about the file is wrong, and the message says so.
 */
class UploadFailedException extends Exception {
}
