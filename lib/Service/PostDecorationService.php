<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\MediaTagsRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Stream;

/**
 * What a client is shown on a post that is not part of the stored post: its
 * link preview, its place, its reaction bar, the people named in its photos
 * and, on a video posted here, how many times it was watched. Every route
 * serving posts to a client calls `decorate()`, with one query per kind for
 * the whole list; nothing federated does. Depends on nothing that depends on
 * `StreamService` or `MediaTagService`, which both call it.
 */
class PostDecorationService {
	public function __construct(
		private LinkPreviewService $linkPreviewService,
		private PlaceService $placeService,
		private ReactionSummaryService $reactionSummaryService,
		private MediaTagsRequest $mediaTagsRequest,
		private CacheActorService $cacheActorService,
		private RemoteFetchQueue $remoteFetchQueue,
		private ?VideoViewService $videoViewService = null,
	) {
	}

	/**
	 * @param Stream[] $posts
	 * @param string $viewerId the reader's actor id, which marks their own
	 *                         reactions as `me`; '' for an anonymous reader
	 */
	public function decorate(array $posts, string $viewerId = ''): void {
		if ($posts === []) {
			return;
		}

		$this->linkPreviewService->attachCards($posts);
		$this->placeService->attachPlaces($posts);
		$this->reactionSummaryService->attachReactions($posts, $viewerId);
		$this->attachTaggedPeople($posts);
		$this->videoViewService?->attach($posts);
	}

	/**
	 * The people named in each post's photos, for the client (on the wire they
	 * are `Mention` tags). Read from the actor cache only, in one query;
	 * somebody not cached is left out and fetched in the background
	 * (`RemoteFetchQueue`) for the next page.
	 *
	 * @param Stream[] $posts
	 */
	private function attachTaggedPeople(array $posts): void {
		$nids = [];
		foreach ($posts as $post) {
			if ($post->getNid() > 0) {
				$nids[] = (string)$post->getNid();
			}
		}

		$tags = $this->mediaTagsRequest->forStreams($nids);
		if ($tags === []) {
			return;
		}

		$wanted = [];
		foreach ($posts as $post) {
			foreach ($tags[$post->getNid()] ?? [] as $actorId) {
				$wanted[$actorId] = $actorId;
			}
		}

		$people = $this->cacheActorService->getCachedFromIds(array_values($wanted));
		foreach ($people as $person) {
			$person->setExportFormat(ACore::FORMAT_LOCAL);
		}
		$this->remoteFetchQueue->resolveActors(array_values(array_diff_key($wanted, $people)));

		foreach ($posts as $post) {
			$named = [];
			foreach ($tags[$post->getNid()] ?? [] as $actorId) {
				if (isset($people[$actorId])) {
					$named[] = $people[$actorId];
				}
			}
			$post->setTaggedPeople($named);
		}
	}
}
