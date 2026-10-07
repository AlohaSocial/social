<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\AccountNotesRequest;
use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\MuteExpiryRequest;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\Relationship;

/**
 * The three things one account keeps about another beside a follow, a block or
 * a mute: a private note, an endorsement, and the moment a mute runs out.
 *
 * None of them is federated and none of them is visible to the account they are
 * about — an endorsement is published on the viewer's own profile, but the row
 * here is only ever read for the account that wrote it.
 */
class AccountRelationService {
	/**
	 * An endorsement is a row in `social_actor_relation`, the table that
	 * already holds blocks and mutes, because it is exactly that shape: one
	 * actor, one other actor, one word, no payload. It gets that table's unique
	 * index — endorsing twice is endorsing once — and its account-deletion
	 * cleanup for nothing. The timelines ignore it: they hide the types they
	 * name, and this is not one of them.
	 */
	public const TYPE_ENDORSE = 'endorse';

	/**
	 * The bell on a profile: "tell me when this account posts".
	 *
	 * The same shape as an endorsement, and in the same table for the same
	 * reasons. Separate from the follow, because Mastodon's is: somebody may
	 * follow an account without wanting to be told every time it writes, and
	 * `POST /accounts/{id}/follow` carries `notify` for exactly that.
	 */
	public const TYPE_NOTIFY = 'notify';

	/**
	 * A suggestion the viewer said no to.
	 *
	 * Mastodon's `DELETE /api/v1/suggestions/{id}`, and the reason it exists:
	 * a "who to follow" panel that offers the same account again after it was
	 * dismissed is a panel people stop reading. Kept as a relation rather than
	 * a decision with a lifetime — an account dismissed once stays dismissed,
	 * because the next suggestion run would otherwise rank it back to the top
	 * for the same reasons it ranked it there before.
	 */
	public const TYPE_SUGGESTION_DISMISSED = 'suggest_no';

	/**
	 * "Show me this account's notifications after all" — the answer to a
	 * notification request, stored against the sender so that it settles
	 * everything they have sent and will send.
	 */
	public const TYPE_NOTIFICATIONS_ACCEPTED = 'notif_ok';

	/** "Stop asking me about this account." The other answer. */
	public const TYPE_NOTIFICATIONS_DISMISSED = 'notif_no';

	/** What Mastodon caps a note at. */
	public const MAX_NOTE = 2000;

	/**
	 * The longest mute this stores. Nothing refuses a longer one — it is
	 * written as this, and a mute that ends in ten years is a permanent mute in
	 * every way a user can tell, while an unbounded `time() + duration`
	 * overflows the column it is written to.
	 */
	public const MAX_DURATION = 315360000;

	public function __construct(
		private AccountNotesRequest $accountNotesRequest,
		private ActorRelationRequest $actorRelationRequest,
		private FollowsRequest $followsRequest,
		private MuteExpiryRequest $muteExpiryRequest,
		private DomainBlockService $domainBlockService,
		private RelationshipService $relationshipService,
	) {
	}

	/**
	 * The note as it is stored: what was typed, without the space around it and
	 * no longer than Mastodon keeps one. An empty note is not a row — clearing
	 * a note and never having written one are the same state, and Mastodon
	 * clears one by sending an empty string.
	 */
	public static function normaliseNote(string $note): string {
		$note = trim($note);

		// characters, not bytes: cutting mid-character would store a broken one
		return (mb_strlen($note) > self::MAX_NOTE) ? mb_substr($note, 0, self::MAX_NOTE) : $note;
	}

	/**
	 * When a mute asked for now with `$duration` seconds runs out; 0 for a mute
	 * that does not.
	 *
	 * A duration of 0 is Mastodon's "until I say otherwise". A negative one is
	 * a mute that has already expired, which is not a mute at all: it is read
	 * as permanent, because a client that sent it meant to mute.
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
	 *                                  account you do not follow — Mastodon's
	 *                                  "Account must be followed", because an
	 *                                  endorsement is published as part of
	 *                                  saying who you follow
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
			// a follow the other side has not answered is not a follow yet, and
			// featuring somebody who may still refuse would publish a claim the
			// viewer has not earned
			throw new InvalidResourceException('Account must be followed');
		}

		$this->actorRelationRequest->save($viewer->getId(), $target->getId(), self::TYPE_ENDORSE);
	}

	/** Unfeaturing an account that was not featured is not an error. */
	/**
	 * Turns the bell on a profile on or off.
	 *
	 * Writing it needs no follow: Mastodon sends `notify` *with* the follow, so
	 * demanding an accepted follow first would refuse the one call that ever
	 * sets it. What it subscribes to is the account posting, which is public
	 * of anybody whose posts the subscriber can already see.
	 */
	public function setNotify(Person $viewer, Person $target, bool $notify): void {
		if ($viewer->getId() === $target->getId()) {
			// being told about your own posts is a notification nobody wants
			return;
		}

		if ($notify) {
			$this->actorRelationRequest->save($viewer->getId(), $target->getId(), self::TYPE_NOTIFY);

			return;
		}

		$this->actorRelationRequest->delete($viewer->getId(), $target->getId(), self::TYPE_NOTIFY);
	}

