<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use DateTime;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\StreamPruneService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Retention against the real database: every protection rule of
 * StreamPruneService, the cascade over dest/act/tag/document rows, the
 * dry-run counter, and the guarantee that local and recent rows survive.
 */
class StreamPruneTest extends TestCase {
	private const BASE = 'https://cloud.example.org/prune';
	private const AUTHOR = 'https://remote.example/prune/users/author';
	private const FOLLOWED = 'https://remote.example/prune/users/followed';
	private const VIEWER = self::BASE . '/users/viewer';

	private const SUFFIXES = [
		'stale', 'fresh', 'local', 'liked', 'actioned', 'followed-author',
		'parent-of-local', 'local-reply', 'direct', 'boosted-object', 'local-boost',
	];

	private StreamRequest $streamRequest;
	private FollowsRequest $followsRequest;
	private StreamPruneService $service;
	private IDBConnection $connection;

	protected function setUp(): void {
		parent::setUp();
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->followsRequest = Server::get(FollowsRequest::class);
		$this->service = Server::get(StreamPruneService::class);
		$this->connection = Server::get(IDBConnection::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach (self::SUFFIXES as $suffix) {
			$this->streamRequest->deleteById($this->id($suffix), Note::TYPE);
		}
		foreach ([self::AUTHOR, self::FOLLOWED, self::VIEWER] as $actor) {
			$this->followsRequest->deleteRelatedId($actor);
		}
		foreach ([CoreRequestBuilder::TABLE_STREAM_ACTIONS => 'stream_id_prim',
			CoreRequestBuilder::TABLE_STREAM_DEST => 'stream_id',
			CoreRequestBuilder::TABLE_STREAM_TAGS => 'stream_id',
			CoreRequestBuilder::TABLE_CACHE_DOCUMENTS => 'parent_id_prim',
			CoreRequestBuilder::TABLE_ACTIONS => 'object_id_prim'] as $table => $field) {
			$qb = $this->connection->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in(
				$field,
				$qb->createNamedParameter(array_map(
					fn (string $suffix): string => md5($this->id($suffix)), self::SUFFIXES
				), IQueryBuilder::PARAM_STR_ARRAY)
			));
			$qb->executeStatement();
		}
	}

	private function id(string $suffix): string {
		return self::BASE . '/notes/' . $suffix;
	}

	private function note(
		string $suffix, bool $old = true, bool $local = false,
		string $author = self::AUTHOR, string $visibility = 'public',
		string $inReplyTo = '', string $objectId = '',
	): Note {
		$note = new Note();
		$note->setId($this->id($suffix));
		$note->setAttributedTo($author);
		$note->setTo('https://www.w3.org/ns/activitystreams#Public');
		$note->setVisibility($visibility);
		$note->setLocal($local);
		$note->setInReplyTo($inReplyTo);
		$note->setObjectId($objectId);
		$note->setContent($suffix);
		$note->setPublishedTime(time() - 200 * 86400);
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
		$this->streamRequest->save($note);

		if ($old) {
			$qb = $this->connection->getQueryBuilder();
			$qb->update(CoreRequestBuilder::TABLE_STREAM)
				->set('creation', $qb->createNamedParameter(new DateTime('200 days ago'), IQueryBuilder::PARAM_DATE))
				->where($qb->expr()->eq('id_prim', $qb->createNamedParameter(md5($note->getId()))));
			$qb->executeStatement();
		}

		return $note;
	}

	private function exists(string $suffix): bool {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('id_prim')->from(CoreRequestBuilder::TABLE_STREAM)
			->where($qb->expr()->eq('id_prim', $qb->createNamedParameter(md5($this->id($suffix)))));
		$cursor = $qb->executeQuery();
		$found = $cursor->fetch() !== false;
		$cursor->closeCursor();

		return $found;
	}

