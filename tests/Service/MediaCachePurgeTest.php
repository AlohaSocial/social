<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MediaPurgeService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * `MediaPurgeService::purgeCache()`, against a table and an appdata held in
 * PHP: the page query below answers the way `getCachedRemotePage()` does —
 * rows still holding a copy, oldest `cached` first, keyset on (`cached`,
 * `nid`) — and `markEvicted()` takes a row out of it.
 */
class MediaCachePurgeTest extends TestCase {
	private const CLOUD = 'https://cloud.example';

	/** @var array<string, array{nid: string, id: string, local_copy: string, resized_copy: string, cached: string, actor: bool}> */
	private array $rows = [];
	/** @var array<string, int> file name => bytes */
	private array $onDisk = [];
	/** @var list<string> the names removeFromCache() was given */
	private array $removed = [];
	/** @var list<string> the nids marked evicted */
	private array $evicted = [];
	/** @var array<string, string> app values written */
	private array $written = [];
	/** @var array<string, string> app values read */
	private array $settings = [];
	private int $pages = 0;
	private MediaPurgeService $service;

	protected function setUp(): void {
		$request = $this->createStub(CacheDocumentsRequest::class);
		$request->method('getCachedRemotePage')->willReturnCallback(
			function (int $limit, string $afterCached = '', int|string $afterNid = 0): array {
				$this->pages++;
				$page = array_values(array_filter(
					$this->rows,
					static fn (array $row): bool => $row['cached'] !== ''
						&& ($afterCached === '' || $row['cached'] > $afterCached
							|| ($row['cached'] === $afterCached && (int)$row['nid'] > (int)$afterNid))
				));
				usort($page, static fn (array $a, array $b): int => [$a['cached'], (int)$a['nid']] <=> [$b['cached'], (int)$b['nid']]);

				return array_slice($page, 0, $limit);
			}
		);
		$request->method('markEvicted')->willReturnCallback(function (int|string $nid): void {
			$this->evicted[] = (string)$nid;
			$this->rows[(string)$nid]['cached'] = '';
		});

		$files = $this->createStub(CacheDocumentService::class);
		$files->method('cachedFileSize')->willReturnCallback(fn (string $name): ?int => $this->onDisk[$name] ?? null);
		$files->method('removeFromCache')->willReturnCallback(function (string $name): void {
			$this->removed[] = $name;
			unset($this->onDisk[$name]);
		});

		$config = $this->createStub(ConfigService::class);
		$config->method('getCloudUrl')->willReturn(self::CLOUD);
		$config->method('getAppValueInt')->willReturnCallback(fn (string $key): int => (int)($this->settings[$key] ?? 0));
		$config->method('getAppValue')->willReturnCallback(fn (string $key): string => $this->written[$key] ?? '');
		$config->method('setAppValue')->willReturnCallback(function (string $key, string $value): void {
			$this->written[$key] = $value;
		});

		$this->service = new MediaPurgeService($request, $files, $config, new NullLogger());
	}

	/** A cached remote copy, `$daysAgo` old, with a full copy and a preview. */
	private function cached(int $nid, int $daysAgo, int $bytes = 100, bool $actor = false, string $id = ''): void {
		$this->rows[(string)$nid] = [
			'nid' => (string)$nid,
			'id' => ($id !== '') ? $id : 'https://remote.example/media/' . $nid,
			'local_copy' => 'full-' . $nid,
			'resized_copy' => 'small-' . $nid,
			'cached' => date('Y-m-d H:i:s', time() - $daysAgo * 86400 - $nid),
			'actor' => $actor,
		];
		$this->onDisk['full-' . $nid] = $bytes;
		$this->onDisk['small-' . $nid] = 10;
	}

	public function testAnAgeLimitTakesOnlyTheCopiesCachedBeforeIt(): void {
		$this->cached(1, 40);
		$this->cached(2, 31);
		$this->cached(3, 5);

		$result = $this->service->purgeCache(30, 0);

		$this->assertSame(['full-1', 'small-1', 'full-2', 'small-2'], $this->removed);
		$this->assertSame(['1', '2'], $this->evicted);
		$this->assertSame(4, $result['files']);
		$this->assertSame(220, $result['bytes']);
		$this->assertArrayHasKey('full-3', $this->onDisk);
	}

	public function testASizeLimitTakesTheOldestFirstUntilTheCacheFits(): void {
		$this->cached(1, 9, 1000);
		$this->cached(2, 8, 1000);
		$this->cached(3, 7, 1000);
		$this->cached(4, 1, 1000);

		// 4 × 1010 bytes; at most 2100 means the two oldest go
		$result = $this->service->purgeCache(0, 2100);

		$this->assertSame(['1', '2'], $this->evicted);
		$this->assertSame(4040, $result['cache_bytes']);
		$this->assertSame(2020, $result['remaining_bytes']);
		$this->assertTrue($result['complete']);
	}

	public function testACacheAlreadyWithinItsSizeIsLeftAlone(): void {
		$this->cached(1, 9, 100);

		$result = $this->service->purgeCache(0, 5000);

		$this->assertSame([], $this->removed);
		$this->assertSame(0, $result['files']);
		$this->assertSame(110, $result['remaining_bytes']);
	}

