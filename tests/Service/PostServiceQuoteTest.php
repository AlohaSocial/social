<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\MediaTagsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\Activity\QuoteRequestInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Activity\QuoteRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\Post;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\EmojiService;
use OCA\Social\Service\LinkPreviewService;
use OCA\Social\Service\ModerationService;
use OCA\Social\Service\PlaceService;
use OCA\Social\Service\PostService;
use OCA\Social\Service\ReactionSummaryService;
use OCA\Social\Service\StatusRevisionService;
use OCA\Social\Service\StreamService;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A local user quoting a post: what ends up on the Note, who it is addressed
 * to, and what this instance may refuse to quote at all.
 */
#[AllowMockObjectsWithoutExpectations]
class PostServiceQuoteTest extends TestCase {
	private const SOCIAL_URL = 'https://social.example/';
	private const ACTOR_ID = 'https://social.example/@alice';
	private const GENERATED_ID = 'https://social.example/@alice/1234567890';
	private const BOB_ID = 'https://remote.example/users/bob';
	private const QUOTED_ID = 'https://remote.example/users/bob/statuses/111';

	private StreamRequest|Stub $streamRequest;
	private ActivityService|MockObject $activityService;
	private CacheActorService|Stub $cacheActorService;
	private PostService $service;

	protected function setUp(): void {
		$this->streamRequest = $this->createStub(StreamRequest::class);
		$this->activityService = $this->createMock(ActivityService::class);
		$this->cacheActorService = $this->createStub(CacheActorService::class);

		$configService = $this->createStub(ConfigService::class);
		$configService->method('generateId')->willReturn(self::GENERATED_ID);
		$configService->method('getSocialUrl')->willReturn(self::SOCIAL_URL);

		$urlGenerator = $this->createStub(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			fn (string $route, array $args = []): string => self::SOCIAL_URL . ($args['path'] ?? $route)
		);

		$streamService = new StreamService(
			$urlGenerator,
			$this->streamRequest,
			$this->activityService,
			$this->cacheActorService,
			$configService,
			$this->createStub(CurlService::class),
			$this->createStub(LinkPreviewService::class),
			$this->createStub(EmojiService::class),
			$this->createStub(\OCP\EventDispatcher\IEventDispatcher::class),
			new NullLogger(),
			$this->createStub(PlaceService::class),
			$this->createStub(ReactionSummaryService::class),
			$this->createStub(MediaTagsRequest::class),
			$this->createStub(AccountService::class)
		);

		$l10nFactory = $this->createStub(IFactory::class);
		$l10nFactory->method('getUserLanguage')->willReturn('en');

		$this->service = new PostService(
			$streamService,
			$this->createStub(AccountService::class),
			$this->activityService,
			$l10nFactory,
			$this->createStub(IUserManager::class),
			$this->createStub(ModerationService::class),
			$this->createStub(StatusRevisionService::class),
			$this->createStub(\OCA\Social\Service\NotificationService::class),
			new \OCA\Social\Service\LinkifyService(),
			$this->createStub(\OCA\Social\Service\ChannelService::class),
			$this->createStub(\OCA\Social\Service\ConfigService::class),
			$this->createStub(\OCP\EventDispatcher\IEventDispatcher::class),
			new NullLogger(),
			$this->createStub(\OCA\Social\Service\InterestService::class),
			$this->createStub(\OCA\Social\Service\VideoDeliveryHold::class),
		);
	}

	private function actor(): Person {
		$actor = new Person();
		$actor->setId(self::ACTOR_ID);
		$actor->setPreferredUsername('alice');
		$actor->setFollowers(self::ACTOR_ID . '/followers');
		$actor->setLocal(true);

		return $actor;
	}

	private function bob(): Person {
		$bob = new Person();
		$bob->setId(self::BOB_ID);
		$bob->setPreferredUsername('bob');
		$bob->setAccount('bob@remote.example');
		$bob->setInbox(self::BOB_ID . '/inbox');
		$bob->setSharedInbox('https://remote.example/inbox');

		return $bob;
	}

	/** Bob's post, as this instance stores it. */
	private function quoted(string $visibility = Stream::TYPE_PUBLIC): Note {
		$note = new Note();
		$note->setId(self::QUOTED_ID);
		$note->setNid(11);
		$note->setAttributedTo(self::BOB_ID);
		$note->setVisibility($visibility);
		if ($visibility === Stream::TYPE_PUBLIC) {
			$note->setToArray([ACore::CONTEXT_PUBLIC]);
		}

		return $note;
	}

