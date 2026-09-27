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
use OCP\User\Events\UserFirstTimeLoggedInEvent;

/**
 * An external user's first login sets up nothing but Social.
 *
 * Other apps use the first login to prepare an account: Files copies the
 * skeleton files into the home folder, the calendar makes a default calendar.
 * None of it is reachable for an external user, and the skeleton alone is
 * several megabytes per account. Registered ahead of every other listener,
 * it stops the event for external users before they see it.
 *
 * @template-implements IEventListener<UserFirstTimeLoggedInEvent>
 */
class ExternalFirstLoginListener implements IEventListener {
	public const PRIORITY = 1000;

	#[\Override]
	public function handle(Event $event): void {
		if ($event instanceof UserFirstTimeLoggedInEvent && ExternalUserBackend::isExternal($event->getUser())) {
			$event->stopPropagation();
		}
	}
}
