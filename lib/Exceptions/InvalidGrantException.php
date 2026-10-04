<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Exceptions;

/**
 * RFC 6749 §5.2 `invalid_grant`: the authorization code is not one this
 * client may exchange under the parameters it presented.
 */
class InvalidGrantException extends ClientException {
}
