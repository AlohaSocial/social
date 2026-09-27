<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Service\CacheActorSweepService;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MediaPurgeService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The eviction of cached remote actors nothing here refers to any more.
 *
 * Which actors those are is decided in SQL (`getSweepableIds()`, exercised by
 * the integration suite against a real database); what is checked here is the
 * part that decides *whether* to sweep at all, how far the cutoff reaches, and
 * that an actor's pictures go with its row rather than being left in appdata
 * under a name nothing remembers any more.
 */
#[AllowMockObjectsWithoutExpectations]
class CacheActorSweepServiceTest extends TestCase {
	private const NOW = 1_700_000_000;

	private ConfigService|MockObject $configService;
	private CacheActorsRequest|MockObject $cacheActorsRequest;
	private CacheDocumentsRequest|MockObject $cacheDocumentsRequest;
	private CacheDocumentService|Stub $cacheDocumentService;
	private CacheActorSweepService $service;

	protected function setUp(): void {
		$this->configService = $this->createMock(ConfigService::class);
		$this->configService->method('getAppValueInt')
			->with(ConfigService::SOCIAL_CACHE_ACTOR_DAYS)->willReturn(180);
		$this->cacheActorsRequest = $this->createMock(CacheActorsRequest::class);
		$this->cacheDocumentsRequest = $this->createMock(CacheDocumentsRequest::class);
		$this->cacheDocumentService = $this->createStub(CacheDocumentService::class);

		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		$this->service = new CacheActorSweepService(
			$this->configService,
			$this->cacheActorsRequest,
			// the real one: the read-then-remove-then-delete sequence it holds
			// is what this test is about, and every other caller uses the same
			new MediaPurgeService(
				$this->cacheDocumentsRequest, $this->cacheDocumentService, new NullLogger()
			),
			new NullLogger(),
			$time
		);
	}

	/**
	 * @param list<list<string>> $pages the ids each successive read returns
	 */
	private function sweepable(array $pages): void {
		$this->cacheActorsRequest->method('getSweepableIds')->willReturnCallback(
			static function () use (&$pages): array {
				return array_shift($pages) ?? [];
			}
		);
	}

	public function testTheCutoffIsTheConfiguredNumberOfDaysBeforeNow(): void {
		$asked = [];
		$this->cacheActorsRequest->method('getSweepableIds')->willReturnCallback(
			static function (int $cutoff, int $limit) use (&$asked): array {
				$asked[] = $cutoff;

				return [];
			}
		);

		$this->service->sweep();

		$this->assertSame([self::NOW - 180 * 86400], $asked);
	}

	/**
	 * The default is 180 days and an administrator who wants nothing evicted
	 * has to be able to say so: `occ config:app:set social cache_actor_days
	 * --value 0`.
	 */
	public function testZeroDaysTurnsTheSweepOffEntirely(): void {
		$this->cacheActorsRequest->expects($this->never())->method('getSweepableIds');

		$this->assertSame(['actors' => 0, 'documents' => 0], $this->service->sweep(0));
	}

	public function testANegativeSettingIsReadAsOffRatherThanAsTheFuture(): void {
		$this->cacheActorsRequest->expects($this->never())->method('getSweepableIds');

		$this->assertSame(['actors' => 0, 'documents' => 0], $this->service->sweep(-30));
	}

	public function testEachPageIsDeletedAndTheNextOneAskedFor(): void {
		$this->sweepable([['https://gone.example/users/a', 'https://gone.example/users/b'], ['https://gone.example/users/c']]);
		$deleted = [];
		$this->cacheActorsRequest->method('deleteCacheById')->willReturnCallback(
			static function (string $id) use (&$deleted): void {
				$deleted[] = $id;
			}
		);

		$result = $this->service->sweep();

		$this->assertSame(3, $result['actors']);
		$this->assertSame([
			'https://gone.example/users/a', 'https://gone.example/users/b', 'https://gone.example/users/c',
		], $deleted);
	}

	/**
	 * The row is the only thing that remembers the file's name, so the files
	 * have to be read before the rows go — a delete that does not read first
	 * leaves the bytes in appdata for good.
	 */
	public function testAnActorsPicturesGoWithIt(): void {
		$this->sweepable([['https://gone.example/users/a']]);
		$icon = new Document();
		$icon->setId('https://gone.example/users/a#icon');
		$icon->setLocalCopy('face');
		$icon->setResizedCopy('face-small');
		$this->cacheDocumentsRequest->method('getByParent')->willReturn([$icon]);
		$removed = [];
		$this->cacheDocumentService->method('removeFromCache')->willReturnCallback(
			static function (string $name) use (&$removed): void {
				$removed[] = $name;
			}
		);
		$this->cacheDocumentsRequest->expects($this->once())->method('deleteByParent')
			->with('https://gone.example/users/a');

		$result = $this->service->sweep();

		$this->assertSame(['face', 'face-small'], $removed);
		$this->assertSame(1, $result['documents']);
	}

	/**
	 * A file that cannot be removed is not a reason to keep the actor: the
	 * row goes and `occ social:media:usage` reports what is left over.
	 */
	public function testAFileThatCannotBeRemovedDoesNotStopTheSweep(): void {
		$this->sweepable([['https://gone.example/users/a']]);
		$icon = new Document();
		$icon->setLocalCopy('face');
		$this->cacheDocumentsRequest->method('getByParent')->willReturn([$icon]);
		$this->cacheDocumentService->method('removeFromCache')
			->willThrowException(new \RuntimeException('appdata is read-only'));
		$this->cacheActorsRequest->expects($this->once())->method('deleteCacheById');

		$this->assertSame(1, $this->service->sweep()['actors']);
	}

	public function testARunIsBoundedSoEvictionNeverDominatesACronSlot(): void {
		$limits = [];
		$this->cacheActorsRequest->method('getSweepableIds')->willReturnCallback(
			static function (int $cutoff, int $limit) use (&$limits): array {
				$limits[] = $limit;

				return ($limit > 0 && count($limits) < 3) ? array_fill(0, $limit, 'https://gone.example/users/x') : [];
			}
		);

		$result = $this->service->sweep(180, false, 150);

		$this->assertSame(150, $result['actors']);
		// a full page, then only what is left of the ceiling
		$this->assertSame([CacheActorSweepService::PAGE, 50], $limits);
	}

	public function testADryRunCountsAndRemovesNothing(): void {
		$this->sweepable([['https://gone.example/users/a', 'https://gone.example/users/b']]);
		$this->cacheActorsRequest->expects($this->never())->method('deleteCacheById');
		$this->cacheDocumentsRequest->expects($this->never())->method('deleteByParent');

		$this->assertSame(2, $this->service->sweep(180, true)['actors']);
	}

	public function testTheConfiguredNumberOfDaysIsWhatTheCronSweepsBy(): void {
		$this->assertSame(180, $this->service->getSweepDays());
	}
}
