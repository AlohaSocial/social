<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Object;

use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Interfaces\Activity\AbstractActivityPubInterface;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Story;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\StoryService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A story (a short, in the UI) from another server: Pixelfed's `Add` to the
 * author's followers, handled by `save()`, and `Delete`, handled by
 * `activity()`. `StoryService` keeps it only when somebody here follows the
 * author.
 */
class StoryInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	public function __construct(
		private StoryService $storyService,
		private CacheActorService $cacheActorService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * A story handed over by the `Add` that carried it.
	 */
	#[\Override]
	public function save(ACore $item): void {
		if (!$item instanceof Story) {
			return;
		}

		// the story's id and its author must come from the same server as the
		// activity: otherwise any instance could put a story on any account
		$item->checkOrigin($item->getId());
		$item->checkOrigin($item->getAttributedTo());

		try {
			$author = $this->cacheActorService->getFromId($item->getAttributedTo());
			$this->storyService->receive($item, $author);
		} catch (Throwable $e) {
			// unfollowed author, already expired, or media unavailable: not
			// kept, but not a delivery to refuse either
			$this->logger->debug('an incoming story was not kept', [
				'story' => $item->getId(), 'reason' => $e->getMessage(),
			]);
		}
	}

	/**
	 * The `Delete` that withdraws one.
	 */
	#[\Override]
	public function activity(ACore $activity, ACore $item): void {
		if ($activity->getType() !== 'Delete') {
			return;
		}

		$activity->checkOrigin($activity->getActorId());
		$this->storyService->withdrawn($item->getId(), $activity->getActorId());
	}

	/**
	 * What a `Delete` naming only an id is looked up by.
	 *
	 * @throws ItemNotFoundException
	 */
	#[\Override]
	public function getItemById(string $id): ACore {
		$story = $this->storyService->bySourceId($id);

		$item = new Story();
		$item->setId($story->getSourceId());
		$item->setAttributedTo($story->getOwnerId());

		return $item;
	}
}
