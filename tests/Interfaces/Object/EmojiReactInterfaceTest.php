<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Object;

use OCA\Social\Db\ReactionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\Object\EmojiReactInterface;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\EmojiReact;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\SignatureService;
use OCA\Social\Tests\Interfaces\ActivityPubTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;

require_once __DIR__ . '/../ActivityPubTestCase.php';

/**
 * Reactions arriving from a peer.
 *
 * The three things that matter: an activity a server had no business sending
 * is refused, a redelivery is not counted twice, and nothing a peer sends can
 * put text — or a picture from their server — into somebody's reaction bar.
 */
#[AllowMockObjectsWithoutExpectations]
class EmojiReactInterfaceTest extends ActivityPubTestCase {
	private const POST = self::LOCAL_URL . '/notes/1';

	/** @var ReactionsRequest&MockObject */
	private $reactionsRequest;
	/** @var StreamRequest&Stub */
	private $streamRequest;
	private EmojiReactInterface $handler;

	private Person $alice;
	private Person $bob;

	protected function setUp(): void {
		parent::setUp();

		$this->reactionsRequest = $this->createMock(ReactionsRequest::class);
		$this->streamRequest = $this->createStub(StreamRequest::class);
		$this->handler = new EmojiReactInterface($this->reactionsRequest, $this->streamRequest);

		$this->alice = $this->person(self::LOCAL_URL . '/users/alice', true);
		$this->bob = $this->person(self::REMOTE_URL . '/users/bob');
	}

	/** bob (remote) reacts to alice's post. */
	private function aReaction(string $emoji = '👍', string $origin = self::REMOTE_HOST): EmojiReact {
		$reaction = new EmojiReact();
		$reaction->setId(self::REMOTE_URL . '/reactions/1');
		$reaction->setActorId($this->bob->getId());
		$reaction->setObjectId(self::POST);
		$reaction->setContent($emoji);
		$reaction->setOrigin($origin, SignatureService::ORIGIN_HEADER, time());

		return $reaction;
	}

	private function thePostExists(): Note {
		$post = $this->note(self::POST, $this->alice->getId(), true);
		$this->streamRequest->method('getStreamById')->willReturn($post);

		return $post;
	}

	public function testAReactionIsStored(): void {
		$this->thePostExists();
		$this->reactionsRequest->expects($this->once())
			->method('save')
			->willReturn(true);

		$this->handler->processIncomingRequest($this->aReaction());
	}

	/**
	 * The Fediverse redelivers routinely. The unique index refuses the second
	 * copy, `save()` reports it, and the interface must treat that as the
	 * ordinary thing it is rather than letting it reach the inbox as a 5xx.
	 */
	public function testARedeliveredReactionIsNotAnError(): void {
		$this->thePostExists();
		$this->reactionsRequest->method('save')->willReturn(false);

		$this->handler->processIncomingRequest($this->aReaction());

		$this->addToAssertionCount(1);
	}

	/** A server may only speak for itself; this is what `checkOrigin` is for. */
	public function testAReactionFromTheWrongHostIsRefused(): void {
		$this->expectException(InvalidOriginException::class);

		$this->handler->processIncomingRequest($this->aReaction('👍', 'elsewhere.example'));
	}

	public function testAReactionToAPostThisInstanceDoesNotHoldIsDropped(): void {
		$this->streamRequest->method('getStreamById')
			->willThrowException(new StreamNotFoundException());
		$this->reactionsRequest->expects($this->never())->method('save');

		$this->handler->processIncomingRequest($this->aReaction());
	}

	/**
	 * A `:shortcode:` is what the Misskey family sends for a custom emoji, with
	 * the picture in a `tag` entry. Taking it would mean either drawing an
	 * image from their server inside the bar or showing a word nobody here can
	 * read; both are worse than dropping it.
	 */
	public function testACustomEmojiShortcodeIsDropped(): void {
		$this->thePostExists();
		$this->reactionsRequest->expects($this->never())->method('save');

		$this->handler->processIncomingRequest($this->aReaction(':blobcat:'));
	}

	public function testTextInAReactionIsDropped(): void {
		$this->thePostExists();
		$this->reactionsRequest->expects($this->never())->method('save');

		$this->handler->processIncomingRequest($this->aReaction('this is not an emoji'));
	}

	public function testAReactionWithNoEmojiAtAllIsDropped(): void {
		$this->thePostExists();
		$this->reactionsRequest->expects($this->never())->method('save');

		$this->handler->processIncomingRequest($this->aReaction(''));
	}

	public function testAnUndoRemovesTheReaction(): void {
		$reaction = $this->aReaction();
		$undo = new Undo();
		$undo->setOrigin(self::REMOTE_HOST, SignatureService::ORIGIN_HEADER, time());

		$this->reactionsRequest->expects($this->once())->method('delete')->with($reaction);

		$this->handler->activity($undo, $reaction);
	}

	public function testAnUndoFromTheWrongHostIsRefused(): void {
		$undo = new Undo();
		$undo->setOrigin('elsewhere.example', SignatureService::ORIGIN_HEADER, time());

		$this->expectException(InvalidOriginException::class);

		$this->handler->activity($undo, $this->aReaction());
	}

	/** Anything that is not an Undo leaves the reaction where it is. */
	public function testAnotherActivityDoesNotRemoveIt(): void {
		$this->reactionsRequest->expects($this->never())->method('delete');

		$this->handler->activity(new Create(), $this->aReaction());
	}

	public function testGetItemFindsTheStoredReaction(): void {
		$stored = $this->aReaction();
		$this->reactionsRequest->method('getReaction')
			->with($this->bob->getId(), self::POST, '👍')
			->willReturn($stored);

		$this->assertSame($stored, $this->handler->getItem($this->aReaction()));
	}

	public function testGetItemSaysSoWhenThereIsNone(): void {
		$this->reactionsRequest->method('getReaction')
			->willThrowException(new ActionDoesNotExistException());

		$this->expectException(ItemNotFoundException::class);

		$this->handler->getItem($this->aReaction());
	}

	public function testSaveRefusesOneAlreadyStored(): void {
		$this->thePostExists();
		$this->reactionsRequest->method('save')->willReturn(false);

		$this->expectException(ItemAlreadyExistsException::class);

		$this->handler->save($this->aReaction());
	}
}
