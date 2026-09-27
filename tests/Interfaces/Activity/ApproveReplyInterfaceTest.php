<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Activity;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\Activity\ApproveReplyInterface;
use OCA\Social\Model\ActivityPub\Activity\ApproveReply;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\SignatureService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * "Your reply may be shown": FEP-5624, as PeerTube ≥ 6.2 sends it.
 *
 * All it may do is move a state on a reply of ours. What is held still is who
 * is allowed to say it — anybody else would be a stranger approving a reply on
 * somebody else's post.
 */
#[AllowMockObjectsWithoutExpectations]
class ApproveReplyInterfaceTest extends TestCase {
	private const REPLY = 'https://cloud.example/apps/social/@alice/9';
	private const VIDEO = 'https://peertube.example/videos/watch/6f4c1e1a';

	private StreamRequest|MockObject $streamRequest;
	private ApproveReplyInterface $handler;

	protected function setUp(): void {
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->handler = new ApproveReplyInterface($this->streamRequest, new NullLogger());
	}

	private function reply(bool $local = true, string $inReplyTo = self::VIDEO): Note {
		$note = new Note();
		$note->setId(self::REPLY)->setLocal($local)->setInReplyTo($inReplyTo);
		$note->setReplyState(Stream::REPLY_PENDING);

		return $note;
	}

	private function approval(string $origin = 'peertube.example', string $objectId = self::REPLY): ApproveReply {
		$activity = new ApproveReply();
		$activity->setId('https://peertube.example/approvals/1');
		$activity->setObjectId($objectId);
		$activity->setOrigin($origin, SignatureService::ORIGIN_HEADER, time());

		return $activity;
	}

	public function testAnApprovalMovesOurReplyToApproved(): void {
		$reply = $this->reply();
		$this->streamRequest->method('getStreamById')->with(self::REPLY)->willReturn($reply);
		$this->streamRequest->expects($this->once())->method('updateDetails');

		$this->handler->processIncomingRequest($this->approval());

		$this->assertSame(Stream::REPLY_APPROVED, $reply->getReplyState());
	}

	/**
	 * The approval has to come from the server that holds the post being
	 * replied to; anybody else saying so is a stranger deciding what has been
	 * approved on somebody else's post.
	 */
	public function testAnApprovalFromSomewhereElseIsRefused(): void {
		$this->streamRequest->method('getStreamById')->willReturn($this->reply());

		$this->expectException(InvalidOriginException::class);

		$this->handler->processIncomingRequest($this->approval(origin: 'stranger.example'));
	}

	public function testAnApprovalAboutSomethingWeNeverWroteIsIgnored(): void {
		$this->streamRequest->method('getStreamById')
			->willThrowException(new StreamNotFoundException());
		$this->streamRequest->expects($this->never())->method('updateDetails');

		$this->handler->processIncomingRequest($this->approval());
	}

	/** A remote post is somebody else's document; its state is not ours to move. */
	public function testAnApprovalAboutARemotePostIsIgnored(): void {
		$this->streamRequest->method('getStreamById')->willReturn($this->reply(local: false));
		$this->streamRequest->expects($this->never())->method('updateDetails');

		$this->handler->processIncomingRequest($this->approval());
	}

	public function testAnApprovalAboutAPostThatIsNotAReplyIsIgnored(): void {
		$this->streamRequest->method('getStreamById')->willReturn($this->reply(inReplyTo: ''));
		$this->streamRequest->expects($this->never())->method('updateDetails');

		$this->handler->processIncomingRequest($this->approval());
	}

	public function testAnApprovalNamingNothingIsIgnored(): void {
		$this->streamRequest->expects($this->never())->method('getStreamById');

		$this->handler->processIncomingRequest($this->approval(objectId: ''));
	}
}
