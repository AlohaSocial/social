<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCA\Social\Cron\SearchIndex;
use OCA\Social\Db\SearchTermsRequest;
use OCA\Social\Service\ConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The job that adds the posts stored before the word index to it: a bounded
 * run from a kept cursor, re-queued until it reaches the newest post, and
 * then never again.
 */
#[AllowMockObjectsWithoutExpectations]
class SearchIndexTest extends TestCase {
	private SearchTermsRequest&MockObject $terms;
	private IJobList&MockObject $jobList;
	/** @var array<string, string> the app settings */
	private array $settings = [];
	private int $now = 1_760_000_000;

	private function job(bool $ready = false): SearchIndex {
		$this->terms = $this->createMock(SearchTermsRequest::class);
		$this->terms->method('isReady')->willReturn($ready);
		$this->jobList = $this->createMock(IJobList::class);

		$config = $this->createMock(ConfigService::class);
		$config->method('getAppValue')->willReturnCallback(fn (string $key): string => $this->settings[$key] ?? '');
		$config->method('setAppValue')->willReturnCallback(function (string $key, $value): void {
			$this->settings[$key] = (string)$value;
		});

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return new SearchIndex($time, $this->terms, $config, $this->jobList, new NullLogger());
	}

	private function runJob(SearchIndex $job): void {
		(new \ReflectionMethod(SearchIndex::class, 'run'))->invoke($job, null);
	}

	public function testItWalksFromTheKeptCursorAndStopsAtTheNewestPost(): void {
		$this->settings[SearchTermsRequest::CURSOR] = '100';
		$job = $this->job();
		$this->terms->expects($this->exactly(2))->method('backfill')->willReturnCallback(
			static fn (string $after, int $limit): array => ($after === '100')
				? ['last' => '600', 'read' => $limit]
				: ['last' => '650', 'read' => 50]
		);
		$this->jobList->expects($this->never())->method('add');

		$this->runJob($job);

		$this->assertSame('650', $this->settings[SearchTermsRequest::CURSOR]);
		$this->assertSame('1', $this->settings[SearchTermsRequest::READY]);
	}

	public function testARunThatRunsOutOfTimeKeepsItsPlaceAndComesBack(): void {
		$job = $this->job();
		$this->terms->expects($this->once())->method('backfill')->with('0')->willReturnCallback(
			function (string $after, int $limit): array {
				$this->now += SearchIndex::SECONDS_PER_RUN;

				return ['last' => '500', 'read' => $limit];
			}
		);
		$this->jobList->expects($this->once())->method('add')->with(SearchIndex::class);

		$this->runJob($job);

		$this->assertSame('500', $this->settings[SearchTermsRequest::CURSOR]);
		$this->assertArrayNotHasKey(SearchTermsRequest::READY, $this->settings);
	}

	public function testAFailedRunKeepsTheLastFinishedChunkAndComesBack(): void {
		$job = $this->job();
		$this->terms->method('backfill')->willReturnCallback(static function (string $after, int $limit): array {
			if ($after === '0') {
				return ['last' => '500', 'read' => $limit];
			}

			throw new RuntimeException('the database went away');
		});
		$this->jobList->expects($this->once())->method('add')->with(SearchIndex::class);

		$this->runJob($job);

		$this->assertSame('500', $this->settings[SearchTermsRequest::CURSOR]);
		$this->assertArrayNotHasKey(SearchTermsRequest::READY, $this->settings);
	}

	public function testOnceTheIndexIsCompleteItDoesNothing(): void {
		$job = $this->job(true);
		$this->terms->expects($this->never())->method('backfill');
		$this->jobList->expects($this->never())->method('add');

		$this->runJob($job);
	}
}
