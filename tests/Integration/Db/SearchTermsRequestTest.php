<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\SearchTermsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\ConfigService;
use OCA\Social\Tests\Integration\Helper\RowSeeder;
use OCA\Social\Tools\SearchTerms;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The word index behind the content search, against the real database: what
 * a stored post writes, what an edit rewrites, which posts a set of words
 * and a prefix find, and the backfill that indexes posts stored before the
 * table existed.
 *
 * The posts are published in this run's own second, decades back: a nid is
 * the publication second with a random suffix, so every nid range below
 * holds this test's posts and no other. A date ahead of now would not do, as
 * its nid is composed from now.
 */
class SearchTermsRequestTest extends TestCase {
	private const BASE = 'https://cloud.example.org/sterms';
	private const ALICE = self::BASE . '/users/alice';
	private const BOB = self::BASE . '/users/bob';

	private const SUFFIXES = ['zebra-1', 'zebra-2', 'zebra-3', 'other', 'edited', 'boost'];

	private SearchTermsRequest $searchTermsRequest;
	private StreamRequest $streamRequest;
	private IDBConnection $connection;
	private int $second;
	/** @var array<string, string> suffix => nid */
	private array $nids = [];
	private string $ready;

	protected function setUp(): void {
		parent::setUp();
		$this->searchTermsRequest = Server::get(SearchTermsRequest::class);
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->connection = Server::get(IDBConnection::class);
		$this->second = 500_000_000 + random_int(0, 100_000) * 100;
		$this->ready = (string)Server::get(ConfigService::class)->getAppValue(SearchTermsRequest::READY);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		Server::get(ConfigService::class)->setAppValue(SearchTermsRequest::READY, $this->ready);
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach (self::SUFFIXES as $suffix) {
			$this->streamRequest->deleteById($this->id($suffix));
			RowSeeder::delete(CoreRequestBuilder::TABLE_SEARCH_TERMS, ['stream_id_prim' => md5($this->id($suffix))]);
		}
	}

	private function id(string $suffix): string {
		return self::BASE . '/notes/' . $suffix;
	}

	/** Published `$offset` seconds into this run's second. */
	private function post(string $suffix, string $html, int $offset, string $author = self::ALICE): Note {
		$note = new Note();
		$note->setId($this->id($suffix));
		$note->setAttributedTo($author);
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$note->setContent($html);
		$note->setPublishedTime($this->second + $offset);
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z', $this->second + $offset));
		$this->streamRequest->save($note);
		$qb = $this->connection->getQueryBuilder();
		$qb->select('nid')->from(CoreRequestBuilder::TABLE_STREAM)
			->where($qb->expr()->eq('id_prim', $qb->createNamedParameter(md5($note->getId()))));
		$result = $qb->executeQuery();
		$this->nids[$suffix] = (string)$result->fetchOne();
		$result->closeCursor();

		return $note;
	}

	/** @return array<string, string> term => nid, of one post */
	private function terms(string $suffix): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('term', 'nid', 'head')
			->from(CoreRequestBuilder::TABLE_SEARCH_TERMS)
			->where($qb->expr()->eq('stream_id_prim', $qb->createNamedParameter(md5($this->id($suffix)))))
			->orderBy('term', 'asc');
		$result = $qb->executeQuery();
		$terms = [];
		while ($row = $result->fetch()) {
			$this->assertSame(SearchTerms::head((string)$row['term']), (string)$row['head']);
			$terms[(string)$row['term']] = (string)$row['nid'];
		}
		$result->closeCursor();

