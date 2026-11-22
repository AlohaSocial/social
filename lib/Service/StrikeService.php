<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\StrikesRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Model\Strike;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * The record of what a moderator decided about an account, and the warning
 * that decides nothing.
 *
 * `social_moderation` holds only the current decision per account; strikes
 * keep the history, so a third silence is not mistaken for a first. A
 * warning is a strike that applies nothing. A local account is told through
 * Nextcloud's notifications; for a remote one it is only a moderators' note,
 * as no ActivityPub activity says "your user has been warned".
 *
 * Nothing here removes a strike: a lift ends a decision, not its record.
 * Only `social:reset` and uninstalling empty the table.
 */
class StrikeService {
	public function __construct(
		private StrikesRequest $strikesRequest,
		private ActorsRequest $actorsRequest,
		private INotificationManager $notificationManager,
		private IUserSession $userSession,
		private NotificationService $notificationService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Records one, and tells the account if it is one of ours.
	 *
	 * @param string $action Strike::WARNING, or the Moderation level applied
	 * @param string $text what the moderator wrote, shown to the account
	 * @param int $reportId the report it came from, or 0
	 */
	public function record(
		string $actorId, string $action, string $text = '', int $reportId = 0,
	): Strike {
		if (!in_array($action, Strike::ACTIONS, true)) {
			throw new \InvalidArgumentException('unknown strike action: ' . $action);
		}

		$strike = new Strike(
			$actorId, $action, $text, $this->currentUserId(), $reportId, time()
		);
		$this->strikesRequest->save($strike);
		$this->notify($strike);
		// And where a Mastodon client can see it: the bell above is Nextcloud's.
		$this->notificationService->onModerationWarning($actorId, $action, $text);

		return $strike;
	}

	/**
	 * One account's history, newest first.
	 *
	 * @return Strike[]
	 */
	public function history(string $actorId, int $limit = StrikesRequest::HISTORY_LIMIT): array {
		return $this->strikesRequest->getForActor($actorId, $limit);
	}

	/**
	 * How many strikes each of these accounts has, in one query.
	 *
	 * @param string[] $actorIds
	 *
	 * @return array<string, int> actor id => how many, missing when none
	 */
	public function countFor(array $actorIds): array {
		return $this->strikesRequest->countForActors($actorIds);
	}

	/** The moderator who took it, or '' for one taken by a command or a job. */
	private function currentUserId(): string {
		$user = $this->userSession->getUser();

		return $user === null ? '' : $user->getUID();
	}

	/**
	 * Tells a local account what was decided about it. A failure costs only the
	 * notification; the decision is already recorded and applied.
	 */
	private function notify(Strike $strike): void {
		try {
			$actor = $this->actorsRequest->getFromId($strike->getActorId());
		} catch (ActorDoesNotExistException $e) {
			// not one of ours, which is the ordinary case for a strike
			return;
		}

		$userId = $actor->getUserId();
		if ($userId === '') {
			return;
		}

		try {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp('social')
				->setDateTime(new \DateTime('now'))
				->setUser($userId)
				->setObject('strike', (string)$strike->getCreation())
				->setSubject('moderation_warning', [
					'action' => $strike->getAction(),
					'text' => $strike->getText(),
				]);
			$this->notificationManager->notify($notification);
		} catch (\Exception $e) {
			$this->logger->warning('could not tell an account about a strike', [
				'actor' => $strike->getActorId(), 'exception' => $e,
			]);
		}
	}
}
