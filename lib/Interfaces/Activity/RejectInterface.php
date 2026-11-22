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

class RejectInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
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
		// See AcceptInterface: `object` may be a link rather than embedded.
		try {
			$object = $this->objectResolver->resolve($item);
		} catch (ItemNotFoundException $e) {
			$this->logger->notice('Reject refers to an unknown object', [
				'activity' => $item->getId(),
				'object' => $item->getObjectId(),
				'origin' => $item->getOrigin(),
			]);

			return;
		}

		// Only the account a follow request was addressed to may reject it; the
		// interface below checks only the host.
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
