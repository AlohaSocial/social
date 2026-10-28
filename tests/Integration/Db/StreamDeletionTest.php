<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use DateTime;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Tests\Integration\Helper\RowSeeder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The batched halves of deleting posts, against the real database: what
 * `deleteRelatedTo()` removes from every table that hangs off a post,
 * `deleteByPrims()` removing the rows themselves, the instance purge's walk
 * over a domain's authors, and the notification sweep's selection.
 */
class StreamDeletionTest extends TestCase {
	private const DOMAIN = 'deldom-itest.example';

	/** every table that keys on a post, and the column it keys on */
	private const RELATED = [
		[CoreRequestBuilder::TABLE_ATPROTO_CHAT_MESSAGE, 'stream_id_prim'],
		[CoreRequestBuilder::TABLE_STREAM_DEST, 'stream_id'],
		[CoreRequestBuilder::TABLE_STREAM_TAGS, 'stream_id'],
		[CoreRequestBuilder::TABLE_STREAM_ACTIONS, 'stream_id_prim'],
		[CoreRequestBuilder::TABLE_STREAM_CARDS, 'stream_id_prim'],
		[CoreRequestBuilder::TABLE_STATUS_REVISIONS, 'stream_id_prim'],
		[CoreRequestBuilder::TABLE_ACTIONS, 'object_id_prim'],
		[CoreRequestBuilder::TABLE_REACTIONS, 'object_id_prim'],
		[CoreRequestBuilder::TABLE_COLLECTION_ITEMS, 'stream_id_prim'],
		[CoreRequestBuilder::TABLE_STREAM_VIEWS, 'stream_id_prim'],
		[CoreRequestBuilder::TABLE_MEDIA_TAGS, 'stream_id_prim'],
		[CoreRequestBuilder::TABLE_STREAM_MEDIA, 'stream_id_prim'],
		[CoreRequestBuilder::TABLE_WATCH, 'stream_id_prim'],
		[CoreRequestBuilder::TABLE_VIDEO_VIEWS, 'stream_id_prim'],
		[CoreRequestBuilder::TABLE_VIDEO_SEEN, 'stream_id_prim'],
		[CoreRequestBuilder::TABLE_TEAM_POSTS, 'stream_id_prim'],
		[CoreRequestBuilder::TABLE_IMPORTED_POSTS, 'stream_id_prim'],
		[CoreRequestBuilder::TABLE_SEARCH_TERMS, 'stream_id_prim'],
	];

	private StreamRequest $streamRequest;
	private IDBConnection $connection;
	/** @var string[] prims of this run */
	private array $prims = [];

	protected function setUp(): void {
		parent::setUp();
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->connection = Server::get(IDBConnection::class);
	}

	protected function tearDown(): void {
		$this->streamRequest->deleteRelatedTo($this->prims);
		$this->streamRequest->deleteByPrims($this->prims);
		parent::tearDown();
	}

	private function prim(): string {
		return $this->prims[] = md5(random_bytes(16));
	}

	/** A bare stream row; enough for the statements under test, which key on it alone. */
	private function streamRow(string $prim, array $values = []): void {
		RowSeeder::insert(CoreRequestBuilder::TABLE_STREAM, array_merge([
			'id_prim' => $prim,
			'id' => 'https://' . self::DOMAIN . '/notes/' . $prim,
			'attributed_to' => 'https://elsewhere-itest.example/users/' . $prim,
			'attributed_to_prim' => md5('https://elsewhere-itest.example/users/' . $prim),
			'attachments' => '[]',
		], $values));
	}

	private function cacheDocNid(string $parentPrim): string {
		RowSeeder::insert(CoreRequestBuilder::TABLE_CACHE_DOCUMENTS, [
			'parent_id_prim' => $parentPrim, 'local_copy' => '', 'resized_copy' => '',
		]);
		$qb = $this->connection->getQueryBuilder();
		$qb->select('nid')->from(CoreRequestBuilder::TABLE_CACHE_DOCUMENTS)
			->where($qb->expr()->eq('parent_id_prim', $qb->createNamedParameter($parentPrim)));
		$result = $qb->executeQuery();
		$nid = (string)$result->fetchOne();
		$result->closeCursor();

		return $nid;
	}

