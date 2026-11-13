<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Listeners;

use OCA\Social\Db\AtprotoOAuthRequest;
use OCA\Social\Service\ClientService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\PasswordUpdatedEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Signs every OAuth application out of an account whose password changed or
 * was reset: the Mastodon apps (`ClientService::revokeAllOf()`) and the
 * Bluesky apps that signed in through this server's OAuth.
 *
 * Nextcloud dispatches the event from `IUser::setPassword()`, which is what
 * the personal settings, the lost-password form, the Users page, the
 * provisioning API and `occ user:resetpassword` all call, for a Social-only
 * account (`ExternalUserBackend`) as for any other. Nextcloud's own sessions
 * and app passwords are Nextcloud's to handle, and Bluesky app passwords are
 * the person's own credentials, revoked where they were made.
 *
 * @template-implements IEventListener<\OCP\EventDispatcher\Event>
 */
class PasswordUpdatedListener implements IEventListener {
	public function __construct(
		private ClientService $clientService,
		private AtprotoOAuthRequest $atprotoOAuth,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!($event instanceof PasswordUpdatedEvent)) {
			return;
		}

		$userId = $event->getUser()->getUID();
		try {
			$revoked = $this->clientService->revokeAllOf($userId);
			if ($revoked > 0) {
				$this->logger->info('Signed {count} Mastodon app(s) out after a password change', [
					'count' => $revoked,
					'user' => $userId,
				]);
			}
		} catch (Throwable $e) {
			$this->logger->error('Could not sign the Mastodon apps out after a password change', [
				'user' => $userId,
				'exception' => $e,
			]);
		}

		try {
			$this->atprotoOAuth->deleteByUser($userId);
		} catch (Throwable $e) {
			$this->logger->error('Could not sign the Bluesky apps out after a password change', [
				'user' => $userId,
				'exception' => $e,
			]);
		}
	}
}
