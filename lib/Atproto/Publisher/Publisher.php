<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Publisher;

use Closure;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\PresenceService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\Move;
use OCA\Social\Atproto\Model\StoredRecord;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Protocol\Tid;
use OCA\Social\Atproto\Repository\CommitResult;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Repository\RepoWrite;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\AtprotoMoveRequest;
use OCA\Social\Db\ImportedPostsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\VerificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Puts what a local account does onto Bluesky: every public post as it is
 * made, deleted or edited, and the profile.
 *
 * Idempotent on purpose. A post is published when it has no record yet,
 * whoever asks — the listener right after the post, the queued job, or the
 * reconcile pass that finds recent missed publications and walks the local
 * record inventory — so a missed event costs minutes, not the post.
 */
class Publisher {
	/** how far back missed first publications are sought */
	public const RECONCILE_WINDOW = 86400;
	/** Keep the inventory cursor long enough to reach old records on busy instances. */
	public const RECONCILE_CURSOR_TTL = 31536000;
	public const RECONCILE_BATCH = 100;
	/** where each reconcile walk got to in its older pages */
	public const RECONCILE_WALK = 'social.atproto.reconcile';
	/** how many posts written during a move away are published before the DID moves, at most */
	public const MOVE_BACKLOG = 500;

	/** set while the posts a move away held back are published */
	private bool $publishingBacklog = false;
	private PostThreadVerifier $threadVerifier;

	public function __construct(
		private AtprotoConfig $config,
		private IdentityService $identities,
		private RepositoryService $repositories,
		private RecordMapper $mapper,
		private VideoUploadService $videos,
		private StreamRequest $streamRequest,
		private CacheActorService $cacheActorService,
		private ITimeFactory $time,
		private LoggerInterface $logger,
		private ImportedPostsRequest $imported,
		private DurableCache $durableCache,
		private ?PresenceService $presence = null,
		private ?ContainerInterface $container = null,
		private ?AtprotoMoveRequest $moves = null,
	) {
		$this->threadVerifier = new PostThreadVerifier($mapper);
	}

	/**
	 * Publishes a post, unless it is not one that goes to Bluesky, is there
	 * already, or its author is not on Bluesky now or was not when they
	 * wrote it. While the author's Bluesky account moves away it waits:
	 * the move publishes what waits right before the DID moves
	 * (publishBacklog()), and once a move is called off or stops before
	 * that, the reconcile pass does.
	 *
	 * @return CommitResult|null the commit, or null when nothing was written
	 * @throws AtprotoException
	 */
	public function publishPost(Stream $post, ?string $requestedRkey = null): ?CommitResult {
		if (!$this->goesToBluesky($post)) {
			return null;
		}
		$existing = $this->recordOf($post->getId());
		if ($existing !== null) {
			// A previous attempt may have committed the root and only part of a
			// long thread. Reconcile the record set on retry instead of treating
			// the root's existence as proof that the whole post was published.
			if (!$this->imported->isImported($post->getAttributedTo(), $post->getId())) {
				$this->updateRecord($post, $existing);
			}
			return null;
		}
		$author = $this->cacheActorService->getFromId($post->getAttributedTo());
		$identity = $this->identities->forActor($author);
		if ($identity === null || !$identity->isActive() || $this->presence?->writtenWhileOff($author, $post) === true) {
			return null;
		}
		if ($this->movingAway($identity)) {
			$this->logger->info('Post waits: the Bluesky account is moving away', ['post' => $post->getId(), 'did' => $identity->did]);

			return null;
		}
		$this->ensureProfile($author, $identity);

		$video = $this->videos->forPost($post, $identity, $author);
		if ($video['state'] === 'waiting') {
			$this->logger->info('Post waits for its video before it goes to Bluesky', ['post' => $post->getId(), 'did' => $identity->did]);

			return null;
		}
		$mapped = $this->mapper->post($post, $identity, $author, $video);
		$rkey = $requestedRkey ?? Tid::next();
		$threadWrites = $this->threadWrites($post, $identity, $mapped, null, $rkey);
		$gateWrites = $this->gateWrites($post, $identity->did, $rkey);
		$writes = $this->rootAndGatesFirst($threadWrites, $rkey, $gateWrites);
		$result = $this->writeChunks($identity, $writes);
		if ($result === null) {
			return null;
		}
		$this->logger->info('Post published to Bluesky', ['post' => $post->getId(), 'did' => $identity->did, 'threaded' => ($mapped['continuations'] ?? []) !== []]);

		return $result;
	}