	public function testADryRunCountsTheSameAndChangesNothing(): void {
		$this->cached(1, 9, 1000);
		$this->cached(2, 8, 1000);
		$this->cached(3, 1, 1000);

		$dry = $this->service->purgeCache(0, 1500, false, true);

		$this->assertSame([], $this->removed);
		$this->assertSame([], $this->evicted);
		$this->assertArrayNotHasKey(ConfigService::SOCIAL_MEDIA_CACHE_PURGED, $this->written);
		$this->assertTrue($dry['dry_run']);

		$real = $this->service->purgeCache(0, 1500);

		$this->assertSame($dry['files'], $real['files']);
		$this->assertSame($dry['bytes'], $real['bytes']);
		$this->assertSame(['1', '2'], $this->evicted);
	}

	/**
	 * A row with no account but an id this instance gave its own upload is
	 * left alone, and nothing is ever removed that a cached row does not
	 * name: the GIF pack, custom emoji and uploads live elsewhere in appdata.
	 */
	public function testOnlyFilesNamedByCachedRemoteRowsAreRemoved(): void {
		$this->cached(1, 90, 100, false, self::CLOUD . '/documents/local/abc');
		$this->cached(2, 90);
		$this->onDisk['gif-pack/cat.gif'] = 5000;

		$result = $this->service->purgeCache(0, 0, true);

		$this->assertSame(['full-2', 'small-2'], $this->removed);
		$this->assertArrayHasKey('full-1', $this->onDisk);
		$this->assertArrayHasKey('gif-pack/cat.gif', $this->onDisk);
		$this->assertSame(2, $result['files']);
	}

	public function testAvatarsAndHeadersAreCountedApartFromAttachments(): void {
		$this->cached(1, 90, 100, true);
		$this->cached(2, 90, 200);

		$result = $this->service->purgeCache(30, 0);

		$this->assertSame(['files' => 2, 'bytes' => 110], $result['avatars']);
		$this->assertSame(['files' => 2, 'bytes' => 210], $result['attachments']);
	}

	/**
	 * Rows whose files were deleted behind the app's back, as on a server
	 * where somebody cleared the hash folders by hand: counted, marked so they
	 * are fetched again on demand, and not counted as freed.
	 */
	public function testRowsWhoseFilesAreAlreadyGoneAreMarkedAndCounted(): void {
		$this->cached(1, 90);
		$this->cached(2, 1);
		unset($this->onDisk['full-1'], $this->onDisk['small-1'], $this->onDisk['full-2'], $this->onDisk['small-2']);
		$this->cached(3, 1, 500);

		$result = $this->service->purgeCache(0, 100000);

		$this->assertSame(2, $result['gone']);
		$this->assertSame(['1', '2'], $this->evicted);
		$this->assertSame(0, $result['files']);
		$this->assertSame(510, $result['cache_bytes']);
	}

	public function testAgeOnlyAlsoMarksTheGoneRowsItReaches(): void {
		$this->cached(1, 90);
		unset($this->onDisk['full-1'], $this->onDisk['small-1']);

		$result = $this->service->purgeCache(30, 0);

		$this->assertSame(1, $result['gone']);
		$this->assertSame(['1'], $this->evicted);
	}

	public function testItReadsPageAfterPageAndRemovesEverythingWithAll(): void {
		$count = MediaPurgeService::CACHE_PAGE * 2 + 7;
		for ($nid = 1; $nid <= $count; $nid++) {
			$this->cached($nid, 3, 1);
		}

		$result = $this->service->purgeCache(0, 0, true);

		$this->assertSame($count, count($this->evicted));
		$this->assertSame($count * 2, $result['files']);
		$this->assertGreaterThanOrEqual(3, $this->pages);
		$this->assertSame([], array_filter($this->onDisk));
	}

	public function testASizePassCutShortByItsDeadlineAppliesNoSizeLimit(): void {
		for ($nid = 1; $nid <= MediaPurgeService::CACHE_PAGE + 1; $nid++) {
			$this->cached($nid, 3, 1000);
		}

		$result = $this->service->purgeCache(0, 1000, false, false, time() - 1);

		$this->assertFalse($result['complete']);
		$this->assertNull($result['cache_bytes']);
		$this->assertSame([], $this->removed);
	}

	public function testWithBothLimitsOffTheConfiguredPurgeDoesNothing(): void {
		$this->cached(1, 900);

		$this->assertNull($this->service->purgeCacheAsConfigured());
		$this->assertSame([], $this->removed);
	}

	public function testTheConfiguredPurgeAppliesBothSettingsAndRecordsWhatItDid(): void {
		$this->settings = [ConfigService::SOCIAL_MEDIA_CACHE_DAYS => '30', ConfigService::SOCIAL_MEDIA_CACHE_MB => '1'];
		$this->cached(1, 40, 10);
		$this->cached(2, 2, 600 * 1024);
		$this->cached(3, 1, 600 * 1024);

		$result = $this->service->purgeCacheAsConfigured();

		$this->assertSame(['1', '2'], $this->evicted, 'one by age, one by size');
		$recorded = json_decode($this->written[ConfigService::SOCIAL_MEDIA_CACHE_PURGED], true);
		$this->assertSame($result['bytes'], $recorded['bytes']);
		$this->assertGreaterThan(0, $recorded['purged']);
		$this->assertSame($recorded['bytes'], $this->service->lastCachePurge()['bytes']);
	}
}
