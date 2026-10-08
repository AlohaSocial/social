<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Model\Client\Story;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\StoryService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
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
 * Stories: one picture that stops existing after a day.
 *
 * Pixelfed's routes, because these are Pixelfed's feature. Every one of them
 * requires a viewer -- there is no public story and so nothing here to answer a
 * signed-out caller with. Whether an account even *has* a story up is only told
 * to its followers, which is why "not allowed to see these" and "there are none"
 * are the same 404.
 */
class StoryController extends ClientApiController {
	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		AccountService $accountService,
		ClientService $clientService,
		private CacheActorService $cacheActorService,
		private StoryService $storyService,
	) {
		parent::__construct($request, $userSession, $logger, $accountService, $clientService);
	}

	/**
	 * The carousel: the viewer's own live stories, then those of the accounts
	 * they follow.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/stories/carousel')]
	public function carousel(): DataResponse {
		try {
			$this->initViewer(['read:stories']);

			return new DataResponse($this->storyService->carousel($this->viewer()), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** The viewer's own live stories, with the count of who has seen each. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/stories/self')]
	public function own(): DataResponse {
		try {
			$this->initViewer(['read:stories']);

			return new DataResponse(
				$this->storyService->forAccount($this->viewer(), $this->viewer()), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** Posts one of the viewer's own uploads as a story. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[UserRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/stories')]
	public function add(
		int $media_id = 0,
		string $caption = '',
		int $duration = Story::DEFAULT_DURATION,
	): DataResponse {
		try {
			$this->initViewer(['write:stories']);

			return new DataResponse(
				$this->storyService->add($this->viewer(), $media_id, $caption, $duration),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/stories/{id}', requirements: ['id' => '\\d+'])]
	public function destroy(int $id): DataResponse {
		try {
			$this->initViewer(['write:stories']);
			$this->storyService->delete($this->viewer(), $id);

			return new DataResponse([], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Marks one seen.
	 *
	 * Called as a client scrolls, so it is written to be called twice: a repeat
	 * is a no-op rather than a second view.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 300, period: 60)]
	#[UserRateLimit(limit: 300, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/stories/{id}/seen', requirements: ['id' => '\\d+'])]
	public function seen(int $id): DataResponse {
		try {
			$this->initViewer(['write:stories']);

			return new DataResponse($this->storyService->markSeen($this->viewer(), $id), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** The live stories of one account, if the viewer may see them. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/accounts/{account_id}/stories')]
	public function byAccount(string $account_id): DataResponse {
		try {
			$this->initViewer(['read:stories']);
			$owner = $this->cacheActorService->resolve($account_id);

			return new DataResponse(
				$this->storyService->forAccount($this->viewer(), $owner), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}
}
