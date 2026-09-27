<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\FollowService;
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
 * Removing somebody from your own followers, which is not blocking them.
 *
 * Mastodon's `POST /api/v1/accounts/{id}/remove_from_followers` ends one
 * direction of a relationship: the account stops receiving the viewer's posts
 * and is told so, while everything else — whether the viewer follows *them*,
 * whether either has blocked the other — is left exactly as it was. A block
 * would do this too and a great deal more, and would be visible to the other
 * side as a block.
 *
 * The activity that says so is a `Reject{Follow}`, which is what this app
 * already sends when a pending follow request is refused: to the other server,
 * a follow that is rejected and a follow that is withdrawn after being
 * accepted are the same statement — "this follow is not in force". So this
 * route is `FollowService::rejectFollowRequest()` applied to an accepted row
 * rather than a pending one, and there is one federating path for both.
 *
 * A route of its own rather than another method on `ApiController` for the
 * reason `ListController` and `TagController` are their own controllers: the
 * scope rule here is the stricter one (see checkTokenScope()).
 *
 * `#[PublicPage]` with `#[NoCSRFRequired]`, like `ApiController`: a Mastodon
 * client authenticates with a bearer token and has no Nextcloud session or
 * CSRF token to present. The route requires a viewer itself, so nothing is
 * public in fact.
 */
class FollowerController extends ClientApiController {

	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		AccountService $accountService,
		private CacheActorService $cacheActorService,
		ClientService $clientService,
		private FollowService $followService,
	) {
		parent::__construct($request, $userSession, $logger, $accountService, $clientService);
	}

	/**
	 * Drops the inbound follow from `{id}` and federates the `Reject`.
	 *
	 * An account that does not follow the viewer is not an error: Mastodon
	 * answers the relationship either way, and a client that lost the answer
	 * and retried must get the same one rather than a 404. The relationship is
	 * read back after the write, so what the client redraws the button from is
	 * the state that now holds and not an assumption about it.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/accounts/{id}/remove_from_followers', requirements: ['id' => '.+'])]
	public function remove(string $id): DataResponse {
		try {
			$this->initViewer(['write:follows', 'follow']);
			$follower = $this->cacheActorService->resolve($id, true);

			$this->followService->setViewer($this->viewer);

			try {
				$this->followService->rejectFollowRequest($follower);
				// the viewer has one follower fewer, and the count their own
				// profile reports is cached — `accountFollow` refreshes it on
				// the other side of the same relationship for the same reason
				$this->accountService->bumpActorCount($this->viewer->getId(), 'count_followers', -1);
			} catch (FollowNotFoundException $e) {
				// not a follower, so there is nothing to remove and nothing to
				// tell the other server about
			}

			return new DataResponse(
				$this->followService->getRelationshipWith($follower), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}
}
