<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\PlacesRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Place;

/**
 * Where a post was taken. Nothing here geocodes: sending somebody's location
 * to a third party as they decide whether to publish it is what Exif
 * stripping prevents. A place is one this instance has seen, or one the
 * client names outright with coordinates it already had.
 */
class PlaceService {
	public function __construct(
		private PlacesRequest $placesRequest,
		private StreamRequest $streamRequest,
	) {
	}

	/**
	 * Places whose name begins with what was typed, out of what this instance
	 * has already seen.
	 *
	 * @return Place[]
	 */
	public function search(string $term, int $limit = PlacesRequest::SEARCH_LIMIT): array {
		return $this->placesRequest->search($term, $limit);
	}

	/** @throws ItemNotFoundException */
	public function byId(int $id): Place {
		return $this->placesRequest->getById($id);
	}

	/**
	 * The public posts taken at a place, newest first, for the caller to dress
	 * (`PostDecorationService`). Public only, whoever asks, since the place
	 * page is public.
	 *
	 * @return Stream[]
	 */
	public function posts(int $placeId, int $limit = 20, int|string $maxId = '0'): array {
		// a 404 for a place nobody named, before any post is read
		$this->placesRequest->getById($placeId);

		$posts = $this->streamRequest->getPublicByPlace($placeId, max(1, min(40, $limit)), max(0, $maxId));
		foreach ($posts as $post) {
			$post->setExportFormat(ACore::FORMAT_LOCAL);
		}

		return $posts;
	}

	/**
	 * The place a client asked a post to carry, or null for "nowhere". Named
	 * by id (from the search route) or outright with a name and optional
	 * coordinates. An unknown id means nowhere rather than refusing the post.
	 */
	public function resolve(int $placeId, string $name, string $country, string $lat, string $lon): ?Place {
		if ($placeId > 0) {
			try {
				return $this->placesRequest->getById($placeId);
			} catch (ItemNotFoundException $e) {
				return null;
			}
		}

		$place = (new Place())->setName($name)->setCountry($country)->setCoordinates($lat, $lon);
		if ($place->getName() === '') {
			return null;
		}

		return $this->placesRequest->findOrCreate($place);
	}

	/**
	 * Fills in the place of every post on a page, in one query, as
	 * `LinkPreviewService::attachCards()` does, rather than joining
	 * `social_place` into every timeline query.
	 *
	 * @param Stream[] $posts
	 */
	public function attachPlaces(array $posts): void {
		$ids = [];
		foreach ($posts as $post) {
			if ($post instanceof Stream && $post->getPlaceId() > 0) {
				$ids[] = $post->getPlaceId();
			}
		}

		if ($ids === []) {
			return;
		}

		// each place once, however many posts on the page share it
		$places = $this->placesRequest->getByIds(array_values(array_unique($ids)));
		foreach ($posts as $post) {
			if (!$post instanceof Stream) {
				continue;
			}

			$place = $places[$post->getPlaceId()] ?? null;
			if ($place !== null) {
				$post->setPlace($place);
			}
		}
	}
}
