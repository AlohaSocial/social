<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Exceptions;

use Exception;

/**
 * No translation provider answered, so nothing was translated. Answered with
 * a 503, as Mastodon does, which clients read as "try again later".
 */
class TranslationUnavailableException extends Exception {
}
