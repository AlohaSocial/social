<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Atproto\Reader\ActivitySubscriptions;
use OCA\Social\Db\AccountNotesRequest;
use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\MuteExpiryRequest;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\Relationship;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Container\ContainerInterface;

/**
 * The three things one account keeps about another beside a follow, a block or
 * a mute: a private note, an endorsement, and the moment a mute runs out. None
 * is federated, and the rows are only read for the account that wrote them.
 */
class AccountRelationService {
	/**
	 * An endorsement is a row in `social_actor_relation`, beside blocks and
	 * mutes, for its unique index and account-deletion cleanup. The timelines
	 * ignore it.
	 */
	public const TYPE_ENDORSE = 'endorse';

	/**
	 * The bell on a profile: "tell me when this account posts". Separate from
	 * the follow, as in Mastodon, where `POST /accounts/{id}/follow` carries
	 * `notify`.
	 */
	public const TYPE_NOTIFY = 'notify';

	/**
	 * A suggestion the viewer dismissed (Mastodon's
	 * `DELETE /api/v1/suggestions/{id}`). Permanent, since the next suggestion
	 * run would rank it back up for the same reasons.
	 */
	public const TYPE_SUGGESTION_DISMISSED = 'suggest_no';

	/**
	 * "Show me this account's notifications after all": the answer to a
	 * notification request, settling everything the sender has sent and will send.
	 */
	public const TYPE_NOTIFICATIONS_ACCEPTED = 'notif_ok';

	/** "Stop asking me about this account." The other answer. */
	public const TYPE_NOTIFICATIONS_DISMISSED = 'notif_no';

	/** What Mastodon caps a note at. */
	public const MAX_NOTE = 2000;

	/**
	 * The longest mute this stores; a longer one is written as this, since an
	 * unbounded `time() + duration` overflows the column.
	 */
	public const MAX_DURATION = 315360000;

	public function __construct(
		private AccountNotesRequest $accountNotesRequest,
		private ActorRelationRequest $actorRelationRequest,
		private FollowsRequest $followsRequest,
		private MuteExpiryRequest $muteExpiryRequest,
		private DomainBlockService $domainBlockService,
		private RelationshipService $relationshipService,
		private ?ContainerInterface $container = null,
		private ?ITimeFactory $timeFactory = null,
	) {
	}

	/**
	 * The note as it is stored: trimmed and no longer than Mastodon keeps one.
	 * An empty note is no row; Mastodon clears a note with an empty string.
	 */
	public static function normaliseNote(string $note): string {
		$note = trim($note);

		// characters, not bytes: cutting mid-character would store a broken one
		return (mb_strlen($note) > self::MAX_NOTE) ? mb_substr($note, 0, self::MAX_NOTE) : $note;
	}

	/**
	 * When a mute asked for now with `$duration` seconds runs out; 0 for a
	 * mute that does not. 0 is Mastodon's "until I say otherwise", and a
	 * negative duration is read as permanent too.
	 */
	public static function expiryOf(int $duration, ?int $now = null): int {
		if ($duration <= 0) {
			return 0;
		}

		return ($now ?? time()) + min($duration, self::MAX_DURATION);
	}

	public function setNote(Person $viewer, Person $target, string $note): string {
		$note = self::normaliseNote($note);
		if ($note === '') {
			$this->accountNotesRequest->delete($viewer->getId(), $target->getId());

			return '';
		}

		$this->accountNotesRequest->save($viewer->getId(), $target->getId(), $note);

		return $note;
	}

	public function getNote(string $viewerId, string $actorId): string {
		return $this->accountNotesRequest->getNote($viewerId, $actorId);
	}

	/**
	 * @throws InvalidResourceException endorsing yourself, or endorsing an
	 *                                  account you do not follow (Mastodon's
	 *                                  "Account must be followed")
	 */
	public function endorse(Person $viewer, Person $target): void {
		if ($viewer->getId() === $target->getId()) {
			throw new InvalidResourceException('cannot feature your own account');
		}

		try {
			$follow = $this->followsRequest->getByPersons($viewer->getId(), $target->getId());
		} catch (FollowNotFoundException $e) {
			throw new InvalidResourceException('Account must be followed');
		}

		if (!$follow->isAccepted()) {
			// a follow request not yet accepted is not a follow
			throw new InvalidResourceException('Account must be followed');
		}

		$this->actorRelationRequest->save($viewer->getId(), $target->getId(), self::TYPE_ENDORSE);
	}

