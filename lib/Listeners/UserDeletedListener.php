<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Listeners;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\RecommendationPreferenceRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ConfigService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Takes the Fediverse account down with the Nextcloud account, so the actor no
 * longer resolves, its posts are gone and remote servers stop delivering to it.
 *
 * @template-implements IEventListener<\OCP\EventDispatcher\Event>
 */
class UserDeletedListener implements IEventListener {
	public function __construct(
		private ActorsRequest $actorsRequest,
		private AccountService $accountService,
		private IConfig $config,
		private LoggerInterface $logger,
		private RecommendationPreferenceRequest $recommendationPreferences,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!($event instanceof UserDeletedEvent)) {
			return;
		}

		$userId = $event->getUser()->getUID();
		try {
			$this->recommendationPreferences->purge($userId);
		} catch (\Throwable $e) {
			$this->logger->error('could not clear recommendation preferences for a deleted user', [
				'userId' => $userId, 'exception' => $e,
			]);
		}
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
