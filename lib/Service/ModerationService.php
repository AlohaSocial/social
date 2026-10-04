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
 * Acting on a report.
 *
 * The reports panel could describe a problem and do nothing about it: resolve
 * marked the complaint handled and left the account exactly as it was. These
 * are the two decisions a moderator actually needs, plus the ability to take
 * one post down.
 *
 * Silencing is reversible and touches nothing: the account stays reachable for
 * the people who chose to follow it, and leaves the public and global
 * timelines. Suspending removes what the account has posted here and refuses
 * what it sends next, so it is the one that needs care — and it is why the
 * decision is recorded rather than only applied. Lifting a suspension stops
 * the refusal; it does not bring back what was deleted, and the confirmation
 * in the admin panel says so.
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
	 * Marks every post by an account sensitive, or stops doing so.
	 *
	 * The step between doing nothing and silencing: an account can be asked to
	 * put a content warning on its pictures without being taken out of the
	 * timelines, which is exactly the case silence is too heavy for. It
	 * applies from the next post — what is already stored was stored with the
	 * flag the author gave it, and rewriting somebody's old posts is a
	 * different and much larger decision.
	 */
	public function forceSensitive(string $actorId, bool $sensitive): void {
		// there has to be a decision row to put it on: an account nobody has
		// decided anything about gets one that decides nothing else
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
	 * Refuses an account that is suspended here anything it would send out.
	 *
	 * A suspension used to be enforced on the way in only, so a local account
	 * suspended by its own admin went on posting, boosting and following from
	 * every client — and this instance went on federating it. Asked at the
	 * service that performs the action, so no entry point can miss it.
	 *
	 * @throws InvalidActionException
	 */
	public function assertNotSuspended(string $actorId): void {
		if ($this->isSuspended($actorId)) {
			throw new InvalidActionException('this account is suspended');
		}
	}

	/**
	 * A local account that moved away may not post or follow from here: its
	 * followers were told to follow the new account, so anything written here
	 * reaches nobody, and Mastodon locks a moved account the same way. Undoing
	 * the move (Settings → Migration) lifts it.
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
	 * Whether the account behind a local handle is suspended.
	 *
	 * For the entry points that serve an account rather than act for it — the
	 * actor document and the WebFinger answer, which have a handle and no
	 * actor id. Mastodon answers 410 for one of these; this instance answered
	 * with a live account and an empty outbox, because suspension was only
	 * ever asked about by the services that write.
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
	 * credentials paths: a suspended account kept the whole interface and the
	 * client API except the five services that refuse a write.
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
	 * Records a decision and applies it.
	 *
	 * Two records, and they are not the same record. `social_moderation` holds
	 * what stands *now* — one row an account, replaced by the next decision,
	 * gone when it is lifted. The strike is the history: it is never replaced
	 * and a lift does not remove it, so the third silence in a month can be
	 * told from the first.
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
	 * Tells the fediverse about the suspension of a **local** account.
	 *
	 * Suspending deletes everything the account posted here and stops this
	 * instance serving its actor. Without this, that is all it did: every
	 * remote instance kept its copy of the account and of every post, so an
	 * account removed by a moderator here carried on existing everywhere else,
	 * and the takedown stopped at our own edge. The `Delete` is the same one
	 * the account's own deletion sends, which is what a peer already knows how
	 * to act on.
	 *
	 * Only for local accounts. A `Delete` this instance signed for somebody
	 * else's actor is not one any other server would act on, and suspending a
	 * remote account is a decision about what *this* instance shows.
	 *
	 * The decision stands whatever the fediverse makes of it: a failure here is
	 * logged and nothing else. The account is already gone locally, and a
	 * moderator waiting on a delivery queue is a moderator who cannot moderate.
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
	 * Lifts a decision. What a suspension deleted stays deleted — this only
	 * stops the instance refusing what the account sends from now on.
	 *
	 * The strikes stay too. A lift says the decision no longer stands, not
	 * that it was never taken, and an account whose history is emptied by
	 * lifting the last decision against it is an account nobody can tell has
	 * been here before.
	 *
	 * The lift is itself recorded, against the moderator who took it. Until
	 * this it was an info line in the app log and nothing else: the history
	 * showed a suspension with no end, so nobody reading it afterwards could
	 * tell an account still suspended from one let off the same afternoon.
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
	 * Puts a lifted local account back where this instance serves it from.
	 *
	 * A suspension drops the cached copy of the actor, which is what the actor
	 * document, the WebFinger answer and the timelines read; nothing rebuilds
	 * it while the suspension stands. Without this the account comes back at
	 * the next pass of the cache cron and not before, so a lift a moderator
	 * took in front of somebody did nothing they could see.
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
	 * Says something and applies nothing: Mastodon's `none`.
	 *
	 * The step the ladder was missing. Without it the lightest thing a
	 * moderator could do to an account was take it out of the timelines,
	 * which is a great deal to reach for over a first offence — so nothing
	 * was done at all, and the account was never told there was a problem.
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

	/**
	 * Takes one post down, whoever wrote it.
	 *
	 * A post of this instance's own goes the way its author's own delete goes:
	 * a federated Delete to the followers, boosters and repliers holding a
	 * copy. Dropping only the row left a post taken down here live on every
	 * other instance that ever saw it. A post from elsewhere is dropped here
	 * and nowhere else — this instance is not its origin, and a Delete it
	 * signed for somebody else's post is not one any other server would act on.
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
				// a takedown does not wait on the fediverse: the post goes, and
				// the Delete that could not be built is what is lost
				$this->logger->error('could not federate a moderator takedown', [
					'stream' => $streamId, 'exception' => $e,
				]);
				$this->streamRequest->deleteById($streamId);
			}
		} else {
			$this->streamRequest->deleteById($streamId);
		}

		// against the account that wrote it, which is where a moderator looks
		// for what has been done about somebody. A takedown used to leave no
		// record at all: the post was gone, and the only trace was a line in
		// the app log naming neither the author nor the moderator.
		$author = $stream->getAttributedTo();
		if ($author !== '') {
			$this->strikeService->record($author, Strike::TAKEDOWN, $streamId);
		}

		$this->logger->info('post removed by a moderator', ['stream' => $streamId]);
		$this->auditService->postTakenDown($streamId);
	}

	/**
	 * Everything an account has here. Its cached actor goes too, so nothing of
	 * it is served from this instance afterwards.
	 *
	 * The relationships go with it. A suspension that left the follow rows in
	 * place kept the account in the delivery fan-out — every local post still
	 * went to it — and kept it in the timelines of the people who followed it,
	 * addressed through the dest rows.
	 *
	 * What it does *not* take is what other accounts own: their block or mute
	 * of it, their note about it, the report they filed. A suspension can be
	 * lifted, and an account let back in must come back to the people who had
	 * blocked it still blocked — see `ActorCascadeService`, which is the one
	 * list of tables a deletion and a suspension both work from.
	 *
	 * Public because a domain purge detaches accounts one at a time and has to
	 * detach exactly what a suspension detaches.
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
