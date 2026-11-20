<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Activity;

use OCA\Social\AP;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Service\StreamQueueService;

class CreateInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	public function __construct(
		private StreamQueueService $streamQueueService,
	) {
	}

	#[\Override]
	public function processIncomingRequest(ACore $item): void {
		if (!$item->hasObject()) {
			$this->queueObjectFetch($item);

			return;
		}
		$object = $item->getObject();

		try {
			$service = AP::instance()->getInterfaceForItem($item->getObject());
			$service->activity($item, $object);
		} catch (ItemUnknownException $e) {
		}
	}

	/**
	 * A `Create` whose `object` is only its id, which the vocabulary allows.
	 * The object is fetched from its origin through the forwarded-post queue,
	 * only when it lives on the activity's host along with its actor, so this
	 * instance never fetches on somebody else's behalf.
	 */
	private function queueObjectFetch(ACore $item): void {
		$objectId = $item->getObjectId();
		$objectHost = strtolower((string)parse_url($objectId, PHP_URL_HOST));
		if ($objectHost === ''
			|| $objectHost !== strtolower($item->getRoot()->getOrigin())
			|| $objectHost !== strtolower((string)parse_url($item->getActorId(), PHP_URL_HOST))) {
			return;
		}

		$this->streamQueueService->queueFetch($item->getRequestToken(), $objectId);
	}
}