	/**
	 * Publishes the posts of an author written since a move away started
	 * and held back meanwhile, oldest first: the move does so right before
	 * it exports the repository for the last time.
	 *
	 * @return int how many were published
	 */
	public function publishBacklog(string $actorId, int $since): int {
		$posts = [];
		$before = 0;
		do {
			$page = $this->streamRequest->getByAuthor($actorId, 50, $before);
			foreach ($page as $post) {
				if ($post->getPublishedTime() < $since) {
					break 2;
				}
				$posts[] = $post;
				$before = $post->getNid();
			}
		} while (count($page) === 50 && count($posts) < self::MOVE_BACKLOG);

		$published = 0;
		$this->publishingBacklog = true;
		try {
			foreach (array_reverse(array_slice($posts, 0, self::MOVE_BACKLOG)) as $post) {
				try {
					if (!$this->imported->isImported($post->getAttributedTo(), $post->getId())) {
						$published += $this->publishPost($post) !== null ? 1 : 0;
					}
				} catch (Throwable $e) {
					$this->logger->warning('Post held back for a move not published to Bluesky', ['post' => $post->getId(), 'exception' => $e]);
				}
			}
		} finally {
			$this->publishingBacklog = false;
		}

		return $published;
	}

	/**
	 * Whether the account moves away now, so its posts wait: while the
	 * move runs or waits for the person, and once the DID moved and the
	 * move stopped before it was done.
	 */
	private function movingAway(Identity $identity): bool {
		if ($this->publishingBacklog || $this->moves === null) {
			return false;
		}
		$move = $this->moves->latestOfDid($identity->did, Move::AWAY);

		return $move !== null && ($move->isUnderWay()
			|| ($move->state === Move::FAILED && in_array($move->step, [Move::STEP_ACTIVATE, Move::STEP_DONE], true)));
	}

	/**
	 * Removes a post's record, and nothing else of the post is on Bluesky.
	 *
	 * @return bool whether a record was there to remove
	 * @throws AtprotoException
	 */
	public function deletePost(string $postId): bool {
		$this->videos->forget($postId);
		$record = $this->recordOf($postId);
		if ($record === null) {
			return false;
		}
		$identity = $this->identities->getByDid($record->did);
		$parts = $this->postRecords($postId, $record->did);
		$continuations = array_values(array_filter($parts, static fn (StoredRecord $part): bool => $part->rkey !== $record->rkey));
		$writes = array_map(static fn (StoredRecord $part): RepoWrite => RepoWrite::delete($part->collection, $part->rkey), $continuations);
		$writes = [...$writes, ...$this->gateDeletes($postId), RepoWrite::delete($record->collection, $record->rkey)];
		$this->writeChunks($identity, $writes);
		$this->logger->info('Post removed from Bluesky', ['post' => $postId, 'did' => $identity->did]);
		$this->repin($identity, $record);

		return true;
	}

