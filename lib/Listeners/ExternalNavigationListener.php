<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Listeners;

use OCA\Social\AppInfo\Application;
use OCA\Social\External\ExternalScope;
use OCA\Social\External\ExternalUserBackend;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use OCP\Navigation\Events\NavigationEntriesFilterEvent;

/**
 * An external user's navigation: the Social app, their settings, appearance
 * and logging out.
 *
 * Needs a server that dispatches `NavigationEntriesFilterEvent`; on one that
 * does not, the page listener's stylesheet hides the app menu instead, and
 * the middleware refuses what the entries point at either way.
 *
 * @template-implements IEventListener<NavigationEntriesFilterEvent>
 */
class ExternalNavigationListener implements IEventListener {
	public const EVENT = 'OCP\\Navigation\\Events\\NavigationEntriesFilterEvent';

	public function __construct(
		private IUserSession $userSession,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!($event instanceof NavigationEntriesFilterEvent)
			|| !ExternalUserBackend::isExternal($this->userSession->getUser())) {
			return;
		}

		$event->setEntries(array_filter(
			$event->getEntries(),
			static fn (array $entry, string $id): bool => ($entry['app'] ?? '') === Application::APP_ID
				|| $id === Application::APP_ID
				|| in_array($id, ExternalScope::MENU_ENTRIES, true),
			ARRAY_FILTER_USE_BOTH
		));
	}
}