	/**
	 * Turns the bell on a profile on or off. Needs no follow, since Mastodon
	 * sends `notify` with the follow request.
	 */
	public function setNotify(Person $viewer, Person $target, bool $notify): void {
		if ($viewer->getId() === $target->getId()) {
			// being told about your own posts is a notification nobody wants
			return;
		}

		if ($notify) {
			$this->actorRelationRequest->save($viewer->getId(), $target->getId(), self::TYPE_NOTIFY);
		} else {
			$this->actorRelationRequest->delete($viewer->getId(), $target->getId(), self::TYPE_NOTIFY);
		}
		// a Bluesky account's bell is kept on Bluesky too; resolved here, as
		// the Bluesky side needs services that need this one
		$this->container?->get(ActivitySubscriptions::class)->set($viewer, $target, $notify);
	}

	/**
	 * Stops suggesting an account to this viewer. Dismissing twice is not an
	 * error, so a retried request succeeds.
	 */
	public function dismissSuggestion(Person $viewer, Person $target): void {
		if ($viewer->getId() === $target->getId()) {
			return;
		}

		$this->actorRelationRequest->save(
			$viewer->getId(), $target->getId(), self::TYPE_SUGGESTION_DISMISSED
		);
	}

	/**
	 * Answers a notification request with yes, replacing any "no" about the
	 * same account.
	 */
	public function acceptNotifications(Person $viewer, Person $sender): void {
		if ($viewer->getId() === $sender->getId()) {
			return;
		}

		$this->actorRelationRequest->delete(
			$viewer->getId(), $sender->getId(), self::TYPE_NOTIFICATIONS_DISMISSED
		);
		$this->actorRelationRequest->save(
			$viewer->getId(), $sender->getId(), self::TYPE_NOTIFICATIONS_ACCEPTED
		);
	}

	/** Answers a notification request with no. */
	public function dismissNotifications(Person $viewer, Person $sender): void {
		if ($viewer->getId() === $sender->getId()) {
			return;
		}

		$this->actorRelationRequest->delete(
			$viewer->getId(), $sender->getId(), self::TYPE_NOTIFICATIONS_ACCEPTED
		);
		$this->actorRelationRequest->save(
			$viewer->getId(), $sender->getId(), self::TYPE_NOTIFICATIONS_DISMISSED
		);
	}

	/**
	 * The senders this reader accepted from their requests, newest decision
	 * first, as the cached accounts they are.
	 *
	 * @return Person[]
	 */
	public function acceptedSenders(Person $viewer, int $limit): array {
		return $this->relationshipService->getRelated($viewer, self::TYPE_NOTIFICATIONS_ACCEPTED, $limit);
	}

	/**
	 * Takes back a "yes" to a notification request, so the policy judges the
	 * sender again. A "no" is left as it is.
	 */
	public function forgetAcceptedSender(Person $viewer, Person $sender): void {
		$this->actorRelationRequest->delete(
			$viewer->getId(), $sender->getId(), self::TYPE_NOTIFICATIONS_ACCEPTED
		);
	}

	/**
	 * What this reader has already decided about a set of senders, in one
	 * query for a page of notifications.
	 *
	 * @param string[] $senders actor ids
	 *
	 * @return array{accepted: array<string, bool>, dismissed: array<string, bool>}
	 */
	public function notificationDecisions(string $viewerId, array $senders): array {
		$decisions = ['accepted' => [], 'dismissed' => []];
		if ($senders === []) {
			return $decisions;
		}

		$wanted = array_fill_keys($senders, true);
		foreach ($this->actorRelationRequest->getByActor(
			$viewerId, self::TYPE_NOTIFICATIONS_ACCEPTED, 5000
		) as $relation) {
			if (isset($wanted[$relation->getObjectId()])) {
				$decisions['accepted'][$relation->getObjectId()] = true;
			}
		}

		foreach ($this->actorRelationRequest->getByActor(
			$viewerId, self::TYPE_NOTIFICATIONS_DISMISSED, 5000
		) as $relation) {
			if (isset($wanted[$relation->getObjectId()])) {
				$decisions['dismissed'][$relation->getObjectId()] = true;
			}
		}

		return $decisions;
	}

