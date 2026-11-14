<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConversationService;
use OCA\Social\Service\NotificationInboxService;
use OCA\Social\Service\NotificationPolicyService;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The three counts the sidebar draws, in one answer: the unread notifications
 * (`/api/v1/notifications/unread_count`), the held senders (the notification
 * policy's `summary`) and the unread conversations
 * (`/api/v1/conversations/unread_count`).
 *
 * One request where a poll or a push made three, under one `ETag` built from
 * the tags of all three, so an unchanged poll is a single `304`. The three
 * routes it combines stay as they are, for Mastodon clients and older pages.
 */
class BadgeController extends ClientApiController {
	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		AccountService $accountService,
		ClientService $clientService,
		private NotificationInboxService $notificationInboxService,
		private NotificationPolicyService $notificationPolicyService,
		private ConversationService $conversationService,
	) {
		parent::__construct($request, $userSession, $logger, $accountService, $clientService);
	}

	/**
	 * `{unread_notifications, pending_requests, notice, unread_conversations}`,
	 * each counted as the route it comes from counts it.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/social/badges')]
	public function index(): Response {
		try {
			$this->initViewer(['read']);
			$viewer = $this->viewer();
			$userId = $viewer->getUserId();

			$tag = md5(
				$this->notificationInboxService->badgeTag($viewer, $userId)
				. '|' . $this->conversationService->unreadTag($viewer)
			);

			return Revalidation::byTag(
				$this->request,
				$tag,
				fn (): array => [
					'unread_notifications' => $this->notificationInboxService->unreadCount($viewer, $userId),
					'pending_requests' => $this->notificationInboxService->pending($viewer)['requests'],
					'notice' => $this->notificationPolicyService->of($userId)->hasNotice(),
					'unread_conversations' => $this->conversationService->countUnread($viewer),
				]
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}
}
