<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Service\QueueRetryService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * What `occ social:queue:retry --instance` and the Retry button select.
 *
 * The statements go through `IDBConnection`, which this suite cannot mock, so
 * the page read and the reset are doubled and what is checked is the walk
 * over the pages and the host matching above them.
 */
#[AllowMockObjectsWithoutExpectations]
class QueueRetryServiceTest extends TestCase {
	/** @var list<array<string, mixed>> the queue, in id order */
	private array $rows = [];
	/** @var list<int> the ids handed to the reset */
	private array $retried = [];

	private function service(): QueueRetryService|MockObject {
		$service = $this->getMockBuilder(QueueRetryService::class)
			->disableOriginalConstructor()
			->onlyMethods(['page', 'retry'])
			->getMock();
		$service->method('page')->willReturnCallback(
			fn (string $table, string $token, int $minTries, int $limit, int $after): array => array_slice(
				array_values(array_filter(
					$this->rows,
					static fn (array $row): bool => $row['id'] > $after && $row['tries'] >= $minTries
				)),
				0,
				$limit
			)
		);
		$service->method('retry')->willReturnCallback(function (string $table, array $ids): int {
			$this->assertSame(CoreRequestBuilder::TABLE_REQUEST_QUEUE, $table);
			$this->retried = $ids;

			return count($ids);
		});

		return $service;
	}

	private function row(int $id, string $host, int $tries = 3): array {
		return [
			'id' => $id, 'token' => 't' . $id, 'status' => 0, 'tries' => $tries,
			'instance' => json_encode(['uri' => 'https://' . $host . '/inbox']),
		];
	}

	public function testOnlyTheDeliveriesToThatInstanceAreQueuedAgain(): void {
		$this->rows = [
			$this->row(1, 'gone.example'),
			$this->row(2, 'other.example'),
			$this->row(3, 'Gone.example', 16),
			$this->row(4, 'gone.example', 0),
		];

		$result = $this->service()->retryInstance('GONE.example');

		$this->assertSame(['queued' => 2, 'limited' => false], $result);
		$this->assertSame([1, 3], $this->retried);
	}

	public function testItStopsAtTheLimitAndSaysSo(): void {
		$this->rows = [$this->row(1, 'gone.example'), $this->row(2, 'gone.example'), $this->row(3, 'gone.example')];

		$this->assertSame(['queued' => 2, 'limited' => true], $this->service()->retryInstance('gone.example', 2));
	}

	public function testNothingToRetryTouchesNothing(): void {
		$this->rows = [$this->row(1, 'other.example')];
		$service = $this->service();
		$service->expects($this->never())->method('retry');

		$this->assertSame(['queued' => 0, 'limited' => false], $service->retryInstance('gone.example'));
	}

	public function testARowThatNamesNoHostMatchesNone(): void {
		$this->assertSame('', QueueRetryService::hostOf(['instance' => 'not json']));
		$this->assertSame('gone.example', QueueRetryService::hostOf($this->row(1, 'Gone.Example')));
	}
}
