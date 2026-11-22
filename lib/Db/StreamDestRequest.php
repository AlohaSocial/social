<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\StreamDest;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\TimelineRevisionService;
use OCA\Social\Tools\Traits\TStringTools;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Class StreamDestRequest
 *
 * @package OCA\Social\Db
 */
class StreamDestRequest extends StreamDestRequestBuilder {
	use TStringTools;

	public function __construct(
		IDBConnection $connection,
		LoggerInterface $logger,
		IURLGenerator $urlGenerator,
		private CacheActorsRequest $cacheActorsRequest,
		ConfigService $configService,
		MiscService $miscService,
		private ConversationsRequest $conversationsRequest,
		private TimelineRevisionService $timelineRevisionService,
	) {
		parent::__construct($connection, $logger, $urlGenerator, $configService, $miscService);
	}

	/**
	 * Stores the recipient rows of one post, in one statement. A failure is a
	 * post in nobody's timeline, so anything but the expected duplicate is
	 * raised and rolls the post's transaction back. Duplicates (an edit, a
	 * redelivery) are skipped by the database, as a failed statement aborts a
	 * PostgreSQL transaction.
	 *
	 * @param array<string, array{string, string}> $recipients account => [type, subtype]
	 */
	public function createRecipients(string $streamId, array $recipients, int|string $nid = 0): void {
		$qb = $this->getQueryBuilder();
		$streamPrim = $qb->prim($streamId);

		$rows = [];
		foreach ($recipients as $actorId => [$type, $subType]) {
			$rows[] = [
				'stream_id' => $streamPrim,
				'actor_id' => $qb->prim((string)$actorId),
				'type' => $type,
				'subtype' => $subType,
				// the post's own sort key, copied so a timeline pages over this
				// row without joining `social_stream`; see
				// `Version1000Date20260917000001`
				'nid' => $nid,
			];
		}

		try {
			$this->insertIgnoringConflicts(self::TABLE_STREAM_DEST, $rows);
		} catch (DBException $e) {
			$this->logger->error('could not store the recipients of a stream', [
				'streamId' => $streamId,
				'recipients' => array_keys($recipients),
				'exception' => $e,
			]);

			throw $e;
		}
	}

	/** @return list<string> */
	public function recipientPrims(string $streamId): array {
		$qb = $this->getQueryBuilder();
		$qb->select('actor_id')
			->from(self::TABLE_STREAM_DEST)
			->where($qb->expr()->eq('stream_id', $qb->createNamedParameter($qb->prim($streamId))));

		$cursor = $qb->executeQuery();
		$recipients = array_map(static fn (array $row): string => (string)$row['actor_id'], $cursor->fetchAll());
		$cursor->closeCursor();

		return $recipients;
	}

	/**
	 * Adds an account to the recipients of a direct message stored already,
	 * as when a Bluesky message reaches a second account here.
	 *
	 * @return bool whether the account was not a recipient before
	 */
	public function addDirectRecipient(Stream $stream, string $actorId): bool {
		$qb = $this->getQueryBuilder();
		$qb->select('actor_id')
			->from(self::TABLE_STREAM_DEST)
			->where($qb->expr()->eq('stream_id', $qb->createNamedParameter($qb->prim($stream->getId()))))
			->andWhere($qb->expr()->eq('actor_id', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter('dm')))
			->setMaxResults(1);
		$cursor = $qb->executeQuery();
		$known = $cursor->fetch() !== false;
		$cursor->closeCursor();
		if ($known) {
			return false;
		}
		$this->createRecipients($stream->getId(), [$actorId => ['dm', '']], $stream->getNid());
		$this->moved([$actorId], TimelineRevisionService::CONVERSATIONS);

		return true;
	}

	public function generateStreamDest(Stream $stream): void {
		if ($this->generateStreamNotification($stream)) {
			return;
		}

		$author = $this->cachedAuthor($stream);
		if ($this->generateStreamDirect($stream, $author)) {
			return;
		}

		$this->generateStreamHome($stream, $author);
	}

