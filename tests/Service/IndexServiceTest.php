<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\AppInfo\Application;
use OCA\Social\Db\StreamDestRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Db\StreamTagsRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\IndexService;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[AllowMockObjectsWithoutExpectations]
class IndexServiceTest extends TestCase {
	private StreamRequest|MockObject $streamRequest;
	private StreamDestRequest|MockObject $streamDestRequest;
	private StreamTagsRequest|MockObject $streamTagsRequest;
	private IConfig|MockObject $config;
	private LoggerInterface|MockObject $logger;
	private IndexService $service;
	/** @var array<string, string> */
	private array $stored = [];
	/** @var list<string> the keys written, in order */
	private array $writes = [];

	protected function setUp(): void {
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->streamDestRequest = $this->createMock(StreamDestRequest::class);
		$this->streamTagsRequest = $this->createMock(StreamTagsRequest::class);
		$this->config = $this->createMock(IConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new IndexService(
			$this->streamRequest,
			$this->streamDestRequest,
			$this->streamTagsRequest,
			$this->config,
			$this->logger
		);
	}

	/**
	 * App values as a map, so a test can say what is stored and read back
	 * what the pass wrote.
	 *
	 * @param array<string, string> $values
	 */
	private function storedConfig(array $values = []): void {
		$this->stored = $values;
		$this->config->method('getAppValue')->willReturnCallback(
			function (string $app, string $key, $default = ''): string {
				$this->assertSame(Application::APP_ID, $app);

				return $this->stored[$key] ?? (string)$default;
			}
		);
		$this->config->method('setAppValue')->willReturnCallback(
			function (string $app, string $key, $value): void {
				$this->stored[$key] = (string)$value;
				$this->writes[] = $key;
			}
		);
		$this->config->method('deleteAppValue')->willReturnCallback(
			function (string $app, string $key): void {
				unset($this->stored[$key]);
			}
		);
	}

	/** @return list<array{nid: string, id_prim: string}> */
	private static function rows(int ...$nids): array {
		return array_map(static fn (int $nid): array => ['nid' => (string)$nid, 'id_prim' => 'p' . $nid], $nids);
	}

	public function testAPageIsHydratedInOneQueryAndTheCursorWrittenOnce(): void {
		$this->storedConfig();
		$rows = self::rows(100, 200);
		$first = $this->createStub(Stream::class);
		$second = $this->createStub(Stream::class);
		$this->streamRequest->expects($this->once())->method('getIndexChunk')
			->with('0', IndexService::CHUNK_SIZE)->willReturn($rows);
		$this->streamRequest->expects($this->once())->method('getIndexStreams')
			->with(['100', '200'])->willReturn(['100' => $first, '200' => $second]);
		$this->streamRequest->expects($this->never())->method('getStream');
		$this->streamDestRequest->expects($this->exactly(2))->method('generateStreamDest');
		$this->streamTagsRequest->expects($this->exactly(2))->method('generateStreamTags');

		$this->assertSame(2, $this->service->repairNextChunk());
		$this->assertSame('200', $this->stored['index_nid']);
		$this->assertSame(1, count(array_keys($this->writes, 'index_nid', true)));
	}

	public function testAShortPageReachesTheTipAndTheRepairIsDoneForGood(): void {
		$this->storedConfig();
		// once for both passes: the second one reads no streams at all
		$this->streamRequest->expects($this->once())->method('getIndexChunk')->willReturn(self::rows(100));
		$this->streamRequest->method('getIndexStreams')->willReturn(['100' => $this->createStub(Stream::class)]);

		$this->assertSame(1, $this->service->repairNextChunk());
		$this->assertSame('1', $this->stored['index_done']);

		$this->assertSame(0, $this->service->repairNextChunk());
	}

	public function testAFullPageIsNotTheTip(): void {
		$this->storedConfig();
		$rows = self::rows(...range(1, IndexService::CHUNK_SIZE));
		$this->streamRequest->method('getIndexChunk')->willReturn($rows);
		$streams = [];
		foreach ($rows as $row) {
			$streams[$row['nid']] = $this->createStub(Stream::class);
		}
		$this->streamRequest->method('getIndexStreams')->willReturn($streams);

		$this->assertSame(IndexService::CHUNK_SIZE, $this->service->repairNextChunk());
		$this->assertArrayNotHasKey('index_done', $this->stored);
		$this->assertSame((string)IndexService::CHUNK_SIZE, $this->stored['index_nid']);
	}

	public function testAnEmptyPageMarksTheRepairDoneAndLeavesTheCursorAlone(): void {
		$this->storedConfig(['index_nid' => '900']);
		$this->streamRequest->expects($this->once())->method('getIndexChunk')->with('900', 500)->willReturn([]);

		$this->assertSame(0, $this->service->repairNextChunk());
		$this->assertSame('900', $this->stored['index_nid']);
		$this->assertSame('1', $this->stored['index_done']);
		$this->assertNotContains('index_nid', $this->writes);
	}

	public function testAFailedStreamHoldsTheCursorBeforeItAndIsRetried(): void {
		$this->storedConfig(['index_nid' => '50']);
		$first = $this->createStub(Stream::class);
		$second = $this->createStub(Stream::class);
		$this->streamRequest->method('getIndexChunk')->willReturn(self::rows(100, 200, 300));
		$this->streamRequest->method('getIndexStreams')->willReturn(['100' => $first, '200' => $second, '300' => $first]);
		$this->streamTagsRequest->method('generateStreamTags')
			->willReturnCallback(static function (Stream $stream) use ($second): void {
				if ($stream === $second) {
					throw new RuntimeException('database temporarily unavailable');
				}
			});
		$this->logger->expects($this->once())->method('error')
			->with($this->stringContains('could not index stream 200'), $this->arrayHasKey('exception'));

		$this->assertSame(1, $this->service->repairNextChunk());
		$this->assertSame('100', $this->stored['index_nid']);
		$this->assertArrayNotHasKey('index_done', $this->stored, 'a held cursor is not the tip');
	}

	public function testAStreamThatKeepsFailingIsSkippedAfterMaxFailures(): void {
		$this->storedConfig(['index_nid' => '100']);
		$poison = $this->createStub(Stream::class);
		$next = $this->createStub(Stream::class);
		$this->streamRequest->method('getIndexChunk')->willReturn(self::rows(200, 300));
		$this->streamRequest->method('getIndexStreams')->willReturn(['200' => $poison, '300' => $next]);
		$this->streamDestRequest->method('generateStreamDest')
			->willReturnCallback(static function (Stream $stream) use ($poison): void {
				if ($stream === $poison) {
					throw new RuntimeException('corrupt row');
				}
			});

		for ($pass = 1; $pass < IndexService::MAX_FAILURES; $pass++) {
			$this->assertSame(0, $this->service->repairNextChunk());
			$this->assertSame('100', $this->stored['index_nid'], 'pass ' . $pass);
		}

		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('skipping stream 200'));
		$this->assertSame(1, $this->service->repairNextChunk());
		$this->assertSame('300', $this->stored['index_nid']);
		$this->assertArrayNotHasKey('index_failing', $this->stored);
	}

	public function testAPageThatCannotBeHydratedIsReadOneStreamAtATime(): void {
		$this->storedConfig();
		$this->streamRequest->method('getIndexChunk')->willReturn(self::rows(100, 200));
		$this->streamRequest->method('getIndexStreams')->willThrowException(new RuntimeException('bad row'));
		$this->streamRequest->expects($this->exactly(2))->method('getStream')
			->willReturnCallback(function (string $prim): Stream {
				if ($prim === 'p200') {
					throw new StreamNotFoundException();
				}

				return $this->createStub(Stream::class);
			});
		$this->streamDestRequest->expects($this->once())->method('generateStreamDest');

		// a stream deleted since the page was read is passed, not held
		$this->assertSame(1, $this->service->repairNextChunk());
		$this->assertSame('200', $this->stored['index_nid']);
	}
}