	/**
	 * Edits replace the existing record in place so the Bluesky URI remains
	 * stable and App Views receive an update event regardless of post age.
	 *
	 * @return string `updated`, `deleted` when the post is no longer eligible,
	 *                `kept` when unchanged, imported or disabled, or `none`
	 *                when the post is not on Bluesky
	 * @throws AtprotoException
	 */
	public function editPost(Stream $post): string {
		$record = $this->recordOf($post->getId());
		if ($record === null) {
			// The initial publication may have failed before it created a
			// record. An edit is another opportunity to publish the current
			// version; publishPost is idempotent and re-check the repository
			// below in case a queued publication won the race.
			if ($this->publishPost($post) !== null) {
				return 'updated';
			}
			$record = $this->recordOf($post->getId());
			if ($record === null) {
				return 'none';
			}
		}
		if ($this->imported->isImported($post->getAttributedTo(), $post->getId())) {
			return 'kept';
		}
		if (!$this->isEligiblePost($post)) {
			return $this->deletePost($post->getId()) ? 'deleted' : 'kept';
		}
		if (!$this->config->isEnabled()) {
			return 'kept';
		}
		return $this->updateRecord($post, $record) ? 'updated' : 'kept';
	}

	/**
	 * Writes one record of a local account — a follow, a like, a repost —
	 * keyed by the Social object it stands for, so it can be removed by it.
	 *
	 * @return bool whether it was written; false when the account has no
	 *              active identity or the record is there already
	 * @throws AtprotoException
	 */
	public function writeRecord(Person $actor, string $collection, array $record, string $localId): bool {
		if (!$this->config->isEnabled() || !$actor->isLocal()) {
			return false;
		}
		foreach ($this->repositories->getRecordsByLocalId($localId) as $existing) {
			if ($existing->collection === $collection) {
				return false;
			}
		}
		$identity = $this->identities->forActor($actor);
		if ($identity === null || !$identity->isActive()) {
			return false;
		}
		return $this->repositories->write($identity->did, $this->identities->signingKey($identity), [
			RepoWrite::createOnce($collection, $record, $localId),
		]) !== null;
	}

	/**
	 * Removes the record a Social object stands for.
	 *
	 * @return bool whether a record was there to remove
	 * @throws AtprotoException
	 */
	public function removeRecord(string $collection, string $localId): bool {
		foreach ($this->repositories->getRecordsByLocalId($localId) as $record) {
			if ($record->collection !== $collection) {
				continue;
			}
			$identity = $this->identities->getByDid($record->did);
			$this->repositories->write($identity->did, $this->identities->signingKey($identity), [
				RepoWrite::delete($record->collection, $record->rkey),
			]);

			return true;
		}

		return false;
	}

	/**
	 * Writes the profile record, or rewrites it when it changed.
	 *
	 * @return bool whether anything was written
	 * @throws AtprotoException
	 */
	public function publishProfile(Person $actor): bool {
		if (!$this->config->isEnabled() || !$actor->isLocal()) {
			return false;
		}
		$identity = $this->identities->forActor($actor);
		if ($identity === null || !$identity->isActive()) {
			return false;
		}
		$written = $this->writeProfile($actor, $identity);
		$this->reissueVerification($actor);

		return $written;
	}

	/**
	 * The instance's verification of the account written again when its
	 * handle or display name changed (`VerificationService::refresh()`);
	 * resolved lazily, as that needs this service.
	 */
	private function reissueVerification(Person $actor): void {
		try {
			$verifications = $this->container?->get(VerificationService::class);
			if ($verifications instanceof VerificationService) {
				$verifications->refresh($actor);
			}
		} catch (Throwable $e) {
			$this->logger->warning('Verification not issued again', ['actor' => $actor->getId(), 'exception' => $e]);
		}
	}

