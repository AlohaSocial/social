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
use OCA\Social\Interfaces\Object\LikeInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Service\DurableCache;
use Psr\Log\LoggerInterface;

class UndoInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	public function __construct(
		private ActivityObjectResolver $objectResolver,
		private LoggerInterface $logger,
		private ?DurableCache $durableCache = null,
	) {
	}

	/**
	 * @throws InvalidOriginException the object belongs to another actor
	 */
	#[\Override]
	public function processIncomingRequest(ACore $item): void {
		// `object` may be a link rather than an embedded object: Mastodon embeds,
		// most others do not, and returning here meant an Undo from those peers
		// undid nothing.
		try {
			$object = $this->objectResolver->resolve($item);
		} catch (ItemNotFoundException $e) {
			if (!$item->hasObject() && $item->getObjectId() !== '' && $item->getActorId() !== '') {
				// A URI-only Undo can arrive before its Like is known locally. The
				// object type is not available yet, so remember it by the actor-owned
				// activity id; a later Like can claim it only with that same actor.
				$item->checkOrigin($item->getObjectId());
				$item->checkOrigin($item->getActorId());
				LikeInterface::rememberUndoBeforeArrival(
					$this->durableCache, $item->getObjectId(), $item->getActorId()
				);
			}
			$this->logger->notice('Undo refers to an unknown object', [
				'activity' => $item->getId(),
				'object' => $item->getObjectId(),
				'origin' => $item->getOrigin(),
			]);

			return;
		}

		// Only what an actor did is theirs to undo. The interfaces below check
		// the host the Undo came from, which on a shared server says nothing
		// about which of its accounts sent it: without this, one account undid
		// a neighbour's like, boost or follow.
		$item->checkActor($item->getActorId(), $object->getActorId());

		try {
			$interface = AP::instance()->getInterfaceForItem($object);
			$interface->activity($item, $object);
		} catch (ItemUnknownException $e) {
		}
	}
}
