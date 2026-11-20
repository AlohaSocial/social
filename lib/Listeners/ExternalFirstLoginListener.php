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
 * An external user's first login sets up nothing but Social. Registered ahead
 * of every other listener, it stops the event so other apps do not prepare
 * what the user cannot reach (Files' skeleton alone is several megabytes).
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