	/**
	 * The state of a post on Bluesky, for the delivery dialog.
	 *
	 * @return array{state: string, uri: string, url: string}
	 */
	public function statusOf(Stream $post): array {
		$record = $this->recordOf($post->getId());
		if ($record !== null) {
			$identity = $this->identities->getByDid($record->did);
			$records = $this->postRecords($post->getId(), $record->did);
			$complete = $this->imported->isImported($post->getAttributedTo(), $post->getId())
				|| $this->threadVerifier->isComplete($post, $records);

			return [
				// A root record alone is not a successful publication when the
				// source text is longer than the Bluesky record limit. Reconcile
				// will repair it; until then do not show a false delivered state.
				'state' => $complete ? 'published' : 'waiting',
				'uri' => $record->uri(),
				'url' => 'https://bsky.app/profile/' . $identity->handle . '/post/' . $record->rkey,
			];
		}

		return ['state' => $this->goesToBluesky($post) ? 'waiting' : 'not_applicable', 'uri' => '', 'url' => ''];
	}

	/**
	 * Brings Bluesky up to date with recent public posts and every local post
	 * already represented in a Bluesky repository: what a missed event or a
	 * failed write left behind — a post without a record, an edit whose
	 * contents differ from the record, or a record whose post is gone. Each
	 * pass looks at two pages of new posts and two pages of records at most
	 * (walkPages()), so older missed edits are repaired over time too.
	 *
	 * @return int how many records were written or removed
	 */
	public function reconcile(int $limit = self::RECONCILE_BATCH): int {
		if (!$this->config->isEnabled()) {
			return 0;
		}
		$done = 0;
		$since = $this->time->getTime() - self::RECONCILE_WINDOW;
		$posts = $this->walkPages('posts', $limit, function (int $before) use ($since, $limit): array {
			$page = [];
			foreach ($this->streamRequest->getLocalPublicSince($since, $limit, $before) as $post) {
				$page[(int)$post->getNid()] = $post;
			}

			return $page;
		});
		foreach ($posts as $post) {
			try {
				// an import is federated nowhere, and one that came with its
				// record keeps that record as it was
				if ($this->imported->isImported($post->getAttributedTo(), $post->getId())) {
					continue;
				}
				$record = $this->recordOf($post->getId());
				if ($record === null) {
					$done += $this->publishPost($post) !== null ? 1 : 0;
				}
			} catch (Throwable $e) {
				$this->logger->warning('Post not published to Bluesky', ['post' => $post->getId(), 'exception' => $e]);
			}
		}
		// Walk the whole local record inventory. The recent-post window is
		// enough to find a missed first publication, but would leave an edit
		// or deletion missed while Bluesky was disabled stale forever when it
		// belonged to an older post.
		$records = $this->walkPages('records', $limit, fn (int $before): array => $this->repositories->getRecordsSince(RecordMapper::POST, 0, $limit, $before));
		$seenLocalIds = [];
		foreach ($records as $record) {
			if ($record->localId !== '' && isset($seenLocalIds[$record->localId])) {
				continue;
			}
			$seenLocalIds[$record->localId] = true;
			try {
				$post = $this->streamRequest->getStreamById($record->localId);
				if ($this->imported->isImported($post->getAttributedTo(), $post->getId())) {
					continue;
				}
				if (!$this->goesToBluesky($post)) {
					$done += $this->deletePost($post->getId()) ? 1 : 0;
					continue;
				}
				$done += $this->updateRecord($post, $record) ? 1 : 0;
			} catch (StreamNotFoundException) {
				try {
					$done += $this->deletePost($record->localId) ? 1 : 0;
				} catch (Throwable $e) {
					$this->logger->warning('Post not removed from Bluesky', ['post' => $record->localId, 'exception' => $e]);
				}
			} catch (Throwable $e) {
				$this->logger->warning('Post not reconciled to Bluesky', ['post' => $record->localId, 'exception' => $e]);
			}
		}

		return $done;
	}

