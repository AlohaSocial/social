<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use InvalidArgumentException;
use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\InsufficientScopeException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\ReportNotFoundException;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\AdminApiService;
use OCA\Social\Service\ClientService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The gate every administration route stands behind.
 *
 * One place decides who is an administrator of this instance and how a
 * refusal is worded, so that Mastodon's admin API and Pixelfed's cannot
 * drift into answering the same person differently. A caller arrives with
 * a bearer token or a session; either way the answer to a non-administrator
 * is deliberately the same whether the user exists, has a Social account or
 * simply may not moderate: the admin API tells them nothing about the
 * instance, not even that.
 */
abstract class AdminApiControllerBase extends Controller {
	protected string $bearer = '';
	protected ?SocialClient $client = null;
	protected string $userId = '';

	public function __construct(
		IRequest $request,
		protected IUserSession $userSession,
		protected LoggerInterface $logger,
		protected AdminApiService $adminApiService,
		protected ClientService $clientService,
	) {
		parent::__construct(Application::APP_ID, $request);

		$authHeader = trim($this->request->getHeader('Authorization'));
		if (strpos($authHeader, ' ')) {
			[$authType, $authToken] = explode(' ', $authHeader);
			if (strtolower($authType) === 'bearer') {
				$this->bearer = $authToken;
			}
		}
	}

	/**
	 * @param string[] $scopes what a bearer token has to carry
	 * @throws ClientNotFoundException
	 * @throws InsufficientScopeException
	 */
	protected function initAdmin(array $scopes = ['admin:read']): void {
		$userId = $this->currentSession();

		if (!$this->adminApiService->isAdministrator($userId)) {
			$this->logger->info('[' . static::class . '] admin API refused to a non-administrator', [
				'user' => $userId,
				'route' => (string)$this->request->getParam('_route', ''),
			]);

			throw new InsufficientScopeException(
				'this API is restricted to the administrators of this instance'
			);
		}

		$this->userId = $userId;

		if ($this->client !== null) {
			$this->checkTokenScope($scopes);
		}
	}

	/**
	 * @throws ClientNotFoundException
	 */
	protected function currentSession(): string {
		if ($this->bearer !== '') {
			try {
				$this->client = $this->clientService->getFromToken($this->bearer);
			} catch (Exception $e) {
				// a stale or made-up token is ordinary internet noise
				$this->logger->debug('[' . static::class . '] unusable bearer token', [
					'exception' => $e->getMessage(),
				]);

				throw new ClientNotFoundException('the access_token was revoked');
			}

			return $this->client->getAuthUserId();
		}

		$user = $this->userSession->getUser();
		if ($user !== null && $this->request->passesCSRFCheck()) {
			return $user->getUID();
		}

		throw new ClientNotFoundException('userId not defined');
	}

	/**
	 * @param string[] $accepted
	 * @throws InsufficientScopeException
	 */
	protected function checkTokenScope(array $accepted): void {
		foreach ($accepted as $scope) {
			$broad = strstr($scope, ':', true);
			$broad = ($broad === false) ? $scope : $broad;

			foreach ($this->client?->getAuthScopes() ?? [] as $granted) {
				if ($granted === $scope || $granted === $broad) {
					return;
				}
			}
		}

		throw new InsufficientScopeException(
			'token scope does not allow this request (needs ' . implode(' or ', $accepted) . ')'
		);
	}

	/**
	 * A failure as the admin API answers it: a status that says what kind,
	 * and a message only where the message is the caller's to read.
	 */
	protected function error(Throwable $e): DataResponse {
		if ($e instanceof InsufficientScopeException) {
			// no WWW-Authenticate: PHP turns any response carrying it into a 401,
			// and a 401 tells a client its token is dead and to sign in again
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		if ($e instanceof ClientNotFoundException) {
			$message = trim($e->getMessage());

			return new DataResponse(
				['error' => ($message === '') ? 'the access_token is invalid' : $message],
				Http::STATUS_UNAUTHORIZED,
				['WWW-Authenticate' => 'Bearer error="invalid_token"']
			);
		}

		if ($e instanceof ItemNotFoundException || $e instanceof ReportNotFoundException) {
			return new DataResponse(['error' => 'Record not found'], Http::STATUS_NOT_FOUND);
		}

		if ($e instanceof InvalidResourceException || $e instanceof InvalidArgumentException) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$this->logger->error('[' . static::class . '] unexpected failure answering the admin API', [
			'exception' => $e,
			'route' => (string)$this->request->getParam('_route', ''),
		]);

		return new DataResponse(
			['error' => 'internal server error'], Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}
}
