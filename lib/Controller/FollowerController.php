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
 * Removing somebody from your own followers, which is not blocking them
 * (`POST /api/v1/accounts/{id}/remove_from_followers`): only their follow of
 * the viewer ends, and they are told so.
 *
 * The activity is a `Reject{Follow}`, the same one sent for a refused follow
 * request, so this is `FollowService::rejectFollowRequest()` applied to an
 * accepted follow. `#[PublicPage]` with `#[NoCSRFRequired]` for bearer-token
 * clients; the route requires a viewer itself.
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
	 * Drops the inbound follow from `{id}` and federates the `Reject`. An
	 * account that does not follow the viewer is not an error, so a retry gets
	 * the same answer; the relationship is read back after the write.
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
