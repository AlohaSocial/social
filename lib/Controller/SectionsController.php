<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use InvalidArgumentException;
use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\SettingRefusedException;
use OCA\Social\Service\SectionsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * The Sections card of the Social settings page.
 *
 * Administrators only, like ServerSettingsController and for the same reason:
 * this controller carries none of the relaxing attributes, so a group the
 * Social section was delegated to -- a moderator -- does not reach it. What
 * the app offers everybody is a decision about the instance, not about a
 * report.
 */
class SectionsController extends Controller {
	public function __construct(
		IRequest $request,
		private SectionsService $sectionsService,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Writes every setting on the card, or none of them.
	 *
	 * @param bool $stories whether stories are offered
	 * @param bool $photos whether the Photos timeline is offered
	 * @param bool $videos whether the Videos timeline is offered
	 * @param string[] $groupLists the Nextcloud groups whose members get a list for them
	 * @param bool|null $gifPack whether the composer offers the animated emoji pack; left alone when not sent
	 */
	#[FrontpageRoute(verb: 'POST', url: '/admin/sections')]
	public function save(
		bool $stories = true,
		bool $photos = true,
		bool $videos = true,
		array $groupLists = [],
		?bool $gifPack = null,
	): DataResponse {
		try {
			return new DataResponse($this->sectionsService->save($stories, $photos, $videos, $groupLists, $gifPack));
		} catch (InvalidArgumentException $e) {
			return new DataResponse(
				$e instanceof SettingRefusedException ? $e->toArray() : ['error' => $e->getMessage()],
				Http::STATUS_BAD_REQUEST
			);
		}
	}
}
