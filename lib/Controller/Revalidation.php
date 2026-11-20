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
 * Conditional GETs for the routes a client polls. They return a
 * `JSONResponse`, because Nextcloud's JSON responder overwrites a
 * `DataResponse`'s `Cache-Control` with `no-store`, which defeats the `ETag`
 * (see `MastodonApiController::tagged()`).
 */
final class Revalidation {
	/**
	 * Headers every `Response` computes itself; not copied when changing
	 * class, so `Cache-Control` is not frozen at its old value.
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
	 * The answer `$answer` builds, tagged with `$tag`, or a `304` without
	 * building it when the caller holds that tag, for polled answers whose
	 * tag is cheaper than the answer.
	 *
	 * @param \Closure(): mixed $answer
	 */
	public static function byTag(IRequest $request, string $tag, \Closure $answer): JSONResponse {
		$etag = '"' . $tag . '"';
		if (self::matches($request, $etag)) {
			return self::notModified($etag);
		}

		$json = self::asJson(new DataResponse($answer(), Http::STATUS_OK));
		$json->addHeader('ETag', $etag);
		$json->addHeader('Cache-Control', self::CACHE_CONTROL);

		return $json;
	}

	/**
	 * An answer tagged by its content, or a `304` when the caller has it: for
	 * small sidebar routes fetched on every page load, saving the body. A
	 * non-`200` passes through untagged.
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
