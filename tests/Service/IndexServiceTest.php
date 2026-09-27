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

	public function testRepairsOneBoundedChunkAndPersistsTheCursorAfterEachCompleteStream(): void {
		$first = $this->createStub(Stream::class);
		$second = $this->createStub(Stream::class);
		$this->config->expects($this->once())->method('getAppValue')
			->with(Application::APP_ID, 'index_nid', '0')->willReturn('0');
		$this->streamRequest->expects($this->once())->method('getIndexChunk')
			->with('0', IndexService::CHUNK_SIZE)
			->willReturn([
				['nid' => '100', 'id_prim' => 'first'],
				['nid' => '200', 'id_prim' => 'second'],
			]);
		$this->streamRequest->expects($this->exactly(2))->method('getStream')
			->willReturnOnConsecutiveCalls($first, $second);
		$this->streamDestRequest->expects($this->exactly(2))->method('generateStreamDest');
		$this->streamTagsRequest->expects($this->exactly(2))->method('generateStreamTags');
		$this->config->expects($this->exactly(2))->method('setAppValue')
			->willReturnCallback(function (string $app, string $key, string $cursor): void {
				$this->assertSame(Application::APP_ID, $app);
				$this->assertSame('index_nid', $key);
				$this->assertContains($cursor, ['100', '200']);
			});

		$this->assertSame(2, $this->service->repairNextChunk());
	}

	public function testFailedStreamIsRetriedWithoutMovingTheCursorPastItsNid(): void {
		$first = $this->createStub(Stream::class);
		$second = $this->createStub(Stream::class);
		$this->config->method('getAppValue')->willReturn('50');
		$this->streamRequest->method('getIndexChunk')->willReturn([
			['nid' => '100', 'id_prim' => 'first'],
			['nid' => '200', 'id_prim' => 'second'],
		]);
		$this->streamRequest->method('getStream')->willReturnOnConsecutiveCalls($first, $second);
		$this->streamDestRequest->expects($this->exactly(2))->method('generateStreamDest');
		$this->streamTagsRequest->expects($this->exactly(2))->method('generateStreamTags')
			->willReturnCallback(static function (Stream $stream) use ($second): void {
				if ($stream === $second) {
					throw new RuntimeException('database temporarily unavailable');
				}
			});
		$this->config->expects($this->once())->method('setAppValue')
			->with(Application::APP_ID, 'index_nid', '100');
		$this->logger->expects($this->once())->method('error')
			->with($this->stringContains('could not index stream 200'), $this->arrayHasKey('exception'));

		$this->assertSame(1, $this->service->repairNextChunk());
	}

	public function testAnEmptyChunkLeavesTheStoredCursorAlone(): void {
		$this->config->expects($this->once())->method('getAppValue')
			->with(Application::APP_ID, 'index_nid', '0')->willReturn('900');
		$this->streamRequest->expects($this->once())->method('getIndexChunk')->with('900', 500)->willReturn([]);
		$this->config->expects($this->never())->method('setAppValue');

		$this->assertSame(0, $this->service->repairNextChunk());
	}
}
