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
 * The response an asynchronous request settles with, in the shape the rest of
 * `CurlService` reads.
 *
 * `IClient::getAsync()` returns an `IPromise`, and what its `wait()` hands
 * back is the response Guzzle itself produced — a bare PSR-7
 * `ResponseInterface`, whose `getHeader()` is the list of values a header
 * has — rather than the `IResponse` that the same promise's `then()` wraps
 * for its handlers, whose `getHeader()` is one value. So every asynchronous
 * answer failed the `instanceof IResponse` check and was thrown away: the
 * delivery queue recorded "no response" for each server it wrote to, the
 * follow graph asked twenty and read nothing back, and a batch of counts got
 * nothing at all.
 *
 * The four methods `IResponse` has are all the difference needs, so it is
 * made here rather than by reaching for the server's own wrapper —
 * `OC\Http\Client\Response`, which lives in `OC\`, the private half of the
 * server that no code in this app is written against.
 *
 * The body is handed over as the stream it is, the way the server's wrapper
 * does for a streamed request, so a reader that stops at a byte limit never
 * holds the rest of it first.
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