	/**
	 * The newest page and the next page below the saved cursor, newest first.
	 * When there is no older page, the cursor is cleared and the next run starts
	 * from the beginning. New posts are therefore checked every run while the
	 * full inventory is covered over successive runs.
	 */
	private function walkPages(string $walk, int $limit, Closure $page): array {
		$newest = $page(0);
		if (count($newest) < $limit) {
			return $newest;
		}
		$floor = min(array_keys($newest));
		// the table, whatever memcache there is: cron runs from the command
		// line one time and from a web request the next
		$cursor = (int)($this->durableCache->getShared(self::RECONCILE_WALK, $walk) ?? 0);
		$older = $page($cursor > 0 && $cursor < $floor ? $cursor : $floor);
		if (count($older) < $limit) {
			$this->durableCache->removeShared(self::RECONCILE_WALK, $walk);
		} else {
			$this->durableCache->setShared(self::RECONCILE_WALK, $walk, min(array_keys($older)), self::RECONCILE_CURSOR_TTL);
		}

		return $newest + $older;
	}

	/**
	 * Whether a post is one that goes to Bluesky at all: public, local, an
	 * ordinary post or a poll, not archived, from an account that is still
	 * here.
	 */
	public function goesToBluesky(Stream $post): bool {
		return $this->config->isEnabled() && $this->isEligiblePost($post);
	}

	/** Whether this post can remain publicly represented on Bluesky. */
	private function isEligiblePost(Stream $post): bool {
		if (!$post->isLocal() || $post->isArchived()) {
			return false;
		}
		if (!in_array($post->getType(), ['Note', 'Question'], true) || $post->getSubType() === 'Event') {
			return false;
		}

		return ($post->getVisibility() === Stream::TYPE_PUBLIC)
			|| ($post->getVisibility() === '' && $post->isPublic());
	}

	private function ensureProfile(Person $actor, Identity $identity): void {
		if ($this->repositories->getRecord($identity->did, RecordMapper::PROFILE, RecordMapper::PROFILE_RKEY) === null) {
			$this->writeProfile($actor, $identity);
		}
	}

	private function writeProfile(Person $actor, Identity $identity): bool {
		return $this->writeSelf($identity, RecordMapper::PROFILE, $this->mapper->profile($actor, $identity), $actor->getId());
	}

	/**
	 * Writes a record a repository holds one of, under `self` — a setting of
	 * the account's, such as who may send it direct messages — or rewrites
	 * it when it changed.
	 *
	 * @return bool whether anything was written
	 * @throws AtprotoException
	 */
	public function writeSelfRecord(Person $actor, string $collection, array $record): bool {
		if (!$this->config->isEnabled() || !$actor->isLocal()) {
			return false;
		}
		$identity = $this->identities->forActor($actor);
		if ($identity === null || !$identity->isActive()) {
			return false;
		}

		// a setting, which no object here stands for
		return $this->writeSelf($identity, $collection, $record, '');
	}

	/**
	 * Whether the account's repository holds its record of a collection
	 * under `self`; false for an account without an identity.
	 */
	public function hasSelfRecord(Person $actor, string $collection): bool {
		$identity = $this->identities->forActor($actor, false);

		return $identity !== null && $this->repositories->getRecord($identity->did, $collection, RecordMapper::PROFILE_RKEY) !== null;
	}

	/**
	 * @throws AtprotoException
	 */
	private function writeSelf(Identity $identity, string $collection, array $record, string $localId): bool {
		$existing = $this->repositories->getRecord($identity->did, $collection, RecordMapper::PROFILE_RKEY);
		if ($existing !== null && $existing->bytes === DagCbor::encode($record)) {
			return false;
		}
		$write = $existing === null
			? RepoWrite::create($collection, $record, $localId, RecordMapper::PROFILE_RKEY)
			: RepoWrite::update($collection, RecordMapper::PROFILE_RKEY, $record, $localId);
		$this->repositories->write($identity->did, $this->identities->signingKey($identity), [$write]);

		return true;
	}

