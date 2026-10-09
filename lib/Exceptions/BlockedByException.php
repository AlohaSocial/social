<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Exceptions;

/**
 * A reply, quote or follow refused because the account it reaches has
 * blocked the one making it (`Service\BlockedBy\BlockedByService`).
 */
class BlockedByException extends InvalidActionException {
}
