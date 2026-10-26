<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Service\RecommendationSnapshotStore;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class RecommendationSnapshotStoreTest extends TestCase {
	private const SECRET = '0123456789abcdef0123456789abcdef';
	private const NOW = 2_000_000_000;

	public function testPagesUseTheSameSnapshotAndRejectOtherViewerOrChangedProfile(): void {
		$values = [];
		$cache = $this->createMock(ICache::class);
		$cache->expects($this->once())->method('set')->willReturnCallback(static function (string $key, mixed $value, int $ttl) use (&$values): bool {
			$values[$key] = $value;
			return $ttl === 3600;
		});
		$cache->expects($this->exactly(2))->method('get')->willReturnCallback(static function (string $key) use (&$values): mixed {
			return $values[$key] ?? null;
		});
		$store = $this->store($cache);
		$reasons = [
			['nid' => '3', 'reason' => [
				'primary' => 'TOPIC_EXPLICIT', 'topic_ids' => ['tech.apple'], 'also' => ['CACHED_CANDIDATE'],
				'candidate_source' => 'LOCAL_CACHED_POSTS', 'protocol' => 'activitypub', 'personalisation_used' => true,
				'selection_stage' => 'RERANK', 'rerank_note' => 'BASE_RANK', 'profile_version' => 4,
				'eligibility_policy_version' => 1, 'explanation_version' => 1, 'algorithm_version' => 5, 'created_at' => self::NOW,
			]],
		];
		$first = $store->create('alice', 'for-you', 'all', 4, 2, self::NOW, ['1', '2', '3', '4', '5'], 2, ['4'], $reasons);

		$this->assertNotNull($first);
		$this->assertSame(['1', '2'], $first['nids']);
		$this->assertSame(['4'], $first['exploration_nids']);
		$this->assertSame([], $first['explanations']);
		$this->assertNotNull($first['next_cursor']);
		$this->assertNull($store->nextPage($first['next_cursor'], 'bob', 'for-you', 'all', 4, 2, self::NOW + 1, 2));
		$this->assertNull($store->nextPage($first['next_cursor'], 'alice', 'for-you', 'all', 5, 2, self::NOW + 1, 2));

		$second = $store->nextPage($first['next_cursor'], 'alice', 'for-you', 'all', 4, 2, self::NOW + 1, 2);
		$this->assertNotNull($second);
		$this->assertSame($first['session_id'], $second['session_id']);
		$this->assertSame(['3', '4'], $second['nids']);
		$this->assertSame(['4'], $second['exploration_nids']);
		$this->assertSame($reasons, $second['explanations']);
		$third = $store->nextPage($second['next_cursor'], 'alice', 'for-you', 'all', 4, 2, self::NOW + 2, 2);
		$this->assertNotNull($third);
		$this->assertSame(['5'], $third['nids']);
		$this->assertNull($third['next_cursor']);
	}

	public function testMissingSnapshotAndInvalidCursorFailClosed(): void {
		$cache = $this->createMock(ICache::class);
		$cache->expects($this->once())->method('set')->willReturn(true);
		$cache->expects($this->once())->method('get')->willReturn(null);
		$store = $this->store($cache);
		$first = $store->create('alice', 'for-you', 'all', 0, 0, self::NOW, ['1', '2'], 1);

		$this->assertNotNull($first);
		$this->assertNull($store->nextPage($first['next_cursor'], 'alice', 'for-you', 'all', 0, 0, self::NOW + 1, 1));
		$this->assertNull($store->nextPage('bad', 'alice', 'for-you', 'all', 0, 0, self::NOW + 1, 1));
	}

	private function store(ICache $cache): RecommendationSnapshotStore {
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->with('social.recommendation.snapshots')->willReturn($cache);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->with('secret', '')->willReturn(self::SECRET);

		return new RecommendationSnapshotStore($cacheFactory, $config);
	}
}
