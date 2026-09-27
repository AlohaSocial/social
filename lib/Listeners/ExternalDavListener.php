<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Listeners;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\Social\External\ExternalUserBackend;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Sabre\DAV\Exception\Forbidden;

/**
 * Refuses every DAV method to an external user, once the DAV server has
 * authenticated the request.
 *
 * Sabre's authentication runs on `beforeMethod:*` at priority 10; this runs
 * after it, so the user is known whatever the credential was. See
 * `ExternalDavGuard` for the check that runs before the DAV server exists.
 *
 * @template-implements IEventListener<SabrePluginAddEvent>
 */
class ExternalDavListener implements IEventListener {
	public const PRIORITY = 15;

	public function __construct(
		private IUserSession $userSession,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!($event instanceof SabrePluginAddEvent)) {
			return;
		}

		$event->getServer()->on('beforeMethod:*', function (): void {
			$this->check();
		}, self::PRIORITY);
	}

	/**
	 * @throws Forbidden
	 */
	public function check(): void {
		if (ExternalUserBackend::isExternal($this->userSession->getUser())) {
			throw new Forbidden('This account can only use the Social app.');
		}
	}
}
