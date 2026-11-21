<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConversationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mastodon's conversations: the direct timeline as one row per exchange, which
 * is what Tusky, Ivory, Ice Cubes and Phanpy show direct messages from (see
 * ConversationService for what a conversation is here).
 *
 * `#[PublicPage]` with `#[NoCSRFRequired]` because a Mastodon client has a
 * bearer token, not a session; every route requires a viewer itself.
 */
class ConversationController extends ClientApiController {

	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		AccountService $accountService,
		ClientService $clientService,
		private ConversationService $conversationService,
	) {
		parent::__construct($request, $userSession, $logger, $accountService, $clientService);
	}

	/**
	 * A page of the viewer's conversations, newest message first, with the
	 * `Link` header masto.js reads its cursor from. The cursor is a message
	 * nid: a conversation id (the thread root) does not move when a message
	 * arrives, so it cannot page.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/conversations')]
	public function index(
		int $limit = ConversationService::LIMIT,
		int|string $max_id = 0,
		int|string $min_id = 0,
		int|string $since_id = 0,
	): DataResponse {
		try {
			$this->initViewer(['read:statuses']);

			$page = $this->conversationService->getPage(
				$this->viewer, $limit, $max_id, $min_id, $since_id
			);

			return $this->paged($page['conversations'], $page['next'], $page['prev']);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Marks every conversation read, up to its newest message: the Direct
	 * messages page shows the messages themselves, so viewing it is reading
	 * them. This app's own route; it needs the scope `read` and `delete` on
	 * one conversation need.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/conversations/read_all')]
	public function readAll(): DataResponse {
		try {
			$this->initViewer(['write:conversations']);

			return new DataResponse(
				['count' => $this->conversationService->markAllRead($this->viewer)],
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * How many conversations have something unread, for the Direct messages
	 * badge: five unread messages in one exchange are one thing to read. This
	 * app's own route, cheap enough to poll.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/conversations/unread_count')]
	public function unreadCount(): Response {
		try {
			$this->initViewer(['read:statuses']);

			// asked on every push and poll, and nearly always unchanged
			return Revalidation::byTag(
				$this->request,
				$this->conversationService->unreadTag($this->viewer),
				fn (): array => ['count' => $this->conversationService->countUnread($this->viewer)]
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Marks the conversation read, and answers with it — which is what
	 * Mastodon returns, so a client redraws the row from the answer rather
	 * than guessing what it now looks like.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	// `/api/v1/conversations/{id}` cannot read this as a conversation named
	// "4/read" because `{id}` matches one segment, and it has to stay that way.
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/conversations/{id}/read')]
	public function read(int|string $id): DataResponse {
		try {
			$this->initViewer(['write:conversations']);

			return new DataResponse(
				$this->conversationService->markRead($this->viewer, $id), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Removes the conversation from the list and answers `{}`, as Mastodon
	 * does. The messages themselves are not deleted — neither here nor there —
	 * and a later message in the same thread brings the conversation back.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/conversations/{id}')]
	public function delete(int|string $id): DataResponse {
		try {
			$this->initViewer(['write:conversations']);
			$this->conversationService->remove($this->viewer, $id);

			return new DataResponse([], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * A page with the `Link` header masto.js reads its cursor from — without
	 * it Elk and Phanpy show the first page and stop.
	 */
	private function paged(array $items, int|string $next, int|string $prev): DataResponse {
		$response = new DataResponse($items, Http::STATUS_OK);

		$links = [];
		if ($next > 0) {
			$links[] = '<' . $this->pageUrl(['max_id' => (string)$next]) . '>; rel="next"';
		}
		if ($prev > 0) {
			$links[] = '<' . $this->pageUrl(['min_id' => (string)$prev]) . '>; rel="prev"';
		}

		if ($links !== []) {
			$response->addHeader('Link', implode(', ', $links));
		}

		return $response;
	}
}
