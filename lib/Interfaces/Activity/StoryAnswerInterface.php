<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Activity;

use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\StoryInteraction;
use OCA\Social\Model\ActivityPub\Activity\View;
use OCA\Social\Service\StoryInteractionService;
use OCA\Social\Service\ViewCountService;

/**
 * The ways a peer answers a short (a story in the code): `View`,
 * `Story:Reaction`, `Story:Reply`; also PeerTube's `View` of a video.
 *
 * The origin check is on the actor, as an answer names its short only by
 * address; the short itself is checked where it is read (ours, live, and
 * followed by whoever answers).
 */
class StoryAnswerInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	public function __construct(
		private StoryInteractionService $storyInteractionService,
		private ViewCountService $viewCountService,
	) {
	}

	/**
	 * @throws InvalidOriginException
	 */
	#[\Override]
	public function processIncomingRequest(ACore $item): void {
		// Stops one server answering on behalf of an account on another.
		$item->checkOrigin($item->getActorId());

		if ($item instanceof View) {
			// PeerTube sends a `View` per watch of a video, so a view naming no short
			// of ours is offered to the post counter.
			if (!$this->storyInteractionService->receiveView($item)) {
				$this->viewCountService->receiveView($item->getStoryId(), $item->getActorId());
			}

			return;
		}

		if ($item instanceof StoryInteraction) {
			$this->storyInteractionService->receiveInteraction($item);
		}
	}
}
