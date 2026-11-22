<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Listeners;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Service\AccountService;
use OCP\Accounts\UserUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserChangedEvent;
use Psr\Log\LoggerInterface;

/**
 * Republishes a profile — the display name, the picture, the fields — when the
 * Nextcloud account behind it changes. A new or removed avatar, whether set
 * here or in Nextcloud's own settings, is also told to the followers and to
 * Bluesky: the picture is the Nextcloud account's.
 *
 * @template-implements IEventListener<\OCP\EventDispatcher\Event>
 */
class UserAccountListener implements IEventListener {
	public function __construct(
		private ActorsRequest $actorsRequest,
		private AccountService $accountService,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if ($event instanceof UserChangedEvent) {
			if ($event->getFeature() !== 'avatar') {
				return;
			}
			$avatar = true;
		} elseif ($event instanceof UserUpdatedEvent) {
			$avatar = false;
		} else {
			return;
		}

		$userId = $event->getUser()->getUID();

		try {
			// look up by the actor's handle, which differs from the user id
			// when the id is not a valid handle or collided with a taken one
			$actor = $this->actorsRequest->getFromUserId($userId);
		} catch (ActorDoesNotExistException $e) {
			// the user never opened Social; there is nothing of theirs to update
			return;
		} catch (\Exception $e) {
			$this->logger->warning('could not look up the Social account of an updated user', [
				'userId' => $userId, 'exception' => $e,
			]);

			return;
		}

		try {
			$avatar
				? $this->accountService->avatarChanged($userId, $actor->getPreferredUsername())
				: $this->accountService->cacheLocalActorByUsername($actor->getPreferredUsername());
		} catch (\Exception $e) {
			$this->logger->warning('issue while updating user account', ['exception' => $e]);
		}
	}
}