	/** The quoted post is found by its numeric id, by its URI, or not at all. */
	private function holding(?Note $quoted): void {
		$this->streamRequest->method('getStreamByNid')
			->willReturnCallback(function (int $nid) use ($quoted): Stream {
				if ($quoted !== null && $nid === $quoted->getNid()) {
					return $quoted;
				}

				throw new StreamNotFoundException();
			});
		$this->streamRequest->method('getStreamById')
			->willReturnCallback(function (string $id) use ($quoted): Stream {
				if ($quoted !== null && $id === $quoted->getId()) {
					return $quoted;
				}

				throw new StreamNotFoundException();
			});
		$this->cacheActorService->method('getFromId')->willReturnCallback(
			fn (string $id): Person => $this->bob()
		);
	}

	private function post(string $quotedId): Post {
		$post = new Post($this->actor());
		$post->setContent('look at this');
		$post->setType(Stream::TYPE_PUBLIC);
		$post->setQuotedId($quotedId);

		return $post;
	}

	private function expectCreateActivity(?Note &$captured): void {
		$this->activityService->method('createActivity')
			->willReturnCallback(function (Person $actor, ACore $item, ?ACore &$activity = null) use (&$captured): string {
				$captured = $item;
				$activity = new Create();
				$activity->setId($item->getId() . '/activity');
				$activity->setObject($item);
				$activity->setActor($actor);

				return 'token-1';
			});
	}

	public function testQuotingAPostPutsItOnTheNote(): void {
		$this->holding($this->quoted());
		$this->expectCreateActivity($note);

		$this->service->createPost($this->post('11'));

		$this->assertSame(self::QUOTED_ID, $note->getQuote());
	}

	/** Mastodon emits the two older names beside `quote`; so does this. */
	public function testTheQuoteFederatesUnderAllThreeNames(): void {
		$this->holding($this->quoted());
		$this->expectCreateActivity($note);

		$this->service->createPost($this->post('11'));
		$wire = $note->exportAsActivityPub();

		$this->assertSame(self::QUOTED_ID, $wire['quote']);
		$this->assertSame(self::QUOTED_ID, $wire['quoteUrl']);
		$this->assertSame(self::QUOTED_ID, $wire['_misskey_quote']);
	}

	public function testAPostMayBeQuotedByItsUri(): void {
		$this->holding($this->quoted());
		$this->expectCreateActivity($note);

		$this->service->createPost($this->post(self::QUOTED_ID));

		$this->assertSame(self::QUOTED_ID, $note->getQuote());
	}

	/**
	 * The quoted author has to learn of the quote — it is their post being
	 * carried into somebody else's audience, and their server that decides
	 * whether the quote may stand.
	 */
	public function testTheQuotedAuthorIsAddressedAndDeliveredTo(): void {
		$this->holding($this->quoted());
		$this->expectCreateActivity($note);

		$this->service->createPost($this->post('11'));

		$this->assertContains(self::BOB_ID, $note->getCcArray());
		$inboxes = array_map(
			static fn (InstancePath $path): string => $path->getUri(),
			$note->getInstancePaths()
		);
		$this->assertContains('https://remote.example/inbox', $inboxes);
	}

	/**
	 * The quote has a column since `Version1000Date20260912000007`, and the
	 * stored wire object is still what federates on the next Update — so the
	 * snapshot taken at creation has to hold it too. Both copies are written
	 * from this one assembled note, which is what keeps them from disagreeing.
	 */
	public function testTheQuoteIsInTheStoredWireObject(): void {
		$this->holding($this->quoted());
		$this->expectCreateActivity($note);

		$this->service->createPost($this->post('11'));

		$source = json_decode($note->getSource(), true);
		$this->assertSame(self::QUOTED_ID, $source['quote']);
	}

	/**
	 * A quote carries the quoted post into the quoter's audience, so it may
	 * only carry what the author addressed to everyone — the rule
	 * `PinService::pin()` and `BoostService::create()` already apply.
	 */
	public function testAFollowersOnlyPostCannotBeQuoted(): void {
		$this->holding($this->quoted(Stream::TYPE_FOLLOWERS));
		$this->activityService->expects($this->never())->method('createActivity');

		$this->expectException(InvalidActionException::class);
		$this->service->createPost($this->post('11'));
	}

