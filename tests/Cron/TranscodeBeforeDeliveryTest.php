<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCA\Social\Cron\TranscodeBeforeDelivery;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\VideoDeliveryHold;
use OCA\Social\Service\VideoTranscodeService;
use OCA\Social\Service\VideoTranscodingWorker;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The job a held video post waits on: convert, then let the post go —
 * whatever the conversion came to.
 */
#[AllowMockObjectsWithoutExpectations]
class TranscodeBeforeDeliveryTest extends TestCase {
	private const POST = 'https://social.example/@alice/42';

	private StreamRequest|MockObject $streamRequest;
	private VideoDeliveryHold|MockObject $hold;
	private VideoTranscodeService|MockObject $videoTranscodeService;
	private VideoTranscodingWorker|MockObject $worker;
	private ActivityService|MockObject $activityService;
	private TranscodeBeforeDelivery $job;

	protected function setUp(): void {
		parent::setUp();

		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->hold = $this->createMock(VideoDeliveryHold::class);
		$this->videoTranscodeService = $this->createMock(VideoTranscodeService::class);
		$this->worker = $this->createMock(VideoTranscodingWorker::class);
		$this->activityService = $this->createMock(ActivityService::class);

		$this->job = new TranscodeBeforeDelivery(
			$this->createStub(ITimeFactory::class),
			$this->streamRequest,
			$this->hold,
			$this->videoTranscodeService,
			$this->worker,
			$this->activityService,
			new NullLogger(),
		);
	}

	private function runJob(mixed $argument): void {
		(new \ReflectionMethod(TranscodeBeforeDelivery::class, 'run'))->invoke($this->job, $argument);
	}

	private function mov(): Document {
		$document = new Document();
		$document->setNid(5);
		$document->setMediaType('video/quicktime');

		return $document;
	}

	/** Converted first, released second: the release reads the converted post. */
	public function testTheVideoIsConvertedAndThenThePostIsReleased(): void {
		$post = new Note();
		$post->setId(self::POST);
		$mov = $this->mov();
		$this->videoTranscodeService->method('isEnabled')->willReturn(true);
		$this->streamRequest->method('getStreamById')->with(self::POST)->willReturn($post);
		$this->hold->method('awaited')->with($this->identicalTo($post))->willReturn([$mov]);

		$order = [];
		$this->worker->expects($this->once())->method('convert')->with($this->identicalTo($mov))
			->willReturnCallback(static function () use (&$order): bool {
				$order[] = 'converted';

				return true;
			});
		$this->activityService->expects($this->once())->method('releaseHeld')->with(self::POST)
			->willReturnCallback(static function () use (&$order): int {
				$order[] = 'released';

				return 3;
			});

		$this->runJob(['post' => self::POST]);

		$this->assertSame(['converted', 'released'], $order);
	}

	/** Switched off in the meantime: nothing is converted, and the post still goes. */
	public function testAPostIsReleasedEvenWhenConversionWasSwitchedOff(): void {
		$this->videoTranscodeService->method('isEnabled')->willReturn(false);
		$this->worker->expects($this->never())->method('convert');
		$this->activityService->expects($this->once())->method('releaseHeld')->with(self::POST);

		$this->runJob(['post' => self::POST]);
	}

	/** A conversion that blew up does not keep the post from going out. */
	public function testAPostIsReleasedEvenWhenTheConversionFailed(): void {
		$post = new Note();
		$this->videoTranscodeService->method('isEnabled')->willReturn(true);
		$this->streamRequest->method('getStreamById')->willReturn($post);
		$this->hold->method('awaited')->willReturn([$this->mov()]);
		$this->worker->method('convert')->willThrowException(new \RuntimeException('disk full'));
		$this->activityService->expects($this->once())->method('releaseHeld')->with(self::POST);

		$this->runJob(['post' => self::POST]);
	}

	/** Deleted before the job ran: the release is what drops the held rows. */
	public function testAPostThatIsGoneIsStillHandedToTheRelease(): void {
		$this->videoTranscodeService->method('isEnabled')->willReturn(true);
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$this->activityService->expects($this->once())->method('releaseHeld')->with(self::POST);

		$this->runJob(['post' => self::POST]);
	}

	public function testAJobWithoutAPostDoesNothing(): void {
		$this->activityService->expects($this->never())->method('releaseHeld');

		$this->runJob([]);
		$this->runJob(null);
	}
}
