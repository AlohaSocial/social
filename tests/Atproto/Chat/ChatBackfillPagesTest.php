<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Chat;

use OCA\Social\Atproto\Chat\ChatBackfill;
use OCA\Social\Atproto\Chat\ChatBackfillPages;
use OCA\Social\Service\DurableCache;
use OCA\Social\Tests\Helper\InMemoryDurableCacheRequest;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ChatBackfillPagesTest extends TestCase {
	private InMemoryDurableCacheRequest $rows;
	private DurableCache $cache;
	private ChatBackfillPages $pages;

	protected function setUp(): void {
		$this->rows = new InMemoryDurableCacheRequest();
		$factory = $this->createStub(ICacheFactory::class);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1790000000);
		$this->cache = new DurableCache($factory, $this->rows, $time);
		$this->pages = new ChatBackfillPages($this->cache);
	}

	/** @return list<array> */
	private function largePage(): array {
		return array_map(static fn (int $id): array => [
			'id' => (string)$id, 'text' => str_repeat('😀/', 1500),
			'sender' => ['did' => 'did:plc:ewvi7nxzyoun6zhxrhs64oiz'],
		], range(1, 100));
	}

	public function testLargeUnicodePagesSurviveRestartWithoutOversizedCacheRows(): void {
		$messages = $this->largePage();
		$this->pages->save('page', $messages);

		$this->assertGreaterThan(2, count($this->rows->rows));
		foreach ($this->rows->rows as $row) {
			$this->assertLessThanOrEqual(65535, strlen($row['value']));
		}
		$this->assertSame($messages, (new ChatBackfillPages($this->cache))->read('page'));
		$this->pages->remove('page');
		$this->assertSame([], $this->rows->rows);
	}

	public function testOldInlinePagesRemainReadable(): void {
		$messages = [['id' => 'old', 'text' => 'Already staged']];
		$this->cache->setShared(ChatBackfill::CACHE, 'page', $messages, ChatBackfill::TTL);

		$this->assertSame($messages, $this->pages->read('page'));
		$this->pages->save('page', [['id' => 'new']]);
		$this->assertSame([['id' => 'new']], $this->pages->read('page'));
	}

	public function testMissingChunkCannotBeImportedButCanBeRetriedAfterRecovery(): void {
		$messages = $this->largePage();
		$this->pages->save('page', $messages);
		$chunk = $this->cache->getShared(ChatBackfill::CACHE, 'page:chunk:1');
		$this->cache->removeShared(ChatBackfill::CACHE, 'page:chunk:1');
		try {
			$this->pages->read('page');
			$this->fail('An incomplete page must never be imported');
		} catch (RuntimeException) {
			$this->assertNotNull($this->cache->getShared(ChatBackfill::CACHE, 'page'));
		}
		$this->cache->setShared(ChatBackfill::CACHE, 'page:chunk:1', $chunk, ChatBackfill::TTL);
		$this->assertSame($messages, $this->pages->read('page'));
	}

	public function testChangedChunkFailsTheIntegrityCheck(): void {
		$this->pages->save('page', $this->largePage());
		$this->cache->setShared(ChatBackfill::CACHE, 'page:chunk:0', base64_encode('changed'), ChatBackfill::TTL);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('integrity check');

		$this->pages->read('page');
	}

	public function testReplacingAChunkedPageRemovesItsOldChunks(): void {
		$this->pages->save('page', $this->largePage());
		$this->pages->save('page', [['id' => 'replacement']]);

		$this->assertCount(1, $this->rows->rows);
		$this->assertSame([['id' => 'replacement']], $this->pages->read('page'));
	}
}
