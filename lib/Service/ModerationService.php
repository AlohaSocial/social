<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\ModerationRequest;
use OCA\Social\Db\StreamDestRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\AccountMovedException;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Moderation;
use OCA\Social\Model\Strike;
use Psr\Log\LoggerInterface;

/**
 * Acting on a report: silencing, suspending, and taking one post down.
 *
 * Silencing is reversible and touches nothing: followers still reach the
 * account, and it leaves the public and global timelines. Suspending removes
 * what it posted here and refuses what it sends next, which is why decisions
 * are recorded. Lifting a suspension does not bring back what was deleted.
 */
class ModerationService {
	public function __construct(
		private ModerationRequest $moderationRequest,
		private StreamRequest $streamRequest,
		private StreamDestRequest $streamDestRequest,
		private StreamService $streamService,
		private ActorsRequest $actorsRequest,
		private AccountService $accountService,
		private LoggerInterface $logger,
		private StrikeService $strikeService,
		private ActorCascadeService $actorCascadeService,
		private AuditService $auditService,
	) {
	}

	/**
	 * @return Moderation[]
	 */
	public function decisions(): array {
		return $this->moderationRequest->getAll();
	}

	public function levelOf(string $actorId): string {
		return $this->moderationRequest->levelOf($actorId);
	}

	/** @return string[] actor ids kept out of the public timelines */
	public function silenced(): array {
		return $this->moderationRequest->getActorIdsAt(Moderation::SILENCE);
	}

	/**
	 * Marks every post by an account sensitive, or stops: the step between doing
	 * nothing and silencing. Applies from the next post; stored posts keep their
	 * own flag.
	 */
	public function forceSensitive(string $actorId, bool $sensitive): void {
		// the flag lives on a decision row, so an account without one gets one
		// that decides nothing else
		if ($this->moderationRequest->levelOf($actorId) === '') {
			$this->moderationRequest->save(new Moderation($actorId, '', '', time()));
		}

		$this->moderationRequest->setForceSensitive($actorId, $sensitive);
		$this->auditService->accountDecided(
			$actorId, $sensitive ? 'force_sensitive' : 'force_sensitive_lifted'
		);
	}

	/** @return string[] the accounts every post of which is marked sensitive */
	public function forcedSensitive(): array {
		return $this->moderationRequest->forcedSensitive();
	}

	public function isSuspended(string $actorId): bool {
		return $this->moderationRequest->levelOf($actorId) === Moderation::SUSPEND;
	}

	/**
	 * Which of these accounts are suspended, asked for a page of them at once.
	 *
	 * @param string[] $actorIds
	 * @return array<string, true> actor id => true
	 */
	public function suspendedAmong(array $actorIds): array {
		return $this->moderationRequest->amongAt($actorIds, Moderation::SUSPEND);
	}

	/**
	 * Refuses a suspended account anything it would send out, asked at the
	 * service performing the action so no entry point can miss it.
	 *
	 * @throws InvalidActionException
	 */
	public function assertNotSuspended(string $actorId): void {
		if ($this->isSuspended($actorId)) {
			throw new InvalidActionException('this account is suspended');
		}
	}

	/**
	 * A local account that moved away may not post or follow from here, as on
	 * Mastodon: its followers were told to follow the new account. Undoing the
	 * move on the Migration page lifts it.
	 *
	 * @throws AccountMovedException
	 */
	public function assertNotMoved(Person $actor): void {
		if ($actor->isLocal() && $actor->getMovedTo() !== '') {
			throw new AccountMovedException(
				'this account has moved to ' . $actor->getMovedTo() . '; undo the move to post or follow from here again'
			);
		}
	}

	/**
	 * Whether the account behind a local handle is suspended, for the entry
	 * points that serve an account by handle (the actor document, WebFinger),
	 * which answer 410 for one, as Mastodon does.
	 */
	public function isSuspendedAccount(string $handle): bool {
		try {
			return $this->isSuspended($this->actorsRequest->getFromUsername($handle)->getId());
		} catch (ActorDoesNotExistException $e) {
			return false;
		}
	}

	/**
	 * The same question asked of a Nextcloud user, for the session and
	 * credentials paths.
	 */
	public function isSuspendedUser(string $userId): bool {
		if ($userId === '') {
			return false;
		}

		try {
			return $this->isSuspended($this->actorsRequest->getFromUserId($userId)->getId());
		} catch (ActorDoesNotExistException $e) {
			return false;
		}
	}

	/**
	 * Records a decision and applies it. `social_moderation` holds what stands
	 * now, one row an account, replaced or removed; the strike is the history,
	 * never replaced and kept on a lift.
	 *
	 * @param string $actorId the account
	 * @param string $level one of Moderation::LEVELS
	 * @param string $comment why, for whoever reads the list later
	 * @param int $reportId the report this came from, or 0
	 */
	public function decide(
		string $actorId, string $level, string $comment = '', int $reportId = 0,
	): Moderation {
		if (!in_array($level, Moderation::LEVELS, true)) {
			throw new \InvalidArgumentException('unknown moderation level: ' . $level);
		}

		$moderation = new Moderation($actorId, $level, $comment, time());
		$this->moderationRequest->save($moderation);
		$this->strikeService->record($actorId, $level, $comment, $reportId);

		if ($level === Moderation::SUSPEND) {
			$this->purgeActor($actorId);
			$this->federateSuspension($actorId);
		}

		// not 'level': the server's logger reads that key in a context as a log
		// level and throws on anything that is not one
		$this->logger->info('moderation decision applied', ['actor' => $actorId, 'decision' => $level]);
		$this->auditService->accountDecided($actorId, $level);

		return $moderation;
	}