	private function insert(string $table, array $values): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->insert($table);
		foreach ($values as $field => $value) {
			$qb->setValue($field, $qb->createNamedParameter($value));
		}
		$qb->executeStatement();
	}

	private function countRows(string $table, string $field, string $suffix): int {
		$qb = $this->connection->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'count')->from($table)
			->where($qb->expr()->eq($field, $qb->createNamedParameter(md5($this->id($suffix)))));
		$cursor = $qb->executeQuery();
		$count = (int)($cursor->fetch()['count'] ?? 0);
		$cursor->closeCursor();

		return $count;
	}

	public function testPruneRemovesOnlyStaleUnprotectedRemoteStatuses(): void {
		$this->note('stale');
		$this->note('fresh', false);
		$this->note('local', true, true, self::VIEWER);
		$this->note('direct', true, false, self::AUTHOR, 'direct');

		// liked via the per-viewer flags
		$this->note('liked');
		$this->insert(CoreRequestBuilder::TABLE_STREAM_ACTIONS, [
			'actor_id' => self::VIEWER, 'actor_id_prim' => md5(self::VIEWER),
			'stream_id' => $this->id('liked'), 'stream_id_prim' => md5($this->id('liked')),
			'liked' => 1, 'boosted' => 0, 'replied' => 0, 'bookmarked' => 0, 'values' => '[]',
		]);

		// liked via a Like action row
		$this->note('actioned');
		$this->insert(CoreRequestBuilder::TABLE_ACTIONS, [
			'id' => $this->id('actioned') . '/like', 'id_prim' => md5($this->id('actioned') . '/like'),
			'type' => 'Like', 'actor_id' => self::VIEWER, 'actor_id_prim' => md5(self::VIEWER),
			'object_id' => $this->id('actioned'), 'object_id_prim' => md5($this->id('actioned')),
		]);

		// author followed by a local user
		$this->note('followed-author', true, false, self::FOLLOWED);
		$follow = new Follow();
		$follow->setId(self::BASE . '/follows/1');
		$follow->setActorId(self::VIEWER);
		$follow->setObjectId(self::FOLLOWED);
		$follow->setFollowId(self::FOLLOWED . '/followers');
		$follow->setAccepted(true);
		$this->followsRequest->save($follow);

		// a local reply protects its remote parent
		$this->note('parent-of-local');
		$this->note('local-reply', true, true, self::VIEWER, 'public', $this->id('parent-of-local'));

		// a local boost protects the boosted remote object
		$this->note('boosted-object');
		$this->note('local-boost', true, true, self::VIEWER, 'public', '', $this->id('boosted-object'));

		// >= : a shared database may hold other stale remote rows
		$dry = $this->service->prune(90, true);
		$this->assertGreaterThanOrEqual(1, $dry['streams'], 'dry-run counts the stale row');
		$this->assertTrue($this->exists('stale'), 'dry-run deletes nothing');

		$result = $this->service->prune(90);

		$this->assertGreaterThanOrEqual(1, $result['streams']);
		$this->assertFalse($this->exists('stale'));
		foreach (['fresh', 'local', 'direct', 'liked', 'actioned', 'followed-author',
			'parent-of-local', 'local-reply', 'boosted-object', 'local-boost'] as $kept) {
			$this->assertTrue($this->exists($kept), $kept . ' must survive');
		}

		// idempotent
		$again = $this->service->prune(90);
		$this->assertSame(0, $again['streams'], 'a second run finds nothing left');
	}

	public function testPruneCascadesOverRelatedRowsAndDocuments(): void {
		$this->note('stale');
		$prim = md5($this->id('stale'));
		$this->insert(CoreRequestBuilder::TABLE_STREAM_DEST, [
			'stream_id' => $prim, 'actor_id' => md5(self::VIEWER), 'type' => 'recipient', 'subtype' => 'to',
		]);
		$this->insert(CoreRequestBuilder::TABLE_STREAM_TAGS, [
			'stream_id' => $prim, 'hashtag' => 'prunetag',
		]);
		$this->insert(CoreRequestBuilder::TABLE_CACHE_DOCUMENTS, [
			'id' => $this->id('stale') . '/doc', 'id_prim' => md5($this->id('stale') . '/doc'),
			'type' => 'Document', 'parent_id' => $this->id('stale'), 'parent_id_prim' => $prim,
			'media_type' => 'image/png', 'mime_type' => 'image/png', 'url' => 'https://remote.example/doc.png',
			'local_copy' => '', 'resized_copy' => '', 'account' => '', 'error' => 0,
			'meta' => '', 'blurhash' => '', 'description' => '',
		]);

		$result = $this->service->prune(90);

		$this->assertGreaterThanOrEqual(1, $result['streams']);
		$this->assertGreaterThanOrEqual(1, $result['documents']);
		$this->assertSame(0, $this->countRows(CoreRequestBuilder::TABLE_STREAM_DEST, 'stream_id', 'stale'));
		$this->assertSame(0, $this->countRows(CoreRequestBuilder::TABLE_STREAM_TAGS, 'stream_id', 'stale'));
		$this->assertSame(0, $this->countRows(CoreRequestBuilder::TABLE_CACHE_DOCUMENTS, 'parent_id_prim', 'stale'));
	}

	public function testDisabledRetentionPrunesNothing(): void {
		$this->note('stale');

		$this->assertSame(['streams' => 0, 'documents' => 0, 'notifications' => 0], $this->service->prune(0));
		$this->assertSame(['streams' => 0, 'documents' => 0, 'notifications' => 0], $this->service->prune());
		$this->assertTrue($this->exists('stale'));
	}
}
