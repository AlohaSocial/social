<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Tools\Model;

use OCA\Social\Tools\Exceptions\CacheItemNotFoundException;
use OCA\Social\Tools\Model\Cache;
use OCA\Social\Tools\Model\CacheItem;
use PHPUnit\Framework\TestCase;

class CacheTest extends TestCase {
	public function testGetItemThrowsForUnknownUrls(): void {
		$this->expectException(CacheItemNotFoundException::class);

		(new Cache())->getItem('https://a.example/missing');
	}

	public function testRemoveItemDropsOnlyThatUrl(): void {
		$cache = new Cache();
		$cache->addItem(new CacheItem('https://a.example/1'))
			->addItem(new CacheItem('https://a.example/2'));

		$cache->removeItem('https://a.example/1');
		$cache->removeItem('https://a.example/unknown');

		$this->assertFalse($cache->hasItem('https://a.example/1'));
		$this->assertTrue($cache->hasItem('https://a.example/2'));
		$this->assertCount(1, $cache->getItems());
	}

	public function testUpdateItemReplacesTheEntryWithTheSameUrl(): void {
		$cache = new Cache();
		$stale = (new CacheItem('https://a.example/1'))->setStatus(0);
		$other = new CacheItem('https://a.example/2');
		$cache->addItem($stale)->addItem($other);
		$fresh = (new CacheItem('https://a.example/1'))->setStatus(200)->setContent('{"type":"Note"}');

		$cache->updateItem($fresh);
		$cache->updateItem(new CacheItem(''));

		$this->assertSame($fresh, $cache->getItem('https://a.example/1'));
		$this->assertSame([$fresh, $other], $cache->getItems(), 'order and the other entry are kept');
	}

	public function testUpdateItemHonoursTheCreateFlagForUnknownUrls(): void {
		$cache = new Cache();
		$item = new CacheItem('https://a.example/1');

		$cache->updateItem($item, false);
		$this->assertFalse($cache->hasItem('https://a.example/1'), 'create=false must not add');

		$cache->updateItem($item);
		$this->assertTrue($cache->hasItem('https://a.example/1'), 'create=true (default) must add');
	}

	public function testJsonSerializeListsTheUrlsAndEmbedsEachItem(): void {
		$cache = new Cache();
		$item = (new CacheItem('https://a.example/1'))->setContent('{"type":"Note"}')->setStatus(200)->setCreation(1714564800);
		$cache->addItem($item);

		$json = $cache->jsonSerialize();

		$this->assertSame(['https://a.example/1'], $json['_items']);
		$this->assertSame(1, $json['_count']);
		$this->assertSame($item, $json['https://a.example/1']);
	}

	public function testImportRebuildsTheCacheFromItsSerializedForm(): void {
		$original = new Cache();
		$first = (new CacheItem('https://a.example/1'))->setContent('{"type":"Note"}')->setStatus(200)->setCreation(1714564800);
		$first->setError(1);
		$original->addItem($first);
		$original->addItem((new CacheItem('https://a.example/2'))->setStatus(404));

		$restored = new Cache();
		$restored->import(json_decode(json_encode($original), true));

		$this->assertCount(2, $restored->getItems());
		$first = $restored->getItem('https://a.example/1');
		$this->assertSame('{"type":"Note"}', $first->getContent());
		$this->assertSame(['type' => 'Note'], $first->getObject());
		$this->assertSame(200, $first->getStatus());
		$this->assertSame(1, $first->getError());
		$this->assertSame(1714564800, $first->getCreation());
		$this->assertSame(404, $restored->getItem('https://a.example/2')->getStatus());
	}

	public function testImportSkipsListedUrlsWithoutAnEntry(): void {
		$cache = new Cache();

		$cache->import(['_items' => ['https://a.example/1', 'https://a.example/2'], 'https://a.example/2' => ['url' => 'https://a.example/2']]);

		$this->assertFalse($cache->hasItem('https://a.example/1'));
		$this->assertTrue($cache->hasItem('https://a.example/2'));
	}

	public function testANewCacheIsEmpty(): void {
		$cache = new Cache();

		$this->assertSame([], $cache->getItems());
		$this->assertFalse($cache->hasItem('https://a.example/1'));
	}

	public function testAddItemSkipsEmptyUrlsAndDuplicates(): void {
		$cache = new Cache();
		$first = new CacheItem('https://a.example/1');

		$cache->addItem(new CacheItem(''))
			->addItem($first)
			->addItem(new CacheItem('https://a.example/1'))
			->addItem(new CacheItem('https://a.example/2'));

		$this->assertCount(2, $cache->getItems());
		$this->assertSame($first, $cache->getItem('https://a.example/1'));
		$this->assertTrue($cache->hasItem('https://a.example/2'));
	}
}
