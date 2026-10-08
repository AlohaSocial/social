<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\SearchTermsRequest;
use OCA\Social\Db\SocialQueryBuilder;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\ConfigService;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * How the word index is written, rewritten and read.
 *
 * The unit suite has no database, so each query builder records what it was
 * asked to build and answers a read from the row sets queued here; the
 * inserts are the multi-row statements the connection receives.
 */
#[AllowMockObjectsWithoutExpectations]
class SearchTermsRequestTest extends TestCase {
	private const POST = 'https://cloud.example/@alice/1';

	/** @var list<array<string, mixed>> what each query builder was asked */
	private array $queries = [];
	/** @var list<list<array<string, mixed>>> rows for the next executeQuery()s, in order */
	private array $rowSets = [];
	/** @var list<array{string, list<int|string>}> */
	private array $inserts = [];

	private function request(): SearchTermsRequest {
		$request = $this->getMockBuilder(SearchTermsRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['getQueryBuilder'])
			->getMock();
		$request->method('getQueryBuilder')->willReturnCallback(fn (): SocialQueryBuilder => $this->queryBuilder());

		$connection = $this->createMock(IDBConnection::class);
		$connection->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_SQLITE);
		$connection->method('escapeLikeParameter')->willReturnCallback(static fn (string $value): string => addcslashes($value, '%_\\'));
		$connection->method('executeStatement')->willReturnCallback(function (string $sql, array $params = []): int {
			$this->inserts[] = [$sql, $params];

			return 1;
		});
		(new ReflectionProperty(SearchTermsRequest::class, 'dbConnection'))->setValue($request, $connection);

