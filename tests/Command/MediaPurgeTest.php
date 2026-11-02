<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Command;

use OCA\Social\Command\MediaPurge;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Service\MediaPurgeService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * What `occ social:media:purge` asks the service for, and what it says.
 * The purge itself is `MediaCachePurgeTest`'s.
 */
#[AllowMockObjectsWithoutExpectations]
class MediaPurgeTest extends TestCase {
	private MediaPurgeService|MockObject $service;
	private CommandTester $tester;

	protected function setUp(): void {
		$this->service = $this->createMock(MediaPurgeService::class);
		$this->tester = new CommandTester(new MediaPurge($this->service));
	}

	/** @return array<string, mixed> */
	private function purged(array $overrides = []): array {
		return $overrides + [
			'dry_run' => false,
			'complete' => true,
			'attachments' => ['files' => 4, 'bytes' => 3 * 1024 * 1024],
			'avatars' => ['files' => 2, 'bytes' => 2048],
			'files' => 6,
			'bytes' => 3 * 1024 * 1024 + 2048,
			'gone' => 0,
			'cache_bytes' => null,
			'remaining_bytes' => null,
		];
	}

	public function testWithoutLimitsItAppliesTheConfiguredOnes(): void {
		$this->service->expects($this->never())->method('purgeCache');
		$this->service->expects($this->once())->method('purgeCacheAsConfigured')->with(false)
			->willReturn($this->purged());

		$this->assertSame(0, $this->tester->execute([]));
		$this->assertStringContainsString('Deleted 6 file(s), 3.0 MiB', $this->tester->getDisplay());
		$this->assertStringContainsString('avatars and headers', $this->tester->getDisplay());
	}

	public function testWithBothSettingsOffItSaysHowToAskAnyway(): void {
		$this->service->method('purgeCacheAsConfigured')->willReturn(null);

		$this->assertSame(0, $this->tester->execute([]));
		$this->assertStringContainsString('--older-than, --max-size or --all', $this->tester->getDisplay());
	}

	public function testTheGivenLimitsAreTheOnlyOnesApplied(): void {
		$this->service->expects($this->never())->method('purgeCacheAsConfigured');
		$this->service->expects($this->once())->method('purgeCache')
			->with(14, 5 * 1024 * 1024 * 1024, false, true)
			->willReturn($this->purged(['dry_run' => true, 'cache_bytes' => 9, 'remaining_bytes' => 3]));

		$this->assertSame(0, $this->tester->execute([
			'--older-than' => '14', '--max-size' => '5G', '--dry-run' => true,
		]));
		$this->assertStringContainsString('Would delete 6 file(s)', $this->tester->getDisplay());
		$this->assertStringContainsString('Cache before: 9 B, after: 3 B', $this->tester->getDisplay());
	}

	public function testAllEmptiesTheCache(): void {
		$this->service->expects($this->once())->method('purgeCache')->with(0, 0, true, false)
			->willReturn($this->purged());

		$this->assertSame(0, $this->tester->execute(['--all' => true]));
	}

	public function testAllDoesNotCombineWithALimit(): void {
		$this->service->expects($this->never())->method('purgeCache');

		$this->assertSame(1, $this->tester->execute(['--all' => true, '--older-than' => '3']));
	}

	public function testRowsAlreadyGoneAreReported(): void {
		$this->service->method('purgeCacheAsConfigured')->willReturn($this->purged(['gone' => 25829]));

		$this->tester->execute([]);

		$this->assertStringContainsString('25829 cached cop(y/ies) named in the database were already gone', $this->tester->getDisplay());
	}

	public function testJsonCarriesTheWholeResult(): void {
		$this->service->method('purgeCacheAsConfigured')->willReturn($this->purged());

		$this->tester->execute(['--output' => 'json']);

		$json = json_decode($this->tester->getDisplay(), true);
		$this->assertSame(6, $json['files']);
		$this->assertSame(['files' => 2, 'bytes' => 2048], $json['avatars']);
	}

	public function testAnUnconfiguredAppIsAnErrorRatherThanAGuess(): void {
		$this->service->method('purgeCacheAsConfigured')->willThrowException(new SocialAppConfigException('no cloud url'));

		$this->assertSame(1, $this->tester->execute([]));
	}

	#[DataProvider('badDays')]
	public function testOlderThanTakesWholeDays(string $days): void {
		$this->service->expects($this->never())->method('purgeCache');

		$this->assertSame(1, $this->tester->execute(['--older-than' => $days]));
	}

	/** @return array<string, array{string}> */
	public static function badDays(): array {
		return ['zero' => ['0'], 'negative' => ['-3'], 'fraction' => ['1.5'], 'word' => ['week']];
	}

	/** @return array<string, array{string, ?int}> */
	public static function sizes(): array {
		return [
			'bytes' => ['1024', 1024],
			'kilo' => ['10K', 10 * 1024],
			'mega' => ['500M', 500 * 1024 * 1024],
			'giga' => ['5G', 5 * 1024 * 1024 * 1024],
			'giga with B' => ['5GB', 5 * 1024 * 1024 * 1024],
			'gibi' => ['5GiB', 5 * 1024 * 1024 * 1024],
			'fraction' => ['1.5G', (int)(1.5 * 1024 * 1024 * 1024)],
			'lower case' => ['2g', 2 * 1024 * 1024 * 1024],
			'zero' => ['0', null],
			'negative' => ['-5G', null],
			'word' => ['lots', null],
			'unit only' => ['G', null],
		];
	}

	#[DataProvider('sizes')]
	public function testASizeIsReadTheWayAnAdministratorWritesIt(string $size, ?int $bytes): void {
		$this->assertSame($bytes, MediaPurge::parseSize($size));
	}
}
