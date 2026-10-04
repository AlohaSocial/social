<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Exceptions;

/**
 * The account has moved: its followers were sent elsewhere, and a post or a
 * follow from it would reach nobody. A 403 to a client, not a 422: the
 * request was fine, the account may not.
 */
class AccountMovedException extends ClientException {
}
