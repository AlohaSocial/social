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
use OCA\Social\Model\ActivityPub\Object\Follow;
use Psr\Log\LoggerInterface;

class AcceptInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	public function __construct(
		private ActivityObjectResolver $objectResolver,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @throws InvalidOriginException the follow was not addressed to this actor
	 */
	#[\Override]
	public function processIncomingRequest(ACore $item): void {
		// an Accept whose `object` is only a link is resolved too, or the follow
		// would stay pending for ever
		try {
			$object = $this->objectResolver->resolve($item);
		} catch (ItemNotFoundException $e) {
			$this->logger->notice('Accept refers to an unknown object', [
				'activity' => $item->getId(),
				'object' => $item->getObjectId(),
				'origin' => $item->getOrigin(),
			]);

			return;
		}

		// a follow request is answered only by the account it was addressed
		// to; the check below is only by host, which a shared server shares
		if ($object instanceof Follow) {
			$item->checkActor($object->getObjectId(), $item->getActorId());
		}

		try {
			$service = AP::instance()->getInterfaceForItem($object);
			$service->activity($item, $object);
		} catch (ItemUnknownException $e) {
		}
	}
}
