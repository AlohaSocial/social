<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use Exception;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\StreamDest;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
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
		private CacheActorService $cacheActorService,
		ConfigService $configService,
		MiscService $miscService,
	) {
		parent::__construct($connection, $logger, $urlGenerator, $configService, $miscService);
	}

	/**
	 * A dest row is what puts a post in a timeline, so a failure here is a post
	 * that exists and is in nobody's timeline — permanently, and until now
	 * invisibly. Anything that is not the expected duplicate is raised, not
	 * swallowed: the caller writes these inside the transaction that stores the
	 * post, where throwing rolls the whole save back and the post can be saved
	 * again.
	 *
	 * The duplicate is expected and must not reach the database as an error.
	 * The same recipient routinely appears twice in one post -- `getToAll()`
	 * returns `to` alongside `toArray`, and the unique index does not include
	 * the subtype, so a recipient in both `to` and `cc` collides as well.
	 * Catching that violation and returning quietly is enough on MySQL and
	 * SQLite but not on PostgreSQL, which aborts the whole transaction on any
	 * failed statement: the remaining inserts and then the commit fail, and the
	 * post is lost. `insertIgnoreConflict()` asks the database to skip the row
	 * instead, so nothing fails in the first place.
	 */
	public function create(
		string $streamId, string $actorId, string $type, string $subType = '', int|string $nid = 0,
	): void {
		$qb = $this->getQueryBuilder();

		try {
			$this->dbConnection->insertIgnoreConflict(
				self::TABLE_STREAM_DEST,
				[
					'stream_id' => $qb->prim($streamId),
					'actor_id' => $qb->prim($actorId),
					'type' => $type,
					'subtype' => $subType,
					// the post's own sort key, copied here because this is the
					// row a timeline pages over: without it the home page has
					// to join every one of these rows to `social_stream` to
					// find out when its post was published, and then sort the
					// result. See `Version1000Date20260917000001`.
					'nid' => $nid,
				]
			);
		} catch (DBException $e) {
			$this->logger->error('could not store the recipient of a stream', [
				'streamId' => $streamId,
				'actorId' => $actorId,
				'type' => $type,
				'subtype' => $subType,
				'exception' => $e,
			]);

			throw $e;
		}
	}

	public function generateStreamDest(Stream $stream): void {
		if ($this->generateStreamNotification($stream)) {
			return;
		}

		if ($this->generateStreamDirect($stream)) {
			return;
		}

		$this->generateStreamHome($stream);
	}

	/**
	 * Each recipient of a post, named once, in the order the post named them.
	 *
	 * A post routinely names the same account more than once: `getToAll()`
	 * returns `to` alongside `toArray`, and an account addressed in both `to`
	 * and `cc` appears in each. The unique index `sat` is on
	 * (stream_id, actor_id, type) *without* the subtype, so all of those are
	 * one row, and asking the database to store them one at a time meant most
	 * of the inserts existed only to be refused.
	 *
	 * `insertIgnoreConflict()` makes a refused row harmless but not free:
	 * InnoDB allocates the auto-increment value before it notices the
	 * conflict, so every duplicate burned an id and dirtied the index it was
	 * about to be rejected by. On the devel instance that had carried
	 * `oc_social_stream_dest` to 882,837 ids for 4,976 live rows — 177 issued
	 * per row kept, and an index of 20 MB over 1 MB of data.
	 *
	 * The first subtype to name an account wins, which is the row the database
	 * kept when the duplicates were sent: `to` is offered before `cc`, and an
	 * account addressed in both is a `to` recipient.
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

	private function generateStreamHome(Stream $stream): void {
		$recipients = self::uniqueRecipients(
			[
				'to' => array_merge($stream->getToAll(), [$stream->getAttributedTo()]),
				'cc' => array_merge($stream->getCcArray(), $stream->getBccArray())
			]
		);

		foreach ($recipients as $actorId => $subtype) {
			$this->create($stream->getId(), $actorId, 'recipient', $subtype, $stream->getNid());
		}
	}

	private function generateStreamDirect(Stream $stream): bool {
		try {
			$author = $this->cacheActorService->getFromId($stream->getAttributedTo());
		} catch (Exception $e) {
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

		foreach (self::uniqueRecipients(['dm' => $all]) as $actorId => $subtype) {
			$this->create($stream->getId(), $actorId, $subtype, '', $stream->getNid());
		}

		return true;
	}

	private function generateStreamNotification(Stream $stream): bool {
		if ($stream->getType() !== SocialAppNotification::TYPE) {
			return false;
		}

		foreach (self::uniqueRecipients(['notif' => $stream->getToAll()]) as $actorId => $type) {
			$this->create($stream->getId(), $actorId, $type, '', $stream->getNid());
		}

		return true;
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

		// Keyset paging, not an offset. The caller rewrites and sometimes
		// deletes the posts behind these rows as it walks them, so the set
		// shrinks underneath an offset and every shift skips a row — which for
		// this caller means a post left addressed to an account that is gone.
		// An id the caller has already passed cannot come back.
		if ($afterId > 0) {
			$qb->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)));
		}
		$qb->orderBy('id', 'asc');

		return $this->getStreamDestsFromRequest($qb);
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
	 * @param string $actorId
	 */
	public function moveActor(string $actorId, string $newId): void {
		$qb = $this->getStreamDestUpdateSql();
		$qb->set('actor_id', $qb->createNamedParameter($qb->prim($newId)));
		$qb->limitToDBField('actor_id', $qb->prim($actorId));

		$qb->executeStatement();
	}
}
