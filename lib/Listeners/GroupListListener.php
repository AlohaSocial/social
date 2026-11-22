<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Listeners;

use OCA\Social\Service\GroupListService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\GroupChangedEvent;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps the group lists in step with the groups, the moment they change.
 * Never fails the group operation: a failure is logged and the cron's
 * reconcile pass settles it.
 *
 * @template-implements IEventListener<\OCP\EventDispatcher\Event>
 */
class GroupListListener implements IEventListener {
	public function __construct(
		private GroupListService $groupListService,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		try {
			if ($event instanceof UserAddedEvent) {
				$this->groupListService->onUserAdded($event->getGroup(), $event->getUser());
			} elseif ($event instanceof UserRemovedEvent) {
				$this->groupListService->onUserRemoved($event->getGroup(), $event->getUser());
			} elseif ($event instanceof GroupDeletedEvent) {
				$this->groupListService->onGroupDeleted($event->getGroup());
			} elseif ($event instanceof GroupChangedEvent && $event->getFeature() === 'displayName') {
				$this->groupListService->onGroupRenamed($event->getGroup());
			}
		} catch (Throwable $e) {
			$this->logger->warning('[GroupListListener] could not follow a group change', [
				'event' => $event::class,
				'exception' => $e,
			]);
		}
	}
}
