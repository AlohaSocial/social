<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Conditional GETs for the routes a client polls.
 *
 * An `ETag` only works when it reaches the wire next to a `Cache-Control`
 * that lets the browser keep the body, and a `DataResponse` cannot carry one:
 * Nextcloud's json responder merges the fresh response's defaults —
 * `no-cache, no-store, must-revalidate` — over the controller's headers (see
 * `MastodonApiController::tagged()`). So what these helpers hand back is a
 * `JSONResponse`, which the dispatcher passes through as it is.
 */
final class Revalidation {
	/**
	 * The headers every `Response` works out for itself, left behind when a
	 * response changes class: the new one computes the same values, and
	 * copying them would freeze today's — `Cache-Control` above all, which is
	 * the one being set on purpose.
	 */
	public const FRAMEWORK_HEADERS = [
		'Cache-Control',
		'Content-Security-Policy',
		'Feature-Policy',
		'X-Request-Id',
		'X-Robots-Tag',
		'X-User-Id',
	];

	/** `private` because an answer may be one account's; `no-cache` so it is revalidated. */
	public const CACHE_CONTROL = 'private, no-cache';

	/** Whether the request already holds the version `$etag` (quoted) names. */
	public static function matches(IRequest $request, string $etag): bool {
		$sent = trim($request->getHeader('If-None-Match'));
		$bare = trim($etag, '"');

		return $sent !== '' && ($sent === $etag || $sent === $bare || $sent === 'W/' . $etag);
	}

	/** The `304` for a request that already holds `$etag`. */
	public static function notModified(string $etag): JSONResponse {
		$response = new JSONResponse([], Http::STATUS_NOT_MODIFIED);
		$response->addHeader('ETag', $etag);
		$response->addHeader('Cache-Control', self::CACHE_CONTROL);

		return $response;
	}

	/**
	 * A `DataResponse` rebuilt as the `JSONResponse` the responder would have
	 * made of it, with the headers its caller set (a paging `Link`, say).
	 */
	public static function asJson(DataResponse $response): JSONResponse {
		$json = new JSONResponse($response->getData(), $response->getStatus());

		foreach ($response->getHeaders() as $name => $value) {
			if (in_array($name, self::FRAMEWORK_HEADERS, true)) {
				continue;
			}

			$json->addHeader($name, $value);
		}

		return $json;
	}

	/**
	 * An answer tagged by what it says, or a `304` when the caller has it.
	 *
	 * For the sidebar routes — emoji, trends, lists, followed tags, the
	 * instance — which are small, cheap to build and asked for on every page
	 * load: the server still builds the answer, but the body is not sent
	 * again and the browser keeps the copy it has. A response that is not a
	 * `200` is passed through untagged.
	 */
	public static function byContent(IRequest $request, DataResponse $response): JSONResponse {
		$json = self::asJson($response);
		if ($response->getStatus() !== Http::STATUS_OK) {
			return $json;
		}

		$etag = '"' . sha1((string)json_encode([$response->getData(), $json->getHeaders()['Link'] ?? ''])) . '"';
		if (self::matches($request, $etag)) {
			return self::notModified($etag);
		}

		$json->addHeader('ETag', $etag);
		$json->addHeader('Cache-Control', self::CACHE_CONTROL);

		return $json;
	}
}