	/**
	 * Whether this viewer wants to see the account's boosts; yes unless
	 * turned off.
	 */
	public function isShowingReblogs(string $viewerId, string $targetId): bool {
		return !$this->actorRelationRequest->exists($viewerId, $targetId, ActorRelation::TYPE_HIDE_REBLOGS);
	}

	/**
	 * Turns this account's boosts on or off in the viewer's timelines. Like
	 * the bell, it needs no follow first.
	 */
	public function setShowReblogs(Person $viewer, Person $target, bool $show): void {
		if ($viewer->getId() === $target->getId()) {
			return;
		}

		if ($show) {
			$this->actorRelationRequest->delete(
				$viewer->getId(), $target->getId(), ActorRelation::TYPE_HIDE_REBLOGS
			);

			return;
		}

		$this->actorRelationRequest->save(
			$viewer->getId(), $target->getId(), ActorRelation::TYPE_HIDE_REBLOGS
		);
	}

	/**
	 * The accounts whose boosts this viewer has turned off.
	 *
	 * @return string[] actor ids
	 */
	public function hiddenBoosters(string $viewerId): array {
		return array_map(
			static fn (ActorRelation $relation): string => $relation->getObjectId(),
			$this->actorRelationRequest->getByActor($viewerId, ActorRelation::TYPE_HIDE_REBLOGS, 5000)
		);
	}

	/** Whether the viewer has asked to be told when this account posts. */
	public function isNotified(string $viewerId, string $targetId): bool {
		return $this->actorRelationRequest->exists($viewerId, $targetId, self::TYPE_NOTIFY);
	}

	/**
	 * Who asked to be told when this account posts.
	 *
	 * @return string[] actor ids
	 */
	public function subscribersOf(string $targetId): array {
		return $this->actorRelationRequest->getLocalByObject($targetId, self::TYPE_NOTIFY);
	}

	/** Unfeaturing an account that was not featured is not an error. */
	public function unendorse(Person $viewer, Person $target): void {
		$this->actorRelationRequest->delete($viewer->getId(), $target->getId(), self::TYPE_ENDORSE);
	}

	public function isEndorsing(string $viewerId, string $actorId): bool {
		return $this->actorRelationRequest->exists($viewerId, $actorId, self::TYPE_ENDORSE);
	}

	/**
	 * The accounts the viewer features on their profile, newest first.
	 *
	 * @return Person[]
	 */
	public function getEndorsed(Person $viewer, int $limit = 40): array {
		return $this->relationshipService->getRelated($viewer, self::TYPE_ENDORSE, $limit);
	}

	/**
	 * Records when a mute taken now runs out. A duration of 0 removes the
	 * expiry, so re-muting permanently ends a timed mute.
	 */
	public function setMuteExpiry(Person $viewer, Person $target, int $duration, ?int $now = null): int {
		$expiresAt = self::expiryOf($duration, $now ?? $this->now());
		if ($expiresAt === 0) {
			$this->muteExpiryRequest->delete($viewer->getId(), $target->getId());

			return 0;
		}

		$this->muteExpiryRequest->save($viewer->getId(), $target->getId(), $expiresAt);

		return $expiresAt;
	}

	/**
	 * Records an expiry decided elsewhere, as the moment itself: an account
	 * export carries the moment, and turning it into a duration would shift
	 * it. A moment already past is stored as is.
	 *
	 * @param int $expiresAt unix time; 0 clears the expiry, as elsewhere here
	 */
	public function setMuteExpiresAt(Person $viewer, Person $target, int $expiresAt): void {
		if ($expiresAt <= 0) {
			$this->muteExpiryRequest->delete($viewer->getId(), $target->getId());

			return;
		}

		$this->muteExpiryRequest->save($viewer->getId(), $target->getId(), $expiresAt);
	}

