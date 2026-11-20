<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Internal;

use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Interfaces\Activity\AbstractActivityPubInterface;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Service\AccountRelationService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\NotificationService;

class SocialAppNotificationInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	public function __construct(
		private StreamRequest $streamRequest,
		private ActorRelationRequest $actorRelationRequest,
		private MiscService $miscService,
		private NotificationService $notificationService,
		private AccountRelationService $accountRelationService,
	) {
	}

	#[\Override]
	public function save(ACore $item): void {
		/** @var SocialAppNotification $notification */
		$notification = $item;
		if ($notification->getId() === '') {
			return;
		}

		if ($this->isSuppressed($notification)) {
			return;
		}

		$notification->setPublished(date('c'));
		$notification->convertPublished();

		$this->miscService->log(
			'Generating notification: ' . json_encode($notification, JSON_UNESCAPED_SLASHES), 1
		);
		$this->streamRequest->save($notification);
		$this->notificationService->onNotification($notification);
	}

	/**
	 * No notification is stored from an actor the recipient has blocked, who
	 * has blocked the recipient, or whom the recipient muted with notifications
	 * hidden. Expired mutes do not count: nothing deletes their rows, so every
	 * reader of a mute must check its expiry.
	 */
	private function isSuppressed(SocialAppNotification $notification): bool {
		$to = $notification->getTo();
		$from = $notification->getAttributedTo();
		if ($to === '' || $from === '') {
			return false;
		}

		foreach ($this->actorRelationRequest->getBetween($to, $from) as $relation) {
			if ($relation->getType() === ActorRelation::TYPE_BLOCK
				|| $relation->getType() === ActorRelation::TYPE_BLOCKED_BY) {
				return true;
			}

			if ($relation->getType() === ActorRelation::TYPE_MUTE
				&& $relation->isNotifications()
				&& !$this->accountRelationService->isMuteExpired($to, $from)) {
				return true;
			}
		}

		return false;
	}

	#[\Override]
	public function update(ACore $item): void {
		/** @var SocialAppNotification $notification */
		$notification = $item;
		$this->miscService->log(
			'Updating notification: ' . json_encode($notification, JSON_UNESCAPED_SLASHES), 1
		);
		$this->streamRequest->update($notification, true);
	}

	#[\Override]
	public function delete(ACore $item): void {
		/** @var Stream $item */
		$this->streamRequest->deleteById($item->getId(), SocialAppNotification::TYPE);
	}
}
