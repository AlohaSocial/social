<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\InsufficientScopeException;
use OCA\Social\Middleware\AnonymousRateLimit;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\MemoriesService;
use OCA\Social\Service\PostDecorationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IDateTimeZone;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What the reader wrote on this day in years gone by.
 *
 * Client-API routes for the frontend's session and mobile clients' tokens.
 * Reading asks for `read:statuses`, since the answer includes the caller's
 * followers-only and direct posts. There is no account parameter: the query
 * does not filter by audience because it only ever reads the caller's own
 * posts, so it must stay that way.
 */
class MemoriesController extends ClientApiController {
	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		AccountService $accountService,
		ClientService $clientService,
		private MemoriesService $memoriesService,
		private PostDecorationService $postDecorationService,
		private IDateTimeZone $dateTimeZone,
	) {
		parent::__construct($request, $userSession, $logger, $accountService, $clientService);
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonymousRateLimit(limit: 60, period: 3600)]
	#[UserRateLimit(limit: 60, period: 3600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/memories/on_this_day')]
	public function onThisDay(): DataResponse {
		try {
			$this->initViewer(['read:statuses']);
			$posts = $this->memoriesService->onThisDay($this->viewer(), $this->dateTimeZone->getTimeZone());

			// a memory is drawn by the same card as a timeline post
			$this->postDecorationService->decorate($posts, $this->viewer()->getId());

			return new DataResponse($posts, Http::STATUS_OK);
		} catch (ClientNotFoundException|InsufficientScopeException $e) {
			return $this->error($e);
		} catch (Throwable $e) {
			$this->logger->warning('could not read the memories', [
				'userId' => $this->viewer?->getUserId(), 'exception' => $e,
			]);

			return new DataResponse(
				['error' => 'could not read the memories'], Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}

	/**
	 * How the reader's week went, if they asked to be told; otherwise
	 * `enabled: false` and nothing is computed.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonymousRateLimit(limit: 60, period: 3600)]
	#[UserRateLimit(limit: 60, period: 3600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/memories/recap')]
	public function recap(): DataResponse {
		try {
			$this->initViewer(['read:statuses']);
			$viewer = $this->viewer();

			return new DataResponse(
				$this->memoriesService->recap($viewer, $viewer->getUserId()), Http::STATUS_OK
			);
		} catch (ClientNotFoundException|InsufficientScopeException $e) {
			return $this->error($e);
		} catch (Throwable $e) {
			$this->logger->warning('could not read the recap', [
				'userId' => $this->viewer?->getUserId(), 'exception' => $e,
			]);

			return new DataResponse(
				['error' => 'could not read the recap'], Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}

	/** Turns the weekly recap on or off for the caller. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/memories/recap')]
	public function setRecap(bool $enabled): DataResponse {
		try {
			$this->initViewer(['write:accounts']);
			$this->memoriesService->setRecapEnabled($this->viewer()->getUserId(), $enabled);

			return new DataResponse(['enabled' => $enabled], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}
}
