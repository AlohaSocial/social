<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\AppInfo\Application;
use OCA\Social\Middleware\ClientApiCorsMiddleware;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Answers an `/api/` address no client-API route does, for a browser client of
 * another origin: the CORS preflight, and a 404 that client can read. Neither
 * looks at who asks. Routed last in `appinfo/routes.php`.
 */
class ClientApiPreflightController extends Controller {
	public function __construct(IRequest $request) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function preflight(string $path): Response {
		return ClientApiCorsMiddleware::cors(new DataResponse(null, Http::STATUS_NO_CONTENT));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function notFound(string $path): Response {
		return ClientApiCorsMiddleware::cors(new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND));
	}
}
