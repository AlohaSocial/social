<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\SocialQueryBuilder;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCP\DB\IResult;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * How the voters of a poll are found: by the indexed `poll_prim` each vote
 * row is written with, for all the polls of one sweep at once.
 */
#[AllowMockObjectsWithoutExpectations]
class PollVotersTest extends TestCase {
	private const POLL = 'https://cloud.example/@alice/1';

	/** @var array<string, mixed> column => value, as the insert set them */
	private array $values = [];
	/** @var string[] */
	private array $wheres = [];
	/** @var array<array<string, string>> */
	private array $rows = [];
	private int $queries = 0;

	private function actionsRequest(): ActionsRequest {
		$request = $this->getMockBuilder(ActionsRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['getQueryBuilder'])
			->getMock();
		$request->method('getQueryBuilder')->willReturnCallback(fn (): SocialQueryBuilder => $this->queryBuilder());

		return $request;
	}

	private function queryBuilder(): SocialQueryBuilder {
		$qb = $this->createMock(SocialQueryBuilder::class);
		foreach (['insert', 'selectDistinct', 'from', 'setMaxResults'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('prim')->willReturnCallback(
			static fn (string $id): string => str_starts_with($id, 'http') ? md5($id) : ''
		);
		$qb->method('setValue')->willReturnCallback(function (string $column, $value) use ($qb): SocialQueryBuilder {
			$this->values[$column] = $value;

			return $qb;
		});
		$qb->method('createNamedParameter')->willReturnCallback(
			static fn ($value) => is_array($value) ? implode(',', $value) : $value
		);
		foreach (['where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnCallback(function (string $predicate) use ($qb): SocialQueryBuilder {
				$this->wheres[] = $predicate;

				return $qb;
			});
		}
		$qb->method('expr')->willReturn(new FakeExpressions());
		$qb->method('executeQuery')->willReturnCallback(function (): IResult {
			$this->queries++;
			$rows = $this->rows;
			$result = $this->createStub(IResult::class);
			$result->method('fetch')->willReturnCallback(static function () use (&$rows) {
				return array_shift($rows) ?? false;
			});

			return $result;
		});

		return $qb;
	}

	public function testTheOptionSuffixIsWhatSeparatesTheVoteFromThePoll(): void {
		$this->assertSame(self::POLL, ActionsRequest::pollOfVote('Vote', self::POLL . '#option-2'));
		// an id that has a `#` of its own keeps it: only the last suffix goes
		$this->assertSame(self::POLL . '#x', ActionsRequest::pollOfVote('Vote', self::POLL . '#x#option-0'));
		$this->assertSame('', ActionsRequest::pollOfVote('Like', self::POLL . '#option-2'));
		$this->assertSame('', ActionsRequest::pollOfVote('Vote', self::POLL));
	}

	public function testAVoteIsStoredWithThePollItWasCastIn(): void {
		$vote = new Like();
		$vote->setType('Vote');
		$vote->setId('https://cloud.example/@bob#votes/1/0');
		$vote->setActorId('https://cloud.example/@bob');
		$vote->setObjectId(self::POLL . '#option-0');

		$this->actionsRequest()->save($vote);

		$this->assertSame(md5(self::POLL), $this->values['poll_prim']);
	}

	public function testALikeIsStoredWithNoPoll(): void {
		$like = new Like();
		$like->setType('Like');
		$like->setId('https://cloud.example/@bob#likes/1');
		$like->setActorId('https://cloud.example/@bob');
		$like->setObjectId(self::POLL);

		$this->actionsRequest()->save($like);

		$this->assertSame('', $this->values['poll_prim']);
	}

	public function testTheVotersOfSeveralPollsAreOneIndexedRead(): void {
		$other = 'https://cloud.example/@alice/2';
		$this->rows = [
			['poll_prim' => md5(self::POLL), 'actor_id' => 'https://cloud.example/@bob'],
			['poll_prim' => md5($other), 'actor_id' => 'https://cloud.example/@carol'],
			['poll_prim' => md5(self::POLL), 'actor_id' => 'https://cloud.example/@dave'],
		];

		$voters = $this->actionsRequest()->votersOfPolls([self::POLL, $other, 'https://cloud.example/@alice/3']);

		$this->assertSame(1, $this->queries);
		$this->assertContains('poll_prim IN (' . md5(self::POLL) . ',' . md5($other) . ',' . md5('https://cloud.example/@alice/3') . ')', $this->wheres);
		$this->assertSame([
			self::POLL => ['https://cloud.example/@bob', 'https://cloud.example/@dave'],
			$other => ['https://cloud.example/@carol'],
			'https://cloud.example/@alice/3' => [],
		], $voters);
		foreach ($this->wheres as $where) {
			$this->assertStringNotContainsString('LIKE', $where);
		}
	}

	public function testNoPollsAskNothing(): void {
		$this->assertSame([], $this->actionsRequest()->votersOfPolls([]));
		$this->assertSame(0, $this->queries);
	}
}