	public function testAPostThisInstanceDoesNotHoldCannotBeQuoted(): void {
		$this->holding(null);
		$this->activityService->expects($this->never())->method('createActivity');

		$this->expectException(InvalidActionException::class);
		$this->service->createPost($this->post('11'));
	}

	/**
	 * FEP-044f: the quoter's server asks the quoted author's server for
	 * approval, naming the quoted post as the object and the quoting post as
	 * the instrument. Without it Mastodon 4.5 never approves the quote, and
	 * renders it as a bare link.
	 */
	public function testAQuoteRequestIsSentToTheQuotedAuthor(): void {
		$this->holding($this->quoted());
		$this->expectCreateActivity($note);

		$sent = [];
		$this->activityService->method('request')->willReturnCallback(
			function (ACore $activity) use (&$sent): string {
				$sent[] = $activity;

				return 'token-2';
			}
		);

		$this->service->createPost($this->post('11'));

		$requests = array_values(array_filter($sent, static fn (ACore $a): bool => $a instanceof QuoteRequest));
		$this->assertCount(1, $requests);
		/** @var QuoteRequest $request */
		$request = $requests[0];
		$this->assertSame(self::ACTOR_ID, $request->getActor()->getId());
		$this->assertSame(self::QUOTED_ID, $request->getObjectId());
		$this->assertSame(self::GENERATED_ID, $request->getInstrument());
		$this->assertContains(self::BOB_ID, $request->getToArray());
	}

	/**
	 * When the quoted post is ours, this server is the authority the
	 * QuoteRequest would be asking. Sending one would be the instance posting
	 * to its own inbox and waiting for its own reply, so the approval is
	 * granted here instead — and it has to be on the note *before* the source
	 * is snapshotted, or the first delivery federates a quote with no stamp.
	 */
	public function testQuotingALocalPostIsApprovedOnTheSpotWithoutAsking(): void {
		$quoted = $this->quoted();
		$quoted->setLocal(true);
		$this->holding($quoted);
		$this->expectCreateActivity($note);
		$this->activityService->expects($this->never())->method('request');

		$this->service->createPost($this->post('11'));

		$this->assertSame(
			self::QUOTED_ID . '/quote_authorizations/'
			. QuoteRequestInterface::stamp(self::GENERATED_ID),
			$note->getQuoteAuthorization()
		);
		$this->assertSame(Stream::QUOTE_ACCEPTED, $note->getQuoteState());
	}

	public function testTheApprovalOfALocalQuoteIsInTheStoredWireObject(): void {
		$quoted = $this->quoted();
		$quoted->setLocal(true);
		$this->holding($quoted);
		$this->expectCreateActivity($note);

		$this->service->createPost($this->post('11'));

		$source = json_decode($note->getSource(), true);
		$this->assertSame($note->getQuoteAuthorization(), $source['quoteAuthorization'] ?? '');
	}

	/**
	 * The stamp this server grants itself has to be the one its own approval
	 * endpoint answers, or the approval we federate is a dead link.
	 */
	public function testTheApprovalOfALocalQuoteNamesTheQuotingPost(): void {
		$quoted = $this->quoted();
		$quoted->setLocal(true);
		$this->holding($quoted);
		$this->expectCreateActivity($note);

		$this->service->createPost($this->post('11'));

		$stamp = substr(
			$note->getQuoteAuthorization(),
			strlen(self::QUOTED_ID . '/quote_authorizations/')
		);
		$this->assertSame(self::GENERATED_ID, QuoteRequestInterface::instrumentOfStamp($stamp));
	}

	/** A remote post is still asked about: its author's server decides. */
	public function testQuotingARemotePostGrantsNoApprovalOfOurOwn(): void {
		$this->holding($this->quoted());
		$this->expectCreateActivity($note);
		$this->activityService->method('request')->willReturn('token-2');

		$this->service->createPost($this->post('11'));

		$this->assertSame('', $note->getQuoteAuthorization());
	}

	public function testAPostThatQuotesNothingSendsNoQuoteRequest(): void {
		$this->expectCreateActivity($note);
		$this->activityService->expects($this->never())->method('request');

		$post = new Post($this->actor());
		$post->setContent('nothing to see');
		$post->setType(Stream::TYPE_PUBLIC);
		$this->service->createPost($post);

		$this->assertSame('', $note->getQuote());
	}
}
