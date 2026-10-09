<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\NotificationPolicyService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Who may send the viewer direct messages: everybody, the people they
 * follow, or nobody (`NotificationPolicyService::directMessagesFrom()`).
 */
class DirectMessageSettingsController extends ClientApiController {
	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		AccountService $accountService,
		ClientService $clientService,
		private NotificationPolicyService $notificationPolicyService,
	) {
		parent::__construct($request, $userSession, $logger, $accountService, $clientService);
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/social/direct_messages')]
	public function directMessages(): DataResponse {
		try {
			$this->initViewer(['read:accounts', 'read']);

			return new DataResponse(
				['from' => $this->notificationPolicyService->directMessagesFrom($this->viewer->getUserId())],
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** Changes who may, and answers with it. `from` is required. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'PATCH', url: '/api/v1/social/direct_messages')]
	public function directMessagesUpdate(mixed $from = null): DataResponse {
		try {
			$this->initViewer(['write:accounts', 'write']);
			if (!is_string($from)) {
				throw new InvalidResourceException('from must be all, following or none');
			}

			return new DataResponse(
				['from' => $this->notificationPolicyService->saveDirectMessagesFrom($this->viewer->getUserId(), $from)],
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}
}
