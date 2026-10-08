<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Details;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\RemoteCountService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What the refresh of a remote post's counts writes: the origin's halves into
 * `details`, and the totals through the recount that adds this instance's
 * own share inside the statement that stores them.
 */
#[AllowMockObjectsWithoutExpectations]
class RemoteCountServiceTest extends TestCase {
	private const POST = 'https://remote.example/notes/1';

	public function testTheTotalsAreRecountedNotWrittenAsCountedHere(): void {
		$post = new Note();
		$post->setId(self::POST);

		$streamRequest = $this->createMock(StreamRequest::class);
		$streamRequest->method('getRemoteStreamsDueForCounts')->willReturn([$post]);
		$streamRequest->method('getStreamById')->willReturn($post);
		$streamRequest->method('countRepliesTo')->willReturn(1);
		$actionsRequest = $this->createMock(ActionsRequest::class);
		$actionsRequest->method('countActions')->willReturn(2);
		$curl = $this->createMock(CurlService::class);
		$curl->method('retrieveObjectsMany')->willReturn([self::POST => [
			'id' => self::POST,
			'likes' => ['totalItems' => 10],
			'shares' => ['totalItems' => 4],
			'replies' => ['totalItems' => 3],
		]]);

		$calls = [];
		$streamRequest->expects($this->once())->method('updateDetails')
			->willReturnCallback(function () use (&$calls, $post): void {
				$calls[] = 'details';
				$this->assertSame(8, $post->getDetailInt(Details::REMOTE_LIKES));
				$this->assertSame(2, $post->getDetailInt(Details::REMOTE_BOOSTS));
				$this->assertSame(2, $post->getDetailInt(Details::REMOTE_REPLIES));
			});
		$streamRequest->expects($this->once())->method('recount')
			->with($this->identicalTo($post), Details::LIKES, Details::BOOSTS, Details::REPLIES)
			->willReturnCallback(function () use (&$calls): void {
				$calls[] = 'recount';
			});

		$service = new RemoteCountService(
			$streamRequest, $actionsRequest, $curl,
			$this->createStub(ITimeFactory::class), $this->createStub(LoggerInterface::class)
		);
		$this->assertSame(['asked' => 1, 'answered' => 1], $service->refresh(true));

		// the halves are stored before the recount that adds to them
		$this->assertSame(['details', 'recount'], $calls);
	}
}
