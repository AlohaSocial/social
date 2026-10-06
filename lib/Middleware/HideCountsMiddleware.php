<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Middleware;

use OCA\Social\Controller\ClientApiController;
use OCA\Social\Controller\LocalController;
use OCA\Social\Controller\MastodonApiController;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\CountsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * Takes the like, dislike, boost and follower numbers out of every client
 * API answer for a reader who has them hidden (`CountsService`).
 *
 * In one place rather than in each route, because the numbers ride on every
 * status and every account the API hands out — timelines, threads, single
 * posts, profiles, notifications, search, conversations — and a route that
 * was forgotten would be the one that shows them. The client APIs only: the
 * ActivityPub documents and the rest are answered to other servers, not to
 * this reader.
 */
class HideCountsMiddleware extends Middleware {
	public function __construct(
		private CountsService $countsService,
		private ClientService $clientService,
		private IUserSession $userSession,
		private IRequest $request,
	) {
	}

	#[\Override]
	public function afterController(Controller $controller, string $methodName, Response $response): Response {
		// a route's DataResponse has already been rendered into a JSONResponse
		// by the time the after-middlewares run; both are handled
		if (!($response instanceof DataResponse || $response instanceof JSONResponse) || !self::isClientApi($controller)) {
			return $response;
		}
		$status = $response->getStatus();
		if ($status < 200 || $status >= 300) {
			return $response;
		}

		if (!$this->countsService->hides($this->readerId())) {
			return $response;
		}

		$encoded = json_encode($response->getData());
		if ($encoded === false) {
			return $response;
		}
		$response->setData($this->countsService->strip(json_decode($encoded)));

		return $response;
	}

	private static function isClientApi(Controller $controller): bool {
		return $controller instanceof ClientApiController
			|| $controller instanceof MastodonApiController
			|| $controller instanceof LocalController;
	}

	/** The reader this answer is for: the token's account, or the session's; '' for nobody. */
	private function readerId(): string {
		$header = trim($this->request->getHeader('Authorization'));
		if (stripos($header, 'bearer ') === 0) {
			try {
				return $this->clientService->getFromToken(trim(substr($header, 7)))->getAuthUserId();
			} catch (Throwable $e) {
				return '';
			}
		}

		return $this->userSession->getUser()?->getUID() ?? '';
	}
}
