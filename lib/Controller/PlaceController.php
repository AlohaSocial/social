<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Db\PlacesRequest;
use OCA\Social\Middleware\AnonymousRateLimit;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\PlaceService;
use OCA\Social\Service\PostDecorationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Places: where a post was taken.
 *
 * Searched over the places this instance has already seen, never a geocoder
 * (see `PlaceService`). A viewer is required, since the known places would
 * map where this instance's people go.
 */
class PlaceController extends ClientApiController {
	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		AccountService $accountService,
		ClientService $clientService,
		private PlaceService $placeService,
		private PostDecorationService $postDecorationService,
	) {
		parent::__construct($request, $userSession, $logger, $accountService, $clientService);
	}

	/** Places whose name begins with what was typed. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonymousRateLimit(limit: 120, period: 60)]
	#[UserRateLimit(limit: 120, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/places/search')]
	public function search(string $q = '', int $limit = PlacesRequest::SEARCH_LIMIT): DataResponse {
		try {
			$this->initViewer(['read']);

			return new DataResponse($this->placeService->search($q, $limit), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** One place by id. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/places/{id}', requirements: ['id' => '\\d+'])]
	public function show(int $id): DataResponse {
		try {
			$this->initViewer(['read']);

			return new DataResponse($this->placeService->byId($id), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}
	/**
	 * The public posts taken at a place, for its page. A viewer is optional and
	 * only marks their own bookmarks and favourites.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/places/{id}/statuses', requirements: ['id' => '\\d+'])]
	public function statuses(int $id, int $limit = 20, int|string $max_id = 0): DataResponse {
		try {
			$viewer = $this->optionalViewer(['read']);

			$posts = $this->placeService->posts($id, $limit, $max_id);
			$this->postDecorationService->decorate($posts, $viewer?->getId() ?? '');

			return new DataResponse($posts, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}
}
