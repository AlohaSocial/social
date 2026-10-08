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
use OCA\Social\Model\Details;
use OCP\DB\IResult;
use PDO;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * `StreamRequest::recount()`, the one writer of a post's counters.
 *
 * The statements it builds are run against an in-memory SQLite database, and
 * the UPDATEs are held back so a test can run them in an order of its
 * choosing: two writers whose statements were built in one order and reach
 * the database in the other is exactly the interleaving that lost a like when
 * the count was made in PHP and written into `details` afterwards.
 */
#[AllowMockObjectsWithoutExpectations]
#[RequiresPhpExtension('pdo_sqlite')]
class StreamRecountTest extends TestCase {
	private const POST = 'https://cloud.example/users/alice/statuses/1';

	private PDO $pdo;

	/** @var list<array{string, array<string, mixed>}> UPDATEs built and not yet run */
	private array $pending = [];

	private int $parameter = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->pdo = new PDO('sqlite::memory:');
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->pdo->exec(
			'CREATE TABLE social_stream (id_prim TEXT, in_reply_to_prim TEXT,'
			. ' count_replies INTEGER NOT NULL DEFAULT 0, count_likes INTEGER NOT NULL DEFAULT 0,'
			. ' count_boosts INTEGER NOT NULL DEFAULT 0, count_dislikes INTEGER NOT NULL DEFAULT 0)'
		);
		$this->pdo->exec('CREATE TABLE social_action (object_id_prim TEXT, type TEXT)');
		$this->pdo->prepare('INSERT INTO social_stream (id_prim, in_reply_to_prim) VALUES (?, ?)')
			->execute([md5(self::POST), '']);
	}

	private function streamRequest(): StreamRequest {
		$request = $this->getMockBuilder(StreamRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['getQueryBuilder'])
			->getMock();
		$request->method('getQueryBuilder')->willReturnCallback(fn (): SocialQueryBuilder => $this->queryBuilder());

		return $request;
	}

	private function queryBuilder(): SocialQueryBuilder {
		$qb = $this->createMock(SocialQueryBuilder::class);
		$params = [];
		$sets = [];
		$where = '';
		$columns = [];

		// as SocialCoreQueryBuilder::prim(): an id that is not a URL has none
		$qb->method('prim')->willReturnCallback(
			static fn (string $id): string => str_starts_with($id, 'http') ? md5($id) : ''
		);
		$qb->method('expr')->willReturn(new FakeExpressions());
		$qb->method('getTableName')->willReturnArgument(0);
		$qb->method('getColumnName')->willReturnArgument(0);
		$qb->method('createFunction')->willReturnArgument(0);
		$qb->method('createNamedParameter')->willReturnCallback(
			function (mixed $value) use (&$params): string {
				$name = ':p' . ++$this->parameter;
				$params[$name] = $value;

				return $name;
			}
		);
		foreach (['update', 'from', 'setMaxResults'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('select')->willReturnCallback(function (string ...$selected) use ($qb, &$columns) {
			$columns = $selected;

			return $qb;
		});
		$qb->method('set')->willReturnCallback(function (string $column, string $value) use ($qb, &$sets) {
			$sets[] = $column . ' = ' . $value;

			return $qb;
		});
		$qb->method('where')->willReturnCallback(function (string $predicate) use ($qb, &$where) {
			$where = $predicate;

			return $qb;
		});
		$qb->method('limitToIdPrim')->willReturnCallback(function (string $prim) use ($qb, &$where): void {
			$where = 'id_prim = ' . $qb->createNamedParameter($prim);
		});
		$qb->method('executeStatement')->willReturnCallback(function () use (&$sets, &$where, &$params): int {
			$this->pending[] = ['UPDATE social_stream SET ' . implode(', ', $sets) . ' WHERE ' . $where, $params];

			return 1;
		});
		$qb->method('executeQuery')->willReturnCallback(function () use (&$columns, &$where, &$params): IResult {
			$select = $this->pdo->prepare('SELECT ' . implode(', ', $columns) . ' FROM social_stream WHERE ' . $where . ' LIMIT 1');
			$select->execute($params);
			$row = $select->fetch(PDO::FETCH_ASSOC);

			$result = $this->createMock(IResult::class);
			$result->method('fetch')->willReturn($row);

			return $result;
		});

		return $qb;
	}

	/** Runs the held UPDATEs, in the order given (by index), or as built. */
	private function execute(int ...$order): void {
		$order = ($order === []) ? array_keys($this->pending) : $order;
		foreach ($order as $index) {
			[$sql, $params] = $this->pending[$index];
			$this->pdo->prepare($sql)->execute($params);
		}
		$this->pending = [];
	}

	private function action(string $type): void {
		$this->pdo->prepare('INSERT INTO social_action (object_id_prim, type) VALUES (?, ?)')
			->execute([md5(self::POST), $type]);
	}

	private function reply(string $suffix): void {
		$this->pdo->prepare('INSERT INTO social_stream (id_prim, in_reply_to_prim) VALUES (?, ?)')
			->execute([md5(self::POST . $suffix), md5(self::POST)]);
	}

	private function column(string $column): int {
		return (int)$this->pdo->query('SELECT ' . $column . ' FROM social_stream WHERE id_prim = \'' . md5(self::POST) . '\'')
			->fetchColumn();
	}

	private function post(int $remoteLikes = 0): Note {
		$post = new Note();
		$post->setId(self::POST);
		$post->setDetailInt(Details::REMOTE_LIKES, $remoteLikes);

		return $post;
	}

	/**
	 * Two likes on one post, each writer's statement built before the other
	 * like was stored and the older statement reaching the database last —
	 * the order in which the count made in PHP lost one of them.
	 */
	public function testTwoLikesLandingTogetherAreBothCounted(): void {
		$request = $this->streamRequest();

		$this->action('Like');
		$request->recount($this->post(4), Details::LIKES);
		$this->action('Like');
		$request->recount($this->post(4), Details::LIKES);

		$this->execute(1, 0);

		$this->assertSame(6, $this->column('count_likes'));
	}

	/** What a statement carries is the origin's half and ids, never a count of this instance's own. */
	public function testNoCountMadeBeforehandIsBoundIntoTheStatement(): void {
		$this->action('Like');
		$this->action('Like');

		$this->streamRequest()->recount($this->post(4), Details::LIKES);

		$this->assertSame([4], array_values(array_filter($this->pending[0][1], 'is_int')));
	}

	/** A like and a boost together each move their own column and nothing else. */
	public function testALikeAndABoostTogetherKeepBoth(): void {
		$request = $this->streamRequest();
		$this->action('Like');
		$this->action('Announce');
		$this->action('Announce');

		$request->recount($this->post(), Details::LIKES);
		$request->recount($this->post(), Details::BOOSTS);
		$this->execute(1, 0);

		$this->assertSame(1, $this->column('count_likes'));
		$this->assertSame(2, $this->column('count_boosts'));
	}

	/** An unlike or a deleted reply is a recount too, so a counter goes down by itself. */
	public function testAnUndoCountsOneFewer(): void {
		$request = $this->streamRequest();
		$this->action('Dislike');
		$this->action('Dislike');
		$request->recount($this->post(), Details::DISLIKES);
		$this->execute();

		$this->pdo->exec('DELETE FROM social_action WHERE rowid = 1');
		$request->recount($this->post(), Details::DISLIKES);
		$this->execute();

		$this->assertSame(1, $this->column('count_dislikes'));
	}

	/**
	 * Replies are rows of the table the statement updates, which MySQL only
	 * allows to be read through a derived table; the origin's half is added.
	 */
	public function testRepliesAreCountedOutOfTheTableBeingUpdated(): void {
		$this->reply('/a');
		$this->reply('/b');
		$post = $this->post();
		$post->setDetailInt(Details::REMOTE_REPLIES, 5);

		$this->streamRequest()->recount($post, Details::REPLIES);

		$this->assertStringContainsString('(SELECT `c` FROM (SELECT COUNT(*)', $this->pending[0][0]);
		$this->execute();
		$this->assertSame(7, $this->column('count_replies'));
	}

	/** Several counters are one statement, as the remote-count refresh writes them. */
	public function testSeveralCountersAreOneStatement(): void {
		$this->action('Like');
		$this->action('Announce');
		$this->reply('/a');

		$this->streamRequest()->recount($this->post(2), Details::LIKES, Details::BOOSTS, Details::REPLIES);

		$this->assertCount(1, $this->pending);
		$this->execute();
		$this->assertSame([3, 1, 1], [$this->column('count_likes'), $this->column('count_boosts'), $this->column('count_replies')]);
	}

	/** The post handed in carries what the row now says, for whatever the caller does with it next. */
	public function testThePostIsGivenTheStoredNumbers(): void {
		$this->pdo->exec('UPDATE social_stream SET count_likes = 12');
		$post = $this->post();

		$this->streamRequest()->recount($post, Details::LIKES);

		$this->assertSame(12, $post->getDetailInt(Details::LIKES));
	}

	public function testAnythingElseIsNotACounterAndWritesNothing(): void {
		$this->streamRequest()->recount($this->post(), Details::REMOTE_LIKES, Details::MENTIONS);

		$post = new Note();
		$post->setId('not-a-url');
		$this->streamRequest()->recount($post, Details::LIKES);

		$this->assertSame([], $this->pending);
	}
}
