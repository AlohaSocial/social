<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Exceptions;

/**
 * RFC 6749 §5.2 `invalid_scope`: a token request asked for a scope the
 * authorization it names does not carry.
 */
class InvalidScopeException extends ClientException {
}
