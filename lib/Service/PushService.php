<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Tells connected web clients about new timeline entries through the
 * notify_push app, so they refresh at once; without notify_push every call
 * is a no-op and clients keep polling. The push names what arrived
 * (`{"kinds": [...]}`), so each part of the page reloads only for its own.
 */
class PushService {
	/** the custom notify_push event name web clients listen on */
	public const EVENT = 'social_timeline';

	public const KIND_HOME = 'home';
	public const KIND_DIRECT = 'direct';
	public const KIND_NOTIFICATION = 'notification';

	private const QUEUE_CLASS = 'OCA\\NotifyPush\\Queue\\IQueue';

	private bool $probed = false;
	private ?object $queue = null;

	public function __construct(
		private DetailsService $detailsService,
		private StreamService $streamService,
		private ActorsRequest $actorsRequest,
		private LoggerInterface $logger,
	) {
	}

	public function onNewStream(string $streamId): void {
		$queue = $this->getQueue();
		if ($queue === null) {
			return;
		}

		try {
			$stream = $this->streamService->getStreamById($streamId);
		} catch (StreamNotFoundException $e) {
			return;
		}

		try {
			$details = $this->detailsService->generateDetailsFromStream($stream);
		} catch (Exception $e) {
			$this->logger->debug('no viewer details for push', ['exception' => $e]);

			return;
		}

		$kinds = [];
		foreach ([
			self::KIND_HOME => $details->getHomeViewers(),
			self::KIND_DIRECT => $details->getDirectViewers(),
		] as $kind => $viewers) {
			foreach ($viewers as $viewer) {
				/** @var Person $viewer */
				if ($viewer->isLocal() && $viewer->getUserId() !== '') {
					$kinds[$viewer->getUserId()][$kind] = true;
				}
			}
		}

		foreach (array_keys($kinds) as $userId) {
			if (!$this->push($queue, (string)$userId, array_keys($kinds[$userId]))) {
				return;
			}
		}
	}

	/**
	 * A notification was stored for `$recipientId`: the badges of a local
	 * account's open pages are read again, held or not, since a held one is
	 * a request waiting.
	 */
	public function onNotification(string $recipientId): void {
		$queue = $this->getQueue();
		if ($queue === null || $recipientId === '') {
			return;
		}

		try {
			$userId = $this->actorsRequest->getFromId($recipientId)->getUserId();
		} catch (Exception $e) {
			return;
		}

		if ($userId !== '') {
			$this->push($queue, $userId, [self::KIND_NOTIFICATION]);
		}
	}

	/**
	 * @param string[] $kinds
	 *
	 * @return bool whether notify_push took it
	 */
	private function push(object $queue, string $userId, array $kinds): bool {
		try {
			$queue->push('notify_custom', [
				'user' => $userId,
				'message' => self::EVENT,
				'body' => ['kinds' => $kinds],
			]);
		} catch (Exception $e) {
			$this->logger->debug('notify_push delivery failed', ['exception' => $e]);

			return false;
		}

		return true;
	}

	/**
	 * The notify_push queue, when that app is installed — probed once per
	 * request. Overridable for tests.
	 */
	protected function getQueue(): ?object {
		if (!$this->probed) {
			$this->probed = true;
			try {
				if (class_exists(self::QUEUE_CLASS)) {
					$this->queue = Server::get(self::QUEUE_CLASS);
				}
			} catch (Exception $e) {
				$this->queue = null;
			}
		}

		return $this->queue;
	}
}
