<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Publisher;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\StoredRecord;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Repository\CommitResult;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Repository\RepoWrite;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\CacheActorService;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Puts what a local account does onto Bluesky: every public post as it is
 * made, deleted or (within the grace period) edited, and the profile.
 *
 * Idempotent on purpose. A post is published when it has no record yet,
 * whoever asks — the listener right after the post, the queued job, or the
 * reconcile pass that sweeps recent posts — so a missed event costs
 * minutes, not the post.
 */
class Publisher {
	/** how old a post may be for an edit to still replace its record */
	public const EDIT_GRACE = 300;
	/** how far back the reconcile pass looks */
	public const RECONCILE_WINDOW = 86400;
	public const RECONCILE_BATCH = 100;

	public function __construct(
		private AtprotoConfig $config,
		private IdentityService $identities,
		private RepositoryService $repositories,
		private RecordMapper $mapper,
		private StreamRequest $streamRequest,
		private CacheActorService $cacheActorService,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Publishes a post, unless it is not one that goes to Bluesky or is
	 * there already.
	 *
	 * @return CommitResult|null the commit, or null when nothing was written
	 * @throws AtprotoException
	 */
	public function publishPost(Stream $post): ?CommitResult {
		if (!$this->goesToBluesky($post)) {
			return null;
		}
		if ($this->recordOf($post->getId()) !== null) {
			return null;
		}
		$author = $this->cacheActorService->getFromId($post->getAttributedTo());
		$identity = $this->identities->forActor($author);
		if ($identity === null || !$identity->isActive()) {
			return null;
		}
		$this->ensureProfile($author, $identity);

		$mapped = $this->mapper->post($post, $identity, $author);
		$result = $this->repositories->write($identity->did, $this->identities->signingKey($identity), [
			RepoWrite::create(RecordMapper::POST, $mapped['record'], $post->getId()),
		]);
		$this->logger->info('Post published to Bluesky', ['post' => $post->getId(), 'did' => $identity->did, 'truncated' => $mapped['truncated']]);

		return $result;
	}

	/**
	 * Removes a post's record, and nothing else of the post is on Bluesky.
	 *
	 * @return bool whether a record was there to remove
	 * @throws AtprotoException
	 */
	public function deletePost(string $postId): bool {
		$record = $this->recordOf($postId);
		if ($record === null) {
			return false;
		}
		$identity = $this->identities->getByDid($record->did);
		$this->repositories->write($identity->did, $this->identities->signingKey($identity), [
			RepoWrite::delete($record->collection, $record->rkey),
		]);
		$this->logger->info('Post removed from Bluesky', ['post' => $postId, 'did' => $identity->did]);

		return true;
	}

	/**
	 * An edit within the grace period replaces the record (a new rkey, so a
	 * new URI); later than that the record stays and its link leads here.
	 *
	 * @return string `replaced`, `kept`, or `none` when the post is not on Bluesky
	 * @throws AtprotoException
	 */
	public function editPost(Stream $post): string {
		$record = $this->recordOf($post->getId());
		if ($record === null) {
			return 'none';
		}
		if ($this->pastGrace($post)) {
			return 'kept';
		}

		return $this->replaceRecord($post, $record) ? 'replaced' : 'kept';
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
		$this->repositories->write($identity->did, $this->identities->signingKey($identity), [
			RepoWrite::create($collection, $record, $localId),
		]);

		return true;
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

		return $this->writeProfile($actor, $identity);
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

			return [
				'state' => 'published',
				'uri' => $record->uri(),
				'url' => 'https://bsky.app/profile/' . $identity->handle . '/post/' . $record->rkey,
			];
		}

		return ['state' => $this->goesToBluesky($post) ? 'waiting' : 'not_applicable', 'uri' => '', 'url' => ''];
	}

