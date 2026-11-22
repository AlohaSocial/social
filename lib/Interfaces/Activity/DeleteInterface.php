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
use OCA\Social\Service\DurableCache;

class DeleteInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	/** The types a deleted id can refer to, in the order they are looked up. */
	private const DELETABLE_TYPES = ['Note', 'Person', 'Story'];

	/**
	 * Where a Delete of something not held yet is remembered, and for how
	 * long: six hours, as Mastodon remembers one.
	 */
	public const EARLY_DELETE_NAMESPACE = 'social.early_delete';
	public const EARLY_DELETE_TTL = 21600;

	public function __construct(
		private ?DurableCache $durableCache = null,
	) {
	}

	/**
	 * Whether `$objectId` was deleted by `$actorId` before it arrived here. A
	 * Delete can overtake its Create, so a Delete that found nothing is
	 * remembered for a while and a Create of the same object by the same actor
	 * in that time is dropped.
	 */
	public static function deletedBeforeArrival(?DurableCache $durableCache, string $objectId, string $actorId): bool {
		return $durableCache !== null && $actorId !== '' && $objectId !== ''
			&& $durableCache->getShared(self::EARLY_DELETE_NAMESPACE, hash('sha256', $objectId)) === $actorId;
	}

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

			// The origin check stops at the host: a post or story is removed only on
			// its author's word, as Mastodon matches a deleted status by uri and
			// account, or any user of the author's server could take it down.
			if (($object instanceof Story || $object instanceof Stream)
				&& !$this->runsAuthor($actorId, $object->getAttributedTo())) {
				$activity->checkActor($object->getAttributedTo(), $actorId);
			}

			// An actor is deleted only by itself, or any account on a server could
			// remove its neighbours from here.
			if ($object instanceof Person) {
				$activity->checkActor($object->getId(), $actorId);
			}

			$interface->delete($object);

			return;
		}

		if ($objectId !== '' && $actorId !== '') {
			$this->durableCache?->setShared(
				self::EARLY_DELETE_NAMESPACE, hash('sha256', $objectId), $actorId, self::EARLY_DELETE_TTL
			);
		}
	}
}
