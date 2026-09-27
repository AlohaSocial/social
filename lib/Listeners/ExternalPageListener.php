<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Listeners;

use OCA\Social\AppInfo\Application;
use OCA\Social\External\ExternalUserBackend;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use OCP\Util;

/**
 * Hides the parts of every page an external user cannot use.
 *
 * The header of a Nextcloud page carries the app menu, unified search and the
 * contacts menu. Their endpoints are refused to external users by
 * `ExternalScopeMiddleware`; this takes them off the page so there is
 * nothing to click that would only fail. Hiding is not the boundary.
 *
 * @template-implements IEventListener<BeforeTemplateRenderedEvent>
 */
class ExternalPageListener implements IEventListener {
	public function __construct(
		private IUserSession $userSession,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!($event instanceof BeforeTemplateRenderedEvent) || !$event->isLoggedIn()) {
			return;
		}

		if (ExternalUserBackend::isExternal($this->userSession->getUser())) {
			Util::addStyle(Application::APP_ID, 'external-scope');
		}
	}
}
