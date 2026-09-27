<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Relationship;
use OCA\Social\Service\AccountRelationService;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\DomainBlockService;
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
 * What one account decides about another, beside following, blocking and
 * muting it: the instances it will not hear from, the note it keeps about an
 * account, and the accounts it features on its profile.
 *
 * They share a controller because they share an access model, and it is the
 * strictest one in this API: every row involved belongs to exactly one account,
 * is only ever read for that account, and is never federated. Nobody — not the
 * account a note is about, not the instance that was blocked — may see any of
 * it, so every route here resolves the viewer first and scopes every read and
 * every write by them.
 *
 * Not `RelationshipService`, which is blocks and mutes and does federate what
 * Mastodon federates. Nothing here leaves the server.
 *
 * `#[PublicPage]` with `#[NoCSRFRequired]`, like `ApiController`,
 * `TagController` and `ListController`, and for the same reason: a Mastodon
 * client authenticates with a bearer token and has no Nextcloud session or CSRF
 * token to present, so `#[NoAdminRequired]` would refuse every real caller
 * before the handler ran. Every route then requires a viewer itself — no token,
 * no session, 401 — so nothing is public in fact.
 */
class RelationController extends ClientApiController {
	/** What Mastodon defaults and caps a page of featured accounts at. */
	private const ENDORSEMENTS_LIMIT = 40;
	private const MAX_ENDORSEMENTS_LIMIT = 80;

	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		AccountService $accountService,
		private CacheActorService $cacheActorService,
		ClientService $clientService,
		private FollowService $followService,
		private AccountRelationService $accountRelationService,
		private DomainBlockService $domainBlockService,
	) {
		parent::__construct($request, $userSession, $logger, $accountService, $clientService);
	}

	/**
	 * The instances the viewer has blocked, as a flat list of domains — which
	 * is what Mastodon answers here, not entities.
	 *
	 * No `Link` header, like `/api/v1/blocks` and `/api/v1/mutes`: the route
	 * takes no cursor, and a header advertising the page just sent is one a
	 * client scrolls for ever.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/domain_blocks')]
	public function domainBlocks(int $limit = DomainBlockService::LIMIT): DataResponse {
		try {
			$this->initViewer(['read:blocks']);
			$limit = max(1, min(DomainBlockService::MAX_LIMIT, $limit));

			return new DataResponse($this->domainBlockService->getBlocked($this->viewer, $limit), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Blocks an instance for the viewer alone, and answers `{}` as Mastodon
	 * does. Blocking one that is already blocked is not an error.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/domain_blocks')]
	public function blockDomain(string $domain = ''): DataResponse {
		try {
			$this->initViewer(['write:blocks']);
			$this->domainBlockService->block($this->viewer, $domain);

			return new DataResponse([], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** Unblocking an instance that was not blocked answers `{}` as well. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/domain_blocks')]
	public function unblockDomain(string $domain = ''): DataResponse {
		try {
			$this->initViewer(['write:blocks']);
			$this->domainBlockService->unblock($this->viewer, $domain);

			return new DataResponse([], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Keeps the viewer's private note about an account and answers the updated
	 * relationship, which is where a client reads the note back from.
	 *
	 * Mastodon calls the parameter `comment`, and an empty one clears the note
	 * rather than storing a blank.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/accounts/{id}/note', requirements: ['id' => '.+'])]
	public function note(string $id, string $comment = ''): DataResponse {
		try {
			$this->initViewer(['write:accounts']);
			$target = $this->cacheActorService->resolve($id, true);
			$this->accountRelationService->setNote($this->viewer, $target, $comment);

			return new DataResponse($this->relationship($target), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** Features the account on the viewer's profile. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/accounts/{id}/pin', requirements: ['id' => '.+'])]
	public function pin(string $id): DataResponse {
		try {
			$this->initViewer(['write:accounts']);
			$target = $this->cacheActorService->resolve($id, true);
			$this->accountRelationService->endorse($this->viewer, $target);

			return new DataResponse($this->relationship($target, true), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** Stops featuring it. Unfeaturing one that was not featured is not an error. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/accounts/{id}/unpin', requirements: ['id' => '.+'])]
	public function unpin(string $id): DataResponse {
		try {
			$this->initViewer(['write:accounts']);
			$target = $this->cacheActorService->resolve($id, true);
			$this->accountRelationService->unendorse($this->viewer, $target);

			return new DataResponse($this->relationship($target, false), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The accounts the viewer features, as Account entities, newest first.
	 *
	 * The viewer's own and nobody else's: Mastodon serves another account's
	 * featured accounts from its profile, not from here.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/endorsements')]
	public function endorsements(int $limit = self::ENDORSEMENTS_LIMIT): DataResponse {
		try {
			$this->initViewer(['read:accounts']);
			$limit = max(1, min(self::MAX_ENDORSEMENTS_LIMIT, $limit));

			$accounts = $this->accountRelationService->getEndorsed($this->viewer, $limit);
			foreach ($accounts as $account) {
				$account->setExportFormat(ACore::FORMAT_LOCAL);
			}

			return new DataResponse($accounts, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The relationship a client is handed back after a write here.
	 *
	 * Decorated on the way out rather than left to `FollowService::
	 * generateRelationship()`: the caller of one of these routes has just
	 * changed one of these fields and must be told what it is now, whether or
	 * not the relationship builder has been taught to read them. Decorating a
	 * relationship twice reaches the same answer.
	 *
	 * @param bool|null $endorsed what this request made of it, when it is this
	 *                            request that decided — no query can answer it
	 *                            better than the write that just happened
	 */
	private function relationship(Person $target, ?bool $endorsed = null): Relationship {
		$this->followService->setViewer($this->viewer);
		$relationship = $this->followService->getRelationshipWith($target);

		$this->accountRelationService->decorate(
			$relationship, $this->viewer->getId(), $target->getId()
		);
		$relationship->setEndorsed(
			$endorsed ?? $this->accountRelationService->isEndorsing($this->viewer->getId(), $target->getId())
		);

		return $relationship;
	}
}
