<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\AP;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Image;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\ActorService;
use OCA\Social\Service\BannerService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MediaPurgeService;
use OCA\Social\Tests\Model\TActivityPubMocks;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__ . '/../Model/TActivityPubMocks.php';

/**
 * What happens to the picture a banner used to be.
 *
 * It used to be nothing: the comment said "the document sweep collects it"
 * and there was no such sweep, so every replaced banner stayed on disk and
 * stayed readable at its own URL by anyone who had it.
 */
#[AllowMockObjectsWithoutExpectations]
class BannerServiceTest extends TestCase {
	use TActivityPubMocks;

	private const ACTOR = 'https://cloud.example/apps/social/@alice';
	private const OLD = 'https://cloud.example/apps/social/media/old-banner.jpeg';

	private AccountService|Stub $accountService;
	private ActorService|MockObject $actorService;
	private CacheActorService|Stub $cacheActorService;
	private MediaPurgeService|MockObject $mediaPurgeService;
	private Person $cached;
	private BannerService $service;

	protected function setUp(): void {
		$this->installActivityPub();

		$alice = new Person();
		$alice->setId(self::ACTOR);
		$alice->setPreferredUsername('alice');

		$this->cached = new Person();
		$this->cached->setId(self::ACTOR);
		$this->cached->setPreferredUsername('alice');
		$this->cached->setHeader(self::OLD);

		$this->accountService = $this->createStub(AccountService::class);
		$this->accountService->method('getActorFromUserId')->willReturn($alice);
		$this->actorService = $this->createMock(ActorService::class);
		$this->cacheActorService = $this->createStub(CacheActorService::class);
		$this->cacheActorService->method('getFromId')->willReturnCallback(fn (): Person => $this->cached);
		$this->mediaPurgeService = $this->createMock(MediaPurgeService::class);
		$configService = $this->createStub(ConfigService::class);
		$configService->method('getCloudUrl')->willReturn('https://cloud.example/');

		$this->service = new BannerService(
			$this->accountService,
			$this->actorService,
			$this->cacheActorService,
			$this->createStub(CacheDocumentService::class),
			$this->mediaPurgeService,
			$this->createStub(ActivityService::class),
			$configService,
			$this->createStub(IURLGenerator::class),
			new NullLogger(),
		);
	}

	protected function tearDown(): void {
		AP::set(null);
	}

	public function testRemovingABannerTakesThePictureWithIt(): void {
		$this->mediaPurgeService->expects($this->once())->method('purgeLocalByUrl')
			->with(self::OLD, 'alice');

		$this->service->remove('alice');

		$this->assertSame('', $this->cached->getHeader());
	}

	public function testRemovingABannerNobodySetIsANoOp(): void {
		$this->cached->setHeader('');
		$this->mediaPurgeService->expects($this->never())->method('purgeLocalByUrl');
		$this->actorService->expects($this->never())->method('cacheLocalActor');

		$this->service->remove('alice');
	}

	public function testReplacingABannerTakesThePreviousPictureAway(): void {
		$tmp = tempnam(sys_get_temp_dir(), 'social-test-');

		try {
			$this->mediaPurgeService->expects($this->once())->method('purgeLocalByUrl')
				->with(self::OLD, 'alice');

			$image = $this->service->setFromTempFile('alice', $tmp);

			$this->assertInstanceOf(Image::class, $image);
			$this->assertTrue($image->isPublic(), 'a banner is part of the actor document');
		} finally {
			@unlink($tmp);
		}
	}

	/**
	 * A picture that cannot be removed is not a reason to refuse the new
	 * banner: the profile is already showing it.
	 */
	public function testAPictureThatWillNotGoDoesNotFailTheChange(): void {
		$this->mediaPurgeService->method('purgeLocalByUrl')
			->willThrowException(new \RuntimeException('appdata is read-only'));

		$this->service->remove('alice');

		$this->assertSame('', $this->cached->getHeader());
	}
}
