<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\StoriesRequest;
use OCA\Social\Db\StoryInteractionsRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\StoryReaction as ApReaction;
use OCA\Social\Model\ActivityPub\Activity\StoryReply as ApReply;
use OCA\Social\Model\ActivityPub\Activity\View;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\Story;
use OCA\Social\Model\Client\StoryInteraction;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\NotificationService;
use OCA\Social\Service\SignatureService;
use OCA\Social\Service\StoryInteractionService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Answering a story: who may, how often, who is told, and what goes on the wire.
 */
#[AllowMockObjectsWithoutExpectations]
class StoryInteractionServiceTest extends TestCase {
	private const LOCAL = 'https://cloud.example/';
	private const ALICE = 'https://cloud.example/@alice';
	private const BOB = 'https://cloud.example/@bob';
	private const CAROL = 'https://pixelfed.example/users/carol';
	private const LOCAL_STORY = 'https://cloud.example/@alice/stories/7';
	private const REMOTE_STORY = 'https://pixelfed.example/stories/carol/9';

	private StoriesRequest|MockObject $storiesRequest;
	private StoryInteractionsRequest|MockObject $interactionsRequest;
	private FollowService|Stub $followService;
	private CacheActorService|Stub $cacheActorService;
	private NotificationService|MockObject $notificationService;
	private StoryInteractionService $service;

	/** @var ACore[] */
	private array $sent = [];

	protected function setUp(): void {
		parent::setUp();

		$this->storiesRequest = $this->createMock(StoriesRequest::class);
		$this->interactionsRequest = $this->createMock(StoryInteractionsRequest::class);
		$this->followService = $this->createStub(FollowService::class);
		$this->cacheActorService = $this->createStub(CacheActorService::class);
		$this->notificationService = $this->createMock(NotificationService::class);

		$activityService = $this->createStub(ActivityService::class);
		$activityService->method('request')->willReturnCallback(
			function (ACore $activity): string {
				$this->sent[] = $activity;

				return 'token';
			}
		);

		$configService = $this->createStub(ConfigService::class);
		$configService->method('getSocialUrl')->willReturn(self::LOCAL);

		$this->service = new StoryInteractionService(
			$this->storiesRequest,
			$this->interactionsRequest,
			$this->followService,
			$this->cacheActorService,
			$activityService,
			$this->createStub(SignatureService::class),
			$this->notificationService,
			$configService,
			new NullLogger(),
		);
	}

	private function person(string $id, string $inbox = ''): Person {
		$person = new Person();
		$person->setId($id);
		$person->setInbox(($inbox !== '') ? $inbox : $id . '/inbox');

		return $person;
	}

	private function story(string $owner, bool $local, string $sourceId): Story {
		$story = new Story();
		$story->setId(7)
			->setOwnerId($owner)
			->setLocal($local)
			->setSourceId($sourceId)
			->setExpiresAt(time() + 3600);

		return $story;
	}

	/** @param bool $following what the follow graph is told to say */
	private function following(bool $following): void {
		$this->followService->method('getLinksBetweenPersons')
			->willReturn(['following' => $following, 'followed_by' => false]);
	}

	public function testAFollowerMayReactAndTheAuthorIsTold(): void {
		$this->following(true);
		$this->storiesRequest->method('getLiveById')
			->willReturn($this->story(self::ALICE, true, self::LOCAL_STORY));
		$this->interactionsRequest->method('countByActor')->willReturn(0);
		$this->cacheActorService->method('getFromId')->willReturn($this->person(self::ALICE));

		$this->notificationService->expects($this->once())->method('onStoryInteraction');

		$answer = $this->service->answer($this->person(self::BOB), 7, StoryInteraction::TYPE_REACTION, '🔥');

		$this->assertSame('🔥', $answer->getContent());
		$this->assertSame(StoryInteraction::TYPE_REACTION, $answer->getType());
		$this->assertSame([], $this->sent, 'the author is here; there is nobody to send to');
	}

	/**
	 * Seeing a story and being able to answer it are the same permission, so
	 * a stranger gets the answer a stranger gets everywhere else about a
	 * story: there is no such thing.
	 */
	public function testSomebodyWhoDoesNotFollowCannotAnswer(): void {
		$this->following(false);
		$this->storiesRequest->method('getLiveById')
			->willReturn($this->story(self::ALICE, true, self::LOCAL_STORY));

		$this->interactionsRequest->expects($this->never())->method('save');
		$this->expectException(ItemNotFoundException::class);

		$this->service->answer($this->person(self::BOB), 7, StoryInteraction::TYPE_REACTION, '🔥');
	}

