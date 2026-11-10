<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Service;

use OCA\Social\Db\DurableCacheRequest;
use OCA\Social\Service\DurableCache;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The inbox's buffers (`DurableCache::getBuffer()` and its siblings) on the
 * real server, written by one `DurableCache` and read and removed by another,
 * as two PHP workers do.
 *
 * Whatever `memcache.distributed` names, the server may hand out a cache that
 * lives in one object: its in-process array cache, which is what it gives a
 * PHPUnit run and an instance that is not installed yet. A buffer kept there
 * is invisible to every other `DurableCache`, so it has to go to the table.
 */
class DurableCacheBufferTest extends TestCase {
	private const NAMESPACE = 'social.integration-test-buffer';
	private const KEY = 'early';

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->worker()->removeBuffer(self::NAMESPACE, self::KEY);
	}

	#[\Override]
	protected function tearDown(): void {
		$this->worker()->removeBuffer(self::NAMESPACE, self::KEY);
		parent::tearDown();
	}

	/** A `DurableCache` of its own, as another PHP worker builds it. */
	private function worker(): DurableCache {
		return new DurableCache(
			Server::get(ICacheFactory::class),
			Server::get(DurableCacheRequest::class),
			Server::get(ITimeFactory::class),
			Server::get(IConfig::class),
		);
	}

	public function testABufferOneWorkerWritesAnotherReads(): void {
		$this->worker()->setBuffer(self::NAMESPACE, self::KEY, ['actor' => 'https://example.test/users/a'], 60);

		$this->assertSame(['actor' => 'https://example.test/users/a'], $this->worker()->getBuffer(self::NAMESPACE, self::KEY));
	}

	public function testABufferOneWorkerRemovesIsGoneForAnother(): void {
		$writer = $this->worker();
		$writer->setBuffer(self::NAMESPACE, self::KEY, ['actor' => 'https://example.test/users/a'], 60);

		$this->worker()->removeBuffer(self::NAMESPACE, self::KEY);

		$this->assertNull($writer->getBuffer(self::NAMESPACE, self::KEY));
	}
}