	/**
	 * Tells the fediverse about the suspension of a local account with the
	 * `Delete` its own deletion sends, so remote copies of it and its posts go
	 * too. Only for local accounts: a `Delete` signed for somebody else's actor
	 * means nothing elsewhere. A failure is only logged; the decision stands.
	 */
	private function federateSuspension(string $actorId): void {
		try {
			$actor = $this->actorsRequest->getFromId($actorId);
			$this->accountService->federateActorDelete($actor);
			$this->logger->info('suspension federated', ['actor' => $actorId]);
		} catch (ActorDoesNotExistException $e) {
			// not one of ours, which is the ordinary case for a suspension
			return;
		} catch (\Exception $e) {
			$this->logger->error('could not federate a suspension', [
				'actor' => $actorId, 'exception' => $e,
			]);
		}
	}

	/**
	 * Lifts a decision; what a suspension deleted stays deleted. The strikes
	 * stay, and the lift is recorded too, against the moderator who took it.
	 *
	 * @param string $comment why, for whoever reads the history later
	 */
	public function lift(string $actorId, string $comment = ''): void {
		$this->moderationRequest->delete($actorId);
		$this->restoreLocalActor($actorId);
		$this->strikeService->record($actorId, Strike::LIFT, $comment);
		$this->logger->info('moderation decision lifted', ['actor' => $actorId]);
		$this->auditService->accountLifted($actorId);
	}

	/**
	 * Puts a lifted local account back where this instance serves it from: the
	 * suspension dropped the cached actor, which would otherwise come back only
	 * with the next cache cron.
	 */
	private function restoreLocalActor(string $actorId): void {
		try {
			$actor = $this->actorsRequest->getFromId($actorId);
		} catch (ActorDoesNotExistException $e) {
			// not one of ours: there is no local copy to rebuild
			return;
		}

		try {
			$this->accountService->cacheLocalActorByUsername($actor->getPreferredUsername());
		} catch (\Exception $e) {
			$this->logger->error('could not restore the actor of a lifted account', [
				'actor' => $actorId, 'exception' => $e,
			]);
		}
	}

	/**
	 * Says something and applies nothing: Mastodon's `none`, a warning lighter
	 * than taking an account out of the timelines.
	 *
	 * @param string $text what the account is shown
	 * @param int $reportId the report this came from, or 0
	 */
	public function warn(string $actorId, string $text = '', int $reportId = 0): Strike {
		$strike = $this->strikeService->record($actorId, Strike::WARNING, $text, $reportId);
		$this->logger->info('account warned', ['actor' => $actorId]);

		return $strike;
	}

	/**
	 * What has been decided about an account before now, newest first.
	 *
	 * @return Strike[]
	 */
	public function history(string $actorId): array {
		return $this->strikeService->history($actorId);
	}

	/**
	 * How many strikes each of these accounts has, in one query.
	 *
	 * @param string[] $actorIds
	 *
	 * @return array<string, int> actor id => how many, missing when none
	 */
	public function strikeCounts(array $actorIds): array {
		return $this->strikeService->countFor($actorIds);
	}

	/** Who wrote a post, or '' when there is no such post. */
	public function authorOf(string $streamId): string {
		try {
			return $this->streamRequest->getStreamById($streamId)->getAttributedTo();
		} catch (StreamNotFoundException $e) {
			return '';
		}
	}

	/**
	 * Takes one post down, whoever wrote it. A local post goes as its author's
	 * delete does, with a federated Delete to whoever holds a copy; a remote post
	 * is dropped here only, as this instance is not its origin.
	 */
	public function removeStream(string $streamId): void {
		try {
			$stream = $this->streamRequest->getStreamById($streamId);
		} catch (StreamNotFoundException $e) {
			return;
		}

		if ($stream->isLocal()) {
			try {
				$this->streamService->deleteLocalItem($stream);
			} catch (\Exception $e) {
				// a takedown does not wait on the fediverse: the post goes even when the
				// Delete cannot be built
				$this->logger->error('could not federate a moderator takedown', [
					'stream' => $streamId, 'exception' => $e,
				]);
				$this->streamRequest->deleteById($streamId);
			}
		} else {
			$this->streamRequest->deleteById($streamId);
		}

		// recorded against the account that wrote it, where a moderator looks
		$author = $stream->getAttributedTo();
		if ($author !== '') {
			$this->strikeService->record($author, Strike::TAKEDOWN, $streamId);
		}

		$this->logger->info('post removed by a moderator', ['stream' => $streamId]);
		$this->auditService->postTakenDown($streamId);
	}

	/**
	 * Everything an account has here, its cached actor and relationships
	 * included, so it is neither served nor delivered to. What other accounts
	 * own (their blocks, mutes, notes, reports) stays, so a lifted account comes
	 * back still blocked; `ActorCascadeService` lists the tables. Public, since a
	 * domain purge detaches accounts the same way.
	 */
	public function purgeActor(string $actorId): void {
		try {
			$this->streamRequest->deleteByAuthor($actorId);
		} catch (\Exception $e) {
			$this->logger->error('could not remove the posts of a suspended account', [
				'actor' => $actorId, 'exception' => $e,
			]);
		}

		$this->actorCascadeService->purge($actorId, reversible: true);

		try {
			// what put its posts in a local timeline, and what addressed local
			// posts to it
			$this->streamDestRequest->deleteRelatedToActor($actorId);
		} catch (\Exception $e) {
			$this->logger->error('could not detach a suspended account', [
				'actor' => $actorId, 'what' => 'dest', 'exception' => $e,
			]);
		}
	}
}
