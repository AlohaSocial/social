<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Exceptions\InsufficientScopeException;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The apps this account has signed in to, and taking one of them back, as
 * Mastodon's Account → Authorized apps.
 *
 * Client-API routes, but nothing a client was granted is consent to sign the
 * person's other apps out: listing needs the broad `read`, and a bearer token
 * may revoke only its own authorization, with `write` (any other id is a 403
 * whether it exists or not). Signing another app out takes the Nextcloud
 * session or an app password. Tokens are stored hashed and never returned.
 */
class AuthorizedAppsController extends ClientApiController {
	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		AccountService $accountService,
		ClientService $clientService,
	) {
		parent::__construct($request, $userSession, $logger, $accountService, $clientService);
	}

	/** The apps this account has signed in to, newest first. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/authorized_apps')]
	public function index(): DataResponse {
		try {
			$this->initViewer(['read']);

			$apps = [];
			foreach ($this->clientService->getAuthorizationsOf($this->viewer()->getUserId()) as $client) {
				$apps[] = [
					'id' => $client->getAuthId(),
					'name' => $client->getAppName(),
					// a stored website is not trusted to be a safe link, so it is checked
					// again before it is shown as one
					'website' => ClientService::websiteLink($client->getAppWebsite()),
					'scopes' => $client->getAuthScopes(),
					// when this account granted it, not when the app registered itself on
					// the instance
					'created_at' => $client->getAuthCreation(),
					'last_used_at' => $client->getLastUpdate(),
					// 0 when the instance lets a token in use live for ever, or
					// the app keeps itself signed in by refreshing
					'expires_at' => $this->clientService->signsOutAt($client),
					// an authorization whose code was never exchanged: shown so it can be
					// taken back, and marked because it is not a sign-in
					'signed_in' => $client->getToken() !== '',
				];
			}

			return new DataResponse($apps, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Takes one of them back; the token is the row, so the app is signed out at
	 * once. Through the session, an id that is not this account's is a 404, never
	 * a 403, so the answers do not reveal which ids exist; through a bearer token
	 * every id but its own is the same 403.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/authorized_apps/{id}')]
	public function revoke(int $id): DataResponse {
		try {
			$this->initViewer(['write']);
			if ($this->client !== null && $this->client->getAuthId() !== $id) {
				throw new InsufficientScopeException(
					'a token may only revoke its own authorization; other apps are signed out from the Nextcloud session'
				);
			}

			if (!$this->clientService->revokeAuthorizationOf($this->viewer()->getUserId(), $id)) {
				return new DataResponse(['error' => 'no such authorization'], Http::STATUS_NOT_FOUND);
			}

			return new DataResponse([], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}
}
