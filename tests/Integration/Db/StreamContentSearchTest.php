<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\StreamDestRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Full-text search against the real database, through the word index or the
 * scan it replaces: the match is case-insensitive, and — the part that
 * matters — the viewer bound holds:
 * public posts and the viewer's own DMs are found, somebody else's DMs never.
 */
class StreamContentSearchTest extends TestCase {
	private const BASE = 'https://cloud.example.org/ftsearch';
	private const VIEWER = self::BASE . '/users/viewer';
	private const OTHER = self::BASE . '/users/other';
	private const AUTHOR = 'https://remote.example/ftsearch/users/author';

	private const SUFFIXES = ['public', 'own-dm', 'foreign-dm', 'unrelated'];

	private StreamRequest $streamRequest;
	private StreamDestRequest $streamDestRequest;
	private CacheActorsRequest $cacheActorsRequest;

	protected function setUp(): void {
		parent::setUp();
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->streamDestRequest = Server::get(StreamDestRequest::class);
		$this->cacheActorsRequest = Server::get(CacheActorsRequest::class);
		$this->cleanup();

		$author = new Person();
		$author->setId(self::AUTHOR)
			->setPreferredUsername('ftauthor');
		$author->setAccount('ftauthor@remote.example');
		$this->cacheActorsRequest->save($author);
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach (self::SUFFIXES as $suffix) {
			$this->streamRequest->deleteById(self::BASE . '/notes/' . $suffix, Note::TYPE);
		}
		$this->cacheActorsRequest->deleteCacheById(self::AUTHOR);
	}

	private function note(string $suffix, string $content, string $to): Note {
		$note = new Note();
		$note->setId(self::BASE . '/notes/' . $suffix);
		$note->setAttributedTo(self::AUTHOR);
		$note->setTo($to);
		$note->setContent($content);
		$note->setVisibility($to === ACore::CONTEXT_PUBLIC ? 'public' : 'direct');
		$note->setPublishedTime(time());
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
		$this->streamRequest->save($note);
		$this->streamDestRequest->generateStreamDest($note);

		return $note;
	}

	private function viewer(): Person {
		$viewer = new Person();
		$viewer->setId(self::VIEWER);
		$viewer->setLocal(true);

		return $viewer;
	}

	private function found(string $term, string $authorId = ''): array {
		$this->streamRequest->setViewer($this->viewer());
		$ids = array_map(
			static fn ($stream): string => $stream->getId(),
			$this->streamRequest->searchContent($term, 20, 0, $authorId)
		);
		sort($ids);

		return $ids;
	}

	public function testSearchIsViewerBoundAndCaseInsensitive(): void {
		$this->note('public', '<p>the Zebra crossed the road</p>', ACore::CONTEXT_PUBLIC);
		$this->note('own-dm', '<p>secret zebra plans for you</p>', self::VIEWER);
		$this->note('foreign-dm', '<p>zebra gossip not for the viewer</p>', self::OTHER);
		$this->note('unrelated', '<p>nothing to see</p>', ACore::CONTEXT_PUBLIC);

		$this->assertSame([
			self::BASE . '/notes/own-dm',
			self::BASE . '/notes/public',
		], $this->found('zeBRA'), 'public + own DM, never a foreign DM');
	}

	public function testANarrowedSearchFindsOnlyThatAccountsPostsTheViewerMaySee(): void {
		$this->note('public', '<p>the Zebra crossed the road</p>', ACore::CONTEXT_PUBLIC);
		$this->note('foreign-dm', '<p>zebra gossip not for the viewer</p>', self::OTHER);

		$this->assertSame([self::BASE . '/notes/public'], $this->found('zebra', self::AUTHOR));
		$this->assertSame([], $this->found('zebra', self::OTHER), 'nothing of somebody who wrote none of it');
	}

	public function testATooShortTermReturnsNothing(): void {
		$this->note('public', '<p>a match</p>', ACore::CONTEXT_PUBLIC);

		$this->assertSame([], $this->found('a'));
	}
}
