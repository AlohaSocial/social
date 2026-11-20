<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Activity;

use OCA\Social\AP;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Story;

class AddInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	public function __construct(
		private FeaturedCollection $featuredCollection,
	) {
	}

	/**
	 * `Add{object, target}` where target is the actor's `featured` collection
	 * is a pin. See FeaturedCollection.
	 *
	 * @throws InvalidOriginException
	 */
	#[\Override]
	public function processIncomingRequest(ACore $item): void {
		// Pixelfed publishes a story with `Add`, addressed to the author's
		// followers; Mastodon adds a post to a `featured` collection with it,
		// which is a pin. What the activity carries tells them apart: a story
		// arrives as the object itself, a pin as an id and a target.
		$story = $item->hasObject() ? $item->getObject() : null;
		if ($story instanceof Story) {
			// The author is whoever performed the Add, whatever the payload's
			// `attributedTo` says, as NoteInterface does for a Create: the origin
			// check StoryInterface makes is host-wide.
			$story->setAttributedTo($item->getActorId());
			// checked against the activity's own origin, which is what stops
			// one server publishing a story onto another's account
			$story->setOrigin($item->getOriginAuthority(), $item->getOriginSource(), $item->getOriginCreationTime());
			AP::instance()->getInterfaceFromType(Story::TYPE)->save($story);

			return;
		}

		$this->featuredCollection->toggle($item, true);
	}
}
