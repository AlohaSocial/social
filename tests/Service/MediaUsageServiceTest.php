<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MediaUsageService;
use OCA\Social\Service\RemoteMediaQuotaService;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * How much disk this app is using, and on whose behalf.
 *
 * The split the administration page rests on is this instance's own uploads —
 * somebody's posts, which are not going anywhere — against cached copies of
 * other servers' pictures, which the retention sweep exists to remove. Getting
 * that split wrong tells an administrator to delete the wrong half, so it is
 * what most of these assert.
 */
class MediaUsageServiceTest extends TestCase {
	private const CLOUD = 'https://cloud.example';

	private CacheDocumentsRequest|Stub $cacheDocumentsRequest;
	private CacheDocumentService|Stub $cacheDocumentService;
	private ConfigService|Stub $configService;
	private MediaUsageService $service;
	private RemoteMediaQuotaService|Stub $remoteMediaQuotaService;
	/** @var string[] the hosts whose day counter the walk reset */
	private array $forgotten = [];

	/** @var array<string, int|null> a stored copy => its size, or null for gone */
	private array $onDisk = [];
	/** @var array<int, array<string, mixed>> the rows the store holds */
	private array $rows = [];
	/** @var array<int, int> nid => the size written back */
	private array $sized = [];
	/** @var array<string, string> the app values written */
	private array $appValues = [];
	/** how many pages the walk asked for */
	private int $pages = 0;

	protected function setUp(): void {
		parent::setUp();

		$this->cacheDocumentsRequest = $this->createStub(CacheDocumentsRequest::class);
		$this->cacheDocumentsRequest->method('getUsagePage')
			->willReturnCallback(function (int $limit, int $after): array {
				$this->pages++;
				$page = array_values(array_filter(
					$this->rows, static fn (array $row): bool => $row['nid'] > $after
				));
				usort($page, static fn (array $a, array $b): int => $a['nid'] <=> $b['nid']);

				return array_slice($page, 0, $limit);
			});
		$this->cacheDocumentsRequest->method('setSize')
			->willReturnCallback(function (int $nid, int $size): void {
				$this->sized[$nid] = $size;
			});

		$this->cacheDocumentService = $this->createStub(CacheDocumentService::class);
		$this->cacheDocumentService->method('cachedFileSize')
			->willReturnCallback(fn (string $copy): ?int => $this->onDisk[$copy] ?? null);

		$this->configService = $this->createStub(ConfigService::class);
		$this->configService->method('getCloudUrl')->willReturn(self::CLOUD);
		$this->configService->method('getAppValue')
			->willReturnCallback(fn (string $key): string => $this->appValues[$key] ?? '');
		$this->configService->method('setAppValue')
			->willReturnCallback(function (string $key, string $value): void {
				$this->appValues[$key] = $value;
			});

		// the walk clears the per-domain quota's day counters as it finishes,
		// because everything they were counting is in the figure it just wrote
		$this->remoteMediaQuotaService = $this->createStub(RemoteMediaQuotaService::class);
		$this->remoteMediaQuotaService->method('forgetAll')
			->willReturnCallback(function (array $hosts): void {
				$this->forgotten = $hosts;
			});

		$this->service = new MediaUsageService(
			$this->cacheDocumentsRequest,
			$this->cacheDocumentService,
			$this->configService,
			$this->remoteMediaQuotaService
		);
	}

	/** @param array<string, mixed> $row */
	private function row(int $nid, string $id, array $row = []): void {
		$this->rows[] = array_merge([
			'nid' => $nid,
			'id' => $id,
			'url' => '',
			'account' => '',
			'local_copy' => '',
			'resized_copy' => '',
			'media_type' => 'image/jpeg',
			'size' => 0,
			'actor_local' => null,
		], $row);
	}

	private function onDisk(string $copy, int $size): string {
		$this->onDisk[$copy] = $size;

		return $copy;
	}

	public function testThisInstancesOwnUploadsAreCountedApartFromEverybodyElsesPictures(): void {
		$this->row(1, self::CLOUD . '/documents/local/aaa', [
			'local_copy' => $this->onDisk('local-aaa', 1000),
		]);
		$this->row(2, 'https://remote.example/media/bbb', [
			'local_copy' => $this->onDisk('remote-bbb', 300),
		]);

		$usage = $this->service->measure();

		$this->assertSame(1000, $usage['local']['attachments']['bytes']);
		$this->assertSame(300, $usage['remote']['attachments']['bytes']);
		$this->assertSame(1300, $usage['bytes']);
		$this->assertSame(2, $usage['files']);
	}