		return $terms;
	}

	/** @return string[] the suffixes of the given nids */
	private function names(array $nids): array {
		return array_map(fn (string $nid): string => (string)array_search($nid, $this->nids, true), $nids);
	}

	/** @param list<string> $whole */
	private function find(array $whole, string $prefix = '', string $author = '', int $limit = 10, string $max = '0', string $min = '0', string $since = '0'): array {
		return $this->names($this->searchTermsRequest->candidateNids($whole, $prefix, $max, $min, $since, $author, $limit));
	}

	public function testAStoredPostIsIndexedUnderEachOfItsWordsOnce(): void {
		$note = $this->post('zebra-1', '<p>Zebras <b>crossing</b> the road, zebras!</p>', 1);

		$expected = array_fill_keys(SearchTerms::ofHtml($note->getContent()), $this->nids['zebra-1']);
		ksort($expected);
		$this->assertSame($expected, $this->terms('zebra-1'));

		$this->searchTermsRequest->index($note);
		$this->assertSame($expected, $this->terms('zebra-1'), 'indexing again is harmless');
	}

	public function testOnlyStatusesAreIndexed(): void {
		$boost = new Announce();
		$boost->setId($this->id('boost'));
		$boost->setContent('<p>zebras</p>');
		$boost->setNid(1);

		$this->searchTermsRequest->index($boost);
		$this->searchTermsRequest->reindex($boost);

		$this->assertSame([], $this->terms('boost'));
	}

	/** An edit writes the difference: new words in, dropped words out, the rest untouched. */
	public function testAnEditRewritesOnlyTheWordsThatChanged(): void {
		$note = $this->post('edited', '<p>zebras crossing roads</p>', 1);
		$note->setContent('<p>zebras crossing rivers</p>');
		$note->setNid(0);

		$this->searchTermsRequest->reindex($note);

		$this->assertSame(
			['crossing' => $this->nids['edited'], 'rivers' => $this->nids['edited'], 'zebras' => $this->nids['edited']],
			$this->terms('edited')
		);
	}

	/** An Update carries no nid: with no word left to copy it from, it is read off the post. */
	public function testAnEditOfAPostWithNoIndexedWordsTakesTheNidOfThePost(): void {
		$note = $this->post('edited', '<p>zebras</p>', 1);
		RowSeeder::delete(CoreRequestBuilder::TABLE_SEARCH_TERMS, ['stream_id_prim' => md5($this->id('edited'))]);
		$note->setContent('<p>giraffes</p>');
		$note->setNid(0);

		$this->searchTermsRequest->reindex($note);

		$this->assertSame(['giraffes' => $this->nids['edited']], $this->terms('edited'));
	}

	public function testEveryWholeWordIsRequiredAndTheNewestComeFirst(): void {
		$this->post('zebra-1', '<p>zebras crossing</p>', 1);
		$this->post('zebra-2', '<p>zebras sleeping</p>', 2);
		$this->post('zebra-3', '<p>zebras crossing again</p>', 3, self::BOB);
		$this->post('other', '<p>crossing alone</p>', 4);

		$this->assertSame(['zebra-3', 'zebra-1'], $this->find(['crossing', 'zebras']));
		$this->assertSame(['zebra-3'], $this->find(['crossing', 'zebras'], '', '', 1));
		$this->assertSame(['zebra-1'], $this->find(['crossing', 'zebras'], '', md5(self::ALICE)), 'by one author');
		$this->assertSame(['zebra-1'], $this->find(['zebras'], '', '', 10, $this->nids['zebra-2'], (string)((int)$this->nids['zebra-1'] - 1)));
		$this->assertSame([], $this->find([]));
		$this->assertSame([], $this->find(['zebras'], '', '', 0));
	}

	/**
	 * A prefix (never shorter than its head, SearchTerms::ofQuery() sees to
	 * that) is read through its head and sifted with LIKE, bounded below by
	 * `$sinceNid`.
	 */
	public function testAPrefixFindsTheWordsItBegins(): void {
		$this->post('zebra-1', '<p>zebras</p>', 1);
		$this->post('zebra-2', '<p>zebu</p>', 2);
		$this->post('other', '<p>zeal</p>', 3);

		$this->assertSame(['zebra-2', 'zebra-1'], $this->find([], 'zeb'));
		$this->assertSame(['zebra-1'], $this->find([], 'zebr'));
		$this->assertSame(['other'], $this->find([], 'zea'));
		$this->assertSame(['zebra-2'], $this->find([], 'zeb', '', 10, '0', '0', $this->nids['zebra-1']));
		$this->assertSame([], $this->find([], 'z_b'), 'the prefix is matched literally');
	}

	public function testADeletedPostIsNoLongerFound(): void {
		$this->post('zebra-1', '<p>zebras</p>', 1);
		$this->streamRequest->deleteById($this->id('zebra-1'));

		$this->assertSame([], $this->find(['zebras']));
		$this->assertSame([], $this->terms('zebra-1'), 'its words go with it');
	}

	public function testTheBackfillIndexesStoredPostsOldestFirstAndSaysWhereItStopped(): void {
		$this->post('zebra-1', '<p>zebras one</p>', 1);
		$this->post('zebra-2', '<p>zebras two</p>', 2);
		$this->post('zebra-3', '<p>zebras three</p>', 3);
		foreach (['zebra-1', 'zebra-2', 'zebra-3'] as $suffix) {
			RowSeeder::delete(CoreRequestBuilder::TABLE_SEARCH_TERMS, ['stream_id_prim' => md5($this->id($suffix))]);
		}
		$before = (string)((int)$this->nids['zebra-1'] - 1);

		$this->assertSame(['last' => $this->nids['zebra-2'], 'read' => 2], $this->searchTermsRequest->backfill($before, 2));
		$this->assertSame(['one' => $this->nids['zebra-1'], 'zebras' => $this->nids['zebra-1']], $this->terms('zebra-1'));
		$this->assertSame([], $this->terms('zebra-3'), 'not reached yet');

		$this->assertSame(['last' => $this->nids['zebra-3'], 'read' => 1], $this->searchTermsRequest->backfill($this->nids['zebra-2'], 2));
		$this->assertSame(['three' => $this->nids['zebra-3'], 'zebras' => $this->nids['zebra-3']], $this->terms('zebra-3'));

		$this->assertSame(['last' => $this->nids['zebra-3'], 'read' => 0], $this->searchTermsRequest->backfill($this->nids['zebra-3'], 2));
		$this->searchTermsRequest->backfill($before, 10);
		$this->assertSame(['one' => $this->nids['zebra-1'], 'zebras' => $this->nids['zebra-1']], $this->terms('zebra-1'), 'running it again is harmless');
	}

	/**
	 * What the table-growth setup check reads: posts counted no further than
	 * a cap, and the word index's size from its highest id, neither of them
	 * a count of a whole large table.
	 */
	public function testTheSizesTheGrowthCheckReadsAreBounded(): void {
		$this->post('zebra-1', '<p>zebras crossing</p>', 1);
		$this->post('zebra-2', '<p>zebras resting</p>', 2);
		$this->post('zebra-3', '<p>zebras grazing</p>', 3);

		$this->assertSame(2, $this->streamRequest->countPostsUpTo(2), 'counting stops at the cap');
		$this->assertGreaterThanOrEqual(3, $this->streamRequest->countPostsUpTo(1000000000));
		$this->assertGreaterThanOrEqual(count($this->terms('zebra-3')), $this->searchTermsRequest->approximateCount());
	}

	public function testTheIndexIsReadyOnlyOnceTheBackfillSaidSo(): void {
		$config = Server::get(ConfigService::class);

		$config->setAppValue(SearchTermsRequest::READY, '0');
		$this->assertFalse($this->searchTermsRequest->isReady());

		$config->setAppValue(SearchTermsRequest::READY, '1');
		$this->assertTrue($this->searchTermsRequest->isReady());
	}
}
