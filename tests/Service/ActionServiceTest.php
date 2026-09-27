<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\ConversationsRequest;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\StreamAction;
use OCA\Social\Service\ActionService;
use OCA\Social\Service\BoostService;
use OCA\Social\Service\LikeService;
use OCA\Social\Service\PinService;
use OCA\Social\Service\StreamActionService;
use OCA\Social\Service\StreamService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ActionServiceTest extends TestCase {
	private const POST_ID = 'https://remote.example/notes/42';

	private StreamService|MockObject $streamService;
	private BoostService|MockObject $boostService;
	private LikeService|MockObject $likeService;
	private StreamActionService|MockObject $streamActionService;
	private PinService|MockObject $pinService;
	private ActionsRequest|MockObject $actionsRequest;
	private ConversationsRequest|MockObject $conversationsRequest;

	/** What rootOf() answers, or '' for "the post is its own root". */
	private string $threadRoot = '';
	/** @var array<int, array<string, mixed>> the mutes that were written */
	private array $mutes = [];
	private ActionService $service;
	private Person $actor;
	private Note $post;

	private \OCA\Social\Service\DislikeService|\PHPUnit\Framework\MockObject\MockObject $dislikeService;
	private \OCA\Social\Service\InterestService|\PHPUnit\Framework\MockObject\MockObject $interestService;

	protected function setUp(): void {
		$this->streamService = $this->createMock(StreamService::class);
		$this->boostService = $this->createMock(BoostService::class);
		$this->likeService = $this->createMock(LikeService::class);
		$this->streamActionService = $this->createMock(StreamActionService::class);
		$this->pinService = $this->createMock(PinService::class);
		$this->actionsRequest = $this->createMock(ActionsRequest::class);
		$this->conversationsRequest = $this->createMock(ConversationsRequest::class);
		$this->conversationsRequest->method('rootOf')->willReturnCallback(
			fn (string $statusId): string => $this->threadRoot ?: $statusId
		);
		$this->conversationsRequest->method('setMuted')->willReturnCallback(
			function (string $actorId, string $rootId, bool $muted): void {
				$this->mutes[] = compact('actorId', 'rootId', 'muted');
			}
		);

		$this->dislikeService = $this->createMock(\OCA\Social\Service\DislikeService::class);
		$this->interestService = $this->createMock(\OCA\Social\Service\InterestService::class);

		$this->service = new ActionService(
			$this->streamService,
			$this->boostService,
			$this->likeService,
			$this->streamActionService,
			$this->pinService,
			$this->actionsRequest,
			$this->conversationsRequest,
			$this->dislikeService,
			$this->interestService,
		);

		$this->actor = new Person();
		$this->actor->setId('https://cloud.example.com/apps/social/@alice');
		$this->post = new Note();
		$this->post->setId(self::POST_ID);
		$this->post->setNid(42);
	}

	public function testUnknownActionIsRejectedBeforeLoadingThePost(): void {
		$this->streamService->expects($this->never())->method('getStreamByNid');

		$this->expectException(InvalidActionException::class);
		$this->service->action($this->actor, 42, 'explode');
	}

	public function testFavouriteCreatesALike(): void {
		$this->streamService->expects($this->once())->method('getStreamByNid')->with(42)->willReturn($this->post);
		$this->likeService->expects($this->once())
			->method('create')
			->with($this->identicalTo($this->actor), self::POST_ID);
		$this->likeService->expects($this->never())->method('delete');
		$this->boostService->expects($this->never())->method('create');

		$this->assertNull($this->service->action($this->actor, 42, 'favourite'));
	}

	public function testUnfavouriteDeletesTheLike(): void {
		$this->streamService->method('getStreamByNid')->willReturn($this->post);
		$this->likeService->expects($this->once())
			->method('delete')
			->with($this->identicalTo($this->actor), self::POST_ID);
		$this->likeService->expects($this->never())->method('create');

		$this->assertNull($this->service->action($this->actor, 42, 'unfavourite'));
	}

	public function testReblogCreatesABoost(): void {
		$this->streamService->method('getStreamByNid')->willReturn($this->post);
		$this->boostService->expects($this->once())
			->method('create')
			->with($this->identicalTo($this->actor), self::POST_ID);
		$this->likeService->expects($this->never())->method('create');

		$this->assertNull($this->service->action($this->actor, 42, 'reblog'));
	}

	public function testUnreblogDeletesTheBoost(): void {
		$this->streamService->method('getStreamByNid')->willReturn($this->post);
		$this->boostService->expects($this->once())
			->method('delete')
			->with($this->identicalTo($this->actor), self::POST_ID);
		$this->boostService->expects($this->never())->method('create');

		$this->assertNull($this->service->action($this->actor, 42, 'unreblog'));
	}

	/**
	 * `translate` used to be one of these, and returned the post unchanged —
	 * which a client cannot tell from a translation. It is a route of its own
	 * now (`StatusApiController::statusTranslate()`), answering a Translation
	 * entity from a real provider, so asking for it here is asking for an
	 * action that does not exist.
	 */
	public function testTranslateIsNotAStatusAction(): void {
		$this->expectException(InvalidActionException::class);

		$this->service->action($this->actor, 42, 'translate');
	}

	/** @return array<string, array{string, bool}> */
	public function bookmarkActionProvider(): array {
		return [
			'bookmark' => ['bookmark', true],
			'unbookmark' => ['unbookmark', false],
		];
	}

	/** @dataProvider bookmarkActionProvider */
	public function testBookmarkTogglesTheLocalFlagAndFederatesNothing(string $action, bool $expected): void {
		$this->streamService->expects($this->once())->method('getStreamByNid')->willReturn($this->post);
		$this->likeService->expects($this->never())->method($this->anything());
		$this->boostService->expects($this->never())->method($this->anything());
		$this->streamActionService->expects($this->once())
			->method('setActionBool')
			->with($this->actor->getId(), $this->post->getId(), StreamAction::BOOKMARKED, $expected);

		$this->assertNull($this->service->action($this->actor, 42, $action));
	}

	/**
	 * Mastodon's conversation mute is about being *told*: the thread's posts
	 * stay on the timelines and only the notifications stop.
	 *
	 * @dataProvider muteActionProvider
	 */
	public function testMutingAConversationIsRecordedAgainstItsRoot(string $action, bool $muted): void {
		$this->streamService->method('getStreamByNid')->willReturn($this->post);
		$this->threadRoot = 'https://cloud.example.com/apps/social/@bob/the-root';
		$this->streamActionService->expects($this->never())->method($this->anything());

		$this->assertNull($this->service->action($this->actor, 42, $action));

		$this->assertSame([[
			'actorId' => $this->actor->getId(),
			'rootId' => 'https://cloud.example.com/apps/social/@bob/the-root',
			'muted' => $muted,
		]], $this->mutes);
	}

	/** @return array<string, array{string, bool}> */
	public function muteActionProvider(): array {
		return [
			'mute' => ['mute', true],
			'unmute' => ['unmute', false],
		];
	}

	/**
	 * Against the root, not the post: a reply that arrives tomorrow is covered
	 * by a mute taken today, which is the whole point of muting a conversation
	 * rather than a post.
	 */
	public function testAPostThatRepliesToNothingIsItsOwnRoot(): void {
		$this->streamService->method('getStreamByNid')->willReturn($this->post);
		$this->threadRoot = '';

		$this->service->action($this->actor, 42, 'mute');

		$this->assertSame($this->post->getId(), $this->mutes[0]['rootId']);
	}

	public function testPinAndUnpinAreHandedToThePinService(): void {
		$pinned = clone $this->post;
		$this->pinService->expects($this->once())->method('pin')
			->with($this->actor, 42)->willReturn($pinned->setPinned(true));

		$this->assertTrue($this->service->action($this->actor, 42, 'pin')->isPinned());

		$this->pinService->expects($this->once())->method('unpin')
			->with($this->actor, 42)->willReturn($this->post->setPinned(false));

		$this->assertFalse($this->service->action($this->actor, 42, 'unpin')->isPinned());
	}

	/**
	 * PeerTube publishes two counters and this app could only send one, so a
	 * video watched here was liked by its viewers and disliked by nobody —
	 * the count its author cares about was a count of everybody else.
	 */
	public function testDislikeAndUndislikeAreHandedToTheDislikeService(): void {
		$this->streamService->method('getStreamByNid')->willReturn($this->post);

		$this->dislikeService->expects($this->once())->method('create')
			->with($this->actor, self::POST_ID);
		$this->service->action($this->actor, 42, 'dislike');

		$this->dislikeService->expects($this->once())->method('delete')
			->with($this->actor, self::POST_ID);
		$this->service->action($this->actor, 42, 'undislike');
	}

	/**
	 * A like, a boost and a bookmark teach My interests from here, where
	 * every one of them passes, so an app's count as much as the web's; taking
	 * one back teaches nothing.
	 */
	public function testWhatTheReaderDoesToAPostTeachesTheirInterests(): void {
		$this->streamService->method('getStreamByNid')->willReturn($this->post);
		$learned = [];
		$this->interestService->method('recordAction')->willReturnCallback(
			function ($actor, $post, string $action) use (&$learned): void {
				$learned[] = $action;
			}
		);

		foreach (['favourite', 'reblog', 'bookmark', 'unfavourite', 'unreblog', 'unbookmark', 'mute'] as $action) {
			$this->service->action($this->actor, 42, $action);
		}

		$this->assertSame(['favourite', 'boost', 'bookmark'], $learned);
	}
}
