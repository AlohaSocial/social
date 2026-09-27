<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tools\Traits;

use Exception;
use JsonSerializable;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Exceptions\FollowLimitException;
use OCA\Social\Exceptions\FollowSameAccountException;
use OCA\Social\Exceptions\InvalidHandleException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\RetrieveAccountFormatException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use Psr\Log\LoggerInterface;

/**
 * Trait TNCDataResponse
 *
 * @deprecated - 19
 * @package OCA\Social\Tools\Traits
 */
trait TNCDataResponse {
	/**
	 * The failures that are the caller's, or another server's, rather than
	 * this one's, and the status that says so — the session-route share of
	 * `MastodonApiController::ERROR_STATUS`, without its 401s: these routes run on
	 * the Nextcloud session, and a 401 from one reads as that session having
	 * ended.
	 */
	private const CALLER_ERROR_STATUS = [
		[StreamNotFoundException::class, Http::STATUS_NOT_FOUND],
		[CacheActorDoesNotExistException::class, Http::STATUS_NOT_FOUND],
		[ActorDoesNotExistException::class, Http::STATUS_NOT_FOUND],
		[ItemNotFoundException::class, Http::STATUS_NOT_FOUND],
		[CacheDocumentDoesNotExistException::class, Http::STATUS_NOT_FOUND],
		[RequestContentException::class, Http::STATUS_NOT_FOUND],
		[InvalidResourceException::class, Http::STATUS_UNPROCESSABLE_ENTITY],
		[InvalidHandleException::class, Http::STATUS_UNPROCESSABLE_ENTITY],
		[RetrieveAccountFormatException::class, Http::STATUS_UNPROCESSABLE_ENTITY],
		[FollowSameAccountException::class, Http::STATUS_UNPROCESSABLE_ENTITY],
		[UnauthorizedFediverseException::class, Http::STATUS_FORBIDDEN],
		[FollowLimitException::class, Http::STATUS_TOO_MANY_REQUESTS],
		[RequestNetworkException::class, Http::STATUS_BAD_GATEWAY],
		[RequestServerException::class, Http::STATUS_BAD_GATEWAY],
		[RequestResultNotJsonException::class, Http::STATUS_BAD_GATEWAY],
		[RequestResultSizeException::class, Http::STATUS_BAD_GATEWAY],
	];

	/**
	 * `fail()` with the status the failure calls for. Only what is left, a
	 * failure of this server's own, answers 500 and is logged: a stale link
	 * or a mistyped handle is not something for the admin's log.
	 */
	protected function failFor(Exception $e): DataResponse {
		foreach (self::CALLER_ERROR_STATUS as [$class, $status]) {
			if ($e instanceof $class) {
				return $this->fail($e, [], $status, false);
			}
		}

		return $this->fail($e);
	}

	/**
	 * @return DataResponse
	 */
	protected function fail(
		Exception $e, array $more = [], int $status = Http::STATUS_INTERNAL_SERVER_ERROR,
		bool $log = true,
	): DataResponse {
		if ($log) {
			// the details go to the log; several callers are @PublicPage, and the
			// exception class and message describe the server's internals
			\OCP\Server::get(LoggerInterface::class)->warning(
				$status . ' - ' . get_class($e) . ' ' . $e->getMessage(),
				['exception' => $e, 'more' => $more]
			);
		}

		$data = array_merge(
			$more,
			[
				'status' => -1,
				'error' => 'request failed',
			]
		);

		return new DataResponse($data, $status);
	}

	/**
	 * @param array $result
	 * @param array $more
	 *
	 * @return DataResponse
	 */
	protected function success(array $result = [], array $more = []): DataResponse {
		$data = array_merge(
			$more,
			[
				'result' => $result,
				'status' => 1
			]
		);

		return new DataResponse($data, Http::STATUS_OK);
	}
	/**
	 * Return JSON-LD response with ActivityPub content type.
	 */
	protected function activityPubSuccess(JsonSerializable $result): DataResponse {
		$response = new DataResponse($result, Http::STATUS_OK);
		$response->addHeader(
			'Content-Type',
			'application/ld+json; profile="https://www.w3.org/ns/activitystreams"'
		);
		return $response;
	}
}