	/**
	 * Without a cap, what a story hands somebody is a private channel to its
	 * poster that the poster cannot close.
	 */
	public function testAnAccountMayOnlyAnswerOneStorySoManyTimes(): void {
		$this->following(true);
		$this->storiesRequest->method('getLiveById')
			->willReturn($this->story(self::ALICE, true, self::LOCAL_STORY));
		$this->cacheActorService->method('getFromId')->willReturn($this->person(self::ALICE));
		$this->interactionsRequest->method('countByActor')
			->willReturn(StoryInteraction::MAX_PER_ACTOR);

		$this->interactionsRequest->expects($this->never())->method('save');
		$this->expectException(InvalidResourceException::class);

		$this->service->answer($this->person(self::BOB), 7, StoryInteraction::TYPE_REACTION, '🔥');
	}

	public function testAnEmptyAnswerIsRefusedBeforeAnythingIsRead(): void {
		$this->storiesRequest->expects($this->never())->method('getLiveById');
		$this->expectException(InvalidResourceException::class);

		$this->service->answer($this->person(self::BOB), 7, StoryInteraction::TYPE_REPLY, "  \n ");
	}

	public function testAReplyLongerThanAReplyIsRefused(): void {
		$this->expectException(InvalidResourceException::class);

		$this->service->answer(
			$this->person(self::BOB), 7, StoryInteraction::TYPE_REPLY,
			str_repeat('a', StoryInteraction::MAX_REPLY_LENGTH + 1)
		);
	}

	/**
	 * The activity Pixelfed's inbox switches on, addressed to the author and
	 * nobody else — a story answer is a message to one person, not a fact
	 * about the story.
	 */
	public function testAnAnswerToARemoteStoryGoesToItsAuthorAsPixelfedsOwnVerb(): void {
		$this->following(true);
		$this->storiesRequest->method('getLiveById')
			->willReturn($this->story(self::CAROL, false, self::REMOTE_STORY));
		$this->interactionsRequest->method('countByActor')->willReturn(0);
		$this->cacheActorService->method('getFromId')
			->willReturn($this->person(self::CAROL, 'https://pixelfed.example/users/carol/inbox'));

		$this->notificationService->expects($this->never())->method('onStoryInteraction');

		$this->service->answer($this->person(self::BOB), 7, StoryInteraction::TYPE_REPLY, 'lovely light');

		$this->assertCount(1, $this->sent);
		$activity = $this->sent[0];
		$this->assertInstanceOf(ApReply::class, $activity);
		$this->assertSame('Story:Reply', $activity->getType());
		$this->assertSame(self::REMOTE_STORY, $activity->getStoryId());
		$this->assertSame('lovely light', $activity->getContent());
		$this->assertSame([self::CAROL], $activity->getToArray() ?: [$activity->getTo()]);

		// Pixelfed refuses an activity whose id is on another host than its
		// actor, so the id is minted under the account doing the answering
		$this->assertStringStartsWith(self::BOB, $activity->getId());
	}

	public function testAReactionUsesTheReactionVerb(): void {
		$this->following(true);
		$this->storiesRequest->method('getLiveById')
			->willReturn($this->story(self::CAROL, false, self::REMOTE_STORY));
		$this->interactionsRequest->method('countByActor')->willReturn(0);
		$this->cacheActorService->method('getFromId')->willReturn($this->person(self::CAROL));

		$this->service->answer($this->person(self::BOB), 7, StoryInteraction::TYPE_REACTION, '🔥');

		$this->assertInstanceOf(ApReaction::class, $this->sent[0]);
		$this->assertSame('Story:Reaction', $this->sent[0]->getType());
	}

	/** Watching a story on another server is invisible to its author without this. */
	public function testWatchingARemoteStorySendsAReceiptInPixelfedsShape(): void {
		$this->cacheActorService->method('getFromId')
			->willReturn($this->person(self::CAROL, 'https://pixelfed.example/users/carol/inbox'));

		$this->service->sendView(
			$this->person(self::BOB), $this->story(self::CAROL, false, self::REMOTE_STORY)
		);

		$this->assertCount(1, $this->sent);
		$activity = $this->sent[0];
		$this->assertInstanceOf(View::class, $activity);

		$payload = $activity->jsonSerialize();
		$this->assertSame('View', $payload['type']);
		$this->assertSame(
			['type' => 'Story', 'object' => self::REMOTE_STORY],
			$payload['object'],
			'the wrapper Pixelfed insists on, not the bare address'
		);
	}