	public function testEverythingThatHangsOffAPostGoesAndNothingOfAnotherPost(): void {
		$gone = $this->prim();
		$kept = $this->prim();
		foreach ([$gone, $kept] as $prim) {
			foreach (self::RELATED as [$table, $column]) {
				RowSeeder::insert($table, [$column => $prim]);
			}
			RowSeeder::insert(CoreRequestBuilder::TABLE_RENDITIONS, [
				'doc_nid' => $this->cacheDocNid($prim), 'local_copy' => '', 'playlist' => '',
			]);
		}
		$goneDoc = $this->cacheDocNid($gone);

		$this->assertSame(2, $this->streamRequest->deleteRelatedTo([$gone]), 'both cached attachments of the post');

		foreach (self::RELATED as [$table, $column]) {
			$this->assertSame(0, RowSeeder::count($table, [$column => $gone]), $table . ' of the deleted post');
			$this->assertSame(1, RowSeeder::count($table, [$column => $kept]), $table . ' of the other post');
		}
		$this->assertSame(0, RowSeeder::count(CoreRequestBuilder::TABLE_CACHE_DOCUMENTS, ['parent_id_prim' => $gone]));
		$this->assertSame(1, RowSeeder::count(CoreRequestBuilder::TABLE_CACHE_DOCUMENTS, ['parent_id_prim' => $kept]));
		$this->assertSame(0, RowSeeder::count(CoreRequestBuilder::TABLE_RENDITIONS, ['doc_nid' => $goneDoc]), 'a document\'s ladder goes with it');
		$this->assertSame(0, $this->streamRequest->deleteRelatedTo([]));
	}

	public function testDeletingByPrimsTakesTheStreamRowsNamedAndNoOthers(): void {
		[$one, $two, $kept] = [$this->prim(), $this->prim(), $this->prim()];
		foreach ([$one, $two, $kept] as $prim) {
			$this->streamRow($prim);
		}

		$this->streamRequest->deleteByPrims([]);
		$this->assertSame(1, RowSeeder::count(CoreRequestBuilder::TABLE_STREAM, ['id_prim' => $one]), 'an empty list deletes nothing');

		$this->streamRequest->deleteByPrims([$one, $two]);

		$this->assertSame(0, RowSeeder::count(CoreRequestBuilder::TABLE_STREAM, ['id_prim' => $one]));
		$this->assertSame(0, RowSeeder::count(CoreRequestBuilder::TABLE_STREAM, ['id_prim' => $two]));
		$this->assertSame(1, RowSeeder::count(CoreRequestBuilder::TABLE_STREAM, ['id_prim' => $kept]));
	}

	/**
	 * Each author of the domain once, whatever the scheme or the case of the
	 * host, and none of a subdomain or of a host that only begins with it.
	 */
	public function testTheAuthorsOfADomainAreItsOwnEachOnce(): void {
		$authors = [
			'https://' . self::DOMAIN . '/users/a' => 2,
			'http://' . self::DOMAIN . '/users/b' => 1,
			'https://' . strtoupper(self::DOMAIN) . '/users/c' => 1,
			'https://sub.' . self::DOMAIN . '/users/d' => 1,
			'https://' . self::DOMAIN . '.evil/users/e' => 1,
		];
		foreach ($authors as $author => $posts) {
			for ($i = 0; $i < $posts; $i++) {
				$this->streamRow($this->prim(), ['attributed_to' => $author, 'attributed_to_prim' => md5($author)]);
			}
		}

		$this->assertEqualsCanonicalizing([
			'https://' . self::DOMAIN . '/users/a',
			'http://' . self::DOMAIN . '/users/b',
			'https://' . strtoupper(self::DOMAIN) . '/users/c',
		], $this->streamRequest->getAuthorsFromDomain(self::DOMAIN));
		$this->assertCount(1, $this->streamRequest->getAuthorsFromDomain(self::DOMAIN, 1));
	}

	public function testAWildcardIsNotADomain(): void {
		$this->expectException(InvalidResourceException::class);

		$this->streamRequest->getAuthorsFromDomain('%');
	}

	/** Only local in-app notifications older than the cutoff, oldest first. */
	public function testTheNotificationSweepSelectsOldLocalNotificationsOldestFirst(): void {
		$cutoff = new DateTime('@' . (time() - 86400));
		$rows = [
			'older' => [SocialAppNotification::TYPE, 1, time() - 3 * 86400],
			'old' => [SocialAppNotification::TYPE, 1, time() - 2 * 86400],
			'recent' => [SocialAppNotification::TYPE, 1, time() - 3600],
			'remote' => [SocialAppNotification::TYPE, 0, time() - 3 * 86400],
			'note' => ['Note', 1, time() - 3 * 86400],
		];
		$prims = [];
		foreach ($rows as $name => [$type, $local, $creation]) {
			$prims[$name] = $this->prim();
			$this->streamRow($prims[$name], [
				'type' => $type, 'local' => $local, 'creation' => new DateTime('@' . $creation),
			]);
		}

		$selected = array_values(array_intersect(
			$this->streamRequest->getNotificationPrimsBefore($cutoff, 10000), $prims
		));

		$this->assertSame([$prims['older'], $prims['old']], $selected);
		$before = $this->streamRequest->countNotificationsBefore($cutoff);
		$this->streamRequest->deleteByPrims([$prims['older']]);
		$this->assertSame($before - 1, $this->streamRequest->countNotificationsBefore($cutoff));
	}
}
