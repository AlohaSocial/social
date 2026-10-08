<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\SocialQueryBuilder;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Stream;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * `social_stream.poll_ends_at`: written with every poll, and the one thing
 * the closed-poll sweep reads polls by.
 */
#[AllowMockObjectsWithoutExpectations]
class StreamPollEndTest extends TestCase {
	/** @var array<string, mixed> column => value, as setValue() received them */
	private array $values = [];
	/** @var string[] */
	private array $wheres = [];
	/** @var string[] */
	private array $orders = [];
	/** @var Stream[] what the read hands back */
	private array $read = [];

	private function streamRequest(): StreamRequest {
		$request = $this->getMockBuilder(StreamRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['getStreamInsertSql', 'getStreamSelectSql', 'getStreamsFromRequest'])
			->getMock();
		$request->method('getStreamInsertSql')->willReturnCallback(fn (): SocialQueryBuilder => $this->queryBuilder());
		$request->method('getStreamSelectSql')->willReturnCallback(fn (): SocialQueryBuilder => $this->queryBuilder());
		$request->method('getStreamsFromRequest')->willReturnCallback(fn (): array => $this->read);

		return $request;
	}

	private function queryBuilder(): SocialQueryBuilder {
		$qb = $this->createMock(SocialQueryBuilder::class);
		$qb->method('prim')->willReturnCallback(static fn (string $id): string => ($id === '') ? '' : md5($id));
		$qb->method('setValue')->willReturnCallback(function (string $column, $value) use ($qb): SocialQueryBuilder {
			$this->values[$column] = $value;

			return $qb;
		});
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value) => $value);
		$qb->method('andWhere')->willReturnCallback(function ($predicate) use ($qb): SocialQueryBuilder {
			$this->wheres[] = (string)$predicate;

			return $qb;
		});
		$qb->method('limitToType')->willReturnCallback(function (string $type) use ($qb): SocialQueryBuilder {
			$this->wheres[] = 's.type = ' . $type;

			return $qb;
		});
		foreach (['orderBy', 'addOrderBy'] as $method) {
			$qb->method($method)->willReturnCallback(function (string $sort, ?string $order = null) use ($qb): SocialQueryBuilder {
				$this->orders[] = $sort . ' ' . $order;

				return $qb;
			});
		}
		$qb->method('expr')->willReturn(new FakeExpressions());

		return $qb;
	}

	public function testAPollIsStoredWithItsEnd(): void {
		$poll = new Question();
		$poll->setId('https://cloud.example/@alice/1');
		$poll->setPollData(['yes', 'no'], false, 3600);

		$this->streamRequest()->saveStream($poll);

		$this->assertSame(strtotime($poll->getEndTime()), $this->values['poll_ends_at']);
	}

	public function testAPostThatIsNotAPollHasNoEnd(): void {
		$note = new Note();
		$note->setId('https://cloud.example/@alice/2');

		$this->streamRequest()->saveStream($note);

		$this->assertArrayNotHasKey('poll_ends_at', $this->values);
	}

	public function testThePollsThatClosedAreReadByTheirEndAlone(): void {
		$since = time() - 600;
		$poll = new Question();
		$this->read = [$poll, new Note()];

		$closed = $this->streamRequest()->getPollsClosedSince($since, 10);

		$this->assertSame([$poll], $closed);
		$this->assertContains('s.poll_ends_at > ' . $since, $this->wheres);
		$this->assertContains('s.type = Question', $this->wheres);
		$this->assertSame('s.poll_ends_at asc', $this->orders[0]);
		foreach ($this->wheres as $where) {
			// no window on when the poll was published: a poll is found by
			// when it closed, however long it ran
			$this->assertStringNotContainsString('published_time', $where);
		}
	}

	public function testAnEndTimeIsReadAsAUnixTime(): void {
		$this->assertSame(1767225600, Question::timestampOf('2026-01-01T00:00:00Z'));
		$this->assertNull(Question::timestampOf(''));
		$this->assertNull(Question::timestampOf('not a date'));
	}
}
