<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Middleware\AnonymousRateLimit;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\Client\Collection;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\CollectionService;
use OCA\Social\Service\PostDecorationService;
use OCP\AppFramework\Http;
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
 * Collections: the albums an account curates out of its own posts, on
 * Pixelfed's routes, as Mastodon has no equivalent.
 *
 * A public collection is readable by anybody, signed-out visitors included.
 * Writes resolve the collection through `CollectionService::own()`, which
 * puts the owner in the SQL, so another's collection is a 404. Routes are
 * `#[PublicPage]` with `#[NoCSRFRequired]` for bearer-token clients; the
 * write routes require a viewer themselves.
 */
class CollectionController extends ClientApiController {
	/** What one page of a collection's posts holds. */
	private const ITEMS_LIMIT = 40;

	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		AccountService $accountService,
		ClientService $clientService,
		private CacheActorService $cacheActorService,
		private CollectionService $collectionService,
		private PostDecorationService $postDecorationService,
	) {
		parent::__construct($request, $userSession, $logger, $accountService, $clientService);
	}

	/** Every collection the viewer owns. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/collections')]
	public function index(): DataResponse {
		try {
			$this->initViewer(['read:collections']);

			$collections = [];
			foreach ($this->collectionService->forProfile($this->viewer(), $this->viewer()) as $collection) {
				$collections[] = $this->collectionService->withPreview($collection);
			}

			return new DataResponse($collections, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonymousRateLimit(limit: 30, period: 60)]
	#[UserRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/collections')]
	public function create(
		string $title = '',
		string $description = '',
		string $visibility = Collection::DEFAULT_VISIBILITY,
	): DataResponse {
		try {
			$this->initViewer(['write:collections']);

			return new DataResponse(
				$this->collectionService->create($this->viewer(), $title, $description, $visibility),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * One collection, as the caller may see it; public ones also to
	 * signed-out visitors.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/collections/{id}', requirements: ['id' => '\\d+'])]
	public function show(int $id): DataResponse {
		try {
			$viewer = $this->optionalViewer(['read:collections']);
			$collection = $this->collectionService->readable($viewer, $id);

			return new DataResponse(
				$this->collectionService->withPreview($collection), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/collections/{id}', requirements: ['id' => '\\d+'])]
	public function update(
		int $id,
		?string $title = null,
		?string $description = null,
		?string $visibility = null,
	): DataResponse {
		try {
			$this->initViewer(['write:collections']);

			return new DataResponse(
				$this->collectionService->update($this->viewer(), $id, $title, $description, $visibility),
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/collections/{id}', requirements: ['id' => '\\d+'])]
	public function destroy(int $id): DataResponse {
		try {
			$this->initViewer(['write:collections']);
			$this->collectionService->delete($this->viewer(), $id);

			return new DataResponse([], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** The posts of a collection, in the owner's order. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/collections/{id}/items', requirements: ['id' => '\\d+'])]
	public function items(int $id, int $limit = self::ITEMS_LIMIT, int $offset = 0): DataResponse {
		try {
			$viewer = $this->optionalViewer(['read:collections']);
			$collection = $this->collectionService->readable($viewer, $id);

			$posts = $this->collectionService->posts(
				$collection, max(1, min($limit, self::ITEMS_LIMIT)), max(0, $offset)
			);
			foreach ($posts as $post) {
				$post->setExportFormat(ACore::FORMAT_LOCAL);
			}
			$this->postDecorationService->decorate($posts, $viewer?->getId() ?? '');

			return new DataResponse($posts, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** Adds one of the viewer's own posts, by its numeric id. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonymousRateLimit(limit: 60, period: 60)]
	#[UserRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/collections/{id}/items', requirements: ['id' => '\\d+'])]
	public function addItem(int $id, int|string $status_id = 0): DataResponse {
		try {
			$this->initViewer(['write:collections']);

			return new DataResponse(
				$this->collectionService->addPost($this->viewer(), $id, $status_id), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(
		verb: 'DELETE',
		url: '/api/v1/collections/{id}/items/{status_id}',
		requirements: ['id' => '\\d+', 'status_id' => '\\d+']
	)]
	public function removeItem(int $id, int|string $status_id): DataResponse {
		try {
			$this->initViewer(['write:collections']);

			return new DataResponse(
				$this->collectionService->removePost($this->viewer(), $id, $status_id), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The collections of an account, as the caller may see them.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/accounts/{account_id}/collections')]
	public function byAccount(string $account_id): DataResponse {
		try {
			$viewer = $this->optionalViewer(['read:collections']);
			$owner = $this->cacheActorService->resolve($account_id);

			$collections = [];
			foreach ($this->collectionService->forProfile($viewer, $owner) as $collection) {
				$collections[] = $this->collectionService->withPreview($collection);
			}

			return new DataResponse($collections, Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}
}
