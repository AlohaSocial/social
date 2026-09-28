<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use InvalidArgumentException;
use OCA\Social\AppInfo\Application;
use OCA\Social\Service\InterestService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * The For you card on the admin page.
 *
 * No attribute that would loosen access: without `#[NoAdminRequired]` the
 * framework refuses anybody but an administrator before this runs, which is
 * what a switch for the whole instance needs.
 */
class InterestsAdminController extends Controller {
	public function __construct(
		IRequest $request,
		private InterestService $interestService,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Writes every setting on the card, or none of them.
	 *
	 * @param bool $enabled whether the feature is on
	 * @param bool $learningDefault whether a reader who never chose learns
	 * @param int $halfLife days for a score to halve
	 * @param float $threshold the score a learned tag needs to be listed
	 * @param int $cap how many interests are listed
	 * @param int $window how many days back the feed looks
	 */
	#[FrontpageRoute(verb: 'POST', url: '/admin/interests')]
	public function save(
		bool $enabled = true,
		bool $learningDefault = true,
		int $halfLife = 30,
		float $threshold = 3.0,
		int $cap = 30,
		int $window = 7,
	): DataResponse {
		try {
			return new DataResponse($this->interestService->saveAdminSettings(
				$enabled, $learningDefault, $halfLife, $threshold, $cap, $window
			));
		} catch (InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
