<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Service\AvatarThumbnailBackfill;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ConfigService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class AvatarThumbnailBackfillTest extends TestCase {
	private CacheDocumentsRequest&MockObject $documents;
	private CacheDocumentService&MockObject $cacheDocumentService;
	/** @var array<string, string> the app settings as the run leaves them */
	private array $settings = [];
	private AvatarThumbnailBackfill $backfill;

	protected function setUp(): void {
		$this->documents = $this->createMock(CacheDocumentsRequest::class);
		$this->cacheDocumentService = $this->createMock(CacheDocumentService::class);
		$config = $this->createMock(ConfigService::class);
		$config->method('getAppValue')->willReturnCallback(fn (string $key): string => $this->settings[$key] ?? '');
		$config->method('setAppValue')->willReturnCallback(function (string $key, $value): void {
			$this->settings[$key] = (string)$value;
		});

		$this->backfill = new AvatarThumbnailBackfill(
			$this->documents, $this->cacheDocumentService, $config, new NullLogger()
		);
	}

	private function icon(int $nid): Document {
		$document = new Document();
		$document->setNid($nid);
		$document->setId('https://remote.example/avatars/' . $nid);

		return $document;
	}

	public function testItWalksFromTheCursorAndRemembersWhereItGot(): void {
		$this->settings[ConfigService::SOCIAL_AVATAR_THUMB_CURSOR] = '10';
		$icons = [$this->icon(11), $this->icon(12)];
		$this->documents->expects($this->once())->method('getCachedActorIcons')->with(2, '10')->willReturn($icons);
		$this->cacheDocumentService->method('rebuildIconThumbnail')->willReturn(true);
		$written = [];
		$this->documents->method('updateThumbnail')->willReturnCallback(function (Document $icon) use (&$written): void {
			$written[] = $icon->getNid();
		});

		$this->assertSame(2, $this->backfill->next(2));
		$this->assertSame([11, 12], $written);
		$this->assertSame('12', $this->settings[ConfigService::SOCIAL_AVATAR_THUMB_CURSOR]);
	}

	public function testAShortPageEndsTheWalkForGood(): void {
		$this->documents->expects($this->once())->method('getCachedActorIcons')->with(5, '0')->willReturn([$this->icon(3)]);
		$this->cacheDocumentService->method('rebuildIconThumbnail')->willReturn(true);

		$this->backfill->next(5);
		$this->assertSame(AvatarThumbnailBackfill::DONE, $this->settings[ConfigService::SOCIAL_AVATAR_THUMB_CURSOR]);

		$this->documents->expects($this->never())->method('updateThumbnail');
		$this->assertSame(0, $this->backfill->next(5), 'nothing is read once it is done');
	}

	public function testAnIconAlreadySmallOrBrokenIsPassedAndNotWritten(): void {
		$this->documents->method('getCachedActorIcons')->willReturn([$this->icon(1), $this->icon(2), $this->icon(3)]);
		$this->cacheDocumentService->method('rebuildIconThumbnail')->willReturnCallback(
			static function (Document $icon): bool {
				if ($icon->getNid() === 2) {
					throw new \RuntimeException('not a picture');
				}

				return $icon->getNid() === 3;
			}
		);
		$this->documents->expects($this->once())->method('updateThumbnail');

		$this->assertSame(1, $this->backfill->next(3));
		$this->assertSame('3', $this->settings[ConfigService::SOCIAL_AVATAR_THUMB_CURSOR]);
	}
}
