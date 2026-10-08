<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\AppInfo\Application;
use OCA\Social\Atproto\OAuth\AuthorizationServer;
use OCA\Social\Atproto\OAuth\DpopNonce;
use OCA\Social\Atproto\OAuth\OAuthException;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The parts of OAuth for Bluesky apps (§6.3) that are theirs alone: the
 * protected resource metadata of this PDS, which names the authorization
 * server, the pushed authorization request endpoint, and the CORS
 * preflights a browser app sends before it calls them. Authorizing, the
 * token and revoking are on the addresses the Mastodon OAuth server
 * already answers (`OAuthController`), which hands an AT Protocol request
 * to `AuthorizationServer`.
 */
class AtprotoOAuthController extends Controller {
	public function __construct(
		IRequest $request,
		private AtprotoConfig $config,
		private AuthorizationServer $server,
		private DpopNonce $nonce,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * `/.well-known/oauth-protected-resource`: the authorization server of
	 * the accounts this PDS holds.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/.well-known/oauth-protected-resource')]
	public function protectedResource(): Response {
		if (!$this->config->isEnabled()) {
			return self::cors(new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND));
		}

		return self::cors(new DataResponse($this->server->resourceMetadata()));
	}

	/**
	 * `POST /oauth/par`: a pushed authorization request (RFC 9126), which
	 * AT Protocol makes the only way to start one.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/oauth/par')]
	public function par(): Response {
		if (!$this->config->isEnabled()) {
			return self::cors(new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND));
		}
		try {
			$answer = $this->server->par($this->request->getParams(), $this->request->getHeader('DPoP'));
			$response = new DataResponse($answer, Http::STATUS_CREATED);
		} catch (OAuthException $e) {
			$response = self::error($e);
		} catch (Throwable $e) {
			$this->logger->error('Bluesky OAuth PAR failed', ['exception' => $e]);
			$response = new DataResponse(['error' => 'server_error', 'error_description' => 'Internal server error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return $this->withNonce(self::cors($response));
	}

	/** The preflight of a browser app's PAR. */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'OPTIONS', url: '/oauth/par')]
	public function parPreflight(): Response {
		return self::cors(new DataResponse(null, Http::STATUS_NO_CONTENT));
	}

	/** The preflight of a browser app's token request. */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'OPTIONS', url: '/oauth/token')]
	public function tokenPreflight(): Response {
		return self::cors(new DataResponse(null, Http::STATUS_NO_CONTENT));
	}

	/** The preflight of a browser app's revocation. */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'OPTIONS', url: '/oauth/revoke')]
	public function revokePreflight(): Response {
		return self::cors(new DataResponse(null, Http::STATUS_NO_CONTENT));
	}

	/**
	 * An OAuth error answer, with the headers that go with it.
	 */
	public static function error(OAuthException $e): DataResponse {
		$response = new DataResponse($e->toArray(), $e->status);
		foreach ($e->headers as $name => $value) {
			$response->addHeader($name, $value);
		}
		$response->addHeader('Cache-Control', 'no-store');

		return $response;
	}

	/**
	 * What a browser app needs to call the authorization server.
	 */
	public static function cors(Response $response): Response {
		$response->addHeader('Access-Control-Allow-Origin', '*');
		$response->addHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
		$response->addHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, DPoP');
		$response->addHeader('Access-Control-Expose-Headers', 'DPoP-Nonce, WWW-Authenticate');
		$response->addHeader('Access-Control-Max-Age', '600');

		return $response;
	}

	private function withNonce(Response $response): Response {
		if (!isset($response->getHeaders()['DPoP-Nonce'])) {
			$response->addHeader('DPoP-Nonce', $this->nonce->current());
		}

		return $response;
	}
}
