<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\SocialQueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The media filters, before and after every row carries its `media_kind`.
 *
 * A row written before the column existed is judged by its attachments, as
 * one branch of an `OR` beside the indexed one; MySQL took that `OR` as a
 * reason to read the whole table for a Photos page. Once the flag says the
 * backfill left no row behind, each filter is the indexed predicate alone.
 */
#[AllowMockObjectsWithoutExpectations]
class MediaKindFilterTest extends TestCase {
	/** @var string[] */
	private array $where = [];

	private function builder(bool $filled): SocialQueryBuilder&MockObject {
		$qb = $this->getMockBuilder(SocialQueryBuilder::class)
			->disableOriginalConstructor()
			->onlyMethods(['expr', 'createNamedParameter', 'andWhere', 'getDefaultSelectAlias'])
			->getMock();
		$qb->method('expr')->willReturn(new FakeExpressions());
		$qb->method('getDefaultSelectAlias')->willReturn('s');
		$qb->method('createNamedParameter')->willReturnCallback(
			static fn ($value): string => is_array($value) ? '[' . implode(',', $value) . ']' : "'" . $value . "'"
		);
		$qb->method('andWhere')->willReturnCallback(function ($predicate) use ($qb) {
			$this->where[] = (string)$predicate;

			return $qb;
		});
		$qb->setMediaKindFilled($filled);

		return $qb;
	}

	public function testUntilEveryRowHasAKindAPostIsAlsoJudgedByItsAttachments(): void {
		$this->builder(false)->limitToMedia();

		$this->assertCount(1, $this->where);
		$this->assertStringContainsString('s.media_kind IS NULL', $this->where[0]);
		$this->assertStringContainsString('s.attachments', $this->where[0]);
	}

	public function testOnceEveryRowHasAKindTheMediaFilterIsTheIndexedPredicateAlone(): void {
		$this->builder(true)->limitToMedia();

		$this->assertSame(["(s.media_kind IS NOT NULL AND s.media_kind <> '')"], $this->where);
	}

	public function testOnceEveryRowHasAKindAVideoIsOneInList(): void {
		$this->builder(true)->limitToVideo();

		$this->assertSame(['s.media_kind IN ([video,mixed])'], $this->where);
	}

	public function testOnceEveryRowHasAKindAMediaTypeIsOneInList(): void {
		$this->builder(true)->limitToMediaType('image');

		$this->assertSame(['s.media_kind IN ([image,mixed])'], $this->where);
	}

	public function testUntilThenAVideoAndAMediaTypeKeepTheirFallback(): void {
		$this->builder(false)->limitToVideo();
		$this->builder(false)->limitToMediaType('image');

		$this->assertCount(2, $this->where);
		$this->assertStringContainsString('s.media_kind IS NULL', $this->where[0]);
		$this->assertStringContainsString('s.media_kind IS NULL', $this->where[1]);
	}
	public function testVisualMediaIncludesImagesVideosAndAnimationsButNotAudioAlone(): void {
		$this->builder(true)->limitToVisualMedia();
		$this->assertSame(['s.media_kind IN ([image,video,gifv,mixed])'], $this->where);
	}

	public function testVisualMediaKeepsTheLegacyAttachmentAndNativeVideoFallback(): void {
		$this->builder(false)->limitToVisualMedia();
		$this->assertCount(1, $this->where);
		$this->assertStringContainsString('s.media_kind IS NULL', $this->where[0]);
		$this->assertStringContainsString("s.subtype = 'Video'", $this->where[0]);
		foreach (['image', 'video', 'gifv'] as $type) {
			$this->assertStringContainsString('%"type":"' . $type . '"%', $this->where[0]);
		}
	}

}
