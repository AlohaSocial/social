<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Exceptions;

use Throwable;

/**
 * A refusal with a stable code and the values the advice needs (a limit, the
 * field refused), so the page can say it in the person's language; the
 * English sentence is for logs and API clients. The codes are listed in
 * API.md and Atproto-Compatibility §13.5.
 */
trait CodedRefusal {
	public readonly string $errorCode;

	/** @var array<string, string|int|float> */
	public readonly array $params;

	/**
	 * @param array<string, string|int|float> $params answered beside `error` and `code`
	 */
	public function __construct(string $message, string $errorCode, ?Throwable $previous = null, array $params = []) {
		parent::__construct($message, 0, $previous);
		$this->errorCode = $errorCode;
		$this->params = $params;
	}

	/** What a route answers for it. */
	public function toArray(): array {
		return ['error' => $this->getMessage(), 'code' => $this->errorCode] + $this->params;
	}
}