	/**
	 * Replaces the contents at the same rkey; nothing is written when the
	 * record already says the same.
	 *
	 * @return bool whether the record changed
	 * @throws AtprotoException
	 */
	private function updateRecord(Stream $post, StoredRecord $record): bool {
		$record = $this->recordOf($post->getId()) ?? $record;
		$identity = $this->identities->getByDid($record->did);
		if (!$identity->isActive()) {
			return false;
		}
		$author = $this->cacheActorService->getFromId($post->getAttributedTo());
		$video = $this->videos->forPost($post, $identity, $author);
		if ($video['state'] === 'waiting') {
			return false;
		}
		$mapped = $this->mapper->post($post, $identity, $author, $video);
		$rkey = $record->rkey;
		$threadWrites = $this->threadWrites($post, $identity, $mapped, $record, $rkey);
		$gateWrites = $this->gateChanges($post, $identity->did, $rkey);
		$writes = $this->rootAndGatesFirst($threadWrites, $rkey, $gateWrites);
		if ($writes !== [] && $this->writeChunks($identity, $writes) === null) {
			return false;
		}
		if ($writes !== []) {
			$this->logger->info('Post edit published to Bluesky', ['post' => $post->getId(), 'did' => $identity->did]);
		}
		$repinned = $this->repin($identity, $record, true);

		return $writes !== [] || $gateWrites !== [] || $repinned;
	}

	/**
	 * A sufficiently long Social post can need more records than one ATProto
	 * repository commit allows. Write bounded commits in order, with the root
	 * first so a partial publication remains discoverable and reconcile can
	 * finish it after a transient failure.
	 *
	 * @param RepoWrite[] $writes
	 */
	private function writeChunks(Identity $identity, array $writes): ?CommitResult {
		$result = null;
		foreach (array_chunk($writes, RepositoryService::MAX_WRITES) as $chunk) {
			$committed = $this->repositories->write($identity->did, $this->identities->signingKey($identity), $chunk);
			// A duplicate first-publication can lose the root createOnce() race.
			// Do not continue with its separately generated replies or report the
			// earlier chunks as a complete thread. Reconciliation can finish it.
			if ($committed === null) {
				return null;
			}
			$result = $committed;
		}

		return $result;
	}

	/** @param RepoWrite[] $threadWrites @param RepoWrite[] $gateWrites @return RepoWrite[] */
	private function rootAndGatesFirst(array $threadWrites, string $rootRkey, array $gateWrites): array {
		$root = [];
		$continuations = [];
		foreach ($threadWrites as $write) {
			if ($write->collection === RecordMapper::POST && $write->rkey === $rootRkey) {
				$root[] = $write;
			} else {
				$continuations[] = $write;
			}
		}

		return [...$root, ...$gateWrites, ...$continuations];
	}

