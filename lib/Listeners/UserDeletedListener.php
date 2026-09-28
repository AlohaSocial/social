<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Listeners;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ConfigService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Takes the Fediverse account down with the Nextcloud account.
 *
 * Without this, deleting a user left everything they had here standing: the
 * actor still resolved over WebFinger, their posts stayed readable, and remote
 * servers went on delivering to an inbox whose owner no longer existed. An
 * administrator removing an account has no reason to suspect any of that, and
 * no reason to know that `occ social:account:delete` exists.
 *
 * @template-implements IEventListener<\OCP\EventDispatcher\Event>
 */
class UserDeletedListener implements IEventListener {
	public function __construct(
		private ActorsRequest $actorsRequest,
		private AccountService $accountService,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!($event instanceof UserDeletedEvent)) {
			return;
		}

		$userId = $event->getUser()->getUID();
		try {
			$this->config->deleteUserValue($userId, 'social', ConfigService::USER_EXTERNAL_SIGNUP_NOTICE_VERSION);
			$this->config->deleteUserValue($userId, 'social', ConfigService::USER_EXTERNAL_SIGNUP_NOTICE_ACCEPTED);
			$this->config->deleteUserValue($userId, 'social', ConfigService::USER_EXTERNAL_SIGNUP_NOTICE_SNAPSHOT);
		} catch (\Throwable $e) {
			// Account deletion must still reach the Social cleanup if the audit
			// values cannot be removed independently.
			$this->logger->warning('could not clear the registration notice record for a deleted user', [
				'userId' => $userId, 'exception' => $e,
			]);
		}

		try {
			// the handle is the actor's own, which is not always the user id
			$actor = $this->actorsRequest->getFromUserId($userId);
		} catch (ActorDoesNotExistException $e) {
			// the user never opened Social; there is nothing of theirs here
			return;
		} catch (\Exception $e) {
			$this->logger->error('could not look up the Social account of a deleted user', [
				'userId' => $userId, 'exception' => $e,
			]);

			return;
		}

		try {
			// marks the actor deleted, drops what belongs to it, and federates a
			// Delete so the servers that cached it drop their copies too
			$this->accountService->deleteActor($actor->getPreferredUsername());
		} catch (\Exception $e) {
			// the Nextcloud user is already gone: log loudly rather than throw,
			// so a federation problem cannot leave the deletion half-applied
			$this->logger->error('could not delete the Social account of a deleted user', [
				'userId' => $userId, 'account' => $actor->getPreferredUsername(), 'exception' => $e,
			]);
		}
	}
}
