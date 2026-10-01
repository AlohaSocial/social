<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Cron\TranscodeBeforeDelivery;
use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Service\VideoDeliveryHold;
use OCA\Social\Service\VideoTranscodeService;
use OCA\Social\Service\VideoTranscodingWorker;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Which new posts wait for their video, and which do not.
 *
 * The second half matters as much as the first: every post without a video
 * still to convert — which is nearly all of them — goes out exactly as it
 * always did.
 */
#[AllowMockObjectsWithoutExpectations]
class VideoDeliveryHoldTest extends TestCase {
	private const POST = 'https://social.example/@alice/42';

	private CacheDocumentsRequest|MockObject $cacheDocumentsRequest;
	private VideoTranscodeService|MockObject $videoTranscodeService;
	private IJobList|MockObject $jobList;
	private VideoDeliveryHold $hold;

	/** @var array<string, Document> */
	private array $documents = [];

	protected function setUp(): void {
		parent::setUp();

		$this->documents = [];
		$this->cacheDocumentsRequest = $this->createMock(CacheDocumentsRequest::class);
		$this->cacheDocumentsRequest->method('getByNid')->willReturnCallback(
			function (int|string $nid): Document {
				return $this->documents[(string)$nid] ?? throw new CacheDocumentDoesNotExistException();
			}
		);
		$this->videoTranscodeService = $this->createMock(VideoTranscodeService::class);
		$this->videoTranscodeService->method('mayNeedConversion')->willReturnCallback(
			static fn (string $type): bool => in_array($type, ['video/quicktime', 'video/mp4'], true)
		);
		$this->jobList = $this->createMock(IJobList::class);

		$this->hold = new VideoDeliveryHold(
			$this->cacheDocumentsRequest,
			$this->videoTranscodeService,
			$this->jobList,
		);
	}

	private function stored(int $nid, string $type, int $transcoded = VideoTranscodingWorker::NOT_LOOKED): void {
		$document = new Document();
		$document->setNid($nid);
		$document->setMediaType($type);
		$document->setLocalCopy('uuid-' . $nid);
		$document->setTranscoded($transcoded);
		$this->documents[(string)$nid] = $document;
	}

	private function post(string ...$attachments): Note {
		$note = new Note();
		$note->setId(self::POST);
		$media = [];
		foreach ($attachments as $i => $type) {
			$media[] = (new MediaAttachment())->setId((string)($i + 1))->setType($type);
		}
		$note->setAttachments($media);

		return $note;
	}

	public function testAnUnconvertedMovHoldsThePostForTenMinutes(): void {
		$this->videoTranscodeService->method('isEnabled')->willReturn(true);
		$this->stored(1, 'video/quicktime');

		$before = time();
		$until = $this->hold->holdUntil($this->post('video'));

		$this->assertGreaterThanOrEqual($before + VideoDeliveryHold::HOLD_SECONDS, $until);
		$this->assertLessThanOrEqual(time() + VideoDeliveryHold::HOLD_SECONDS, $until);
		$this->assertSame(600, VideoDeliveryHold::HOLD_SECONDS);
	}

	/** Most posts: nothing is looked up, not even whether ffmpeg exists. */
	public function testAPostWithoutAVideoIsNotHeldAndCostsNothing(): void {
		$this->videoTranscodeService->expects($this->never())->method('isEnabled');
		$this->cacheDocumentsRequest->expects($this->never())->method('getByNid');

		$this->assertSame(0, $this->hold->holdUntil($this->post()));
		$this->assertSame(0, $this->hold->holdUntil($this->post('image', 'image')));
	}

	/** No ffmpeg, or the setting off: no hold at all. */
	public function testNothingIsHeldWhereNothingWillBeConverted(): void {
		$this->videoTranscodeService->method('isEnabled')->willReturn(false);
		$this->stored(1, 'video/quicktime');

		$this->cacheDocumentsRequest->expects($this->never())->method('getByNid');

		$this->assertSame(0, $this->hold->holdUntil($this->post('video')));
	}

	/**
	 * An H.264 MP4 is recorded as needing nothing when it is uploaded, and a
	 * post carrying it goes out at once.
	 */
	public function testAVideoThatNeedsNothingIsNotWaitedFor(): void {
		$this->videoTranscodeService->method('isEnabled')->willReturn(true);
		$this->stored(1, 'video/mp4', VideoTranscodingWorker::NOT_NEEDED);

		$this->assertSame(0, $this->hold->holdUntil($this->post('video')));
	}

	/** Nor one the sweep has already converted, or given up on. */
	public function testAVideoAlreadyThroughTheTranscoderIsNotWaitedFor(): void {
		$this->videoTranscodeService->method('isEnabled')->willReturn(true);
		$this->stored(1, 'video/mp4', VideoTranscodingWorker::CONVERTED);
		$this->stored(2, 'video/quicktime', VideoTranscodingWorker::FAILED);

		$this->assertSame(0, $this->hold->holdUntil($this->post('video', 'video')));
	}

	/** An HEVC MP4 is not known to be H.264 at upload, and is waited for. */
	public function testAnMp4NobodyHasLookedAtIsWaitedFor(): void {
		$this->videoTranscodeService->method('isEnabled')->willReturn(true);
		$this->stored(1, 'image/jpeg');
		$this->stored(2, 'video/mp4');

		$awaited = $this->hold->awaited($this->post('image', 'video'));

		$this->assertCount(1, $awaited);
		$this->assertSame(2, $awaited[0]->getNid());
	}

	public function testAVideoWhoseRowIsGoneIsNotWaitedFor(): void {
		$this->videoTranscodeService->method('isEnabled')->willReturn(true);

		$this->assertSame(0, $this->hold->holdUntil($this->post('video')));
	}

	/** The job names the post alone, so a second hold finds it already queued. */
	public function testTheConversionIsQueuedForThePost(): void {
		$this->jobList->expects($this->once())->method('add')
			->with(TranscodeBeforeDelivery::class, ['post' => self::POST]);

		$this->hold->convertSoon($this->post('video'));
	}
}
