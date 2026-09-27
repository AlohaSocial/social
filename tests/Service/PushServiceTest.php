<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\StreamDetails;
use OCA\Social\Service\DetailsService;
use OCA\Social\Service\PushService;
use OCA\Social\Service\StreamService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A PushService whose notify_push queue is a local spy: what production
 * resolves from the notify_push app when installed.
 */
class TestablePushService extends PushService {
	public ?object $testQueue = null;

	protected function getQueue(): ?object {
		return $this->testQueue;
	}
}

#[AllowMockObjectsWithoutExpectations]
class PushServiceTest extends TestCase {
	private DetailsService|MockObject $detailsService;
	private StreamService|MockObject $streamService;
	private TestablePushService $service;
	/** @var array<int, array{string, array}> */
	private array $pushed = [];

	protected function setUp(): void {
		$this->detailsService = $this->createMock(DetailsService::class);
		$this->streamService = $this->createMock(StreamService::class);
		$this->service = new TestablePushService(
			$this->detailsService, $this->streamService, new NullLogger()
		);

		$pushed = &$this->pushed;
		$this->service->testQueue = new class($pushed) {
			/** @param array<int, array{string, array}> $pushed */
			public function __construct(
				private array &$pushed,
			) {
			}

			public function push(string $channel, array $payload): void {
				$this->pushed[] = [$channel, $payload];
			}
		};
	}

	private function person(string $userId, bool $local = true): Person {
		$person = new Person();
		$person->setId('https://cloud.example/@' . ($userId ?: 'remote'));
		$person->setUserId($userId);
		$person->setLocal($local);

		return $person;
	}

	private function withDetails(array $home, array $direct = []): void {
		$details = new StreamDetails(new Note());
		foreach ($home as $viewer) {
			$details->addHomeViewer($viewer);
		}
		foreach ($direct as $viewer) {
			$details->addDirectViewer($viewer);
		}
		$this->streamService->method('getStreamById')->willReturn(new Note());
		$this->detailsService->method('generateDetailsFromStream')->willReturn($details);
	}

	public function testEveryLocalViewerIsNotifiedOnce(): void {
		$alice = $this->person('alice');
		$this->withDetails([$alice, $this->person('bob')], [$alice, $this->person('', false)]);

		$this->service->onNewStream('https://remote.example/notes/1');

		$this->assertSame([
			['notify_custom', ['user' => 'alice', 'message' => 'social_timeline']],
			['notify_custom', ['user' => 'bob', 'message' => 'social_timeline']],
		], $this->pushed, 'alice once despite home+direct, the remote viewer never');
	}

	public function testWithoutNotifyPushNothingHappens(): void {
		$this->service->testQueue = null;
		$this->streamService->expects($this->never())->method('getStreamById');

		$this->service->onNewStream('https://remote.example/notes/1');

		$this->assertSame([], $this->pushed);
	}

	public function testAMissingStreamIsANoOp(): void {
		$this->streamService->method('getStreamById')
			->willThrowException(new StreamNotFoundException());
		$this->detailsService->expects($this->never())->method('generateDetailsFromStream');

		$this->service->onNewStream('gone');

		$this->assertSame([], $this->pushed);
	}
}
