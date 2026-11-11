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
 * An account a team posts from.
 *
 * Pixelfed's answer to "several people, one voice" is its `Group*` family:
 * twenty models, still in beta, and a second social graph beside the one it
 * already has. Nextcloud's answer is the one it has had all along — a group of
 * people who already work together — and this is that group given an account.
 * It is the one thing in this comparison that Nextcloud can do and Pixelfed
 * cannot, because Pixelfed has no idea who works with whom.
 *
 * A team account is **an actor like any other**. That is the whole design: it
 * has a key pair, a followers collection, an inbox and an outbox, it can be
 * followed from Mastodon and Pixelfed, it can be moderated, it can be
 * suspended, and none of that needed writing again. What is new is only who is
 * allowed to speak as it.
 *
 * **The membership is the Nextcloud group, live.** No membership is copied
 * here, deliberately: a copy of a group is a copy that drifts, and the drift
 * is somebody who left the organisation still able to post as it. Every check
 * asks the group manager at the moment somebody tries.
 *
 * **Who wrote a post is recorded, and shown inside the team.** Outside it the
 * team speaks with one voice, which is the point of having one; inside it,
 * somebody has to be able to find out which of them wrote the thing everybody
 * is now talking about. A moderator sees it too — a report about a team
 * account is otherwise a report about nobody.
 */
class TeamService {
	/**
	 * The `user_id` a team actor is stored under.
	 *
	 * Actors are keyed by the Nextcloud user they belong to, and a team
	 * belongs to no one user. The prefix is reserved rather than clever: a
	 * Nextcloud user id may not contain a slash, so nothing a real account is
	 * ever stored under can collide with this, and a team actor therefore
	 * never resolves as somebody's own account on any path that looks one up
	 * by user.
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
	 * Gives a group an account to post from.
	 *
	 * An administrator's act, which is why there is no route for it: creating
	 * an actor on this instance makes an address other servers will follow and
	 * cache, and that is not a thing to hand to anybody who happens to be in a
	 * group.
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
	 * The team accounts somebody may post as, right now.
	 *
	 * Asked of the group manager rather than of a stored membership: somebody
	 * who left the group this morning may not post as it this afternoon, and
	 * the only way to be sure of that is to ask.
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
			// not "forbidden": whether an account is a team's, and which group
			// is behind it, is not something to confirm to somebody who is not
			// in it
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
			// the post is already out; losing the trail is bad but it is not a
			// reason to fail a post that has been federated
			$this->logger->warning('could not record who wrote a team post', [
				'post' => $streamId, 'exception' => $e,
			]);
		}
	}

	/**
	 * Who wrote each of these posts, for a reader who is allowed to know.
	 *
	 * Outside the team the team speaks with one voice; inside it, and to a
	 * moderator, the trail is the point. A reader who is neither gets an empty
	 * answer rather than a filtered one, so a caller cannot forget to check.
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
