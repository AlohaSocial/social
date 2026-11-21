<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tools\Http;

use OCP\Http\Client\IResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * The response an asynchronous request settles with, as an `IResponse`.
 * `IPromise::wait()` hands back Guzzle's PSR-7 response, whose `getHeader()`
 * is a list, not the `IResponse` that `then()` wraps; the four `IResponse`
 * methods are made here rather than using the server's private
 * `OC\Http\Client\Response`. The body stays a stream, so a reader stopping at
 * a byte limit never holds the rest.
 */
final class FetchedResponse implements IResponse {
	public function __construct(
		private ResponseInterface $response,
	) {
	}

	#[\Override]
	public function getBody() {
		return $this->response->getBody()->detach();
	}

	#[\Override]
	public function getStatusCode(): int {
		return $this->response->getStatusCode();
	}

	#[\Override]
	public function getHeader(string $key): string {
		return $this->response->getHeader($key)[0] ?? '';
	}

	#[\Override]
	public function getHeaders(): array {
		return $this->response->getHeaders();
	}
}
