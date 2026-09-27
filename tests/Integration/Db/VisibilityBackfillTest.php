<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Migration\BackfillRemoteVisibility;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\ConfigService;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The one-shot repair that classifies the visibility of remote statuses
 * stored before estimation landed, against the real database: the set-based
 * public/unlisted updates, the per-author followers/direct classification,
 * and the guarantees that local rows and already-classified rows stay
 * untouched (which also makes re-runs no-ops).
 */
class VisibilityBackfillTest extends TestCase {
	private const BASE = 'https://cloud.example.org/visbf';
	private const AUTHOR = 'https://remote.example/visbf/users/author';
	private const AUTHOR_FOLLOWERS = self::AUTHOR . '/followers';
	private const GHOST = 'https://gone.example/visbf/users/ghost';

	private const SUFFIXES = ['public-to', 'public-toarray', 'unlisted', 'followers', 'direct', 'ghost', 'classified', 'local'];

	private StreamRequest $streamRequest;
	private CacheActorsRequest $cacheActorsRequest;
	private BackfillRemoteVisibility $repair;

	protected function setUp(): void {
		parent::setUp();
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->cacheActorsRequest = Server::get(CacheActorsRequest::class);
		$this->repair = Server::get(BackfillRemoteVisibility::class);
		// the step is one-shot and its marker is set on any instance that has
		// upgraded, so clearing it is what makes these tests of the backfill
		// rather than of the marker
		Server::get(ConfigService::class)->setAppValue('migration_remote_visibility_backfilled', '0');
		$this->cleanup();

		$author = new Person();
		$author->setId(self::AUTHOR)
			->setPreferredUsername('author');
		$author->setAccount('author@remote.example')
			->setFollowers(self::AUTHOR_FOLLOWERS);
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

	private function note(
		string $suffix, string $to, array $cc = [], string $visibility = '',
		bool $local = false, string $author = self::AUTHOR, array $toArray = [],
	): Note {
		$note = new Note();
		$note->setId(self::BASE . '/notes/' . $suffix);
		$note->setAttributedTo($author);
		$note->setTo($to);
		$note->setToArray($toArray);
		$note->setCcArray($cc);
		$note->setVisibility($visibility);
		$note->setLocal($local);
		$note->setContent($suffix);
		$note->setPublishedTime(time());
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z'));

		$this->streamRequest->save($note);

		return $note;
	}

	private function visibilityOf(string $suffix): string {
		// read the column raw: getStreamById() inner-joins the author's cache
		// row, which the ghost-author case deliberately does not have
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select('visibility')->from('social_stream')
			->where($qb->expr()->eq('id_prim', $qb->createNamedParameter(md5(self::BASE . '/notes/' . $suffix))));
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();
		$this->assertNotFalse($row, 'stream row ' . $suffix . ' exists');

		return (string)$row['visibility'];
	}

	public function testBackfillClassifiesEveryAddressingShapeOnce(): void {
		$this->note('public-to', ACore::CONTEXT_PUBLIC);
		$this->note('public-toarray', self::BASE . '/users/someone', [], '', false, self::AUTHOR, [ACore::CONTEXT_PUBLIC]);
		$this->note('unlisted', self::AUTHOR_FOLLOWERS, [ACore::CONTEXT_PUBLIC]);
		$this->note('followers', self::AUTHOR_FOLLOWERS);
		$this->note('direct', self::BASE . '/users/someone');
		$this->note('ghost', self::GHOST . '/followers', [], '', false, self::GHOST);
		$this->note('classified', ACore::CONTEXT_PUBLIC, [], 'unlisted');
		$this->note('local', self::BASE . '/users/someone', [], '', true);

		$output = $this->createStub(IOutput::class);
		$this->repair->run($output);

		$this->assertSame('public', $this->visibilityOf('public-to'));
		$this->assertSame('public', $this->visibilityOf('public-toarray'), 'as:Public among several to recipients');
		$this->assertSame('unlisted', $this->visibilityOf('unlisted'));
		$this->assertSame('followers', $this->visibilityOf('followers'));
		$this->assertSame('direct', $this->visibilityOf('direct'));
		$this->assertSame('direct', $this->visibilityOf('ghost'), 'an uncached author degrades to direct');
		$this->assertSame('unlisted', $this->visibilityOf('classified'), 'already-classified rows stay untouched');
		$this->assertSame('', $this->visibilityOf('local'), 'local rows are never touched');

		// idempotent: a second run changes nothing and reports nothing
		$second = $this->createMock(IOutput::class);
		$second->expects($this->never())->method('info');
		$this->repair->run($second);
		$this->assertSame('followers', $this->visibilityOf('followers'));
	}
}
