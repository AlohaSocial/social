<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use DateTime;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\SocialQueryBuilder;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Where a page of an ActivityPub collection starts.
 *
 * The outbox, followers, following and replies collections were paged by
 * offset: page N read and discarded 40 × (N − 1) rows, and a peer crawling a
 * large collection paid that on every fetch. Their `next` links are cursors
 * now, and a cursor page starts where the last one ended.
 */
#[AllowMockObjectsWithoutExpectations]
class CollectionKeysetTest extends TestCase {
	/** @var string[] */
	private array $where = [];
	/** @var array<int, mixed> */
	private array $bound = [];

	private function builder(): SocialQueryBuilder {
		$qb = $this->createMock(SocialQueryBuilder::class);
		$qb->method('expr')->willReturn(new FakeExpressions());
		$qb->method('createNamedParameter')->willReturnCallback(function ($value, $type = null): string {
			$this->bound[] = [$value, $type];

			return ':p' . count($this->bound);
		});
		$qb->method('andWhere')->willReturnCallback(function ($predicate) use ($qb) {
			$this->where[] = (string)$predicate;

			return $qb;
		});

		return $qb;
	}

	private function limitBefore(string $cursor): void {
		$request = $this->getMockBuilder(FollowsRequest::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
		(new ReflectionMethod(FollowsRequest::class, 'limitBeforeCursor'))->invoke($request, $this->builder(), $cursor);
	}

	public function testAFollowCursorIsItsCreationAndItsPrim(): void {
		$follow = new Follow();
		$follow->setId('https://remote.example/follows/1');
		$follow->setCreation(1790000000);

		$cursor = FollowsRequest::cursorAfter($follow);

		$this->assertSame('1790000000-' . md5('https://remote.example/follows/1'), $cursor);
		$this->assertTrue(FollowsRequest::isCursor($cursor));
		$this->assertFalse(FollowsRequest::isCursor("1790000000-x' OR 1=1"));
	}

	/** In the order of the lists: creation descending, then the prim. */
	public function testTheNextPageIsTheRowsAfterTheCursorInThatOrder(): void {
		$prim = md5('https://remote.example/follows/1');
		$this->limitBefore('1790000000-' . $prim);

		$this->assertSame(['f.creation <= :p1', '(f.creation < :p1 OR f.id_prim < :p2)'], $this->where);
		// a DATETIME is compared as a date, never as a number
		$this->assertInstanceOf(DateTime::class, $this->bound[0][0]);
		$this->assertSame(1790000000, $this->bound[0][0]->getTimestamp());
		$this->assertSame(IQueryBuilder::PARAM_DATE, $this->bound[0][1]);
		$this->assertSame($prim, $this->bound[1][0]);
	}

	public function testTheFirstPageHasNoCursor(): void {
		$this->limitBefore('');

		$this->assertSame([], $this->where);
	}

	public function testSomethingThatIsNotACursorMatchesNothing(): void {
		$this->limitBefore('page-2');

		$this->assertSame(['f.id_prim = :p1'], $this->where);
		$this->assertSame('', $this->bound[0][0]);
	}

	public function testAFollowReadFromTheDatabaseKnowsWhenItWasWritten(): void {
		$follow = new Follow();
		$follow->importFromDatabase(['id' => 'https://remote.example/follows/1', 'creation' => '2026-09-21 12:26:40']);

		$this->assertSame((new DateTime('2026-09-21 12:26:40'))->getTimestamp(), $follow->getCreation());
	}

	/** Both lists go through the cursor, and the posts are paged on their nid. */
	public function testEveryCollectionReadTakesACursor(): void {
		$follows = (string)file_get_contents(__DIR__ . '/../../lib/Db/FollowsRequest.php');
		foreach (['getFollowersByActorId', 'getFollowingByActorId'] as $method) {
			$body = preg_split('/\n\t\}\n/', preg_split('/function ' . $method . '\(/', $follows, 2)[1] ?? '', 2)[0];
			$this->assertStringContainsString('$this->limitBeforeCursor($qb, $before);', $body, $method);
		}

		$streams = (string)file_get_contents(__DIR__ . '/../../lib/Db/StreamRequest.php');
		$body = preg_split('/\n\t\}\n/', preg_split('/function getPublicByAuthor\(/', $streams, 2)[1] ?? '', 2)[0];
		$this->assertStringContainsString("\$qb->expr()->lt('s.nid'", $body);
		$this->assertStringContainsString("orderBy('s.nid', 'desc')", $body);
		$threads = (string)file_get_contents(__DIR__ . '/../../lib/Db/StreamThreads.php');
		$body = preg_split('/\n\t\}\n/', preg_split('/function getPublicRepliesTo\(/', $threads, 2)[1] ?? '', 2)[0];
		$this->assertStringContainsString("\$qb->expr()->gt('s.nid'", $body);
		$this->assertStringContainsString("orderBy('s.nid', 'asc')", $body);
	}
}
