<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use InvalidArgumentException;
use OCA\Social\AppInfo\Application;
use OCA\Social\Atproto\Reader\BlueskyDiscovery;
use OCA\Social\Atproto\Reader\BlueskyFeeds;
use OCA\Social\Atproto\Reader\StarterPacks;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\LinkPreviewService;
use OCA\Social\Service\PlaceService;
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
 * Bluesky's custom feeds and lists for the person signed in (§9.6): the
 * ones they keep, keeping or dropping one, Bluesky's suggestions, and a
 * page of one as a timeline; and starter packs, opened and followed.
 */
class AtprotoFeedsController extends Controller {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private AtprotoConfig $config,
		private AccountService $accountService,
		private BlueskyFeeds $feeds,
		private StarterPacks $starterPacks,
		private BlueskyDiscovery $discovery,
		private LinkPreviewService $linkPreviewService,
		private PlaceService $placeService,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * The feeds and lists the viewer keeps.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/social/bluesky/feeds')]
	public function saved(): DataResponse {
		return $this->answer(fn (Person $viewer): array => ['feeds' => $this->feeds->saved($viewer)]);
	}

	/**
	 * Keeps a feed or list, by its `at://` URI or its bsky.app address.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/social/bluesky/feeds')]
	public function add(string $feed): DataResponse {
		return $this->answer(fn (Person $viewer): array => ['feed' => $this->feeds->add($viewer, $feed)]);
	}

	/**
	 * Stops keeping a feed or list.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/social/bluesky/feeds')]
	public function remove(string $uri): DataResponse {
		return $this->answer(function (Person $viewer) use ($uri): array {
			$this->feeds->remove($viewer, $uri);

			return [];
		});
	}

	/**
	 * The feeds Bluesky suggests to the viewer.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/social/bluesky/feeds/suggested')]
	public function suggested(): DataResponse {
		return $this->answer(fn (Person $viewer): array => ['feeds' => $this->feeds->suggested($viewer)]);
	}

	/**
	 * A page of a feed or list as statuses, in the feed's own order; the
	 * next page is the one after the post of `max_id`.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/timelines/bluesky')]
	public function timeline(string $feed, int $limit = 20, string $max_id = ''): DataResponse {
		return $this->answer(function (Person $viewer) use ($feed, $limit, $max_id): array {
			$posts = $this->feeds->page($viewer, $feed, $max_id, $limit);
			$this->linkPreviewService->attachCards($posts);
			$this->placeService->attachPlaces($posts);

			return $posts;
		});
	}

	/**
	 * A starter pack — who is in it, which feeds come with it — by its
	 * bsky.app address or `at://` URI.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/social/bluesky/starter-pack')]
	public function starterPack(string $pack): DataResponse {
		return $this->answer(fn (Person $viewer): array => ['pack' => $this->starterPacks->read($viewer, $pack)]);
	}

	/**
	 * Follows members of a starter pack, a batch at a time, and keeps its
	 * feeds when asked to.
	 *
	 * @param string[] $dids
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/social/bluesky/starter-pack/follow')]
	public function followStarterPack(string $pack, array $dids = [], bool $feeds = false): DataResponse {
		return $this->answer(fn (Person $viewer): array => $this->starterPacks->follow($viewer, $pack, array_values(array_filter($dids, 'is_string')), $feeds));
	}

	/**
	 * The topics trending on Bluesky, each a feed to read here or a search.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/social/bluesky/trends')]
	public function trends(): DataResponse {
		return $this->answer(fn (Person $viewer): array => ['trends' => $this->discovery->trends()]);
	}

	/**
	 * The accounts Bluesky suggests to the viewer.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/social/bluesky/suggestions')]
	public function suggestions(): DataResponse {
		return $this->answer(fn (Person $viewer): array => ['accounts' => $this->discovery->suggestions($viewer)]);
	}

	/**
	 * @param callable(Person): array $work
	 */
	private function answer(callable $work): DataResponse {
		$user = $this->userSession->getUser();
		if (!$this->config->isEnabled() || $user === null) {
			return new DataResponse(['error' => 'Bluesky is not enabled on this server'], Http::STATUS_NOT_FOUND);
		}
		try {
			return new DataResponse($work($this->accountService->getActorFromUserId($user->getUID())));
		} catch (InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->warning('Bluesky feeds request failed', ['exception' => $e]);

			return new DataResponse(['error' => 'Bluesky did not answer'], Http::STATUS_BAD_GATEWAY);
		}
	}
}
