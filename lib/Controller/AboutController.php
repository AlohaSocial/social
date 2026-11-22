<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\AppInfo\Application;
use OCA\Social\Middleware\AnonymousRateLimit;
use OCA\Social\Service\AboutService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * What the About page shows: the rules, the contact, and the privacy policy
 * and legal notice. Public, like Mastodon's instance information: a visitor
 * reads them before signing up.
 */
class AboutController extends Controller {
	public function __construct(
		IRequest $request,
		private AboutService $aboutService,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonymousRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/about/info')]
	public function info(): DataResponse {
		return new DataResponse($this->aboutService->about());
	}
}
