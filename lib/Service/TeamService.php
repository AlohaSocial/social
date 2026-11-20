<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\TeamsRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * An account a Nextcloud group posts from.
 *
 * A team account is an actor like any other (keys, followers, inbox, outbox,
 * moderation); only who may speak as it is new. Membership is the Nextcloud
 * group, asked live on every check, so somebody who left cannot post. Who
 * wrote a post is recorded and shown only inside the team and to moderators.
 */
class TeamService {
	/**
	 * The `user_id` a team actor is stored under. A Nextcloud user id cannot
	 * contain a slash, so this never collides with a real account.
	 */
	public const USER_PREFIX = 'team/';

	public function __construct(
		private TeamsRequest $teamsRequest,
		private AccountService $accountService,
		private IGroupManager $groupManager,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
	}

	/** The user id a team account is stored under. */
	public static function userIdFor(string $groupId): string {
		return self::USER_PREFIX . $groupId;
	}

	/** Whether an account is one of these rather than a person's. */
	public static function isTeamUserId(string $userId): bool {
		return str_starts_with($userId, self::USER_PREFIX);
	}

	/**
	 * Gives a group an account to post from. Admin-only, so there is no route:
	 * it creates an address other servers will follow and cache.
	 *
	 * @throws InvalidResourceException the group is unknown, or it already has one
	 */
	public function create(string $groupId, string $username): Person {
		if (!$this->groupManager->groupExists($groupId)) {
			throw new InvalidResourceException('there is no group called ' . $groupId);
		}

		foreach ($this->teamsRequest->getByGroups([$groupId]) as $existing) {
			throw new InvalidResourceException(
				$groupId . ' already posts as ' . $existing['actor_id']
			);
		}

		$this->accountService->createActor(self::userIdFor($groupId), $username);
		$actor = $this->accountService->getActor($username);

		$this->teamsRequest->create($actor->getId(), $groupId);

		return $actor;
	}

	/** Whether somebody may post as any team account: whether `forUser()` could list one. */
	public function hasTeamsFor(string $userId): bool {
		$user = $this->userManager->get($userId);

		return $user !== null && $this->teamsRequest->getByGroups($this->groupManager->getUserGroupIds($user)) !== [];
	}

	/**
	 * The team accounts somebody may post as right now, asked of the group
	 * manager.
	 *
	 * @return Person[]
	 */
	public function forUser(string $userId): array {
		$user = $this->userManager->get($userId);
		if ($user === null) {
			return [];
		}

		$groups = $this->groupManager->getUserGroupIds($user);

		$teams = [];
		foreach ($this->teamsRequest->getByGroups($groups) as $row) {
			try {
				$actor = $this->accountService->getFromId($row['actor_id']);
				$actor->setExportFormat(ACore::FORMAT_LOCAL);
				$teams[] = $actor;
			} catch (Throwable $e) {
				// a team whose actor is gone is not one to offer
				$this->logger->debug('a team account could not be read', [
					'actor' => $row['actor_id'], 'reason' => $e->getMessage(),
				]);
			}
		}

		return $teams;
	}

	/**
	 * The team account somebody is allowed to post as, by its handle.
	 *
	 * @throws ItemNotFoundException the handle is not a team account of this
	 *                               instance, or this person is not in its group
	 */
	public function assertMayPostAs(string $userId, string $handle): Person {
		$handle = ltrim(trim($handle), '@');
		if ($handle === '') {
			throw new ItemNotFoundException('no such team');
		}

		try {
			$actor = $this->accountService->getActor($handle);
			$groupId = $this->teamsRequest->groupOf($actor->getId());
		} catch (Throwable $e) {
			throw new ItemNotFoundException('no such team');
		}

		if (!$this->isMember($userId, $groupId)) {
			// Not "forbidden": whether a team exists is not confirmed to outsiders.
			throw new ItemNotFoundException('no such team');
		}

		return $actor;
	}

	/** Whether somebody is in the group behind a team account. */
	public function isMember(string $userId, string $groupId): bool {
		return $this->userManager->get($userId) !== null
			&& $this->groupManager->isInGroup($userId, $groupId);
	}

	/** Records who wrote one post from a team account. */
	public function recordAuthor(string $streamId, Person $author): void {
		try {
			$this->teamsRequest->recordAuthor($streamId, $author->getId());
		} catch (Throwable $e) {
			// The post is already federated; a lost trail does not fail it.
			$this->logger->warning('could not record who wrote a team post', [
				'post' => $streamId, 'exception' => $e,
			]);
		}
	}

	/**
	 * Who wrote each of these posts, for team members and moderators; anybody
	 * else gets an empty answer, so a caller cannot forget to check.
	 *
	 * @param string[] $streamIds
	 *
	 * @return array<string, string> keyed by the hash of the post's id
	 */
	public function authorsFor(array $streamIds, ?string $userId, bool $isModerator = false): array {
		if ($streamIds === [] || ($userId === null && !$isModerator)) {
			return [];
		}

		return $this->teamsRequest->authorsOf($streamIds);
	}

	/** Takes a team account's binding away; the actor is deleted separately. */
	public function delete(string $handle): bool {
		try {
			$actor = $this->accountService->getActor(ltrim($handle, '@'));
		} catch (Throwable $e) {
			return false;
		}

		return $this->teamsRequest->delete($actor->getId());
	}

	/**
	 * Every team account on this instance.
	 *
	 * @return array<int, array{actor_id: string, group_id: string}>
	 */
	public function all(): array {
		return $this->teamsRequest->getAll();
	}
}
