<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Middleware;

use OCA\Social\Controller\AdminApiControllerBase;
use OCA\Social\Controller\ClientApiController;
use OCA\Social\Controller\ClientApiPreflightController;
use OCA\Social\Controller\MastodonApiController;
use OCA\Social\Controller\OAuthController;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use Throwable;

/**
 * Lets a browser client on another origin (Elk, Phanpy) read the client API:
 * `Access-Control-Allow-Origin: *`, as Mastodon answers, on the `/api/` routes
 * of the three API bases, the OAuth app registration, token, revocation and
 * userinfo, and NodeInfo, errors included.
 *
 * Never with credentials; a request that does carry the session cookie is held
 * to the CSRF check, which another origin cannot pass. Nextcloud's `#[CORS]`
 * does not fit: it reflects the origin, answers no preflight, and logs a
 * session out on a request without a CSRF token.
 */
class ClientApiCorsMiddleware extends Middleware {
	public const ALLOW_METHODS = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';
	/**
	 * A fixed list rather than what the preflight asks for: `OCS-APIRequest`
	 * and `requesttoken` each make Nextcloud take a session as CSRF-checked.
	 */
	public const ALLOW_HEADERS = 'Authorization, Content-Type, Idempotency-Key';
	public const EXPOSE_HEADERS = 'Link, X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset';

	/** @var list<class-string> the controllers whose `/api/` routes take a bearer token */
	private const API_BASES = [
		MastodonApiController::class,
		ClientApiController::class,
		AdminApiControllerBase::class,
	];

	/** @var array<class-string, list<string>> routes outside `/api/` a browser client calls */
	private const OPEN_ROUTES = [
		OAuthController::class => ['apps', 'token', 'revoke', 'userinfo', 'nodeinfo2', 'nodeinfo21'],
	];

	public function __construct(
		private IRequest $request,
	) {
	}

	#[\Override]
	public function afterController(Controller $controller, string $methodName, Response $response): Response {
		if (!$this->isOpen($controller, $methodName)) {
			return $response;
		}

		// an answer that already says whom it is open to, the AT Protocol
		// token path's with DPoP among its headers, keeps what it says
		foreach (array_keys($response->getHeaders()) as $name) {
			if (strtolower($name) === 'access-control-allow-origin') {
				return $response;
			}
		}

		return self::cors($response);
	}

	/** The headers a browser client of any origin needs, without credentials. */
	public static function cors(Response $response): Response {
		$response->addHeader('Access-Control-Allow-Origin', '*');
		$response->addHeader('Access-Control-Allow-Methods', self::ALLOW_METHODS);
		$response->addHeader('Access-Control-Allow-Headers', self::ALLOW_HEADERS);
		$response->addHeader('Access-Control-Expose-Headers', self::EXPOSE_HEADERS);
		$response->addHeader('Access-Control-Max-Age', '600');

		return $response;
	}

	private function isOpen(Controller $controller, string $methodName): bool {
		if ($controller instanceof ClientApiPreflightController) {
			return true;
		}

		foreach (self::OPEN_ROUTES as $class => $methods) {
			if ($controller instanceof $class && in_array($methodName, $methods, true)) {
				return true;
			}
		}

		foreach (self::API_BASES as $class) {
			if ($controller instanceof $class) {
				return $this->isApiPath();
			}
		}

		return false;
	}

	/**
	 * Whether this request is for one of the app's `/api/` routes, which
	 * leaves out the bases' admin page and file routes.
	 */
	private function isApiPath(): bool {
		try {
			$path = $this->request->getPathInfo();
		} catch (Throwable $e) {
			return false;
		}
		if (!is_string($path)) {
			return false;
		}

		$app = strpos($path, '/apps/social/');

		return $app !== false && str_starts_with(substr($path, $app + strlen('/apps/social')), '/api/');
	}
}