	/**
	 * Brings Bluesky up to date with the recent public posts: what a missed
	 * event or a failed write left behind — a post without a record, an edit
	 * within the grace period the record does not show, a record whose post
	 * is gone.
	 *
	 * @return int how many records were written or removed
	 */
	public function reconcile(int $limit = self::RECONCILE_BATCH): int {
		if (!$this->config->isEnabled()) {
			return 0;
		}
		$done = 0;
		$since = $this->time->getTime() - self::RECONCILE_WINDOW;
		foreach ($this->streamRequest->getLocalPublicSince($since, $limit) as $post) {
			try {
				$record = $this->recordOf($post->getId());
				if ($record === null) {
					$done += $this->publishPost($post) !== null ? 1 : 0;
				} elseif (!$this->pastGrace($post)) {
					$done += $this->replaceRecord($post, $record) ? 1 : 0;
				}
			} catch (Throwable $e) {
				$this->logger->warning('Post not published to Bluesky', ['post' => $post->getId(), 'exception' => $e]);
			}
		}
		foreach ($this->repositories->getRecordsSince(RecordMapper::POST, $since, $limit) as $record) {
			try {
				$this->streamRequest->getStreamById($record->localId);
			} catch (StreamNotFoundException) {
				try {
					$done += $this->deletePost($record->localId) ? 1 : 0;
				} catch (Throwable $e) {
					$this->logger->warning('Post not removed from Bluesky', ['post' => $record->localId, 'exception' => $e]);
				}
			}
		}

		return $done;
	}

	/**
	 * Whether a post is one that goes to Bluesky at all: public, local, an
	 * ordinary post or a poll, not archived, from an account that is still
	 * here.
	 */
	public function goesToBluesky(Stream $post): bool {
		if (!$this->config->isEnabled() || !$post->isLocal() || $post->isArchived()) {
			return false;
		}
		if (!in_array($post->getType(), ['Note', 'Question'], true)) {
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
		$record = $this->mapper->profile($actor, $identity);
		$existing = $this->repositories->getRecord($identity->did, RecordMapper::PROFILE, RecordMapper::PROFILE_RKEY);
		if ($existing !== null && $existing->bytes === DagCbor::encode($record)) {
			return false;
		}
		$write = $existing === null
			? RepoWrite::create(RecordMapper::PROFILE, $record, $actor->getId(), RecordMapper::PROFILE_RKEY)
			: RepoWrite::update(RecordMapper::PROFILE, RecordMapper::PROFILE_RKEY, $record, $actor->getId());
		$this->repositories->write($identity->did, $this->identities->signingKey($identity), [$write]);

		return true;
	}

	/** Whether the post is too old for an edit to still replace its record. */
	private function pastGrace(Stream $post): bool {
		return $post->getPublishedTime() > 0 && $this->time->getTime() - $post->getPublishedTime() > self::EDIT_GRACE;
	}

	/**
	 * Replaces a post's record with what the post maps to now, under a new
	 * rkey; nothing is written when the record already says the same.
	 *
	 * @return bool whether the record was replaced
	 * @throws AtprotoException
	 */
	private function replaceRecord(Stream $post, StoredRecord $record): bool {
		$identity = $this->identities->getByDid($record->did);
		$author = $this->cacheActorService->getFromId($post->getAttributedTo());
		$mapped = $this->mapper->post($post, $identity, $author);
		if (DagCbor::encode($mapped['record']) === $record->bytes) {
			return false;
		}
		$this->repositories->write($identity->did, $this->identities->signingKey($identity), [
			RepoWrite::delete($record->collection, $record->rkey),
			RepoWrite::create(RecordMapper::POST, $mapped['record'], $post->getId()),
		]);
		$this->logger->info('Post edit published to Bluesky', ['post' => $post->getId(), 'did' => $identity->did]);

		return true;
	}

	private function recordOf(string $postId): ?StoredRecord {
		foreach ($this->repositories->getRecordsByLocalId($postId) as $record) {
			if ($record->collection === RecordMapper::POST) {
				return $record;
			}
		}

		return null;
	}
}
