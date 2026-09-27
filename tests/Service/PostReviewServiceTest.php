<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\PostHoldsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\HeldPost;
use OCA\Social\Model\Post;
use OCA\Social\Model\Strike;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\ModerationService;
use OCA\Social\Service\PostReviewService;
use OCA\Social\Service\PostService;
use OCA\Social\Service\StatusAssemblyService;
use OCA\Social\Service\StrikeService;
use OCP\IGroupManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * What is held, what is not, and what happens to it afterwards.
 *
 * The rules are the interesting half. Every one of them decides whether a
 * person's writing reaches anybody, so each is asserted in both directions:
 * the post it holds, and the post it must not.
 */
#[AllowMockObjectsWithoutExpectations]
class PostReviewServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example/@alice';

	private PostHoldsRequest|MockObject $postHoldsRequest;
	private StreamRequest|MockObject $streamRequest;
	private FollowsRequest|Stub $followsRequest;
	private ModerationService|MockObject $moderationService;
	private PostService|MockObject $postService;
	private StatusAssemblyService|Stub $statusAssemblyService;
	private StrikeService|MockObject $strikeService;
	private ConfigService|Stub $configService;
	private IGroupManager|MockObject $groupManager;
	private AccountService|MockObject $accountService;
	private PostReviewService $service;

	/** @var array<string, string> the app settings, as the service reads them */
	private array $settings = [
		ConfigService::SOCIAL_REVIEW_FIRST_POST => '1',
		ConfigService::SOCIAL_AUTOSPAM => '1',
		ConfigService::SOCIAL_REVIEW_POSTS => '1',
	];

	protected function setUp(): void {
		$this->postHoldsRequest = $this->createMock(PostHoldsRequest::class);
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->followsRequest = $this->createStub(FollowsRequest::class);
		$this->moderationService = $this->createMock(ModerationService::class);
		$this->postService = $this->createMock(PostService::class);
		$this->statusAssemblyService = $this->createStub(StatusAssemblyService::class);
		$this->strikeService = $this->createMock(StrikeService::class);
		$this->accountService = $this->createMock(AccountService::class);

		$this->configService = $this->createStub(ConfigService::class);
		$this->configService->method('getAppValueBool')->willReturnCallback(
			fn (string $key): bool => ($this->settings[$key] ?? '0') === '1'
		);
		$this->configService->method('getAppValueInt')->willReturnCallback(
			fn (string $key): int => (int)($this->settings[$key] ?? 1)
		);

		$this->groupManager = $this->createMock(IGroupManager::class);

		$this->service = new PostReviewService(
			$this->postHoldsRequest,
			$this->streamRequest,
			$this->followsRequest,
			$this->moderationService,
			$this->postService,
			$this->statusAssemblyService,
			$this->strikeService,
			$this->accountService,
			$this->configService,
			$this->groupManager,
			new NullLogger()
		);
	}

	private function alice(): Person {
		$actor = new Person();
		$actor->setId(self::ALICE);
		$actor->setPreferredUsername('alice');

		return $actor;
	}

	/** An account that has posted before and has followers: nothing special. */
	private function established(): void {
		$this->streamRequest->method('countPostsBy')->willReturn(12);
		$this->followsRequest->method('countFollowers')->willReturn(30);
	}

	public function testTheFirstPostOfAnAccountThatHasPublishedNothingIsHeld(): void {
		$this->streamRequest->method('countPostsBy')->with(self::ALICE)->willReturn(0);

		$this->assertSame(
			HeldPost::REASON_FIRST_POST,
			$this->service->assess($this->alice(), 'hello everybody', Stream::TYPE_PUBLIC)
		);
	}

	/**
	 * Somebody who can empty the queue is not somebody to put in it.
	 *
	 * The case that made this obvious is the first post on a brand-new
	 * instance: it is the administrator's, it was held for a moderator who was
	 * the same person, and a fresh install looked broken — the post did not
	 * appear and the only place it existed was a panel nobody had opened yet.
	 * The end-to-end suite caught it as "a post written in the dialog shows up
	 * in My Feed" failing, twice, on a clean install.
	 */
	public function testAnAdministratorsOwnFirstPostIsNotHeldForThemToApprove(): void {
		$this->streamRequest->method('countPostsBy')->willReturn(0);
		$this->groupManager->method('isAdmin')->willReturn(true);

		$actor = $this->alice();
		$actor->setUserId('alice');

		$this->assertSame('', $this->service->assess($actor, 'hello everybody', Stream::TYPE_PUBLIC));
	}

	/** An actor with no Nextcloud user behind it — a team account — is nobody's administrator. */
	public function testAnActorWithNoUserBehindItIsNotTreatedAsAModerator(): void {
		$this->streamRequest->method('countPostsBy')->willReturn(0);
		$this->groupManager->expects($this->never())->method('isAdmin');

		$this->assertSame(
			HeldPost::REASON_FIRST_POST,
			$this->service->assess($this->alice(), 'hello everybody', Stream::TYPE_PUBLIC)
		);
	}

	// --- videos ------------------------------------------------------------

	/**
	 * The rule an instance that hosts video wants: a video is minutes of
	 * somebody's attention and a great deal of somebody else's disk.
	 */
	public function testAPostWithAVideoIsHeldWhenTheInstanceAsksForThat(): void {
		$this->settings[ConfigService::SOCIAL_REVIEW_VIDEOS] = '1';
		$this->established();

		$this->assertSame(
			HeldPost::REASON_VIDEO,
			$this->service->assess($this->alice(), 'watch this', Stream::TYPE_PUBLIC, true)
		);
	}

	/**
	 * Unlike the other four rules, this one is not about the account: it
	 * applies to a trusted account with a thousand posts behind it too, every
	 * time, because what it is about is the video.
	 */
	public function testAnEstablishedAccountsVideoIsHeldJustTheSame(): void {
		$this->settings[ConfigService::SOCIAL_REVIEW_VIDEOS] = '1';
		$this->established();
		// an account a moderator has already made a decision about, which is
		// what makes the other rules stop applying to it
		$this->moderationService->method('levelOf')->willReturn('silence');

		$this->assertSame(
			HeldPost::REASON_VIDEO,
			$this->service->assess($this->alice(), 'watch this', Stream::TYPE_PUBLIC, true)
		);
	}

	public function testAPostWithNoVideoIsNotHeldByTheVideoRule(): void {
		$this->settings[ConfigService::SOCIAL_REVIEW_VIDEOS] = '1';
		$this->established();

		$this->assertSame(
			'', $this->service->assess($this->alice(), 'just words', Stream::TYPE_PUBLIC, false)
		);
	}

	public function testAVideoIsNotHeldOnAnInstanceThatHasNotAskedForThat(): void {
		$this->settings[ConfigService::SOCIAL_REVIEW_VIDEOS] = '0';
		$this->established();

		$this->assertSame(
			'', $this->service->assess($this->alice(), 'watch this', Stream::TYPE_PUBLIC, true)
		);
	}

	/** A moderator's own video is not held for them to approve, like their first post. */
	public function testAModeratorsVideoIsNotHeld(): void {
		$this->settings[ConfigService::SOCIAL_REVIEW_VIDEOS] = '1';
		$this->established();
		$this->groupManager->method('isAdmin')->willReturn(true);

		$actor = $this->alice();
		$actor->setUserId('alice');

		$this->assertSame(
			'', $this->service->assess($actor, 'watch this', Stream::TYPE_PUBLIC, true)
		);
	}

	/** A direct message is nobody else's business, video or not. */
	public function testADirectMessageWithAVideoIsNotHeld(): void {
		$this->settings[ConfigService::SOCIAL_REVIEW_VIDEOS] = '1';
		$this->established();

		$this->assertSame(
			'', $this->service->assess($this->alice(), 'watch this', Stream::TYPE_DIRECT, true)
		);
	}

	public function testThePostAfterThatIsNotHeld(): void {
		$this->established();

		$this->assertSame('', $this->service->assess($this->alice(), 'hello again', Stream::TYPE_PUBLIC));
	}

	/**
	 * A followers-only first post is still a first post: the rule is about the
	 * account, not the audience.
	 */
	public function testAFirstPostToFollowersIsHeldToo(): void {
		$this->streamRequest->method('countPostsBy')->willReturn(0);

		$this->assertSame(
			HeldPost::REASON_FIRST_POST,
			$this->service->assess($this->alice(), 'hello', Stream::TYPE_FOLLOWERS)
		);
	}

	/**
	 * An account graduates by having posts approved — a person having looked
	 * at it that many times, which is the only measure of trust this app has
	 * that is not a guess.
	 */
	public function testAnInstanceCanAskForMoreThanOnePostToBeLookedAt(): void {
		$this->settings[ConfigService::SOCIAL_REVIEW_POSTS] = '3';
		$this->followsRequest->method('countFollowers')->willReturn(10);

		$this->streamRequest->method('countPostsBy')->willReturn(2);
		$this->assertSame(
			HeldPost::REASON_FIRST_POST,
			$this->service->assess($this->alice(), 'still new here', Stream::TYPE_PUBLIC),
			'two approved posts is not yet three'
		);
	}

	public function testPastThatNumberNothingIsHeld(): void {
		$this->settings[ConfigService::SOCIAL_REVIEW_POSTS] = '3';
		$this->followsRequest->method('countFollowers')->willReturn(10);
		$this->streamRequest->method('countPostsBy')->willReturn(3);

		$this->assertSame('', $this->service->assess($this->alice(), 'settled in', Stream::TYPE_PUBLIC));
	}

	/** A mistyped setting must not hold an account's posts for ever. */
	public function testTheNumberIsBounded(): void {
		$this->settings[ConfigService::SOCIAL_REVIEW_POSTS] = '100000';

		$this->assertSame(
			PostReviewService::MAX_POSTS_BEFORE_TRUSTED, $this->service->postsBeforeTrusted()
		);
	}

	/**
	 * Holding a direct message would put private correspondence in front of a
	 * moderator who was not written to, for a machine's reason.
	 */
	public function testADirectMessageIsNeverHeld(): void {
		$this->streamRequest->method('countPostsBy')->willReturn(0);

		$this->assertSame(
			'',
			$this->service->assess($this->alice(), 'https://a https://b https://c https://d https://e https://f', Stream::TYPE_DIRECT)
		);
	}

	public function testAWallOfLinksIsHeld(): void {
		$this->established();
		$text = 'buy https://a.example https://b.example https://c.example '
			. 'https://d.example https://e.example https://f.example';

		$this->assertSame(
			HeldPost::REASON_LINKS, $this->service->assess($this->alice(), $text, Stream::TYPE_PUBLIC)
		);
	}

	/** Five links is a link roundup somebody wrote; six in one line is not. */
	public function testAPostWithAFewLinksIsNotHeld(): void {
		$this->established();
		$text = 'reading list: https://a.example https://b.example https://c.example';

		$this->assertSame('', $this->service->assess($this->alice(), $text, Stream::TYPE_PUBLIC));
	}

	/**
	 * The same links in a long post are somebody's actual writing. The rule is
	 * link *density*, not links.
	 */
	public function testALongPostCarryingItsLinksIsNotHeld(): void {
		$this->established();
		$text = str_repeat('Here is a paragraph of real writing about the subject at hand. ', 5)
			. 'https://a.example https://b.example https://c.example '
			. 'https://d.example https://e.example https://f.example';

		$this->assertSame('', $this->service->assess($this->alice(), $text, Stream::TYPE_PUBLIC));
	}

	public function testMentionsScatteredByAnAccountNobodyKnowsAreHeld(): void {
		$this->streamRequest->method('countPostsBy')->willReturn(3);
		$this->followsRequest->method('countFollowers')->willReturn(0);
		$this->followsRequest->method('countFollowing')->willReturn(0);
		$text = '@a @b @c @d @e @f free money';

		$this->assertSame(
			HeldPost::REASON_MENTIONS, $this->service->assess($this->alice(), $text, Stream::TYPE_PUBLIC)
		);
	}

	/**
	 * The same post from an account with followers is a person having a bad
	 * day in public, and the people who follow them can say so.
	 */
	public function testTheSameMentionsFromAnAccountWithFollowersAreNotHeld(): void {
		$this->streamRequest->method('countPostsBy')->willReturn(3);
		$this->followsRequest->method('countFollowers')->willReturn(12);
		$text = '@a @b @c @d @e @f come and look at this';

		$this->assertSame('', $this->service->assess($this->alice(), $text, Stream::TYPE_PUBLIC));
	}

	/** An email address is not six mentions, and a URL path is not one. */
	public function testAnAddressInsideTheTextIsNotAMention(): void {
		$this->streamRequest->method('countPostsBy')->willReturn(3);
		$this->followsRequest->method('countFollowers')->willReturn(0);
		$this->followsRequest->method('countFollowing')->willReturn(0);

		$this->assertSame(
			'',
			$this->service->assess($this->alice(), 'write to me@example.com or see https://x.example/@bob', Stream::TYPE_PUBLIC)
		);
	}

	/**
	 * An account a moderator has already decided about is being dealt with by
	 * that decision; the queue is for accounts nobody has looked at.
	 */
	public function testAnAccountAlreadyDecidedAboutIsNotHeld(): void {
		$this->streamRequest->method('countPostsBy')->willReturn(0);
		$this->moderationService->method('levelOf')->with(self::ALICE)->willReturn('silence');

		$this->assertSame('', $this->service->assess($this->alice(), 'hello', Stream::TYPE_PUBLIC));
	}

	public function testNothingIsHeldWhenBothSwitchesAreOff(): void {
		$this->settings = [
			ConfigService::SOCIAL_REVIEW_FIRST_POST => '0',
			ConfigService::SOCIAL_AUTOSPAM => '0',
		];
		$this->streamRequest->expects($this->never())->method('countPostsBy');

		$this->assertSame(
			'',
			$this->service->assess($this->alice(), '@a @b @c @d @e @f spam', Stream::TYPE_PUBLIC)
		);
	}

	public function testFirstPostReviewCanBeOffWhileTheSpamRulesStayOn(): void {
		$this->settings[ConfigService::SOCIAL_REVIEW_FIRST_POST] = '0';
		$this->streamRequest->expects($this->never())->method('countPostsBy');
		$this->followsRequest->method('countFollowers')->willReturn(0);
		$this->followsRequest->method('countFollowing')->willReturn(0);

		$this->assertSame('', $this->service->assess($this->alice(), 'hello', Stream::TYPE_PUBLIC));
		$this->assertSame(
			HeldPost::REASON_MENTIONS,
			$this->service->assess($this->alice(), '@a @b @c @d @e @f now', Stream::TYPE_PUBLIC)
		);
	}

	/**
	 * A moderator reading a column of actor URLs is reading the same forty
	 * characters over and over with the name buried at the end.
	 */
	public function testTheQueueCarriesEachAccountsHandle(): void {
		$held = (new HeldPost())->setId(7)->setActorId(self::ALICE);
		$this->postHoldsRequest->method('page')->willReturn([$held]);
		$author = $this->alice();
		$author->setAccount('alice@cloud.example');
		$this->accountService->method('getFromId')->with(self::ALICE)->willReturn($author);

		$this->assertSame('alice@cloud.example', $this->service->pending()[0]->getHandle());
	}

	/** An actor that cannot be resolved is still a row a moderator must see. */
	public function testARowWhoseAccountCannotBeResolvedKeepsItsId(): void {
		$held = (new HeldPost())->setId(7)->setActorId(self::ALICE);
		$this->postHoldsRequest->method('page')->willReturn([$held]);
		$this->accountService->method('getFromId')
			->willThrowException(new \RuntimeException('gone'));

		$this->assertSame(self::ALICE, $this->service->pending()[0]->getHandle());
	}

	public function testHoldingStoresTheRequestAndNothingElse(): void {
		$saved = null;
		$this->postHoldsRequest->method('save')
			->willReturnCallback(function (HeldPost $held) use (&$saved): int {
				$saved = $held;

				return 7;
			});
		// the post itself is never created: that is the whole point
		$this->postService->expects($this->never())->method('createPost');

		$held = $this->service->hold(
			$this->alice(), ['text' => 'hello', 'visibility' => 'public'], HeldPost::REASON_FIRST_POST
		);

		$this->assertSame($saved, $held);
		$this->assertSame(self::ALICE, $held->getActorId());
		$this->assertSame('hello', $held->paramText());
		$this->assertSame(HeldPost::REASON_FIRST_POST, $held->getReason());
	}

	/**
	 * A client told its post was held will be pressed again by its user, and
	 * the Pixelfed app retries a 422 by itself. The second attempt has to find
	 * the first rather than give a moderator the same post twice.
	 */
	public function testHoldingTheSamePostTwiceAnswersWithTheRowThatIsAlreadyWaiting(): void {
		$already = (new HeldPost())->setId(3)->setActorId(self::ALICE)->setParams(['text' => 'hello']);
		$this->postHoldsRequest->method('save')->willReturn(0);
		$this->postHoldsRequest->method('getByActor')->willReturn([$already]);

		$held = $this->service->hold($this->alice(), ['text' => 'hello'], HeldPost::REASON_FIRST_POST);

		$this->assertSame(3, $held->getId());
	}

	public function testAnAccountMayNotFillTheQueue(): void {
		$this->postHoldsRequest->method('countForActor')
			->willReturn(PostReviewService::MAX_PENDING_PER_ACTOR);
		$this->postHoldsRequest->expects($this->never())->method('save');

		$this->expectException(InvalidActionException::class);
		$this->service->hold($this->alice(), ['text' => 'again'], HeldPost::REASON_FIRST_POST);
	}

	public function testApprovingPublishesThePostAndLetsTheRowGo(): void {
		$held = (new HeldPost())->setId(7)->setActorId(self::ALICE)->setParams(['text' => 'hello']);
		$this->postHoldsRequest->method('getById')->with(7)->willReturn($held);
		$post = new Post($this->alice());
		$this->statusAssemblyService->method('fromParams')->willReturn($post);

		$order = [];
		$this->postHoldsRequest->method('delete')->willReturnCallback(function () use (&$order): bool {
			$order[] = 'delete';

			return true;
		});
		$this->postService->method('createPost')->willReturnCallback(function () use (&$order) {
			$order[] = 'publish';

			return null;
		});

		$this->service->approve(7, $this->alice());

		// deleted first: a failure to publish must leave nothing a second
		// approval could publish twice
		$this->assertSame(['delete', 'publish'], $order);
	}

	public function testApprovingAPostOfSomebodyElseIsRefused(): void {
		$held = (new HeldPost())->setId(7)->setActorId('https://cloud.example/@bob');
		$this->postHoldsRequest->method('getById')->willReturn($held);
		$this->postHoldsRequest->expects($this->never())->method('delete');
		$this->postService->expects($this->never())->method('createPost');

		$this->expectException(ItemNotFoundException::class);
		$this->service->approve(7, $this->alice());
	}

	public function testRefusingTellsTheAuthorAndIsRecordedAgainstThem(): void {
		$held = (new HeldPost())->setId(7)->setActorId(self::ALICE)
			->setReason(HeldPost::REASON_LINKS)->setParams(['text' => 'buy things']);
		$this->postHoldsRequest->method('getById')->willReturn($held);
		$this->postHoldsRequest->expects($this->once())->method('delete')->with(7);

		$recorded = [];
		$this->strikeService->method('record')->willReturnCallback(
			function (string $actorId, string $action, string $text = '', int $reportId = 0) use (&$recorded): Strike {
				$recorded = [$actorId, $action, $text];

				return new Strike($actorId, $action, $text, '', 0, 1757548800);
			}
		);

		$this->service->reject(7, 'this is an advertisement');

		$this->assertSame(
			[self::ALICE, Strike::TAKEDOWN, 'this is an advertisement'], $recorded
		);
	}

	/** The post is refused either way; a strike that fails must not put it back. */
	public function testAPostIsStillRefusedWhenTheStrikeCannotBeRecorded(): void {
		$held = (new HeldPost())->setId(7)->setActorId(self::ALICE);
		$this->postHoldsRequest->method('getById')->willReturn($held);
		$this->postHoldsRequest->expects($this->once())->method('delete')->with(7);
		$this->strikeService->method('record')->willThrowException(new \RuntimeException('down'));

		$this->service->reject(7);
		$this->addToAssertionCount(1);
	}

	/**
	 * An author taking back their own waiting post is not a moderation
	 * decision: the row goes and nothing is recorded.
	 */
	public function testAnAuthorCanTakeTheirOwnPostBack(): void {
		$held = (new HeldPost())->setId(7)->setActorId(self::ALICE);
		$this->postHoldsRequest->method('getByIdForActor')->with(7, self::ALICE)->willReturn($held);
		$this->postHoldsRequest->expects($this->once())->method('delete')->with(7);
		$this->strikeService->expects($this->never())->method('record');

		$this->service->withdraw($this->alice(), 7);
	}

	public function testTakingBackSomebodyElsesPostIsTheSameAsOneThatDoesNotExist(): void {
		$this->postHoldsRequest->method('getByIdForActor')
			->willThrowException(new ItemNotFoundException('no such held post'));
		$this->postHoldsRequest->expects($this->never())->method('delete');

		$this->expectException(ItemNotFoundException::class);
		$this->service->withdraw($this->alice(), 7);
	}
}
