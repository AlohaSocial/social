<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Activity;

use OCA\Social\AP;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Story;
use OCA\Social\Model\ActivityPub\Stream;

class DeleteInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	/** The types a deleted id can refer to, in the order they are looked up. */
	private const DELETABLE_TYPES = ['Note', 'Person', 'Story'];

	/**
	 * @throws InvalidOriginException
	 */
	#[\Override]
	public function processIncomingRequest(ACore $item): void {
		$item->checkOrigin($item->getId());
		$item->checkOrigin($item->getObjectId());

		if ($item->hasObject()) {
			$object = $item->getObject();
			try {
				$interface = AP::instance()->getInterfaceForItem($object);
				$interface->activity($item, $object);

				return;
			} catch (ItemUnknownException $e) {
				// A Tombstone, or any other object type this app has no handler for.
				// Its id is all that is needed to remove what it points at, so fall
				// through to the lookup below.
			}
		}

		$this->deleteById($item);
	}

	/**
	 * Whether the deleting actor is the account behind the author: PeerTube
	 * files a video under its channel and deletes it as the account that
	 * runs the channel; see `Person::isRunBy()`.
	 */
	private function runsAuthor(string $actorId, string $authorId): bool {
		if ($actorId === $authorId) {
			return false;
		}

		try {
			$author = AP::instance()->getInterfaceFromType(Person::TYPE)->getItemById($authorId);
		} catch (ItemNotFoundException|ItemUnknownException $e) {
			return false;
		}

		return $author instanceof Person && $author->isRunBy($actorId);
	}

	/**
	 * @throws InvalidOriginException the id names something of someone else's
	 */
	private function deleteById(ACore $activity): void {
		$objectId = $activity->getObjectId();
		$actorId = $activity->getActorId();

		foreach (self::DELETABLE_TYPES as $type) {
			try {
				$interface = AP::instance()->getInterfaceFromType($type);
				$object = $interface->getItemById($objectId);
			} catch (ItemNotFoundException $e) {
				continue;
			} catch (ItemUnknownException $e) {
				continue;
			}

			// The origin check stops at the host. A post is removed only on its
			// author's word — Mastodon looks a deleted status up by uri *and*
			// account — or any user of the author's server could take it down.
			// A story goes the same way: on its author's word, not on that of
			// anybody whose server can reach this one.
			if (($object instanceof Story || $object instanceof Stream)
				&& !$this->runsAuthor($actorId, $object->getAttributedTo())) {
				$activity->checkActor($object->getAttributedTo(), $actorId);
			}

			// An actor is deleted by itself alone. Nothing else is, which is
			// why the host-wide origin check let any account on a server
			// remove any of its neighbours from here.
			if ($object instanceof Person) {
				$activity->checkActor($object->getId(), $actorId);
			}

			$interface->delete($object);

			return;
		}
	}
}
