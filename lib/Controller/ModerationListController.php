<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ModerationList\ModerationListService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The lists of accounts to mute or block the viewer subscribes to
 * (`ModerationListService`): this app's own, for its settings.
 */
class ModerationListController extends Controller {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private AccountService $accountService,
		private ModerationListService $lists,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/social/moderation_lists')]
	public function index(): DataResponse {
		return new DataResponse($this->lists->list($this->userId()));
	}

	/**
	 * Subscribes to a list, named by its link, to mute or block everybody on
	 * it: the accounts are muted or blocked at once.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/social/moderation_lists')]
	public function subscribe(string $url = '', string $kind = 'mute'): DataResponse {
		try {
			return new DataResponse($this->lists->subscribe($this->accountService->getActorFromUserId($this->userId()), $url, $kind));
		} catch (InvalidActionException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->warning('Subscribing to a moderation list failed', ['exception' => $e]);

			return new DataResponse(['error' => 'The list could not be read'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Ends a subscription: the mutes or blocks the list made are taken back.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/social/moderation_lists')]
	public function unsubscribe(string $uri = ''): DataResponse {
		try {
			$this->lists->unsubscribe($this->accountService->getActorFromUserId($this->userId()), $uri);

			return new DataResponse($this->lists->list($this->userId()));
		} catch (Throwable $e) {
			$this->logger->warning('Unsubscribing from a moderation list failed', ['exception' => $e]);

			return new DataResponse(['error' => 'The subscription could not be ended'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	private function userId(): string {
		return (string)$this->userSession->getUser()?->getUID();
	}
}
