<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Exceptions;

/**
 * The AppView answered, and what was asked for is not there: an unknown
 * handle, a deleted post, an account it does not index.
 */
class AppViewNotFoundException extends AtprotoException {
}
