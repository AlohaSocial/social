<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\NotificationDelivery;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\IURLGenerator;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Where a user's notification delivery setting lives, and the digest job's
 * bookkeeping: three user values, the setting as JSON
 * (`notification_delivery`), what the last digest covered up to
 * (`notification_digest_at`), and a flag that is `1` while the setting needs
 * the digest job (`notification_delivery_scheduled`), as Nextcloud can find
 * users by a value but not by a key.
 *
 * The digest (`digestFor()`) is one Nextcloud notification counting, per
 * kind, what the user has not read since the last one. What the notification
 * policy held or a muted thread produced is not counted; senders waiting in
 * the requests inbox get a line of their own but never make a digest alone.
 */
class NotificationDeliveryService {
	public const CONFIG_KEY = 'notification_delivery';
	public const DIGEST_AT_KEY = 'notification_digest_at';
	public const SCHEDULED_KEY = 'notification_delivery_scheduled';

	/** The subject `Notifier` renders a digest from. */
	public const SUBJECT_DIGEST = 'digest';

	/** The marker whose position says what the user has read. */
	private const TIMELINE = 'notifications';

	public function __construct(
		private ConfigService $configService,
		private IUserConfig $userConfig,
		private ITimeFactory $time,
		private ActorsRequest $actorsRequest,
		private StreamRequest $streamRequest,
		private MarkerService $markerService,
		private INotificationManager $notificationManager,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
		private NotificationInboxService $notificationInboxService,
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
	 * Writes what was named and leaves the rest as it is. Switching to a
	 * digest starts the clock now, so the first digest is not the whole
	 * backlog; switching away raises what it held as one last digest, and
	 * switching away from every schedule forgets the clock.
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

		if ($before->isDigest() && !$after->isDigest()) {
			$this->digestFor($userId, new DateTimeImmutable('@' . $this->time->getTime()));
		}

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
	 * Raises one digest covering what the user has not read (past the read
	 * marker) between the last digest and `$until`, and moves the clock to
	 * `$until`, so a late cron still reports the window asked for. Older
	 * digests are taken off the bell first, so one sits there at a time.
	 *
	 * Null when there was nothing to say (waiting senders alone are not); the
	 * clock moves anyway.
	 *
	 * @return array{counts: array<string, int>, total: int, link: string, waiting?: int}|null the digest's parameters
	 */
	public function digestFor(string $userId, DateTimeImmutable $until): ?array {
		try {
			$actor = $this->actorsRequest->getFromUserId($userId);
		} catch (Exception $e) {
			// a user with the setting and no account: nothing can have been
			// addressed to them
			return null;
		}

		$since = $this->lastDigestAt($userId);
		$marker = $this->markerService->lastReadId($userId, self::TIMELINE);
		$bySubType = $this->streamRequest->countNotificationsBySubType(
			$actor, $marker, $this->asStored($since), $this->asStored($until->getTimestamp())
		);
		foreach ($this->notificationInboxService->held($actor, $marker) as $held) {
			$at = $held->getPublishedTime();
			if ($at > $since && $at <= $until->getTimestamp() && ($bySubType[$held->getSubType()] ?? 0) > 0) {
				$bySubType[$held->getSubType()]--;
			}
		}
		$this->setLastDigestAt($userId, $until->getTimestamp());

		$counts = [];
		$total = 0;
		// in the order the subjects are declared in, which is the order the
		// digest reads them out in
		foreach (NotificationService::SUBJECTS as $subType => $subject) {
			$count = $bySubType[$subType] ?? 0;
			if ($count > 0) {
				$counts[$subject] = ($counts[$subject] ?? 0) + $count;
				$total += $count;
			}
		}
		if ($total === 0) {
			return null;
		}

		$parameters = [
			'counts' => $counts,
			'total' => $total,
			'link' => rtrim($this->urlGenerator->linkToRouteAbsolute('social.Navigation.navigate'), '/')
				. '/timeline/notifications',
		];
		$waiting = count($this->notificationInboxService->requests($actor));
		if ($waiting > 0) {
			$parameters['waiting'] = $waiting;
		}

		try {
			$older = $this->notificationManager->createNotification();
			$older->setApp(Application::APP_ID)
				->setUser($userId)
				->setSubject(self::SUBJECT_DIGEST);
			$this->notificationManager->markProcessed($older);

			$raised = $this->notificationManager->createNotification();
			$raised->setApp(Application::APP_ID)
				->setDateTime((new DateTime())->setTimestamp($until->getTimestamp()))
				->setUser($userId)
				->setObject(NotificationService::OBJECT, 'digest-' . $until->getTimestamp())
				->setSubject(self::SUBJECT_DIGEST, $parameters);
			$this->notificationManager->notify($raised);
		} catch (Exception $e) {
			$this->logger->warning('could not raise a notification digest', ['exception' => $e]);
		}

		return $parameters;
	}

	/**
	 * A unix time as the stream table's `creation` column is compared with:
	 * a DateTime in the server's zone, which is what the rows were written
	 * with.
	 */
	private function asStored(int $timestamp): DateTime {
		return (new DateTime())->setTimestamp($timestamp);
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
