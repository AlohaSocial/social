<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Activity;

use OCA\Social\AppInfo\Application;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCP\Activity\IManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mirrors each bell notification into the Activity app, with the same subject
 * (`NotificationService::SUBJECTS`) so `Provider` words both alike. Never
 * throws: a failed activity entry must not undo the action that caused it.
 */
class Publisher {
	public const TYPE = 'social';
	public const OBJECT = 'social_notification';

	public function __construct(
		private IManager $activityManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param string $userId who is told
	 * @param string $subject a key of `NotificationService::SUBJECTS`
	 * @param Person|null $actor who acted, when cached here
	 * @param string $actorId the actor's ActivityPub id, for when it is not
	 * @param string $link where the entry leads
	 * @param string $excerpt the post concerned, as plain text; '' when there is none
	 * @param int|string $objectId the notification row's nid, so the entry is its own
	 * @param bool $warned whether `$excerpt` is the post's content warning rather than its text
	 */
	public function publish(
		string $userId,
		string $subject,
		?Person $actor,
		string $actorId,
		string $link,
		string $excerpt,
		int|string $objectId,
		bool $warned = false,
	): void {
		$parameters = [
			'account' => ($actor === null) ? $actorId : self::labelOf($actor),
			'acct' => ($actor === null) ? '' : $actor->getAccount(),
			'user' => ($actor === null) ? '' : $actor->getUserId(),
			'actor' => $actorId,
			'link' => $link,
			'avatar' => ($actor === null) ? '' : $actor->getAvatar(),
			'excerpt' => $excerpt,
		];
		if ($warned) {
			$parameters['warned'] = true;
		}

		try {
			$event = $this->activityManager->generateEvent();
			$event->setApp(Application::APP_ID)
				->setType(self::TYPE)
				->setAffectedUser($userId)
				->setTimestamp(time())
				->setObject(self::OBJECT, (int)$objectId)
				->setLink($link)
				->setSubject($subject, $parameters);
			if ($actor !== null && $actor->getUserId() !== '') {
				$event->setAuthor($actor->getUserId());
			}

			$this->activityManager->publish($event);
		} catch (Throwable $e) {
			$this->logger->warning('could not publish an activity entry', ['exception' => $e]);
		}
	}

	/**
	 * The name a person publishes under, and the handle when that is empty.
	 */
	public static function labelOf(Person $actor): string {
		$name = trim($actor->getName());
		if ($name === '') {
			$name = $actor->getDisplayName();
		}

		return ($name !== '') ? $name : $actor->getAccount();
	}
}
