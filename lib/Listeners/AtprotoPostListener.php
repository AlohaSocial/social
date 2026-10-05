<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Listeners;

use OCA\Social\Events\PostDeletedEvent;
use OCA\Social\Events\PostPublishedEvent;
use OCA\Social\Service\Atproto\AtprotoEgress;
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
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		try {
			if ($event instanceof PostPublishedEvent) {
				$this->egress->publish($event->getPost());
			} elseif ($event instanceof PostDeletedEvent) {
				$this->egress->delete($event->getPost());
			}
		} catch (Throwable $e) {
			$this->logger->warning('could not mirror a Social post to AT-Proto', [
				'event' => $event::class,
				'exception' => $e,
			]);
		}
	}
}
