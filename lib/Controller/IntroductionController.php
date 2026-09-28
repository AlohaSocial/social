<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;

/** Persists the reader's completion of Social's first-run introduction. */
class IntroductionController extends Controller {
	public function __construct(
		IRequest $request,
		private ?string $userId,
		private IConfig $config,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/introduction/dismiss')]
	public function dismiss(): DataResponse {
		if ($this->userId === null) {
			return new DataResponse([], 401);
		}

		$this->config->setUserValue($this->userId, Application::APP_ID, 'introduction_dismissed', '1');

		return new DataResponse(['dismissed' => true]);
	}
}
