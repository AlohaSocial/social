<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Listeners;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Puts "Share to Social" into the Files app. Loaded on every Files page, so
 * it is the one entry built without the shared framework chunk (see
 * `webpack.common.js`).
 *
 * @template-implements IEventListener<\OCP\EventDispatcher\Event>
 */
class FilesScriptsListener implements IEventListener {
	#[\Override]
	public function handle(Event $event): void {
		if (!($event instanceof LoadAdditionalScriptsEvent)) {
			return;
		}

		// An init script, so the action is there on first paint.
		Util::addInitScript('social', 'social-filesAction');
	}
}
