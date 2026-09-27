<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Listeners;

use OCA\Social\External\ExternalUserBackend;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserEnumerationFilterEvent;

/**
 * Takes external users out of the lists of users Nextcloud hands out, the
 * user status list among them.
 *
 * @template-implements IEventListener<UserEnumerationFilterEvent>
 */
class ExternalUserStatusListener implements IEventListener {
	public function __construct(
		private ExternalUserBackend $userBackend,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!($event instanceof UserEnumerationFilterEvent)) {
			return;
		}

		$users = $event->getUsers();
		$kept = array_values(array_filter(
			$users,
			fn (string $uid): bool => !$this->userBackend->userExists($uid)
		));
		if (count($kept) !== count($users)) {
			$event->setUsers($kept);
		}
	}
}
