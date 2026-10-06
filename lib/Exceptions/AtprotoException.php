<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Exceptions;

use Exception;
use Throwable;

/**
 * One XRPC call that did not answer with what it was asked for.
 *
 * The status code is kept apart from the message because the two say
 * different things about the next attempt: a `401` may be a session that
 * expired and is worth logging in again, a `400` names a request that will
 * fail the same way forever, and status `0` is the network — no answer at
 * all, which is a reason to back off rather than to stop. `isTransient()`
 * is that distinction in one call, so a caller keeping a failure count does
 * not have to re-derive it.
 *
 * The XRPC error name (`InvalidLogin`, `RepoNotFound`, …) travels alongside
 * the human sentence the peer wrote, and is empty when the answer was not an
 * XRPC error document at all — an HTML error page, or nothing.
 */
class AtprotoException extends Exception {
	public function __construct(
		string $message,
		int $status = 0,
		private string $xrpcError = '',
		?Throwable $previous = null,
	) {
		parent::__construct($message, $status, $previous);
	}

	/**
	 * The HTTP status the call answered with, or `0` when it never did.
	 */
	public function getStatus(): int {
		return $this->getCode();
	}

	public function getXrpcError(): string {
		return $this->xrpcError;
	}

	/**
	 * Whether trying the same call again can plausibly produce a different
	 * answer: the network failed, or the peer itself did.
	 */
	public function isTransient(): bool {
		$status = $this->getStatus();

		return $status === 0 || $status >= 500;
	}
}
