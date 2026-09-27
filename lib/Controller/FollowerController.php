<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\InsufficientScopeException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\FollowService;
use OCP\AppFramework\Controller;
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
class FollowerController extends Controller {
	private string $bearer = '';
	private ?SocialClient $client = null;
	private ?Person $viewer = null;

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private AccountService $accountService,
		private CacheActorService $cacheActorService,
		private ClientService $clientService,
		private FollowService $followService,
	) {
		parent::__construct(Application::APP_ID, $request);

		$authHeader = trim($this->request->getHeader('Authorization'));
		if (strpos($authHeader, ' ')) {
			[$authType, $authToken] = explode(' ', $authHeader);
			if (strtolower($authType) === 'bearer') {
				$this->bearer = $authToken;
			}
		}
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

	/**
	 * Resolves the viewer from the bearer token, or from the Nextcloud session
	 * when there is none — the same order `ApiController` uses, because the
	 * same clients call both.
	 *
	 * @param string[] $scopes any one of which satisfies a bearer token
	 *
	 * @throws ClientNotFoundException there is nobody to answer for
	 * @throws InsufficientScopeException the token is fine, its grant is not
	 */
	private function initViewer(array $scopes): void {
		try {
			$userId = $this->currentSession($scopes);
			$this->viewer = $this->accountService->getActorFromUserId($userId);
		} catch (InsufficientScopeException $e) {
			throw $e;
		} catch (Exception $e) {
			// a missing, stale or made-up token is ordinary internet noise and
			// is answered with a 401, not logged as a fault
			$this->logger->debug('[FollowerController] no usable credentials', [
				'exception' => $e->getMessage(),
			]);

			throw new ClientNotFoundException('the access_token was revoked');
		}
	}

	/**
	 * @param string[] $scopes
	 *
	 * @throws ClientNotFoundException
	 * @throws InsufficientScopeException
	 */
	private function currentSession(array $scopes): string {
		if ($this->bearer !== '') {
			$this->client = $this->clientService->getFromToken($this->bearer);
			$this->checkTokenScope($scopes);

			return $this->client->getAuthUserId();
		}

		$user = $this->userSession->getUser();
		if ($user !== null && $this->request->passesCSRFCheck()) {
			return $user->getUID();
		}

		throw new ClientNotFoundException('userId not defined');
	}

	/**
	 * A granular scope is satisfied by itself or by the broad scope that
	 * contains it: `write:follows` by `write:follows` or by `write`, and by
	 * nothing else — not by `write:statuses`, which is a grant to post and not
	 * a grant to change who may read what the token's owner posts.
	 *
	 * @param string[] $accepted
	 *
	 * @throws InsufficientScopeException
	 */
	private function checkTokenScope(array $accepted): void {
		foreach ($accepted as $scope) {
			$broad = strstr($scope, ':', true);
			$broad = ($broad === false) ? $scope : $broad;

			foreach ($this->client->getAuthScopes() as $granted) {
				if ($granted === $scope || $granted === $broad) {
					return;
				}
			}
		}

		throw new InsufficientScopeException(
			'token scope does not allow this request (needs ' . implode(' or ', $accepted) . ')'
		);
	}

	/**
	 * A failure as a Mastodon client can act on it: `{"error": "..."}` with a
	 * status that says what to do about it. An unrecognised failure is a bug
	 * on this side, so it answers 500 and its message is not sent on — this is
	 * a `#[PublicPage]` route, and echoing getMessage() publishes whatever the
	 * failure happened to name.
	 */
	private function error(Throwable $e): DataResponse {
		if ($e instanceof InsufficientScopeException) {
			return new DataResponse(
				['error' => $e->getMessage()],
				Http::STATUS_FORBIDDEN,
				['WWW-Authenticate' => 'Bearer error="insufficient_scope"']
			);
		}

		if ($e instanceof ClientNotFoundException) {
			$message = trim($e->getMessage());

			return new DataResponse(
				['error' => ($message === '') ? 'the access_token is invalid' : $message],
				Http::STATUS_UNAUTHORIZED,
				['WWW-Authenticate' => 'Bearer error="invalid_token"']
			);
		}

		if ($e instanceof ItemNotFoundException || $e instanceof CacheActorDoesNotExistException) {
			return new DataResponse(['error' => 'Record not found'], Http::STATUS_NOT_FOUND);
		}

		$this->logger->error('[FollowerController] unexpected failure answering the client API', [
			'exception' => $e,
			'route' => (string)$this->request->getParam('_route', ''),
		]);

		return new DataResponse(
			['error' => 'internal server error'], Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}
}
