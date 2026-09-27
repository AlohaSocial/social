<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\StreamDestRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Saving a post is one fact, against the real database.
 *
 * A post is stored as a row in `social_stream` and then a row per recipient in
 * `social_stream_dest` — and the recipient rows are what put it in a timeline.
 * Written outside a transaction, a failure between the two left a post that
 * exists, is in nobody's timeline, and that nothing ever notices: the recipient
 * insert logged its failure and carried on.
 *
 * These are integration tests because a transaction is a database behaviour;
 * there is nothing to assert about it without one, and the two halves of it —
 * a failure must roll the post back, a duplicate must not — cannot both be
 * checked against a double.
 */
class StreamSaveAtomicityTest extends TestCase {
	private const BASE = 'https://remote.example/atomic';

	private StreamRequest $streamRequest;
	private IDBConnection $connection;

	protected function setUp(): void {
		parent::setUp();
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->connection = Server::get(IDBConnection::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	/** @return string[] */
	private function suffixes(): array {
		return ['whole', 'repeated', 'partial'];
	}

	private function id(string $suffix): string {
		return self::BASE . '/notes/' . $suffix;
	}

	private function cleanup(): void {
		$prims = array_map(fn (string $suffix): string => md5($this->id($suffix)), $this->suffixes());

		foreach ([
			CoreRequestBuilder::TABLE_STREAM => 'id_prim',
			CoreRequestBuilder::TABLE_STREAM_DEST => 'stream_id',
			CoreRequestBuilder::TABLE_STREAM_TAGS => 'stream_id',
		] as $table => $field) {
			$qb = $this->connection->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in(
				$field,
				$qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)
			));
			$qb->executeStatement();
		}
	}

	private function note(string $suffix): Note {
		$note = new Note();
		$note->setId($this->id($suffix));
		$note->setAttributedTo(self::BASE . '/users/author');
		$note->setPublishedTime(time());
		$note->setVisibility(Stream::TYPE_PUBLIC);
		$note->setTo(Stream::CONTEXT_PUBLIC);
		$note->setToArray([Stream::CONTEXT_PUBLIC]);

		return $note;
	}

	private function countRows(string $table, string $field, string $value): int {
		$qb = $this->connection->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'c'))
			->from($table)
			->where($qb->expr()->eq($field, $qb->createNamedParameter($value)));
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return (int)($row['c'] ?? 0);
	}

	public function testAPostAndItsRecipientsAreStoredTogether(): void {
		$this->streamRequest->save($this->note('whole'));

		$this->assertSame(
			1,
			$this->countRows(CoreRequestBuilder::TABLE_STREAM, 'id_prim', md5($this->id('whole')))
		);
		$this->assertGreaterThan(
			0,
			$this->countRows(CoreRequestBuilder::TABLE_STREAM_DEST, 'stream_id', md5($this->id('whole'))),
			'a post with no recipient rows is a post in nobody timeline'
		);
	}

	/**
	 * The ordinary post that the transaction must not cost us.
	 *
	 * Recipients and hashtags repeat all the time — `getToAll()` hands back
	 * `to` alongside `toArray`, the unique index on the recipient rows does not
	 * include the subtype so the same actor in `to` and `cc` collides too, and
	 * a post can simply carry a hashtag twice. Each of those is a refused
	 * insert, and PostgreSQL aborts the whole transaction on any refused
	 * statement: catching the violation and carrying on is not enough there,
	 * the commit fails afterwards and the post is lost. The inserts have to ask
	 * the database to skip the row instead.
	 */
	public function testARepeatedRecipientOrHashtagDoesNotLoseThePost(): void {
		$note = $this->note('repeated');
		$note->setCcArray([Stream::CONTEXT_PUBLIC, self::BASE . '/users/author']);
		$note->setHashtags(['repeated', 'repeated']);

		$this->streamRequest->save($note);

		$this->assertSame(
			1,
			$this->countRows(CoreRequestBuilder::TABLE_STREAM, 'id_prim', md5($note->getId())),
			'a post whose recipients or hashtags repeat was refused by the database'
		);
		$this->assertGreaterThan(
			0,
			$this->countRows(CoreRequestBuilder::TABLE_STREAM_DEST, 'stream_id', md5($note->getId())),
			'a post with no recipient rows is a post in nobody timeline'
		);
	}

	/**
	 * The failure this exists for: the recipient rows cannot be written, and
	 * the post must not survive on its own.
	 *
	 * The failure is injected rather than provoked through the schema. Every
	 * collision the schema can be made to produce is a duplicate, and a
	 * duplicate is the one case these writes are meant to shrug off — see the
	 * test above. What is left is a recipient write that fails for a reason
	 * nobody anticipated, which is exactly the case the transaction is for.
	 */
	public function testAPostWhoseRecipientsFailIsNotLeftBehind(): void {
		$note = $this->note('partial');

		$failing = $this->createStub(StreamDestRequest::class);
		$failing->method('generateStreamDest')
			->willThrowException(new \RuntimeException('the recipient rows could not be written'));

		$property = new \ReflectionProperty(StreamRequest::class, 'streamDestRequest');
		$property->setAccessible(true);
		$original = $property->getValue($this->streamRequest);
		$property->setValue($this->streamRequest, $failing);

		try {
			// whether this raises or is swallowed is the caller's business;
			// what matters is what is left behind
			try {
				$this->streamRequest->save($note);
			} catch (\Throwable $e) {
			}
		} finally {
			$property->setValue($this->streamRequest, $original);
		}

		$this->assertSame(
			0,
			$this->countRows(CoreRequestBuilder::TABLE_STREAM, 'id_prim', md5($note->getId())),
			'the post was stored without its recipients: it exists and is in nobody timeline'
		);
	}
}
