<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use DateTime;
use OCA\Social\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tells the administrators that somebody opened the app before it was set
 * up, once: the person is told to ask an administrator, and this is what
 * makes sure the administrator has heard.
 */
class SetupNotificationService {
	public const SUBJECT = 'setup_required';
	private const OBJECT_TYPE = 'setup';

	public function __construct(
		private IGroupManager $groupManager,
		private INotificationManager $notificationManager,
		private ConfigService $configService,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/** Notifies every administrator, unless they have been notified before. */
	public function notifyAdminsOnce(): void {
		if ($this->configService->getAppValue(ConfigService::SETUP_ADMINS_NOTIFIED) !== '') {
			return;
		}
		// written first: two people opening the app at once notify once
		$this->configService->setAppValue(ConfigService::SETUP_ADMINS_NOTIFIED, (string)$this->time->getTime());

		$admins = $this->groupManager->get('admin');
		if ($admins === null) {
			return;
		}

		try {
			foreach ($admins->getUsers() as $user) {
				$notification = $this->notificationManager->createNotification();
				$notification->setApp(Application::APP_ID)
					->setUser($user->getUID())
					->setDateTime(new DateTime('@' . $this->time->getTime()))
					->setObject(self::OBJECT_TYPE, 'cloud_url')
					->setSubject(self::SUBJECT);
				$this->notificationManager->notify($notification);
			}
		} catch (Throwable $e) {
			$this->logger->warning('could not tell the administrators that the app needs setting up', ['exception' => $e]);
		}
	}

	/** Takes the notifications down once the app is set up. */
	public function withdraw(): void {
		try {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp(Application::APP_ID)
				->setObject(self::OBJECT_TYPE, 'cloud_url');
			$this->notificationManager->markProcessed($notification);
		} catch (Throwable $e) {
			$this->logger->debug('could not withdraw the setup notifications', ['exception' => $e]);
		}
	}
}
