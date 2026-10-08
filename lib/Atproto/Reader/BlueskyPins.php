<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\Interfaces\Activity\FeaturedCollection;
use OCA\Social\Model\ActivityPub\Actor\Person;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The post a Bluesky account pinned (its profile's `pinnedPost`), as a pin
 * here: kept the way a Fediverse account's pins are, so its profile here
 * shows it pinned. The post is fetched from the AppView when it is not
 * here yet.
 */
class BlueskyPins {
	public function __construct(
		private PostStore $postStore,
		private FeaturedCollection $featured,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param string $uri the pinned post's `at://` URI, '' for none
	 */
	public function keep(Person $actor, string $uri): void {
		$wanted = [];
		$postId = $uri === '' ? '' : BlueskyIds::postIdOfUri($uri);
		try {
			if ($postId !== '' && ($this->postStore->isKnown($postId) || ($this->postStore->storeByUri($uri) && $this->postStore->isKnown($postId)))) {
				$wanted[] = $postId;
			}
			$this->featured->match($actor, $wanted);
		} catch (Throwable $e) {
			$this->logger->info('Pinned post of a Bluesky account not kept', ['actor' => $actor->getId(), 'exception' => $e]);
		}
	}
}