	/**
	 * Another Social instance's document carries the same id shape as this
	 * one's, so the cloud url is what actually decides which side a row is on.
	 */
	public function testACopyOfAnotherSocialInstancesPictureIsNotCountedAsLocal(): void {
		$this->row(1, 'https://other.example/documents/local/aaa', [
			'local_copy' => $this->onDisk('other-aaa', 500),
		]);

		$usage = $this->service->measure();

		$this->assertSame(0, $usage['local']['attachments']['bytes']);
		$this->assertSame(500, $usage['remote']['attachments']['bytes']);
	}

	/** Having a cached actor as a parent is what makes a document their picture. */
	public function testAPictureBelongingToAnActorIsCountedAsAnAvatarNotAnAttachment(): void {
		$this->row(1, 'https://remote.example/media/face', [
			'local_copy' => $this->onDisk('face', 40),
			'actor_local' => 0,
		]);
		$this->row(2, self::CLOUD . '/documents/avatar/mine', [
			'local_copy' => $this->onDisk('mine', 20),
		]);

		$usage = $this->service->measure();

		$this->assertSame(40, $usage['remote']['avatars']['bytes']);
		$this->assertSame(20, $usage['local']['avatars']['bytes']);
		$this->assertSame(0, $usage['remote']['attachments']['bytes']);
	}

	public function testBothCopiesOfAPictureAreCounted(): void {
		$this->row(1, self::CLOUD . '/documents/local/aaa', [
			'local_copy' => $this->onDisk('full', 900),
			'resized_copy' => $this->onDisk('thumb', 100),
		]);

		$usage = $this->service->measure();

		$this->assertSame(1000, $usage['local']['attachments']['bytes']);
		$this->assertSame(2, $usage['local']['attachments']['files']);
	}

	/** A pointer at a file on the server that hosts it; no bytes here. */
	public function testAStreamedVideoIsCountedAsAPointerAndNotAsBytes(): void {
		$this->row(1, 'https://remote.example/video/aaa', [
			'local_copy' => Document::COPY_STREAMED,
			'media_type' => 'video/mp4',
		]);

		$usage = $this->service->measure();

		$this->assertSame(1, $usage['streamed']);
		$this->assertSame(0, $usage['bytes']);
	}

	/** Served out of Nextcloud's own avatar store, never copied here. */
	public function testAPictureNextcloudItselfHoldsIsCountedAsElsewhere(): void {
		$this->row(1, self::CLOUD . '/documents/avatar/alice', ['local_copy' => 'avatar']);
		$this->row(2, self::CLOUD . '/documents/header/alice', ['local_copy' => 'header']);

		$usage = $this->service->measure();

		$this->assertSame(2, $usage['elsewhere']);
		$this->assertSame(0, $usage['bytes']);
	}

	/**
	 * A row whose file is gone is a row worth showing an administrator, and it
	 * must not be counted as zero bytes of something that is still there.
	 */
	public function testARowWhoseFileIsGoneIsCountedAsMissing(): void {
		$this->row(1, self::CLOUD . '/documents/local/aaa', ['local_copy' => 'vanished']);

		$usage = $this->service->measure();

		$this->assertSame(1, $usage['missing']);
		$this->assertSame(0, $usage['files']);
	}

	public function testTheWalkPagesThroughEverythingRatherThanStoppingAtTheFirstPage(): void {
		for ($nid = 1; $nid <= 501; $nid++) {
			$this->row($nid, self::CLOUD . '/documents/local/' . $nid, [
				'local_copy' => $this->onDisk('copy-' . $nid, 10),
			]);
		}

		$usage = $this->service->measure();

		$this->assertSame(501, $usage['rows']);
		$this->assertSame(5010, $usage['bytes']);
		// two pages of rows and one that came back empty
		$this->assertSame(3, $this->pages);
	}

	/**
	 * `social_cache_doc.size` arrived with the video work, and every row
	 * written before it carries 0 — which is what the per-account quota reads.
	 * This walk is already asking how big each file is.
	 */
	public function testAVideoWithNoStoredSizeGetsOneAsTheWalkPasses(): void {
		$this->row(1, 'https://remote.example/video/aaa', [
			'local_copy' => $this->onDisk('video-aaa', 4096),
			'media_type' => 'video/mp4',
		]);

		$this->service->measure();

		$this->assertSame([1 => 4096], $this->sized);
	}

