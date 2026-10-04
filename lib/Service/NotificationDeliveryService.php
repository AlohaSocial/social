<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\NotificationDelivery;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;

/**
 * Where a user's notification delivery setting lives, and the bookkeeping the
 * digest job needs around it.
 *
 * Three user values, all under this app: the setting itself as one JSON
 * document (`notification_delivery`), the moment the last digest covered up
 * to (`notification_digest_at`), and a flag that is `1` exactly while the
 * setting needs the digest job (`notification_delivery_scheduled`). The flag
 * exists because Nextcloud can find the users holding a given *value* but not
 * the users holding *any* value for a key: the job asks for the flag and
 * never reads the setting of the many users who left it at instant.
 */
class NotificationDeliveryService {
	public const CONFIG_KEY = 'notification_delivery';
	public const DIGEST_AT_KEY = 'notification_digest_at';
	public const SCHEDULED_KEY = 'notification_delivery_scheduled';

	public function __construct(
		private ConfigService $configService,
		private IUserConfig $userConfig,
		private ITimeFactory $time,
	) {
	}

	/** The user's setting, with the defaults where nothing was stored. */
	public function of(string $userId): NotificationDelivery {
		$stored = $this->configService->getUserValue(self::CONFIG_KEY, $userId);
		if ($stored === '') {
			return new NotificationDelivery();
		}

		$decoded = json_decode($stored, true);

		return NotificationDelivery::fromArray(is_array($decoded) ? $decoded : []);
	}

	/**
	 * Writes what was named and leaves the rest as it is.
	 *
	 * Switching *to* a digest starts the clock now: the first digest reports
	 * what arrives from here on, not everything the account has ever been
	 * told, which would make the first thing the setting does a dump of the
	 * backlog. Switching *away* from every schedule forgets the clock.
	 *
	 * @param array<string, mixed> $changes
	 *
	 * @throws InvalidResourceException what a 422 says
	 */
	public function save(string $userId, array $changes): NotificationDelivery {
		$before = $this->of($userId);
		$after = NotificationDelivery::fromArray($before->toArray())->apply($changes);

		$this->configService->setValueForUser(
			$userId, self::CONFIG_KEY, (string)json_encode($after->toArray())
		);

		if ($after->isScheduled()) {
			$this->configService->setValueForUser($userId, self::SCHEDULED_KEY, '1');
		} else {
			$this->userConfig->deleteUserConfig($userId, Application::APP_ID, self::SCHEDULED_KEY);
		}

		$schedulesNow = $after->isScheduled() && !$before->isScheduled();
		$digestsNow = $after->isDigest() && !$before->isDigest();
		if ($schedulesNow || $digestsNow) {
			$this->setLastDigestAt($userId, $this->time->getTime());
		} elseif (!$after->isScheduled()) {
			$this->userConfig->deleteUserConfig($userId, Application::APP_ID, self::DIGEST_AT_KEY);
		}

		return $after;
	}

	/**
	 * Whether a notification about to reach the bell is held for the user's
	 * next digest instead.
	 *
	 * @param string $subject `NotificationDelivery::SUBJECT_DIRECT` for a
	 *                        direct message, else the raised subject
	 * @param bool $actorFollowed whether the user follows whoever acted
	 */
	public function holds(string $userId, string $subject, bool $actorFollowed): bool {
		$delivery = $this->of($userId);
		if (!$delivery->isScheduled()) {
			// the common case, and it costs one config read and no clock
			return false;
		}

		return $delivery->holds(
			$subject,
			$actorFollowed,
			(new DateTimeImmutable('@' . $this->time->getTime())),
			$this->timezoneOf($userId)
		);
	}

	/** The unix time the last digest covered up to; 0 when none has. */
	public function lastDigestAt(string $userId): int {
		return (int)$this->configService->getUserValue(self::DIGEST_AT_KEY, $userId);
	}

	public function setLastDigestAt(string $userId, int $at): void {
		$this->configService->setValueForUser($userId, self::DIGEST_AT_KEY, (string)$at);
	}

	/**
	 * The zone the user's clock runs in: what Nextcloud's own settings hold
	 * for them, else the server's, else UTC when neither is a zone PHP knows.
	 */
	public function timezoneOf(string $userId): DateTimeZone {
		$name = $this->userConfig->getValueString($userId, 'core', 'timezone', '');
		if ($name === '') {
			$name = date_default_timezone_get();
		}

		try {
			return new DateTimeZone($name);
		} catch (Exception) {
			return new DateTimeZone('UTC');
		}
	}

	/**
	 * The users whose setting needs the digest job.
	 *
	 * @return string[]
	 */
	public function scheduledUserIds(): array {
		$users = [];
		foreach ($this->userConfig->searchUsersByValueString(Application::APP_ID, self::SCHEDULED_KEY, '1') as $userId) {
			$users[] = (string)$userId;
		}

		return $users;
	}
}