	/**
	 * The root record and its continuation replies, all linked to one Social
	 * post. The first record keeps its rkey; subsequent parts keep theirs when
	 * edited so replies to them also retain their URIs.
	 *
	 * @param array{record: array<string, mixed>, truncated: bool, continuations?: array<int, array{text: string, facets: array}>} $mapped
	 * @return RepoWrite[]
	 * @throws AtprotoException when a post would exceed the repository commit limit
	 */
	private function threadWrites(Stream $post, Identity $identity, array $mapped, ?StoredRecord $root, string $rootRkey): array {
		$existing = $root === null ? [] : $this->postRecords($post->getId(), $identity->did);
		$rootRecord = $mapped['record'];
		$rootBytes = DagCbor::encode($rootRecord);
		$rootCid = Cid::forDagCbor($rootBytes);
		$rootUri = Syntax::atUri($identity->did, RecordMapper::POST, $rootRkey);
		$rootRef = $rootRecord['reply']['root'] ?? ['uri' => $rootUri, 'cid' => $rootCid->toString()];
		$parentRef = ['uri' => $rootUri, 'cid' => $rootCid->toString()];
		$writes = [];
		if ($root === null) {
			$writes[] = RepoWrite::createOnce(RecordMapper::POST, $rootRecord, $post->getId(), $rootRkey);
		} elseif ($rootBytes !== $root->bytes) {
			$writes[] = RepoWrite::update(RecordMapper::POST, $rootRkey, $rootRecord, $post->getId());
		}

		$continuations = $mapped['continuations'] ?? [];
		foreach ($continuations as $index => $part) {
			$record = [
				'$type' => RecordMapper::POST,
				'text' => $part['text'],
				'createdAt' => $rootRecord['createdAt'],
				'reply' => ['root' => $rootRef, 'parent' => $parentRef],
			];
			if ($part['facets'] !== []) {
				$record['facets'] = array_values($part['facets']);
			}
			if (isset($rootRecord['langs'])) {
				$record['langs'] = $rootRecord['langs'];
			}
			$warningLabels = [];
			foreach ($rootRecord['labels']['values'] ?? [] as $label) {
				if (is_array($label) && ($label['val'] ?? '') === '!warn') {
					$warningLabels[] = $label;
				}
			}
			if ($warningLabels !== []) {
				$record['labels'] = ['$type' => 'com.atproto.label.defs#selfLabels', 'values' => $warningLabels];
			}
			$old = $existing[$index + 1] ?? null;
			$rkey = $old?->rkey ?? Tid::next();
			$bytes = DagCbor::encode($record);
			if ($old === null) {
				$writes[] = RepoWrite::create(RecordMapper::POST, $record, $post->getId(), $rkey);
			} elseif ($old->bytes !== $bytes) {
				$writes[] = RepoWrite::update(RecordMapper::POST, $rkey, $record, $post->getId());
			}
			$parentRef = ['uri' => Syntax::atUri($identity->did, RecordMapper::POST, $rkey), 'cid' => Cid::forDagCbor($bytes)->toString()];
		}
		foreach (array_slice($existing, count($continuations) + 1) as $obsolete) {
			$writes[] = RepoWrite::delete(RecordMapper::POST, $obsolete->rkey);
		}
		return $writes;
	}

	/** @return StoredRecord[] root first, then its continuation replies */
	private function postRecords(string $postId, string $did): array {
		$records = array_values(array_filter(
			$this->repositories->getRecordsByLocalId($postId),
			static fn (StoredRecord $record): bool => $record->did === $did && $record->collection === RecordMapper::POST,
		));
		usort($records, static fn (StoredRecord $a, StoredRecord $b): int => strcmp($a->rkey, $b->rkey));

		return $records;
	}

	/**
	 * Refresh the profile when a pinned record changes or is deleted. The
	 * pinned post is a strong reference, so an in-place edit changes its CID
	 * even though its URI remains the same.
	 */
	private function repin(Identity $identity, StoredRecord $gone, bool $throwOnFailure = false): bool {
		$profile = $this->repositories->getRecord($identity->did, RecordMapper::PROFILE, RecordMapper::PROFILE_RKEY);
		$value = $profile === null ? null : DagCbor::decode($profile->bytes);
		if (!is_array($value) || ($value['pinnedPost']['uri'] ?? '') !== $gone->uri()) {
			return false;
		}
		try {
			return $this->writeProfile($this->cacheActorService->getFromId($identity->actorId), $identity);
		} catch (Throwable $e) {
			$this->logger->warning('Pinned post not updated on the Bluesky profile', ['did' => $identity->did, 'exception' => $e]);
			if ($throwOnFailure) {
				throw $e;
			}
			return false;
		}
	}

	/**
	 * The gates a post needs beside it, under the post's own rkey, as the
	 * lexicons require: a postgate for its quote policy, a threadgate for
	 * who may reply.
	 *
	 * @return array<string, array> collection => record
	 */
	private function gatesOf(Stream $post, string $did, string $rkey): array {
		$uri = 'at://' . $did . '/' . RecordMapper::POST . '/' . $rkey;
		$this->publishRuleLists($post);

		return array_filter([
			RecordMapper::POSTGATE => $this->mapper->postgate($post, $uri),
			RecordMapper::THREADGATE => $this->mapper->threadgate($post, $uri),
		], static fn (?array $gate): bool => $gate !== null);
	}

