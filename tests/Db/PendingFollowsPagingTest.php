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
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * How a page of pending follow requests is cut.
 *
 * `creation` is stored to the second, so a cursor on it alone skips or
 * repeats the requests that arrived in the same one. The rows the statement
 * returns need a database (tests/Integration/Db/FollowLifecycleTest reads
 * them); what it asks for does not.
 */
#[AllowMockObjectsWithoutExpectations]
class PendingFollowsPagingTest extends TestCase {
	private const ALICE = 'https://cloud.example/@alice';
	private const KEY = '0123456789abcdef0123456789abcdef';

	/** @var string[] */
	private array $where = [];
	/** @var string[] */
	private array $order = [];
	private ?int $max = null;
	/** @var Follow[] what the statement "returned" */
	private array $rows = [];

	private function request(): FollowsRequest&MockObject {
		$qb = $this->createMock(SocialQueryBuilder::class);
		$qb->method('expr')->willReturn(new FakeExpressions());
		$qb->method('prim')->willReturnCallback(static fn (string $id): string => md5($id));
		$qb->method('createNamedParameter')->willReturnCallback(
			static fn ($value): string => ($value instanceof DateTime)
				? "'" . $value->format('Y-m-d H:i:s') . "'"
				: "'" . (string)$value . "'"
		);
		$qb->method('andWhere')->willReturnCallback(function ($clause) use (&$qb): SocialQueryBuilder {
			$this->where[] = (string)$clause;

			return $qb;
		});
		$qb->method('setMaxResults')->willReturnCallback(function (?int $max) use (&$qb): SocialQueryBuilder {
			$this->max = $max;

			return $qb;
		});
		foreach (['orderBy', 'addOrderBy'] as $method) {
			$qb->method($method)->willReturnCallback(
				function (string $sort, ?string $direction = null) use (&$qb): SocialQueryBuilder {
					$this->order[] = trim($sort . ' ' . (string)$direction);

					return $qb;
				}
			);
		}

		$request = $this->getMockBuilder(FollowsRequest::class)
			->disableOriginalConstructor()
			->onlyMethods([
				'getFollowsSelectSql', 'limitToPrim', 'leftJoinCacheActors',
				'leftJoinDetails', 'getFollowsFromRequest',
			])
			->getMock();
		$request->method('getFollowsSelectSql')->willReturn($qb);
		$request->method('getFollowsFromRequest')->willReturnCallback(fn (): array => $this->rows);

		return $request;
	}

	private function follow(string $actor, int $creation, string $key): Follow {
		$follow = new Follow();
		$follow->setActorId($actor);
		$follow->setCreation($creation);
		$follow->setIdPrim($key);

		return $follow;
	}

	public function testTheCursorIsTheRowsPlaceInTheOrder(): void {
		$follow = $this->follow(self::ALICE, 1767268800, self::KEY);

		$this->assertSame('1767268800-' . self::KEY, FollowsRequest::pendingCursor($follow));
	}

	public function testARowReadBackCarriesWhatItsCursorIsMadeOf(): void {
		$follow = new Follow();
		$follow->importFromDatabase([
			'id' => 'https://remote.example/@bob#follow/1',
			'id_prim' => self::KEY,
			'creation' => '2026-01-01 12:00:00',
		]);

		$this->assertSame(self::KEY, $follow->getIdPrim());
		$this->assertSame((int)strtotime('2026-01-01 12:00:00'), $follow->getCreation());
	}

	public function testAPageIsBoundedByItsLimit(): void {
		$this->request()->getPendingByObjectId(self::ALICE, 40);

		$this->assertSame(40, $this->max);
		$this->assertSame(['f.creation desc', 'f.id_prim desc'], $this->order);
	}

	/**
	 * The next page starts after the cursor's row, whose second it may share
	 * with rows it has not yet reached: those are decided by the key.
	 */
	public function testTheNextPageComparesTheDateAndTheKeyTogether(): void {
		$time = (new DateTime())->setTimestamp(1767268800)->format('Y-m-d H:i:s');

		$this->request()->getPendingByObjectId(self::ALICE, 2, '1767268800-' . self::KEY);

		$this->assertSame(
			["(f.creation < '$time' OR (f.creation = '$time' AND f.id_prim < '" . self::KEY . "'))"],
			$this->where
		);
		$this->assertSame(['f.creation desc', 'f.id_prim desc'], $this->order);
	}

	public function testThePreviousPageIsReadUpwardsAndHandedBackNewestFirst(): void {
		$time = (new DateTime())->setTimestamp(1767268800)->format('Y-m-d H:i:s');
		$older = $this->follow('https://remote.example/@bob', 1767268800, str_repeat('1', 32));
		$newer = $this->follow('https://remote.example/@carol', 1767268800, str_repeat('2', 32));
		$this->rows = [$older, $newer];

		$page = $this->request()->getPendingByObjectId(self::ALICE, 2, '', '1767268800-' . self::KEY);

		$this->assertSame(
			["(f.creation > '$time' OR (f.creation = '$time' AND f.id_prim > '" . self::KEY . "'))"],
			$this->where
		);
		$this->assertSame(['f.creation asc', 'f.id_prim asc'], $this->order);
		$this->assertSame([$newer, $older], $page);
	}

	/**
	 * An account id or a status id sent as `max_id` is not a place in this
	 * list; starting it again would send a client round in a circle.
	 */
	public function testACursorThatIsNotOneOfOursPagesNothing(): void {
		$this->rows = [$this->follow(self::ALICE, 1, self::KEY)];

		$this->assertSame([], $this->request()->getPendingByObjectId(self::ALICE, 2, '113456789012345678'));
	}
}