	/**
	 * When each of these mutes runs out, in one query.
	 *
	 * @param string[] $actorIds
	 *
	 * @return array<string, int> actor id => unix time; a mute with no expiry
	 *                            is absent, not 0
	 */
	public function muteExpiries(string $viewerId, array $actorIds): array {
		return $this->muteExpiryRequest->getExpiries($viewerId, $actorIds);
	}

	/** Unmuting takes the expiry with it: the mute it belonged to is gone. */
	public function clearMuteExpiry(Person $viewer, Person $target): void {
		$this->muteExpiryRequest->delete($viewer->getId(), $target->getId());
	}

	/**
	 * Whether the mute the viewer holds over this account has run out. Asked
	 * on the read, so an expiry works without background jobs.
	 */
	public function isMuteExpired(string $viewerId, string $actorId, ?int $now = null): bool {
		$expiresAt = $this->muteExpiryRequest->getExpiry($viewerId, $actorId);

		return $expiresAt !== 0 && $expiresAt <= ($now ?? $this->now());
	}

	/**
	 * The accounts of a mutes listing, without the ones whose mute has run
	 * out, in one query for the page.
	 *
	 * @param Person[] $accounts
	 *
	 * @return Person[]
	 */
	public function withoutExpiredMutes(Person $viewer, array $accounts, ?int $now = null): array {
		if ($accounts === []) {
			return [];
		}

		$expiries = $this->muteExpiryRequest->getExpiries(
			$viewer->getId(), array_map(static fn (Person $account): string => $account->getId(), $accounts)
		);
		$now ??= $this->now();

		return array_values(
			array_filter($accounts, static function (Person $account) use ($expiries, $now): bool {
				$expiresAt = $expiries[$account->getId()] ?? 0;

				return $expiresAt === 0 || $expiresAt > $now;
			})
		);
	}

	/**
	 * `decorate()` for a whole page, in two queries instead of two per account.
	 * `domain_blocking` reads a per-viewer list that is already memoised.
	 *
	 * @param array<string, Relationship> $relationships keyed by actor id
	 */
	public function decorateMany(array $relationships, string $viewerId, ?int $now = null): void {
		if ($relationships === []) {
			return;
		}

		$actorIds = array_keys($relationships);
		$notes = $this->accountNotesRequest->getNotes($viewerId, $actorIds);
		$expiries = $this->muteExpiryRequest->getExpiries($viewerId, $actorIds);
		$now ??= $this->now();

		foreach ($relationships as $actorId => $relationship) {
			$relationship->setDomainBlocking($this->domainBlockService->isBlocking($viewerId, $actorId));
			$relationship->setNote($notes[$actorId] ?? '');

			$expiresAt = $expiries[$actorId] ?? 0;
			if ($relationship->isMuting() && $expiresAt !== 0 && $expiresAt <= $now) {
				$relationship->setMuting(false)->setMutingNotifications(false);
				$expiresAt = 0;
			}
			$relationship->setMuteExpiresAt($relationship->isMuting() ? $expiresAt : 0);
		}
	}

	/**
	 * Fills in the Relationship fields no other lookup does (`domain_blocking`,
	 * `note`) and clears `muting` for a mute that has run out, which is how an
	 * expiry works without deleting the row. `endorsed` is set by the caller.
	 */
	public function decorate(
		Relationship $relationship, string $viewerId, string $actorId, ?int $now = null,
	): Relationship {
		$relationship->setDomainBlocking($this->domainBlockService->isBlocking($viewerId, $actorId));
		$relationship->setNote($this->getNote($viewerId, $actorId));

		// read once and used twice: whether the mute still applies, and when
		// it stops, are the same row
		$expiresAt = $relationship->isMuting()
			? $this->muteExpiryRequest->getExpiry($viewerId, $actorId)
			: 0;
		if ($expiresAt !== 0 && $expiresAt <= ($now ?? $this->now())) {
			$relationship->setMuting(false)
				->setMutingNotifications(false);
			$expiresAt = 0;
		}
		$relationship->setMuteExpiresAt($expiresAt);

		return $relationship;
	}

	private function now(): int {
		return $this->timeFactory?->getTime() ?? time();
	}
}
