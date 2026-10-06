<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Listeners;

use OCA\Social\Cron\AtprotoMirrorJob;
use OCA\Social\Events\PostDeletedEvent;
use OCA\Social\Events\PostPublishedEvent;
use OCA\Social\Events\PostUpdatedEvent;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\Atproto\AtprotoEgress;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mirrors local public lifecycle events without ever affecting ActivityPub.
 * A PDS failure is logged after the local post/delete has completed; retry is
 * safe because the local-to-AT mapping makes a repeated publish idempotent.
 *
 * @template-implements IEventListener<Event>
 */
class AtprotoPostListener implements IEventListener {
	public function __construct(
		private AtprotoEgress $egress,
		private LoggerInterface $logger,
		private ?IJobList $jobList = null,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		try {
			if ($event instanceof PostPublishedEvent) {
				$this->egress->publish($event->getPost());
			} elseif ($event instanceof PostUpdatedEvent) {
				$this->egress->update($event->getPost());
			} elseif ($event instanceof PostDeletedEvent) {
				$this->egress->delete($event->getPost());
			}
		} catch (Throwable $e) {
			$this->logger->warning('could not mirror a Social post to AT-Proto', [
				'event' => $event::class,
				'exception' => $e,
			]);
			if ($this->jobList !== null && ($event instanceof PostPublishedEvent || $event instanceof PostUpdatedEvent || $event instanceof PostDeletedEvent)) {
				try {
					$this->jobList->add(AtprotoMirrorJob::class, [
						'operation' => $event instanceof PostPublishedEvent ? 'publish' : ($event instanceof PostUpdatedEvent ? 'update' : 'delete'),
						'post' => $this->snapshot($event->getPost()),
					]);
				} catch (Throwable $queueError) {
					$this->logger->error('could not queue the failed AT-Proto mirror', [
						'event' => $event::class, 'exception' => $queueError,
					]);
				}
			}
		}
	}

	/** Convert JsonSerializable attachment objects to a job-safe scalar tree. */
	private function snapshot(Stream $post): array {
		$encoded = json_encode($post->exportAsLocal(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$decoded = is_string($encoded) ? json_decode($encoded, true) : null;

		return is_array($decoded) ? $decoded : [];
	}
}
