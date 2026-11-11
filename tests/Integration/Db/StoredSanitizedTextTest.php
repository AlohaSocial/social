<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Security\HtmlSanitizer;
use OCA\Social\Service\SanitizerUpgrade;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Post bodies and cached bios are stored sanitized and stamped, so their
 * reads skip the sanitizer; rows from before the stamp read as they always
 * did and are brought up to it by the walk.
 */
class StoredSanitizedTextTest extends TestCase {
	private const BASE = 'https://remote.example/sanitized';
	private const AUTHOR = self::BASE . '/users/author';
	private const NOTE = self::BASE . '/notes/1';
	private const MARKED = '<p onclick="x()">hello <a href="/relative">there</a></p>';

	private StreamRequest $streams;
	private CacheActorsRequest $actors;
	private IDBConnection $db;

	protected function setUp(): void {
		parent::setUp();
		$this->streams = Server::get(StreamRequest::class);
		$this->actors = Server::get(CacheActorsRequest::class);
		$this->db = Server::get(IDBConnection::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		$this->streams->deleteById(self::NOTE, Note::TYPE);
		$this->actors->deleteCacheById(self::AUTHOR);
	}

	/** What every read hands out: the sanitizer's output, onclick gone and the link resolved. */
	private function clean(string $base): string {
		$clean = HtmlSanitizer::sanitize(self::MARKED, $base);
		$this->assertStringNotContainsString('onclick', $clean);
		$this->assertStringContainsString('href="https://remote.example/relative"', $clean);

		return $clean;
	}

	private function saveNote(): void {
		$author = new Person();
		$author->setId(self::AUTHOR)->setPreferredUsername('author');
		$author->setAccount('author@remote.example')->setSummary(self::MARKED);
		$this->actors->save($author);

		$note = new Note();
		$note->setId(self::NOTE);
		$note->setAttributedTo(self::AUTHOR);
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$note->setVisibility('public');
		$note->setContent(self::MARKED);
		$note->setPublishedTime(time());
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
		$this->streams->save($note);
	}

	/** @return array<string, mixed> */
	private function row(string $table, string $prim): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($table)->where($qb->expr()->eq('id_prim', $qb->createNamedParameter($prim)));
		$row = $qb->executeQuery()->fetch();
		$this->assertIsArray($row);

		return $row;
	}

	public function testTextIsStoredSanitizedAndStampedAndReadAsStored(): void {
		$this->saveNote();

		$stream = $this->row(CoreRequestBuilder::TABLE_STREAM, md5(self::NOTE));
		$this->assertSame($this->clean(self::NOTE), $stream['content']);
		$this->assertSame(HtmlSanitizer::version(), (int)$stream['sanitized']);
		$actor = $this->row(CoreRequestBuilder::TABLE_CACHE_ACTORS, md5(self::AUTHOR));
		$this->assertSame(self::MARKED, $actor['summary'], 'the bio as it arrived is kept');
		$this->assertSame($this->clean(self::AUTHOR), $actor['description']);
		$this->assertSame(HtmlSanitizer::version(), (int)$actor['sanitized']);

		$this->assertSame($this->clean(self::NOTE), $this->streams->getStreamById(self::NOTE)->getContent());
		$this->assertSame($this->clean(self::AUTHOR), $this->actors->getFromId(self::AUTHOR)->getDescription());
	}

	/**
	 * A row from before the stamp reads sanitized, as before, and the walk
	 * rewrites it to the stored form.
	 */
	public function testARowFromBeforeTheStampIsSanitizedOnReadAndUpgradedByTheWalk(): void {
		$this->saveNote();
		foreach ([CoreRequestBuilder::TABLE_STREAM => ['content', md5(self::NOTE)], CoreRequestBuilder::TABLE_CACHE_ACTORS => ['description', md5(self::AUTHOR)]] as $table => [$column, $prim]) {
			$qb = $this->db->getQueryBuilder();
			$qb->update($table)
				->set($column, $qb->createNamedParameter($column === 'content' ? self::MARKED : null))
				->set('sanitized', $qb->createNamedParameter(0))
				->where($qb->expr()->eq('id_prim', $qb->createNamedParameter($prim)));
			$qb->executeStatement();
		}

		$this->assertSame($this->clean(self::NOTE), $this->streams->getStreamById(self::NOTE)->getContent());
		$this->assertSame($this->clean(self::AUTHOR), $this->actors->getFromId(self::AUTHOR)->getDescription());

		$config = Server::get(IAppConfig::class);
		foreach (SanitizerUpgrade::CURSORS as $key) {
			$config->deleteKey('social', $key);
		}
		$upgrade = Server::get(SanitizerUpgrade::class);
		do {
			$more = $upgrade->run(50);
		} while ($more > 0);

		$this->assertSame($this->clean(self::NOTE), $this->row(CoreRequestBuilder::TABLE_STREAM, md5(self::NOTE))['content']);
		$actor = $this->row(CoreRequestBuilder::TABLE_CACHE_ACTORS, md5(self::AUTHOR));
		$this->assertSame($this->clean(self::AUTHOR), $actor['description']);
		$this->assertSame(HtmlSanitizer::version(), (int)$actor['sanitized']);
		$this->assertNull($upgrade->page(CoreRequestBuilder::TABLE_STREAM), 'the walk is finished');
	}
}
