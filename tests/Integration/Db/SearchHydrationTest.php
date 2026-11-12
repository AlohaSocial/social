<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\SearchTermsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\ConfigService;
use OCA\Social\Tests\Integration\QueryCounter;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A content search decides which posts the viewer may see over their nids,
 * and reads the posts afterwards — through the word index and through the
 * scan that stands in for it until the index is complete.
 *
 * Asked as one query, the viewer's recipient join paired each candidate with
 * every account the viewer follows, and the `DISTINCT` it needs then sorted
 * that product with every column of the post attached: a page of twenty was
 * 30,000 wide rows sorted on disk for an account following 300 others, and
 * 0.6 s on PostgreSQL whatever the size of the instance (`occ
 * social:debug:benchmark`, 20,000 notes). What is pinned here is the shape that
 * keeps the follows out of the read of the posts, and that the visibility
 * the one query enforced still holds.
 */
class SearchHydrationTest extends TestCase {
	private const BASE = 'https://cloud.example.org/searchhydration';
	private const VIEWER = self::BASE . '/users/viewer';
	private const FOLLOWED = 'https://followed.example/searchhydration/users/followed';
	private const STRANGER = 'https://stranger.example/searchhydration/users/stranger';
	private const OTHERS = 12;

	/**
	 * A join on the follows table. A correlated `EXISTS` on it, as the
	 * visibility of a boosted post is checked, reads one row per post and is
	 * not what this is about.
	 */
	private const JOINS_FOLLOWS = '/JOIN\s+["`]?\w*social_follow["`]?\s/i';

	/**
	 * The viewer's follows as `limitToViewer()` reads them, under the alias
	 * `f`: probed in an `EXISTS` beside the public and direct branches.
	 */
	private const READS_VIEWER_FOLLOWS = '/(JOIN|FROM)\s+["`]?\w*social_follow["`]?\s+["`]?f["`]?\s/i';

	/** @var string[] */
	private array $notes = [];

	private string $readyBefore = '';

	protected function setUp(): void {
		parent::setUp();
		$this->readyBefore = (string)Server::get(ConfigService::class)->getAppValue(SearchTermsRequest::READY);
		$this->cleanup();

		$cache = Server::get(CacheActorsRequest::class);
		foreach ([self::FOLLOWED, self::STRANGER] as $id) {
			$cache->save($this->person($id));
		}

		// the viewer follows a dozen accounts besides the one that posts:
		// enough that a join on the follows multiplies what it reads
		for ($i = 0; $i < self::OTHERS; $i++) {
			$this->follow(self::BASE . '/followed' . $i);
		}
		$this->follow(self::FOLLOWED);
	}

	protected function tearDown(): void {
		$this->cleanup();
		Server::get(ConfigService::class)->setAppValue(SearchTermsRequest::READY, $this->readyBefore);
		parent::tearDown();
	}

	private function cleanup(): void {
		$streams = Server::get(StreamRequest::class);
		foreach ($this->notes as $id) {
			$streams->deleteById($id, Note::TYPE);
		}
		$this->notes = [];

		$follows = Server::get(FollowsRequest::class);
		$follows->deleteById(self::BASE . '/follows/' . md5(self::FOLLOWED));
		for ($i = 0; $i < self::OTHERS; $i++) {
			$follows->deleteById(self::BASE . '/follows/' . md5(self::BASE . '/followed' . $i));
		}

		$cache = Server::get(CacheActorsRequest::class);
		foreach ([self::FOLLOWED, self::STRANGER] as $id) {
			$cache->deleteCacheById($id);
		}
	}

	private function person(string $id): Person {
		$person = new Person();
		$person->setId($id);
		$person->setPreferredUsername(basename($id));
		$person->setAccount(basename($id) . '@' . parse_url($id, PHP_URL_HOST));
		$person->setFollowers($id . '/followers');

		return $person;
	}

	private function follow(string $objectId): void {
		$follow = new Follow();
		$follow->setId(self::BASE . '/follows/' . md5($objectId));
		$follow->setActorId(self::VIEWER);
		$follow->setObjectId($objectId);
		$follow->setFollowId($objectId . '/followers');
		$follow->setAccepted(true);
		Server::get(FollowsRequest::class)->save($follow);
	}

	/** @param string[] $to */
	private function note(string $name, string $author, array $to, int $published): void {
		$note = new Note();
		$note->setId(self::BASE . '/notes/' . $name);
		$note->setAttributedTo($author);
		$note->setTo(array_shift($to) ?? '');
		$note->setToArray($to);
		$note->setContent('<p>a quokka sighting, ' . $name . '</p>');
		$note->setPublishedTime($published);
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z', $published));
		Server::get(StreamRequest::class)->save($note);
		$this->notes[] = $note->getId();
	}

	/** @return array<string, array{string}> */
	public static function paths(): array {
		return [
			'through the word index' => ['1'],
			'through the scan, before the index is complete' => ['0'],
		];
	}

	#[DataProvider('paths')]
	public function testASearchFindsWhatTheViewerMaySeeAndNothingElse(string $ready): void {
		Server::get(ConfigService::class)->setAppValue(SearchTermsRequest::READY, $ready);
		$now = time();
		$this->note('public', self::STRANGER, [ACore::CONTEXT_PUBLIC], $now - 40);
		$this->note('followers-of-followed', self::FOLLOWED, [self::FOLLOWED . '/followers'], $now - 30);
		$this->note('followers-of-stranger', self::STRANGER, [self::STRANGER . '/followers'], $now - 20);
		$this->note('to-the-viewer', self::STRANGER, [self::VIEWER], $now - 10);

		$streams = Server::get(StreamRequest::class);
		$viewer = $this->person(self::VIEWER);
		$viewer->setLocal(true);
		$streams->setViewer($viewer);
		$run = QueryCounter::record(static fn (): array => $streams->searchContent('quokka', 20));

		$this->assertSame(
			[self::BASE . '/notes/to-the-viewer', self::BASE . '/notes/followers-of-followed', self::BASE . '/notes/public'],
			array_map(static fn (Stream $post): string => $post->getId(), $run->result()),
			'newest first: what was addressed to the viewer, the followers-only post of an account they '
			. 'follow and the public one — not the followers-only post of a stranger'
		);

		$follows = $run->matching(self::READS_VIEWER_FOLLOWS);
		$this->assertNotEmpty($follows, 'the visibility is still decided against the follows: ' . $run->summary());
		foreach ($follows as $query) {
			$this->assertSame(
				['nid'], array_values(array_diff(self::selected($query['sql']), ['published_time'])),
				'a statement reading the follows selects the nid, and nothing wider: ' . $query['sql']
			);
		}

		$reads = array_filter($run->queries(), static fn (array $query): bool => in_array('content', self::selected($query['sql']), true));
		$this->assertNotEmpty($reads, 'the posts are read: ' . $run->summary());
		foreach ($reads as $query) {
			$this->assertDoesNotMatchRegularExpression(self::JOINS_FOLLOWS, $query['sql'], 'and the read of the posts does not join the follows');
		}
	}

	/**
	 * The columns of `s` a SELECT projects, unquoted.
	 *
	 * @return list<string>
	 */
	private static function selected(string $sql): array {
		if (preg_match('/^\s*SELECT\s+(?:DISTINCT\s+)?(.*?)\sFROM\s/is', $sql, $match) !== 1) {
			return [];
		}
		preg_match_all('/["`]?\bs["`]?\.["`]?(\w+)["`]?/', $match[1], $columns);

		return array_values(array_unique($columns[1]));
	}
}