		return $request;
	}

	private function queryBuilder(): SocialQueryBuilder {
		$index = count($this->queries);
		$this->queries[$index] = ['where' => [], 'join' => [], 'order' => null, 'limit' => null, 'delete' => null, 'select' => [], 'executed' => false];
		$record = function (string $key, mixed $value) use ($index): void {
			if (is_array($this->queries[$index][$key])) {
				$this->queries[$index][$key][] = $value;
			} else {
				$this->queries[$index][$key] = $value;
			}
		};

		$qb = $this->createMock(SocialQueryBuilder::class);
		$qb->method('prim')->willReturnCallback(static fn (string $id): string => str_starts_with($id, 'http') ? md5($id) : '');
		$qb->method('expr')->willReturn(new FakeExpressions());
		$qb->method('createNamedParameter')->willReturnCallback(
			static fn ($value): string => ':' . (is_array($value) ? implode(',', $value) : (string)$value)
		);
		foreach (['select', 'selectDistinct'] as $method) {
			$qb->method($method)->willReturnCallback(function (...$columns) use ($qb, $record): SocialQueryBuilder {
				foreach ($columns as $column) {
					$record('select', (string)$column);
				}

				return $qb;
			});
		}
		$qb->method('from')->willReturnSelf();
		$qb->method('delete')->willReturnCallback(function (string $table) use ($qb, $record): SocialQueryBuilder {
			$record('delete', $table);

			return $qb;
		});
		foreach (['where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnCallback(function ($predicate) use ($qb, $record): SocialQueryBuilder {
				$record('where', (string)$predicate);

				return $qb;
			});
		}
		$qb->method('innerJoin')->willReturnCallback(function ($from, $table, $alias, $on) use ($qb, $record): SocialQueryBuilder {
			$record('join', $table . ' ' . $alias . ' ON ' . $on);

			return $qb;
		});
		$qb->method('orderBy')->willReturnCallback(function ($column, $order) use ($qb, $record): SocialQueryBuilder {
			$record('order', $column . ' ' . $order);

			return $qb;
		});
		$qb->method('setMaxResults')->willReturnCallback(function ($limit) use ($qb, $record): SocialQueryBuilder {
			$record('limit', $limit);

			return $qb;
		});
		$qb->method('executeQuery')->willReturnCallback(function (): IResult {
			$rows = array_shift($this->rowSets) ?? [];
			$result = $this->createMock(IResult::class);
			$result->method('fetch')->willReturnCallback(static function () use (&$rows) {
				return array_shift($rows) ?? false;
			});

			return $result;
		});
		$qb->method('executeStatement')->willReturnCallback(function () use ($record): int {
			$record('executed', true);

			return 1;
		});

		return $qb;
	}

	private function note(string $content, int|string $nid = '42'): Note {
		$note = new Note();
		$note->setId(self::POST);
		$note->setContent($content);
		$note->setNid($nid);

		return $note;
	}

	/** @return list<list<int|string>> the rows of every insert, as (term, nid, stream_id_prim) */
	private function insertedRows(): array {
		$rows = [];
		foreach ($this->inserts as [$sql, $params]) {
			$this->assertStringStartsWith('INSERT OR IGNORE INTO `*PREFIX*social_search_term` (`term`, `head`, `nid`, `stream_id_prim`)', $sql);
			foreach (array_chunk($params, 4) as [$term, $head, $nid, $prim]) {
				$this->assertSame(mb_substr($term, 0, 3), $head);
				$rows[] = [$term, $nid, $prim];
			}
		}

		return $rows;
	}

	public function testAStoredPostIsIndexedUnderEachOfItsWordsInOneStatement(): void {
		$this->request()->index($this->note('<p>The Zebra crossed the road</p>'));

		$this->assertCount(1, $this->inserts);
		$this->assertSame([
			['the', '42', md5(self::POST)],
			['zebra', '42', md5(self::POST)],
			['crossed', '42', md5(self::POST)],
			['road', '42', md5(self::POST)],
		], $this->insertedRows());
	}

	public function testOnlyStatusesAreIndexed(): void {
		$boost = new Announce();
		$boost->setId(self::POST . '/activity');
		$boost->setContent('a boost has no words of its own');
		$boost->setNid('42');

		$this->request()->index($boost);

		$this->assertSame([], $this->inserts);
	}

	public function testAnEditWritesOnlyTheWordsThatChanged(): void {
		$this->rowSets[] = [['term' => 'zebra', 'nid' => '42'], ['term' => 'old', 'nid' => '42']];

		$this->request()->reindex($this->note('<p>zebra new</p>', 0));

		$this->assertSame('social_search_term', $this->queries[1]['delete']);
		$this->assertContains('term IN (:old)', $this->queries[1]['where']);
		$this->assertContains('stream_id_prim = :' . md5(self::POST), $this->queries[1]['where']);
		$this->assertSame([['new', '42', md5(self::POST)]], $this->insertedRows(), 'the nid is the stored rows\' own');
	}

	public function testAnUpdateThatChangedNoWordWritesNothing(): void {
		$this->rowSets[] = [['term' => 'zebra', 'nid' => '42'], ['term' => 'crossed', 'nid' => '42']];

		$this->request()->reindex($this->note('<p>Zebra crossed</p>'));

		$this->assertCount(1, $this->queries, 'the read and nothing else');
		$this->assertSame([], $this->inserts);
	}

	/** An Update from another server does not carry the nid this instance stored the post under. */
	public function testAPostWithNoWordsYetHasItsNidReadOffThePost(): void {
		$this->rowSets[] = [];
		$this->rowSets[] = [['nid' => '77']];

		$this->request()->reindex($this->note('<p>zebra</p>', 0));

		$this->assertContains('id_prim = :' . md5(self::POST), $this->queries[1]['where']);
		$this->assertSame([['zebra', '77', md5(self::POST)]], $this->insertedRows());
	}

	public function testTheLongestWholeWordDrivesAndTheOthersArePointLookups(): void {
		$nids = ['9', '7'];
		$this->rowSets[] = array_map(static fn (string $nid): array => ['nid' => $nid], $nids);

		$found = $this->request()->candidateNids(['red', 'zebra'], '', '100', 0, '0', '', 20);

		$this->assertSame($nids, $found);
		$query = $this->queries[0];
		$this->assertSame(['sx.nid'], $query['select']);
		$this->assertSame('sx.term = :zebra', $query['where'][0]);
		$this->assertContains('sx.nid < :100', $query['where']);
		$this->assertSame('social_search_term sx0 ON (sx0.nid = sx.nid AND sx0.term = :red)', $query['join'][0]);
		$this->assertSame('social_stream s ON (s.nid = sx.nid AND s.type IN (:Note,Question))', $query['join'][1]);
		$this->assertSame('sx.nid desc', $query['order']);
		$this->assertSame(20, $query['limit']);
		foreach ($query['where'] as $predicate) {
			$this->assertStringNotContainsString('LIKE', $predicate, 'a whole word is an equality');
		}
	}

	public function testALonePrefixIsReadThroughItsHeadInsideTheWindow(): void {
		$this->request()->candidateNids([], 'zebr', 0, '5', '1000', '', 20);

		$where = $this->queries[0]['where'];
		$this->assertSame('sx.head = :zeb', $where[0], 'an equality any index answers, in any collation');
		$this->assertSame('sx.term LIKE :zebr%', $where[1]);
		$this->assertContains('sx.nid > :1000', $where);
		$this->assertContains('sx.nid > :5', $where);
	}

	public function testAPrefixBesideWholeWordsIsNotAskedOfTheIndex(): void {
		$this->request()->candidateNids(['zebra'], 'cro', 0, 0, '1000', '', 20);

		foreach ($this->queries[0]['where'] as $predicate) {
			$this->assertStringNotContainsString('cro', $predicate);
			$this->assertStringNotContainsString(':1000', $predicate, 'whole words are searched across all time');
		}
	}

	public function testANarrowedSearchJoinsThePostsOfThatAuthorOnly(): void {
		$this->request()->candidateNids(['zebra'], '', 0, 0, '0', 'abc', 20);

		$this->assertStringContainsString('s.attributed_to_prim = :abc', $this->queries[0]['join'][0]);
	}

	public function testNothingToLookUpIsNoQuery(): void {
		$this->assertSame([], $this->request()->candidateNids([], '', 0, 0, '0', '', 20));
		$this->assertSame([], $this->queries);
	}

	public function testTheBackfillIndexesAChunkAndSaysWhereItGotTo(): void {
		$this->rowSets[] = [
			['nid' => '10', 'id_prim' => 'p10', 'content' => '<p>first post</p>'],
			['nid' => '11', 'id_prim' => 'p11', 'content' => '<p>second</p>'],
		];

		$progress = $this->request()->backfill('9', 500);

		$this->assertSame(['last' => '11', 'read' => 2], $progress);
		$this->assertContains('nid > :9', $this->queries[0]['where']);
		$this->assertSame('nid asc', $this->queries[0]['order']);
		$this->assertSame([['first', '10', 'p10'], ['post', '10', 'p10'], ['second', '11', 'p11']], $this->insertedRows());
	}

	public function testAnEmptyChunkStaysWhereItWas(): void {
		$this->assertSame(['last' => '9', 'read' => 0], $this->request()->backfill('9', 500));
		$this->assertSame([], $this->inserts);
	}

	public function testTheIndexIsReadyOnlyOnceTheBackfillSaidSo(): void {
		$request = $this->request();
		$config = $this->createMock(ConfigService::class);
		$config->method('getAppValue')->with(SearchTermsRequest::READY)->willReturnOnConsecutiveCalls('', '1');
		(new ReflectionProperty(SearchTermsRequest::class, 'configService'))->setValue($request, $config);

		$this->assertFalse($request->isReady());
		$this->assertTrue($request->isReady());
	}
}