	/**
	 * Stops suggesting an account to this viewer.
	 *
	 * Dismissing the same account twice is not an error — the table's unique
	 * index makes the second write the first one again, and a client that
	 * retries a dropped request must not be told it was wrong.
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
	 * Answers a notification request with yes. Any previous "no" about the
	 * same account goes: the two are one decision with two values, and a row
	 * of each would leave which one applies to the reading of the table.
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
	 * Takes back a "yes" to a notification request: the sender is judged by
	 * the policy again, as if nobody had decided about them. A "no" is left
	 * where it is; this undoes acceptance only.
	 */
	public function forgetAcceptedSender(Person $viewer, Person $sender): void {
		$this->actorRelationRequest->delete(
			$viewer->getId(), $sender->getId(), self::TYPE_NOTIFICATIONS_ACCEPTED
		);
	}

	/**
	 * What this reader has already decided about a set of senders.
	 *
	 * One query for the whole set: a page of notifications comes from a
	 * handful of accounts, and asking per account would be a query per row for
	 * an answer that does not change inside one page.
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
	 * Whether this viewer wants to see the account's boosts. Nothing stored
	 * means yes, which is what an account that never touched the setting
	 * expects.
	 */
	public function isShowingReblogs(string $viewerId, string $targetId): bool {
		return !$this->actorRelationRequest->exists($viewerId, $targetId, ActorRelation::TYPE_HIDE_REBLOGS);
	}

	/**
	 * Turns this account's boosts on or off in the viewer's timelines.
	 *
	 * Like the bell, it needs no follow first: Mastodon sends `reblogs` *with*
	 * the follow, and demanding an accepted follow would refuse the one call
	 * that ever sets it.
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
	 * expiry rather than writing one, so re-muting an account permanently ends
	 * a mute that used to be timed.
	 */
	public function setMuteExpiry(Person $viewer, Person $target, int $duration, ?int $now = null): int {
		$expiresAt = self::expiryOf($duration, $now);
		if ($expiresAt === 0) {
			$this->muteExpiryRequest->delete($viewer->getId(), $target->getId());

			return 0;
		}

		$this->muteExpiryRequest->save($viewer->getId(), $target->getId(), $expiresAt);

		return $expiresAt;
	}

	/**
	 * Records an expiry that was decided elsewhere, as the moment itself.
	 *
	 * `setMuteExpiry()` takes a duration, which is what a client sends and
	 * what a mute taken now is made of; an account export carries the moment
	 * the mute runs out, and turning it back into a duration would move every
	 * expiry forward by however long the archive sat in a download folder. A
	 * moment already past is stored as it is: it says the mute has ended, and
	 * every read of it already knows what that means.
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
	 * Whether the mute the viewer holds over this account has run out.
	 *
	 * Asked of the read rather than of a cron job: a mute stops applying the
	 * second it expires, on an instance whose background jobs never run as much
	 * as on one where they do.
	 */
	public function isMuteExpired(string $viewerId, string $actorId, ?int $now = null): bool {
		$expiresAt = $this->muteExpiryRequest->getExpiry($viewerId, $actorId);

		return $expiresAt !== 0 && $expiresAt <= ($now ?? time());
	}

	/**
	 * The accounts of a mutes listing, without the ones whose mute has run out.
	 *
	 * One query for the whole page — the listing is a page of accounts, not one
	 * account asked about repeatedly.
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
		$now ??= time();

		return array_values(
			array_filter($accounts, static function (Person $account) use ($expiries, $now): bool {
				$expiresAt = $expiries[$account->getId()] ?? 0;

				return $expiresAt === 0 || $expiresAt > $now;
			})
		);
	}

	/**
	 * The three fields of a Relationship that no other lookup fills in, and the
	 * one it fills in too generously.
	 *
	 * `domain_blocking`, `note` and `endorsed` are each a fact about the pair
	 * the relationship is being built for, and `muting` is read off a row that
	 * may have run out — the mute is left in place and stops being reported,
	 * which is what makes an expiry work without anything deleting it.
	 *
	 * `endorsed` is not read here: the relation row it comes from is already in
	 * hand where this is called, and asking for it again would be a query for
	 * something the caller just read.
	 */
	/**
	 * `decorate()` for a whole page, in two queries instead of two per account.
	 *
	 * The single-account version is a lookup of the note and a lookup of the
	 * mute's expiry; asked about forty accounts it was eighty round trips.
	 * `domain_blocking` is not among them: it reads a per-viewer list that is
	 * already memoised, so the first account pays for it and the rest do not.
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
		$now ??= time();

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
		if ($expiresAt !== 0 && $expiresAt <= ($now ?? time())) {
			$relationship->setMuting(false)
				->setMutingNotifications(false);
			$expiresAt = 0;
		}
		$relationship->setMuteExpiresAt($expiresAt);

		return $relationship;
	}
}