	/**
	 * Each recipient of a post, named once, in the order the post named them:
	 * `sat` is unique on (stream_id, actor_id, type) without the subtype, and
	 * every refused duplicate would still use up an InnoDB auto-increment id.
	 * The first subtype to name an account wins, and `to` comes before `cc`.
	 *
	 * @param array<string, string[]> $recipients subtype => the accounts it names
	 *
	 * @return array<string, string> account => the subtype that first named it
	 */
	private static function uniqueRecipients(array $recipients): array {
		$seen = [];
		foreach ($recipients as $subtype => $actorIds) {
			foreach ($actorIds as $actorId) {
				if ($actorId === '' || array_key_exists($actorId, $seen)) {
					continue;
				}

				$seen[$actorId] = $subtype;
			}
		}

		return $seen;
	}

	/**
	 * A public or unlisted post reaches its author's followers whatever it
	 * names, as on Mastodon: PeerTube addresses a channel's video to the
	 * followers of the account behind the channel, not of the channel.
	 *
	 * A followers collection the post names reaches whoever follows it, so
	 * only the author's own are kept; see `withoutForeignCollections()`.
	 */
	private function generateStreamHome(Stream $stream, ?Person $author): void {
		$cc = array_merge($stream->getCcArray(), $stream->getBccArray());
		$addressed = array_merge($stream->getToAll(), $cc);
		$own = ($author === null) ? [] : self::ownCollections($author);
		if ($author !== null && in_array($author->getFollowers(), $own, true)
			&& in_array(Stream::CONTEXT_PUBLIC, $addressed, true)) {
			$cc[] = $author->getFollowers();
		}

		$recipients = self::uniqueRecipients(
			[
				'to' => array_merge($stream->getToAll(), [$stream->getAttributedTo()]),
				'cc' => $cc,
			]
		);
		$recipients = $this->withoutForeignCollections($recipients, $stream, $author, $own);

		$this->createRecipients(
			$stream->getId(),
			array_map(static fn (string $subtype): array => ['recipient', $subtype], $recipients),
			$stream->getNid()
		);
	}

	/**
	 * The author's followers and following collections, where they are on the
	 * author's own server: an actor document can name any URL there, and
	 * another account's collection would put the post in its followers' home
	 * timelines.
	 *
	 * @return list<string>
	 */
	private static function ownCollections(Person $author): array {
		return array_values(array_filter(
			[$author->getFollowers(), $author->getFollowing()],
			static fn (string $collection): bool => Person::isOnHostOf($collection, $author->getId())
		));
	}

	/**
	 * The recipients without the collections of other accounts that a home
	 * timeline here would read the post through. A home timeline reads every
	 * post naming a collection its account follows (`social_follow.follow_id`),
	 * and its account's own followers collection, so a post naming another
	 * account's followers would reach all of them. Dropped is a recipient that
	 * a follow of another account targets, or that is the followers collection
	 * of another local account; kept is everything else: the public
	 * collection, accounts by id, the author's own collections, those of the
	 * account behind a channel (`Person::isRunBy()`), and URLs no follow here
	 * targets.
	 *
	 * @param array<string, string> $recipients account => subtype
	 * @param list<string> $own the author's own collections
	 *
	 * @return array<string, string>
	 */
	private function withoutForeignCollections(
		array $recipients, Stream $stream, ?Person $author, array $own,
	): array {
		$authorId = self::withoutFragment($stream->getAttributedTo());

		// prim => recipient, for a follow of it; prim of a local account =>
		// recipient, for that account's followers collection
		$followed = [];
		$localFollowers = [];
		foreach (array_keys($recipients) as $id) {
			$id = (string)$id;
			if ($id === Stream::CONTEXT_PUBLIC || $id === $stream->getAttributedTo() || in_array($id, $own, true)) {
				continue;
			}

			$prim = FileCommentsRequest::primOf($id);
			if ($prim !== '') {
				$followed[$prim] = $id;
			}
			if (str_ends_with($id, '/followers')) {
				$account = FileCommentsRequest::primOf(substr($id, 0, -strlen('/followers')));
				if ($account !== '') {
					$localFollowers[$account] = $id;
				}
			}
		}

		if ($followed === [] && $localFollowers === []) {
			return $recipients;
		}

		$allowed = [FileCommentsRequest::primOf($authorId)];
		foreach (($author === null) ? [] : $author->getAttributedToActors() as $owner) {
			if ($author->isRunBy($owner['id'])) {
				$allowed[] = FileCommentsRequest::primOf($owner['id']);
			}
		}

		$follows = $this->followsOf(array_values(array_unique(array_merge(
			array_keys($followed), array_keys($localFollowers)
		))));
		foreach ($follows as [$collection, $account]) {
			// a Loopback row is the one follow whose collection is its own account
			if ($account === $collection) {
				$id = $localFollowers[$collection] ?? '';
			} else {
				$id = $followed[$collection] ?? '';
			}

			if ($id !== '' && !in_array($account, $allowed, true)) {
				unset($recipients[$id]);
			}
		}

		return $recipients;
	}