	/** A story of this server's is counted here; there is nobody to tell. */
	public function testWatchingALocalStorySendsNothing(): void {
		$this->service->sendView(
			$this->person(self::BOB), $this->story(self::ALICE, true, self::LOCAL_STORY)
		);

		$this->assertSame([], $this->sent);
	}

	public function testAnArrivingViewMarksTheStorySeen(): void {
		$this->following(true);
		$this->storiesRequest->method('getBySourceId')
			->willReturn($this->story(self::ALICE, true, self::LOCAL_STORY));

		$this->storiesRequest->expects($this->once())->method('markSeen')
			->with(7, self::CAROL);

		$activity = new View();
		$activity->setActorId(self::CAROL);
		$activity->setStoryId(self::LOCAL_STORY);

		$this->service->receiveView($activity);
	}

	/**
	 * A `View` from somebody who does not follow the author is a claim, not a
	 * receipt: they could not have been shown the story in the first place.
	 */
	public function testAViewFromSomebodyWhoDoesNotFollowIsIgnored(): void {
		$this->following(false);
		$this->storiesRequest->method('getBySourceId')
			->willReturn($this->story(self::ALICE, true, self::LOCAL_STORY));

		$this->storiesRequest->expects($this->never())->method('markSeen');

		$activity = new View();
		$activity->setActorId(self::CAROL);
		$activity->setStoryId(self::LOCAL_STORY);

		$this->service->receiveView($activity);
	}

	/** A story on somebody else's server is not this instance's to mark. */
	public function testAViewNamingAnotherServersStoryIsIgnored(): void {
		$this->storiesRequest->expects($this->never())->method('getBySourceId');
		$this->storiesRequest->expects($this->never())->method('markSeen');

		$activity = new View();
		$activity->setActorId(self::CAROL);
		$activity->setStoryId(self::REMOTE_STORY);

		$this->service->receiveView($activity);
	}

	public function testAnArrivingReplyIsKeptAndTheAuthorIsTold(): void {
		$this->following(true);
		$this->storiesRequest->method('getBySourceId')
			->willReturn($this->story(self::ALICE, true, self::LOCAL_STORY));
		$this->interactionsRequest->method('countByActor')->willReturn(0);

		$kept = null;
		$this->interactionsRequest->method('save')->willReturnCallback(
			function (StoryInteraction $interaction) use (&$kept): bool {
				$kept = $interaction;

				return true;
			}
		);
		$this->notificationService->expects($this->once())->method('onStoryInteraction');

		$activity = new ApReply();
		$activity->setId('https://pixelfed.example/statuses/1');
		$activity->setActorId(self::CAROL);
		$activity->setStoryId(self::LOCAL_STORY);
		$activity->setContent('<p>lovely <b>light</b></p>');

		$this->service->receiveInteraction($activity);

		$this->assertSame(StoryInteraction::TYPE_REPLY, $kept->getType());
		$this->assertSame('lovely light', $kept->getContent(), 'text, not markup from another server');
		$this->assertSame(self::CAROL, $kept->getActorId());
	}

	/**
	 * An inbox is retried, and a reaction counted twice would be two
	 * reactions — so the author is told only when the row was actually new.
	 */
	public function testARedeliveredReactionTellsTheAuthorNothingASecondTime(): void {
		$this->following(true);
		$this->storiesRequest->method('getBySourceId')
			->willReturn($this->story(self::ALICE, true, self::LOCAL_STORY));
		$this->interactionsRequest->method('countByActor')->willReturn(0);
		$this->interactionsRequest->method('save')->willReturn(false);

		$this->notificationService->expects($this->never())->method('onStoryInteraction');

		$activity = new ApReaction();
		$activity->setId('https://pixelfed.example/statuses/1');
		$activity->setActorId(self::CAROL);
		$activity->setStoryId(self::LOCAL_STORY);
		$activity->setContent('🔥');

		$this->service->receiveInteraction($activity);
	}

	/** Who answered is the poster's business, like the view count. */
	public function testOnlyTheOwnerIsToldWhatWasSaidAboutAStory(): void {
		$this->storiesRequest->method('getLiveById')
			->willReturn($this->story(self::ALICE, true, self::LOCAL_STORY));

		$this->interactionsRequest->expects($this->never())->method('forStory');
		$this->expectException(ItemNotFoundException::class);

		$this->service->forStory($this->person(self::BOB), 7);
	}
}