	/** Overwriting a size already there would let this run disagree with what was published. */
	public function testASizeThatIsAlreadyKnownIsLeftAlone(): void {
		$this->row(1, 'https://remote.example/video/aaa', [
			'local_copy' => $this->onDisk('video-aaa', 4096),
			'media_type' => 'video/mp4',
			'size' => 111,
		]);

		$this->service->measure();

		$this->assertSame([], $this->sized);
	}

	public function testAnImageIsNotGivenASizeBecauseNothingReadsIt(): void {
		$this->row(1, self::CLOUD . '/documents/local/aaa', [
			'local_copy' => $this->onDisk('image-aaa', 4096),
		]);

		$this->service->measure();

		$this->assertSame([], $this->sized);
	}

	public function testTheAnswerIsKeptWithTheMomentItWasTaken(): void {
		$this->row(1, self::CLOUD . '/documents/local/aaa', [
			'local_copy' => $this->onDisk('local-aaa', 1000),
		]);

		$stored = $this->service->measureAndStore();

		$this->assertGreaterThan(0, $stored['measured']);
		$this->assertSame($stored, $this->service->lastMeasured());
	}

	/**
	 * "Nothing has been measured yet" and "this instance stores nothing" are
	 * different things to show an administrator.
	 */
	public function testAnInstanceThatHasNeverMeasuredSaysSoRatherThanShowingZeroes(): void {
		$this->assertNull($this->service->lastMeasured());
	}

	public function testAStoredAnswerThatIsNotReadableIsTreatedAsNoAnswer(): void {
		$this->appValues[ConfigService::SOCIAL_MEDIA_USAGE] = 'not json';

		$this->assertNull($this->service->lastMeasured());
	}

	/**
	 * The per-domain quota reads this walk's figure, so the walk has to
	 * produce one: without it the quota would have nothing to enforce against
	 * but what it had counted since the last restart.
	 */
	public function testEachRemoteHostsBytesAreTotalledForTheQuota(): void {
		$this->row(1, 'https://remote.example/media/a', [
			'local_copy' => $this->onDisk('a', 500),
		]);
		$this->row(2, 'https://remote.example/media/b', [
			'local_copy' => $this->onDisk('b', 300),
		]);
		$this->row(3, 'https://other.example/media/c', [
			'local_copy' => $this->onDisk('c', 100),
		]);
		$this->row(4, self::CLOUD . '/documents/local/mine', [
			'local_copy' => $this->onDisk('mine', 900),
		]);

		$usage = $this->service->measure();

		$this->assertSame(
			['remote.example' => 800, 'other.example' => 100],
			$usage['domains'],
			'this instance\'s own uploads are nobody else\'s quota to spend'
		);
	}

	/**
	 * An attachment without an id of its own is given one under this
	 * instance's address, and its bytes belong to the server its url names.
	 */
	public function testAnAttachmentWithAGeneratedIdIsTotalledUnderTheServerItCameFrom(): void {
		$this->row(1, self::CLOUD . '/documents/g/1d2c3b4a-0000-4000-8000-000000000000', [
			'url' => 'https://social.b.example/apps/social/media/0bef598f-ef65-4857-a108-b3766689b78c.jpeg',
			'local_copy' => $this->onDisk('a', 500),
		]);
		$this->row(2, self::CLOUD . '/documents/g/2d2c3b4a-0000-4000-8000-000000000000', [
			'url' => 'https://files.mastodon.example/media/cat.jpg',
			'local_copy' => $this->onDisk('b', 300),
		]);

		$usage = $this->service->measure();

		$this->assertSame(['social.b.example' => 500, 'files.mastodon.example' => 300], $usage['domains']);
	}

	/**
	 * Everything the counters were holding is in the figure the walk just
	 * wrote; leaving them would charge those bytes twice and shrink the quota
	 * a little more with every pass.
	 */
	public function testTheWalkClearsTheDayCountersItHasJustAccountedFor(): void {
		$this->row(1, 'https://remote.example/media/a', [
			'local_copy' => $this->onDisk('a', 500),
		]);

		$this->service->measureAndStore();

		$this->assertSame(['remote.example'], $this->forgotten);
	}
}
