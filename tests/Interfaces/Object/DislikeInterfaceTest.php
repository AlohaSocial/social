<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Object;

use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Interfaces\Object\DislikeInterface;
use OCA\Social\Model\ActivityPub\Object\Dislike;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\SignatureService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * `Dislike`: PeerTube's other counter, which Mastodon has never had.
 *
 * It used to arrive as an activity with no model at all — logged as an unknown
 * type and dropped — so a video whose author cared about the number showed
 * none of them.
 */
#[AllowMockObjectsWithoutExpectations]
class DislikeInterfaceTest extends TestCase {
	private const POST = 'https://cloud.example/apps/social/@alice/1';
	private const CAROL = 'https://peertube.example/accounts/carol';

	private ActionsRequest|MockObject $actionsRequest;
	private StreamRequest|MockObject $streamRequest;
	private DislikeInterface $handler;

	protected function setUp(): void {
		$this->actionsRequest = $this->createMock(ActionsRequest::class);
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->handler = new DislikeInterface($this->actionsRequest, $this->streamRequest);
	}

	private function dislike(string $origin = 'peertube.example'): Dislike {
		$dislike = new Dislike();
		$dislike->setId('https://peertube.example/dislikes/1')
			->setActorId(self::CAROL)
			->setObjectId(self::POST);
		$dislike->setOrigin($origin, SignatureService::ORIGIN_HEADER, time());

		return $dislike;
	}

	public function testADislikeIsStoredAndCountedOntoThePost(): void {
		$this->actionsRequest->method('getActionFromItem')
			->willThrowException(new ActionDoesNotExistException());
		$this->actionsRequest->expects($this->once())->method('save');

		$post = new Note();
		$post->setId(self::POST);
		$this->streamRequest->method('getStreamById')->willReturn($post);
		$this->streamRequest->expects($this->once())->method('recount')
			->with($this->identicalTo($post), 'dislikes');
		$this->streamRequest->expects($this->never())->method('updateDetails');

		$this->handler->processIncomingRequest($this->dislike());
	}

	/** A redelivered dislike is the same dislike, not a second one. */
	public function testTheSameDislikeTwiceIsOneRow(): void {
		$this->actionsRequest->method('getActionFromItem')->willReturn($this->dislike());
		$this->actionsRequest->expects($this->never())->method('save');

		$this->handler->processIncomingRequest($this->dislike());
	}

	/** One server may not dislike on behalf of an account on another. */
	public function testADislikeFromTheWrongServerIsRefused(): void {
		$this->expectException(InvalidOriginException::class);

		$this->handler->processIncomingRequest($this->dislike(origin: 'stranger.example'));
	}

	public function testUndoingADislikeRecountsThePost(): void {
		$this->actionsRequest->expects($this->once())->method('delete');
		$post = new Note();
		$post->setId(self::POST);
		$this->streamRequest->method('getStreamById')->willReturn($post);
		$this->streamRequest->expects($this->once())->method('recount')
			->with($this->identicalTo($post), 'dislikes');

		$undo = new \OCA\Social\Model\ActivityPub\Activity\Undo();
		$undo->setOrigin('peertube.example', SignatureService::ORIGIN_HEADER, time());

		$this->handler->activity($undo, $this->dislike());
	}

	/**
	 * A like tells an author somebody liked them. A dislike arriving as a
	 * notification would be a way to needle somebody from anywhere, one
	 * activity at a time — so nothing here notifies, and there is no
	 * notification service to do it with.
	 */
	public function testADislikeNeverNotifies(): void {
		$this->assertSame(
			['actionsRequest', 'streamRequest'],
			array_map(
				static fn (\ReflectionParameter $p): string => $p->getName(),
				(new \ReflectionClass(DislikeInterface::class))->getConstructor()->getParameters()
			)
		);
	}
}