	/**
	 * The lists a reply rule names, published as Bluesky lists before the
	 * threadgate names them (`BlueskyLists`); resolved lazily, as that needs
	 * this service.
	 */
	private function publishRuleLists(Stream $post): void {
		$lists = null;
		foreach ($post->getReplyRuleParts() as $part) {
			if (!str_starts_with($part, Stream::REPLY_RULE_LIST)) {
				continue;
			}
			$lists ??= $this->container?->get(BlueskyLists::class);
			if (!$lists instanceof BlueskyLists) {
				return;
			}
			try {
				$lists->ensure($this->cacheActorService->getFromId($post->getAttributedTo()), (int)substr($part, strlen(Stream::REPLY_RULE_LIST)));
			} catch (Throwable $e) {
				$this->logger->warning('A reply rule\'s list not published to Bluesky', ['post' => $post->getId(), 'exception' => $e]);
			}
		}
	}

	/**
	 * @return RepoWrite[]
	 */
	private function gateWrites(Stream $post, string $did, string $rkey): array {
		$writes = [];
		foreach ($this->gatesOf($post, $did, $rkey) as $collection => $gate) {
			$writes[] = RepoWrite::create($collection, $gate, $post->getId(), $rkey);
		}

		return $writes;
	}

	/**
	 * Writes a published post's gates again, as its reply rule and quote
	 * policy now say: each one made, rewritten or taken away in one commit;
	 * nothing for a post not on Bluesky, or while its author is not.
	 *
	 * @throws AtprotoException
	 */
	public function updateGates(Stream $post): void {
		$record = $this->recordOf($post->getId());
		if ($record === null || !$this->config->isEnabled()) {
			return;
		}
		$identity = $this->identities->getByDid($record->did);
		if (!$identity->isActive()) {
			return;
		}
		$writes = $this->gateChanges($post, $record->did, $record->rkey);
		if ($writes !== []) {
			$this->repositories->write($identity->did, $this->identities->signingKey($identity), $writes);
		}
	}

	/** @return RepoWrite[] */
	private function gateChanges(Stream $post, string $did, string $rkey): array {
		$gates = $this->gatesOf($post, $did, $rkey);
		$writes = [];
		foreach ($this->repositories->getRecordsByLocalId($post->getId()) as $stored) {
			if ($stored->collection !== RecordMapper::POSTGATE && $stored->collection !== RecordMapper::THREADGATE) {
				continue;
			}
			if (isset($gates[$stored->collection])) {
				if (DagCbor::encode($gates[$stored->collection]) !== $stored->bytes) {
					$writes[] = RepoWrite::update($stored->collection, $stored->rkey, $gates[$stored->collection], $post->getId());
				}
			} else {
				$writes[] = RepoWrite::delete($stored->collection, $stored->rkey);
			}
			unset($gates[$stored->collection]);
		}
		foreach ($gates as $collection => $gate) {
			$writes[] = RepoWrite::create($collection, $gate, $post->getId(), $rkey);
		}
		return $writes;
	}

	/**
	 * @return RepoWrite[]
	 */
	private function gateDeletes(string $postId): array {
		$deletes = [];
		foreach ($this->repositories->getRecordsByLocalId($postId) as $record) {
			if ($record->collection === RecordMapper::POSTGATE || $record->collection === RecordMapper::THREADGATE) {
				$deletes[] = RepoWrite::delete($record->collection, $record->rkey);
			}
		}

		return $deletes;
	}

	private function recordOf(string $postId): ?StoredRecord {
		$records = array_values(array_filter(
			$this->repositories->getRecordsByLocalId($postId),
			static fn (StoredRecord $record): bool => $record->collection === RecordMapper::POST,
		));
		usort($records, static fn (StoredRecord $a, StoredRecord $b): int => strcmp($a->rkey, $b->rkey));

		return $records[0] ?? null;
	}
}