	/**
	 * Which accounts the follows of these collections follow, read off the
	 * (follow_id_prim, object_id_prim, actor_id_prim) index.
	 *
	 * @param list<string> $prims
	 *
	 * @return list<array{string, string}> follow_id_prim, object_id_prim
	 */
	protected function followsOf(array $prims): array {
		$qb = $this->getQueryBuilder();
		$qb->selectDistinct(['follow_id_prim', 'object_id_prim'])
			->from(self::TABLE_FOLLOWS)
			->where($qb->expr()->in(
				'follow_id_prim', $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)
			));

		$follows = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$follows[] = [(string)$row['follow_id_prim'], (string)$row['object_id_prim']];
		}
		$cursor->closeCursor();

		return $follows;
	}

	/**
	 * The author is read from the cache and never fetched, as an HTTP request
	 * inside the post's transaction would hold its row locks. An uncached
	 * author (every save path caches it first) leaves the post addressed as a
	 * home-timeline post.
	 */
	private function cachedAuthor(Stream $stream): ?Person {
		$authorId = self::withoutFragment($stream->getAttributedTo());

		try {
			return $this->cacheActorsRequest->getFromId($authorId);
		} catch (CacheActorDoesNotExistException $e) {
			$this->logger->debug('author not cached while addressing a stream; treated as not direct', [
				'stream' => $stream->getId(), 'author' => $authorId,
			]);

			return null;
		}
	}

	private static function withoutFragment(string $id): string {
		$anchor = strpos($id, '#');

		return ($anchor === false) ? $id : substr($id, 0, $anchor);
	}

	private function generateStreamDirect(Stream $stream, ?Person $author): bool {
		if ($author === null) {
			return false;
		}

		$all = array_merge(
			$stream->getToAll(), [$stream->getAttributedTo()], $stream->getCcArray(), $stream->getBccArray()
		);

		foreach ($all as $item) {
			if ($item === Stream::CONTEXT_PUBLIC || $item === $author->getFollowers()) {
				return false;
			}
		}

		$recipients = self::uniqueRecipients(['dm' => $all]);
		$this->createRecipients(
			$stream->getId(),
			array_map(static fn (string $type): array => [$type, ''], $recipients),
			$stream->getNid()
		);
		$this->conversationsRequest->storeRoot($stream->getId(), $stream->getInReplyTo());
		$this->moved(array_keys($recipients), TimelineRevisionService::CONVERSATIONS);

		return true;
	}

	private function generateStreamNotification(Stream $stream): bool {
		if ($stream->getType() !== SocialAppNotification::TYPE) {
			return false;
		}

		$recipients = self::uniqueRecipients(['notif' => $stream->getToAll()]);
		$this->createRecipients(
			$stream->getId(),
			array_map(static fn (string $type): array => [$type, ''], $recipients),
			$stream->getNid()
		);
		$this->moved(array_keys($recipients), TimelineRevisionService::NOTIFICATIONS);

		return true;
	}

	/**
	 * Moves the badge number of every local account among `$recipients`.
	 * Inside the post's transaction, so the number is never ahead of the rows.
	 *
	 * @param array<array-key, string|int> $recipients actor ids
	 */
	private function moved(array $recipients, string $key): void {
		foreach ($recipients as $actorId) {
			$this->timelineRevisionService->bumpForActor((string)$actorId, $key);
		}
	}

	public function emptyStreamDest(): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_STREAM_DEST);

		$qb->executeStatement();
	}

	/**
	 * @return StreamDest[]
	 */
	public function getRelatedToActor(Person $actor, int $limit = 0, int $afterId = 0): array {
		$qb = $this->getStreamDestSelectSql();
		if ($limit > 0) {
			$qb->setMaxResults($limit);
		}
		$orX = $qb->expr()->orX(
			$qb->exprLimitToDBField('actor_id', $qb->prim($actor->getId())),
			$qb->exprLimitToDBField('actor_id', $qb->prim($actor->getFollowers())),
			$qb->exprLimitToDBField('actor_id', $qb->prim($actor->getFollowing()))
		);
		$qb->where($orX);

		// Keyset paging: the caller rewrites and deletes the posts behind
		// these rows as it walks them, so an offset would skip rows.
		if ($afterId > 0) {
			$qb->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)));
		}
		$qb->orderBy('id', 'asc');

		return $this->getStreamDestsFromRequest($qb);
	}

	/**
	 * Drops every recipient row of these posts, which takes them out of every
	 * timeline and viewer-limited read while the posts themselves stay;
	 * `generateStreamDest()` puts the rows back.
	 *
	 * @param string[] $ids the posts' ids
	 */
	public function deleteByStreamIds(array $ids): void {
		if ($ids === []) {
			return;
		}
		$qb = $this->getStreamDestDeleteSql();
		$prims = array_map(static fn (string $id): string => $qb->prim($id), $ids);
		$qb->andWhere($qb->expr()->in('stream_id', $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)));

		$qb->executeStatement();
	}

	/**
	 * @param string $actorId
	 */
	public function deleteRelatedToActor(string $actorId): void {
		$qb = $this->getStreamDestDeleteSql();
		// actor_id holds the prim already, so it is matched as it stands:
		// LOWER() over it only defeated the social_sd_at index
		$qb->limitToDBField('actor_id', $qb->prim($actorId));

		$qb->executeStatement();
	}

	/**
	 * Readdresses every post addressed to `$actorId` to `$newId`, copying a
	 * page at a time and then removing: an UPDATE onto an existing
	 * (stream_id, actor_id, type) row of `sat` would be refused, and the whole
	 * Move with it. Nothing here can be refused, so it can run inside a
	 * transaction on PostgreSQL.
	 */
	public function moveActor(string $actorId, string $newId): void {
		$newPrim = $this->getQueryBuilder()->prim($newId);
		$afterId = 0;
		do {
			$qb = $this->getQueryBuilder();
			$qb->select('id', 'stream_id', 'type', 'subtype', 'nid')
				->from(self::TABLE_STREAM_DEST)
				->where($qb->expr()->eq('actor_id', $qb->createNamedParameter($qb->prim($actorId))))
				->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
				->orderBy('id', 'asc')
				->setMaxResults(self::INSERT_IGNORE_CHUNK);

			$rows = [];
			$cursor = $qb->executeQuery();
			while ($data = $cursor->fetch()) {
				$afterId = (int)$data['id'];
				$rows[] = [
					'stream_id' => (string)$data['stream_id'],
					'actor_id' => $newPrim,
					'type' => (string)$data['type'],
					'subtype' => (string)$data['subtype'],
					'nid' => (int)$data['nid'],
				];
			}
			$cursor->closeCursor();

			$this->insertIgnoringConflicts(self::TABLE_STREAM_DEST, $rows);
		} while (count($rows) === self::INSERT_IGNORE_CHUNK);

		$this->deleteRelatedToActor($actorId);
	}
}
