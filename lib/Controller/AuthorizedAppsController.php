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
 * The apps this account has signed in to, and taking one of them back.
 *
 * Mastodon keeps this under Account → Authorized apps, and it is the first
 * place somebody looks after losing a phone. Every authorization has been
 * recorded in `social_client_auth` since the table was split out.
 *
 * Client-API routes, so the settings page reaches them with its session and a
 * mobile client with the token it signed in with — but the two are not equal
 * here. This is the one place that manages *other* apps' tokens, and Mastodon
 * has no API for it at all, so nothing a client was granted can be read as
 * consent to sign the person's other apps out:
 *
 *  - listing asks for the broad `read`, not one granular part of it;
 *  - a bearer token may revoke only its own authorization, with `write`.
 *    Any other id is a **403** whether it exists or not. Signing another app
 *    out takes the Nextcloud session, the web page or an app password.
 *
 * What comes back never carries the token itself — it is stored hashed, and
 * there is nothing a client needs it for here.
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
					'website' => $client->getAppWebsite(),
					'scopes' => $client->getAuthScopes(),
					// when *this account* granted it, not when the app registered
					// itself on the instance — the second is the same date for
					// everybody and says nothing about whose phone this is
					'created_at' => $client->getAuthCreation(),
					'last_used_at' => $client->getLastUpdate(),
					// 0 when the instance lets a token in use live for ever, or
					// the app keeps itself signed in by refreshing
					'expires_at' => $this->clientService->signsOutAt($client),
					// an authorization whose code was never exchanged: the browser
					// came back and the app never asked for its token. Worth
					// showing, because taking it back is still the right thing to
					// do with it, and worth marking, because it is not a sign-in
					'signed_in' => $client->getToken() !== '',
				];
			}

			return new DataResponse($apps, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Takes one of them back. The app is signed out at once: the token is the
	 * row, and the row is gone.
	 *
	 * Through the session, an id that is not one of this account's own is a
	 * **404** and never a 403 — the two answers together would say which ids
	 * exist. Through a bearer token every id but its own is the same 403, so
	 * that answer says nothing either.
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
