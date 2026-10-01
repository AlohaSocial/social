<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use DateTime;
use OCA\Social\Db\MediaTagsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\FederationDeliveryException;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\Post;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\EmojiService;
use OCA\Social\Service\InstanceService;
use OCA\Social\Service\LinkPreviewService;
use OCA\Social\Service\ModerationService;
use OCA\Social\Service\PlaceService;
use OCA\Social\Service\PostService;
use OCA\Social\Service\ReactionSummaryService;
use OCA\Social\Service\StatusRevisionService;
use OCA\Social\Service\StreamService;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * PostService is exercised with a real StreamService (the Note it builds is
 * what matters) and a mocked ActivityService that records what it is handed.
 */
#[AllowMockObjectsWithoutExpectations]
class PostServiceTest extends TestCase {
	private const SOCIAL_URL = 'https://social.example/';
	private const ACTOR_ID = 'https://social.example/@alice';
	private const ACTOR_FOLLOWERS = 'https://social.example/@alice/followers';
	private const GENERATED_ID = 'https://social.example/@alice/1234567890';
	private const BOB_ID = 'https://remote.example/users/bob';
	private const BOB_SHARED_INBOX = 'https://remote.example/inbox';
	private const CAROL_ID = 'https://third.example/users/carol';
	private const CAROL_INBOX = 'https://third.example/users/carol/inbox';

	private StreamRequest|MockObject $streamRequest;
	private AccountService|MockObject $accountService;
	private ActivityService|MockObject $activityService;
	private CacheActorService|MockObject $cacheActorService;
	private ModerationService|MockObject $moderationService;
	private \OCP\EventDispatcher\IEventDispatcher|Stub $eventDispatcher;
	/** @var object[] every event the service dispatched */
	private array $dispatched = [];
	private PostService $service;

	/** what the poster's Nextcloud is set to, as IFactory::getUserLanguage() reports it */
	private StatusRevisionService|Stub $revisionService;
	private string $userLanguage = 'de_DE';

	private \OCA\Social\Service\InterestService|MockObject $interestService;
	private \OCA\Social\Service\VideoDeliveryHold|MockObject $videoDeliveryHold;

	protected function setUp(): void {
		$this->interestService = $this->createMock(\OCA\Social\Service\InterestService::class);
		$this->videoDeliveryHold = $this->createMock(\OCA\Social\Service\VideoDeliveryHold::class);
		$this->revisionService = $this->createStub(StatusRevisionService::class);
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->activityService = $this->createMock(ActivityService::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);

		$configService = $this->createStub(ConfigService::class);
		$configService->method('generateId')->willReturn(self::GENERATED_ID);
		$configService->method('getSocialUrl')->willReturn(self::SOCIAL_URL);

		// hashtag hrefs are built through the router, the way the app's own
		// timeline URLs are
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
		$l10nFactory->method('getUserLanguage')->willReturnCallback(fn (): string => $this->userLanguage);

		$this->moderationService = $this->createMock(ModerationService::class);
		$this->eventDispatcher = $this->createStub(\OCP\EventDispatcher\IEventDispatcher::class);
		$this->eventDispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event): void {
				$this->dispatched[] = $event;
			}
		);

		$this->service = new PostService(
			$streamService,
			$this->accountService,
			$this->activityService,
			$l10nFactory,
			$this->createStub(IUserManager::class),
			$this->moderationService,
			$this->revisionService,
			$this->createStub(\OCA\Social\Service\NotificationService::class),
			new \OCA\Social\Service\LinkifyService(),
			$this->createStub(\OCA\Social\Service\ChannelService::class),
			$this->createStub(\OCA\Social\Service\ConfigService::class),
			$this->eventDispatcher,
			new NullLogger(),
			$this->interestService,
			$this->videoDeliveryHold,
		);
	}

	private function actor(): Person {
		$actor = new Person();
		$actor->setId(self::ACTOR_ID);
		$actor->setPreferredUsername('alice');
		$actor->setFollowers(self::ACTOR_FOLLOWERS);
		$actor->setLocal(true);

		return $actor;
	}

	private function bob(): Person {
		$bob = new Person();
		$bob->setId(self::BOB_ID);
		$bob->setPreferredUsername('bob');
		$bob->setAccount('bob@remote.example');
		$bob->setInbox(self::BOB_ID . '/inbox');
		$bob->setSharedInbox(self::BOB_SHARED_INBOX);

		return $bob;
	}

	/** Someone else in the conversation, on a third server that publishes no shared inbox. */
	private function carol(): Person {
		$carol = new Person();
		$carol->setId(self::CAROL_ID);
		$carol->setPreferredUsername('carol');
		$carol->setAccount('carol@third.example');
		$carol->setInbox(self::CAROL_INBOX);

		return $carol;
	}

	private function post(string $content, string $type = Stream::TYPE_PUBLIC): Post {
		$post = new Post($this->actor());
		$post->setContent($content);
		$post->setType($type);

		return $post;
	}

	/**
	 * Records the Note handed to ActivityService::createActivity() and hands
	 * back a Create wrapping it, the way the real service does.
	 */
	private function expectCreateActivity(?Note &$captured, string $token = 'token-1'): void {
		$this->activityService->expects($this->once())
			->method('createActivity')
			->willReturnCallback(function (Person $actor, ACore $item, ?ACore &$activity = null) use (&$captured, $token): string {
				$captured = $item;
				$activity = new Create();
				$activity->setId($item->getId() . '/activity');
				$activity->setObject($item);
				$activity->setActor($actor);

				return $token;
			});
	}

	// createPost()

	public function testCreatePostBuildsNoteAndWrapsItInCreateActivity(): void {
		$this->cacheActorService->method('getFromAccount')->with('bob@remote.example', true)->willReturn($this->bob());
		$this->accountService->expects($this->once())
			->method('bumpActorCount')
			->with(self::ACTOR_ID, 'count_posts', 1);
		$this->expectCreateActivity($note);

		$token = '';
		$activity = $this->service->createPost(
			$this->post('Hello @bob@remote.example it\'s "great" #Nextcloud'), $token
		);

		$this->assertSame('token-1', $token);
		$this->assertInstanceOf(Create::class, $activity);
		$this->assertSame(self::GENERATED_ID . '/activity', $activity->getId());
		$this->assertSame($note, $activity->getObject());
		$this->assertSame(self::ACTOR_ID, $activity->getActor()->getId());

		$this->assertInstanceOf(Note::class, $note);
		$this->assertSame(self::GENERATED_ID, $note->getId());
		$this->assertTrue($note->isLocal());
		$this->assertSame(self::ACTOR_ID, $note->getAttributedTo());
		$this->assertSame(Stream::TYPE_PUBLIC, $note->getVisibility());
		$this->assertSame(
			'<p>Hello <a href="' . self::BOB_ID . '" class="u-url mention" rel="nofollow noopener noreferrer">'
			. '@bob@remote.example</a> it&#039;s &quot;great&quot; '
			. '<a href="' . self::SOCIAL_URL . 'tags/nextcloud" class="mention hashtag" rel="tag">#Nextcloud</a></p>',
			$note->getContent()
		);
		$this->assertEqualsWithDelta(time(), (new DateTime($note->getPublished()))->getTimestamp(), 5);
		$this->assertSame(['Nextcloud'], $note->getHashtags());
		$this->assertSame(
			[
				['type' => 'Mention', 'href' => self::BOB_ID, 'name' => '@bob@remote.example'],
				['type' => 'Hashtag', 'href' => self::SOCIAL_URL . 'tags/nextcloud', 'name' => '#Nextcloud'],
			],
			$note->getTags()
		);
		$this->assertSame('', $note->getInReplyTo());
		$this->assertSame([], $note->getAttachments());
	}

	public function testCreatePostCarriesTheContentWarningAsTheSummary(): void {
		$this->expectCreateActivity($note);

		$post = $this->post('who shot him');
		$post->setSpoilerText('season finale');
		$this->service->createPost($post);

		// AP calls it `summary`; the client API calls it `spoiler_text`
		$this->assertSame('season finale', $note->getSpoilerText());
		$this->assertSame('season finale', $note->getSummary());
	}

	/**
	 * `spoiler_text` is plain text on the wire, so what is stored and federated
	 * is what was typed. It used to be entity-encoded, which every reader —
	 * here, on every instance it federated to, and in the composer of the next
	 * edit — showed as the entities themselves.
	 */
	public function testCreatePostKeepsTheContentWarningAsPlainText(): void {
		$this->expectCreateActivity($note);

		$post = $this->post('who shot him');
		$post->setSpoilerText("Bob's finale");
		$this->service->createPost($post);

		$this->assertSame("Bob's finale", $note->getSpoilerText());
	}

	public function testCreatePostDropsMarkupFromTheContentWarning(): void {
		$this->expectCreateActivity($note);

		$post = $this->post('body');
		$post->setSpoilerText('<script>alert("x")</script>');
		$this->service->createPost($post);

		// dropped rather than encoded: a plain-text field carries no markup,
		// and nothing downstream has to unescape it
		$this->assertSame('alert("x")', $note->getSpoilerText());
	}

	/** The edit path stores the same shape the create path does. */
	public function testEditPostKeepsTheContentWarningAsPlainTextToo(): void {
		$stored = $this->storedNote();
		$this->streamRequest->method('getStreamByNid')
			->willReturnOnConsecutiveCalls($stored, $this->storedNote());
		$this->activityService->method('updateActivity')->willReturn('token');

		$this->service->editPost(7, $this->actor(), 'body', "<b>Bob's</b> finale");

		$this->assertSame("Bob's finale", $stored->getSpoilerText());
	}

	public function testCreatePostCarriesTheSensitiveFlagToTheNote(): void {
		$this->expectCreateActivity($note);

		$post = $this->post('look at this');
		$post->setSensitive(true);
		$this->service->createPost($post);

		// what makes a client blur the attachments, here and on every instance
		// the Create federates to
		$this->assertTrue($note->isSensitive());
		$this->assertTrue($note->exportAsActivityPub()['sensitive']);
	}

	public function testCreatePostWithoutTheSensitiveFlagIsNotSensitive(): void {
		$this->expectCreateActivity($note);

		$this->service->createPost($this->post('look at this'));

		$this->assertFalse($note->isSensitive());
	}

	public function testCreatePostWithoutAContentWarningLeavesTheSummaryEmpty(): void {
		$this->expectCreateActivity($note);

		$this->service->createPost($this->post('just words'));

		$this->assertSame('', $note->getSpoilerText());
	}

	/**
	 * @return array<string, array{string, string, string[], string[]}>
	 */
	public static function visibilityProvider(): array {
		return [
			'public' => [Stream::TYPE_PUBLIC, ACore::CONTEXT_PUBLIC, [], [self::ACTOR_FOLLOWERS, self::BOB_ID]],
			'unlisted' => [Stream::TYPE_UNLISTED, self::ACTOR_FOLLOWERS, [], [ACore::CONTEXT_PUBLIC, self::BOB_ID]],
			'followers' => [Stream::TYPE_FOLLOWERS, self::ACTOR_FOLLOWERS, [], [self::BOB_ID]],
			'direct' => [Stream::TYPE_DIRECT, '', [self::BOB_ID], []],
		];
	}

	#[DataProvider('visibilityProvider')]
	public function testCreatePostAddressesMentionPerVisibility(
		string $type,
		string $expectedTo,
		array $expectedToArray,
		array $expectedCc,
	): void {
		$this->cacheActorService->method('getFromAccount')->willReturn($this->bob());
		$this->expectCreateActivity($note);

		$this->service->createPost($this->post('Hi @bob@remote.example', $type));

		$this->assertSame($type, $note->getVisibility());
		$this->assertSame($expectedTo, $note->getTo());
		$this->assertSame($expectedToArray, $note->getToArray());
		$this->assertSame($expectedCc, $note->getCcArray());
		$this->assertSame($type === Stream::TYPE_PUBLIC || $type === Stream::TYPE_UNLISTED, $note->isPublic());
		$this->assertCount(1, $note->getTags('Mention'));
	}

	public function testCreatePostDirectMessageFederatesOnlyToMentionedInboxes(): void {
		$this->cacheActorService->method('getFromAccount')->willReturn($this->bob());
		$this->expectCreateActivity($note);

		$this->service->createPost($this->post('psst @bob@remote.example', Stream::TYPE_DIRECT));

		$paths = $note->getInstancePaths();
		$this->assertCount(1, $paths);
		$this->assertSame(self::BOB_SHARED_INBOX, $paths[0]->getUri());
		$this->assertSame(InstancePath::TYPE_INBOX, $paths[0]->getType());
		$this->assertSame(InstancePath::PRIORITY_HIGH, $paths[0]->getPriority());
		$this->assertTrue($note->isFilterDuplicate());
	}

	public function testCreatePostPublicFederatesToFollowersAndMentionedInboxes(): void {
		$this->cacheActorService->method('getFromAccount')->willReturn($this->bob());
		$this->expectCreateActivity($note);

		$this->service->createPost($this->post('hey @bob@remote.example'));

		$paths = $note->getInstancePaths();
		$this->assertCount(2, $paths);
		$this->assertSame(self::ACTOR_ID, $paths[0]->getUri());
		$this->assertSame(InstancePath::TYPE_FOLLOWERS, $paths[0]->getType());
		$this->assertSame(self::BOB_SHARED_INBOX, $paths[1]->getUri());
		$this->assertSame(InstancePath::PRIORITY_MEDIUM, $paths[1]->getPriority());
	}

	public function testCreatePostWithoutMentionsOrTagsNeedsNoLookup(): void {
		$this->cacheActorService->expects($this->never())->method('getFromAccount');
		$this->expectCreateActivity($note);

		$this->service->createPost($this->post('just words'));

		$this->assertSame([], $note->getTags());
		$this->assertSame([], $note->getHashtags());
		$this->assertSame(ACore::CONTEXT_PUBLIC, $note->getTo());
		$this->assertSame([], $note->getToArray());
		$this->assertSame([self::ACTOR_FOLLOWERS], $note->getCcArray());
	}

	public function testCreatePostReplyWiresParentAndAddressesItsAuthor(): void {
		$parentId = 'https://remote.example/notes/parent';
		$parent = new Note();
		$parent->setId($parentId);
		$parent->setAttributedTo(self::BOB_ID);
		$this->streamRequest->method('getStreamById')->with($parentId)->willReturn($parent);
		$this->cacheActorService->method('getFromId')->with(self::BOB_ID)->willReturn($this->bob());
		$this->expectCreateActivity($note);

		$post = $this->post('I agree');
		$post->setReplyTo($parentId);
		$this->service->createPost($post);

		$this->assertSame($parentId, $note->getInReplyTo());
		$paths = $note->getInstancePaths();
		$this->assertCount(2, $paths);
		$this->assertSame('https://remote.example/inbox', $paths[1]->getUri());
		$this->assertSame(InstancePath::TYPE_INBOX, $paths[1]->getType());
		$this->assertSame(InstancePath::PRIORITY_HIGH, $paths[1]->getPriority());
	}

	/**
	 * A reply is addressed like any other post: `replyTo()` addresses the
	 * author of the parent, and everybody the text names is addressed the way
	 * a mention always is — a `cc` entry, a `Mention` tag and an inbox of
	 * their own. What was missing was the naming: the composer prefilled the
	 * author alone, so the third person in a conversation was told nothing.
	 *
	 * The handles are separated by the non-breaking space the composer puts
	 * between two mention pills, because that is what the server is handed.
	 */
	public function testAReplyReachesEveryParticipantOfTheConversation(): void {
		$parentId = 'https://remote.example/notes/parent';
		$parent = new Note();
		$parent->setId($parentId);
		$parent->setAttributedTo(self::BOB_ID);
		$this->streamRequest->method('getStreamById')->with($parentId)->willReturn($parent);
		$this->cacheActorService->method('getFromId')->with(self::BOB_ID)->willReturn($this->bob());
		$this->cacheActorService->method('getFromAccount')->willReturnMap([
			['bob@remote.example', true, $this->bob()],
			['carol@third.example', true, $this->carol()],
		]);
		$this->expectCreateActivity($note);

		$post = $this->post("@bob@remote.example\u{00a0}@carol@third.example I agree");
		$post->setReplyTo($parentId);
		$this->service->createPost($post);

		$this->assertSame(
			['@bob@remote.example', '@carol@third.example'],
			array_column($note->getTags('Mention'), 'name')
		);
		$this->assertSame(
			[self::BOB_ID, self::CAROL_ID],
			array_values(array_diff($note->getCcArray(), [self::ACTOR_FOLLOWERS]))
		);

		$inboxes = array_map(
			fn (InstancePath $path): string => $path->getUri(),
			array_filter(
				$note->getInstancePaths(),
				fn (InstancePath $path): bool => $path->getType() === InstancePath::TYPE_INBOX
			)
		);
		$this->assertContains(self::BOB_SHARED_INBOX, $inboxes);
		// a server with no shared inbox is reached at the personal one, which
		// is the only address Carol has
		$this->assertContains(self::CAROL_INBOX, $inboxes);
	}

	/**
	 * The recount counts public statuses (`countNotesFromActorId()` joins the
	 * public recipient row), so counting every post here meant the number
	 * climbed on each followers-only post and fell back at the next cron pass:
	 * a profile's post count that visibly wobbled.
	 */
	public function testAFollowersOnlyPostIsNotCountedTowardsTheProfile(): void {
		$this->expectCreateActivity($note);
		$this->accountService->expects($this->never())->method('bumpActorCount');

		$this->service->createPost($this->post('for my followers', Stream::TYPE_FOLLOWERS));
	}

	public function testCreatePostToUnknownReplyTargetFailsBeforeAnythingIsSent(): void {
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$this->activityService->expects($this->never())->method('createActivity');
		$this->accountService->expects($this->never())->method('bumpActorCount');

		$post = $this->post('I agree');
		$post->setReplyTo('https://remote.example/notes/missing');

		$this->expectException(StreamNotFoundException::class);
		$this->service->createPost($post);
	}

	public function testCreatePostToUnreachableReplyAuthorFails(): void {
		$parent = new Note();
		$parent->setId('https://remote.example/notes/parent');
		$parent->setAttributedTo(self::BOB_ID);
		$this->streamRequest->method('getStreamById')->willReturn($parent);
		$this->cacheActorService->method('getFromId')->willThrowException(new RequestNetworkException());
		$this->activityService->expects($this->never())->method('createActivity');

		$post = $this->post('I agree');
		$post->setReplyTo('https://remote.example/notes/parent');

		$this->expectException(RequestNetworkException::class);
		$this->service->createPost($post);
	}

	public function testCreatePostCarriesMediaAttachments(): void {
		$medias = [['id' => '1', 'type' => 'image', 'url' => 'https://social.example/media/1.png']];
		$this->expectCreateActivity($note);

		$post = $this->post('look');
		$post->setMedias($medias);
		$this->service->createPost($post);

		$this->assertSame($medias, $note->getAttachments());
	}

	/**
	 * A post with a video still to be converted is queued held, and its
	 * conversion is queued once the post exists for the job to read.
	 */
	public function testAPostWithAVideoToConvertIsHeldAndItsConversionQueued(): void {
		$until = time() + 600;
		$this->videoDeliveryHold->method('holdUntil')->willReturn($until);

		$order = [];
		$this->activityService->expects($this->once())->method('createActivity')
			->with($this->anything(), $this->isInstanceOf(Note::class), $this->anything(), $until)
			->willReturnCallback(function (Person $actor, ACore $item, ?ACore &$activity = null) use (&$order): string {
				$order[] = 'queued';
				$activity = new Create();
				$activity->setObject($item);

				return 'token';
			});
		$this->videoDeliveryHold->expects($this->once())->method('convertSoon')
			->with($this->isInstanceOf(Note::class))
			->willReturnCallback(static function () use (&$order): void {
				$order[] = 'conversion';
			});

		$this->service->createPost($this->post('my holiday'));

		$this->assertSame(['queued', 'conversion'], $order);
	}

	/** Every other post goes as it always did: no hold, no job. */
	public function testAPostWithNothingToConvertIsNeitherHeldNorQueuedForConversion(): void {
		$this->videoDeliveryHold->method('holdUntil')->willReturn(0);
		$this->activityService->expects($this->once())->method('createActivity')
			->with($this->anything(), $this->anything(), $this->anything(), 0)
			->willReturnCallback(function (Person $actor, ACore $item, ?ACore &$activity = null): string {
				$activity = new Create();
				$activity->setObject($item);

				return 'token';
			});
		$this->videoDeliveryHold->expects($this->never())->method('convertSoon');

		$this->service->createPost($this->post('just words'));
	}

	/**
	 * An edit made while the post still waits for its video waits with it,
	 * so the Update cannot overtake the Create it edits.
	 */
	public function testAnEditOfAHeldPostIsHeldToo(): void {
		$this->streamRequest->method('getStreamByNid')
			->willReturnOnConsecutiveCalls($this->storedNote(), $this->storedNote());
		$until = time() + 600;
		$this->videoDeliveryHold->method('holdUntil')->willReturn($until);
		$this->activityService->expects($this->once())->method('updateActivity')
			->with($this->anything(), $this->anything(), $until)
			->willReturn('token');
		$this->videoDeliveryHold->expects($this->once())->method('convertSoon');

		$this->service->editPost(7, $this->actor(), 'better words');
	}

	public function testAnEditOfAnyOtherPostGoesAtOnce(): void {
		$this->streamRequest->method('getStreamByNid')
			->willReturnOnConsecutiveCalls($this->storedNote(), $this->storedNote());
		$this->videoDeliveryHold->method('holdUntil')->willReturn(0);
		$this->activityService->expects($this->once())->method('updateActivity')
			->with($this->anything(), $this->anything(), 0)
			->willReturn('token');
		$this->videoDeliveryHold->expects($this->never())->method('convertSoon');

		$this->service->editPost(7, $this->actor(), 'better words');
	}

	public function testCreatePostMergesExplicitAndInlineRecipients(): void {
		$carol = new Person();
		$carol->setId('https://other.example/users/carol');
		$carol->setInbox('https://other.example/users/carol/inbox');
		$this->cacheActorService->method('getFromAccount')->willReturnMap([
			['carol@other.example', true, $carol],
			['bob@remote.example', true, $this->bob()],
		]);
		$this->expectCreateActivity($note);

		$post = $this->post('hi @bob@remote.example', Stream::TYPE_DIRECT);
		$post->addTo('carol@other.example');
		$this->service->createPost($post);

		$this->assertSame([$carol->getId(), self::BOB_ID], $note->getToArray());
	}

	// fixRecipientAndHashtags()

	/**
	 * @return array<string, array{string, string[], string[]}>
	 */
	public static function inlineTokensProvider(): array {
		return [
			'mention and hashtag' => ['hi @bob@remote.example #Nextcloud', ['bob@remote.example'], ['Nextcloud']],
			'several of each, deduplicated' => [
				'@a@x.tld @b@y.tld @a@x.tld #one #two #one', ['a@x.tld', 'b@y.tld'], ['one', 'two'],
			],
			'email-like text is not a mention' => ['write to frank@example.org', [], []],
			'hash inside a word is not a tag' => ['issue#42 is fixed', [], []],
			'leading tokens' => ['#first @bob@remote.example', ['bob@remote.example'], ['first']],
			'nothing' => ['plain words', [], []],
		];
	}

	#[DataProvider('inlineTokensProvider')]
	public function testFixRecipientAndHashtagsExtractsInlineTokens(string $content, array $to, array $hashtags): void {
		$post = $this->post($content);

		$this->service->fixRecipientAndHashtags($post);

		$this->assertSame($to, $post->getTo());
		$this->assertSame($hashtags, $post->getHashtags());
	}

	public function testFixRecipientAndHashtagsKeepsExplicitRecipientsFirst(): void {
		$post = $this->post('hi @bob@remote.example #tag');
		$post->addTo('carol@other.example');
		$post->addHashtag('Existing');

		$this->service->fixRecipientAndHashtags($post);

		$this->assertSame(['carol@other.example', 'bob@remote.example'], $post->getTo());
		$this->assertSame(['Existing', 'tag'], $post->getHashtags());
	}

	public function testCreatePostEscapesHtmlAndTurnsNewlinesIntoBreaks(): void {
		$this->expectCreateActivity($note);

		$this->service->createPost($this->post("line one\n<b>line two</b>"));

		$this->assertSame(
			'<p>line one<br />&lt;b&gt;line two&lt;/b&gt;</p>',
			$note->getContent(),
			'user text is escaped first, then markup is built around it'
		);
	}

	/**
	 * Peers render `content` and look for nothing to linkify in it, so a URL
	 * written here arrived on every one of them as dead text.
	 */
	public function testCreatePostLinksTheUrlsInTheText(): void {
		$this->expectCreateActivity($note);

		$this->service->createPost($this->post('read https://example.invalid/a'));

		$this->assertSame(
			'<p>read <a href="https://example.invalid/a" rel="nofollow noopener noreferrer">'
			. 'https://example.invalid/a</a></p>',
			$note->getContent()
		);
	}

	/**
	 * The links and the `tag` array come out of one parse, so the markup cannot
	 * name somebody the tags do not — which is the list a receiving instance
	 * checks a mention against before it notifies anybody.
	 */
	public function testAMentionThatResolvedToNobodyIsNeitherTaggedNorLinked(): void {
		$this->cacheActorService->method('getFromAccount')
			->willReturnCallback(fn (string $account): Person => $account === 'bob@remote.example'
				? $this->bob()
				: throw new CacheActorDoesNotExistException());
		$this->expectCreateActivity($note);

		$this->service->createPost($this->post('@bob@remote.example @ghost@nowhere.invalid'));

		$this->assertSame(
			[['type' => 'Mention', 'href' => self::BOB_ID, 'name' => '@bob@remote.example']],
			$note->getTags()
		);
		$this->assertStringContainsString('>@bob@remote.example</a>', $note->getContent());
		$this->assertStringContainsString('@ghost@nowhere.invalid</p>', $note->getContent());
		$this->assertSame(1, substr_count($note->getContent(), '<a '), 'only the tagged mention is a link');
	}

	/**
	 * A handle read as "everything up to the next space" swallowed the full
	 * stop that ended the sentence, and the post was addressed to — and
	 * federated as mentioning — an account nobody has.
	 */
	public function testAHandleAtTheEndOfASentenceKeepsItsDomainAndNotThePunctuation(): void {
		$this->cacheActorService->expects($this->once())
			->method('getFromAccount')
			->with('bob@remote.example', true)
			->willReturn($this->bob());
		$this->expectCreateActivity($note);

		$this->service->createPost($this->post('ask @bob@remote.example.'));

		$this->assertSame('@bob@remote.example', $note->getTags()[0]['name']);
		$this->assertStringEndsWith('</a>.</p>', $note->getContent());
	}

	public function testCreatePostWithAPollBuildsAQuestion(): void {
		$this->expectCreateActivity($note);

		$post = $this->post('Cats or dogs?');
		$post->setPoll(['options' => ['Cats', 'Dogs', '  ', 'Birds', 'Fish', 'Too many'], 'expires_in' => 3600, 'multiple' => true]);
		$this->service->createPost($post);

		$this->assertInstanceOf(Question::class, $note);
		$this->assertTrue($note->isMultiple());
		$this->assertCount(4, $note->getOptions(), 'blank options dropped, capped at four');
		$this->assertSame('Cats', $note->getOptions()[0]['title']);
		$this->assertStringContainsString('anyOf', $note->getSource(), 'the poll snapshot lives in the source');
	}

	public function testAPollLastsAsLongAsTheInstanceAdvertises(): void {
		$this->expectCreateActivity($note);

		$post = $this->post('Cats or dogs?');
		$post->setPoll([
			'options' => ['Cats', 'Dogs'], 'expires_in' => PostService::POLL_MAX_EXPIRATION,
		]);
		$this->service->createPost($post);

		$this->assertInstanceOf(Question::class, $note);
		$this->assertEqualsWithDelta(
			time() + PostService::POLL_MAX_EXPIRATION,
			strtotime($note->getEndTime()),
			10,
			'a poll asked for at the advertised maximum runs that long'
		);
	}

	public function testAPollShorterThanTheAdvertisedMinimumIsRaisedToIt(): void {
		$this->expectCreateActivity($note);

		$post = $this->post('Cats or dogs?');
		$post->setPoll(['options' => ['Cats', 'Dogs'], 'expires_in' => 1]);
		$this->service->createPost($post);

		$this->assertEqualsWithDelta(
			time() + PostService::POLL_MIN_EXPIRATION, strtotime($note->getEndTime()), 10
		);
	}

	public function testCreatePostWithASingleOptionPollIsRefused(): void {
		$this->activityService->expects($this->never())->method('createActivity');

		$post = $this->post('broken');
		$post->setPoll(['options' => ['Only one'], 'expires_in' => 3600]);

		$this->expectException(\InvalidArgumentException::class);

		$this->service->createPost($post);
	}

	public function testASuspendedAccountCannotPost(): void {
		// enforced where the post is made, so no client and no route can be the
		// one that was forgotten
		$this->moderationService->expects($this->once())
			->method('assertNotSuspended')
			->with(self::ACTOR_ID)
			->willThrowException(new InvalidActionException('this account is suspended'));
		$this->activityService->expects($this->never())->method('createActivity');

		$this->expectException(InvalidActionException::class);

		$this->service->createPost($this->post('still here'));
	}

	public function testASuspendedAccountCannotEditWhatItPosted(): void {
		$this->moderationService->method('assertNotSuspended')
			->willThrowException(new InvalidActionException('this account is suspended'));
		$this->streamRequest->expects($this->never())->method('update');
		$this->activityService->expects($this->never())->method('updateActivity');

		$this->expectException(InvalidActionException::class);

		$this->service->editPost(7, $this->actor(), 'new');
	}

	public function testAPostLongerThanTheAdvertisedLimitIsRefused(): void {
		$this->activityService->expects($this->never())->method('createActivity');

		$post = $this->post(str_repeat('a', InstanceService::MAX_CHARACTERS + 1));

		$this->expectException(InvalidActionException::class);

		$this->service->createPost($post);
	}

	public function testTheLimitCountsCharactersAndNotBytes(): void {
		$this->expectCreateActivity($note);

		// every one of these is three bytes, so a byte count would refuse it
		$post = $this->post(str_repeat('。', InstanceService::MAX_CHARACTERS));
		$this->service->createPost($post);

		$this->assertInstanceOf(Note::class, $note);
	}

	public function testTheSpoilerCountsTowardsTheSameLimit(): void {
		$this->activityService->expects($this->never())->method('createActivity');

		$post = $this->post(str_repeat('a', InstanceService::MAX_CHARACTERS - 1));
		$post->setSpoilerText('cw');

		$this->expectException(InvalidActionException::class);

		$this->service->createPost($post);
	}

	// editPost()

	private function storedNote(
		string $attributedTo = self::ACTOR_ID,
		string $visibility = Stream::TYPE_PUBLIC,
	): Note {
		$note = new Note();
		$note->setNid(7);
		$note->setId('https://social.example/@alice/7');
		$note->setAttributedTo($attributedTo);
		$note->setContent('old');
		$note->setSpoilerText('old cw');
		$note->setSensitive(false);
		$note->setPublished('2020-01-01T00:00:00+00:00');
		// every stored post has one, and it decides who an edit is federated to
		$note->setVisibility($visibility);
		$note->setLocal(true);

		return $note;
	}

	public function testAnEditLongerThanTheAdvertisedLimitIsRefused(): void {
		$this->streamRequest->method('getStreamByNid')->with(7)->willReturn($this->storedNote());
		$this->streamRequest->expects($this->never())->method('update');

		$this->expectException(InvalidActionException::class);

		$this->service->editPost(
			7, $this->actor(), str_repeat('a', InstanceService::MAX_CHARACTERS + 1)
		);
	}

	public function testEditPostUpdatesOwnPostAndFederatesAnUpdate(): void {
		$stored = $this->storedNote();
		$reloaded = $this->storedNote();
		$reloaded->setContent('new');
		$this->streamRequest->expects($this->exactly(2))
			->method('getStreamByNid')
			->with(7)
			->willReturnOnConsecutiveCalls($stored, $reloaded);
		$this->streamRequest->expects($this->once())
			->method('update')
			->with($this->identicalTo($stored), true);
		$this->activityService->expects($this->once())
			->method('updateActivity')
			->with(
				$this->callback(fn (Person $p): bool => $p->getId() === self::ACTOR_ID),
				$this->identicalTo($reloaded)
			)
			->willReturn('token');

		$result = $this->service->editPost(7, $this->actor(), 'new', 'new cw', true);

		$this->assertSame($reloaded, $result);
		$this->assertSame('<p>new</p>', $stored->getContent());
		$this->assertSame('new cw', $stored->getSpoilerText());
		$this->assertTrue($stored->isSensitive());
		$this->assertSame('2020-01-01T00:00:00+00:00', $stored->getPublished(), 'published is when the post was written, not when it was last edited');
		$this->assertEqualsWithDelta(time(), (new DateTime($stored->getUpdated()))->getTimestamp(), 5);

		$paths = $reloaded->getInstancePaths();
		$this->assertCount(1, $paths);
		$this->assertSame(self::ACTOR_ID, $paths[0]->getUri());
		$this->assertSame(InstancePath::TYPE_FOLLOWERS, $paths[0]->getType());
		$this->assertSame(InstancePath::PRIORITY_LOW, $paths[0]->getPriority());
	}

	/**
	 * An edit is a fresh parse of the text, so a hashtag written into a post
	 * has to gain its tag: without one it renders as dead text, the post
	 * reaches no tag timeline and nobody following the tag sees it.
	 */
	public function testEditPostTagsAHashtagTheEditAdded(): void {
		$stored = $this->storedNote();
		$this->streamRequest->method('getStreamByNid')
			->willReturnOnConsecutiveCalls($stored, $this->storedNote());
		$this->activityService->method('updateActivity')->willReturn('token');

		$this->service->editPost(7, $this->actor(), 'hello #nextcloud');

		$this->assertSame(['nextcloud'], $stored->getHashtags());
		$this->assertSame(
			[['type' => 'Hashtag', 'href' => self::SOCIAL_URL . 'tags/nextcloud', 'name' => '#nextcloud']],
			$stored->getTags('Hashtag')
		);
		$this->assertStringContainsString(
			'<a href="' . self::SOCIAL_URL . 'tags/nextcloud"', $stored->getContent(),
			'a tag the edit added has to be linked, not left as text'
		);
	}

	/**
	 * And a hashtag an edit removed has to lose it, or the post stays in that
	 * tag's timeline for ever.
	 */
	public function testEditPostDropsAHashtagTheEditRemoved(): void {
		$stored = $this->storedNote();
		$stored->setHashtags(['nextcloud']);
		$stored->addTag(['type' => 'Hashtag', 'href' => 'https://social.example/tags/nextcloud', 'name' => '#nextcloud']);
		$this->streamRequest->method('getStreamByNid')
			->willReturnOnConsecutiveCalls($stored, $this->storedNote());
		$this->activityService->method('updateActivity')->willReturn('token');

		$this->service->editPost(7, $this->actor(), 'hello again');

		$this->assertSame([], $stored->getHashtags());
		$this->assertSame([], $stored->getTags('Hashtag'));
	}

	/** An account named by the edit is addressed, and is not named twice. */
	public function testEditPostAddressesAnAccountTheEditNamed(): void {
		$stored = $this->storedNote();
		$this->cacheActorService->method('getFromAccount')->willReturn($this->bob());
		$this->streamRequest->method('getStreamByNid')
			->willReturnOnConsecutiveCalls($stored, $this->storedNote());
		$this->activityService->method('updateActivity')->willReturn('token');

		$this->service->editPost(7, $this->actor(), 'hi @bob@remote.example');

		$this->assertSame(
			[['type' => 'Mention', 'href' => self::BOB_ID, 'name' => '@bob@remote.example']],
			$stored->getTags('Mention')
		);
		$this->assertContains(self::BOB_ID, $stored->getCcArray());
		$this->assertStringContainsString('<a href="' . self::BOB_ID . '"', $stored->getContent());
	}

	public function testEditPostDoesNotNameTheSameAccountTwice(): void {
		$stored = $this->storedNote();
		$stored->addTag(['type' => 'Mention', 'href' => self::BOB_ID, 'name' => '@bob@remote.example']);
		$this->cacheActorService->expects($this->never())->method('getFromAccount');
		$this->streamRequest->method('getStreamByNid')
			->willReturnOnConsecutiveCalls($stored, $this->storedNote());
		$this->activityService->method('updateActivity')->willReturn('token');

		$this->service->editPost(7, $this->actor(), 'hi again @bob@remote.example');

		$this->assertCount(1, $stored->getTags('Mention'));
	}

	/**
	 * A direct message was delivered to the people it named. The followers path
	 * expands to the shared inbox of every instance with a follower on it, so
	 * adding it to the Update posts the whole edited text of a private message
	 * to servers that were never recipients.
	 */
	public function testEditingADirectMessageIsNotFederatedToTheFollowers(): void {
		$stored = $this->storedNote(self::ACTOR_ID, Stream::TYPE_DIRECT);
		$reloaded = $this->storedNote(self::ACTOR_ID, Stream::TYPE_DIRECT);
		$this->streamRequest->method('getStreamByNid')
			->willReturnOnConsecutiveCalls($stored, $reloaded);
		$this->activityService->method('updateActivity')->willReturn('token');

		$this->service->editPost(7, $this->actor(), 'new text');

		$this->assertSame([], $reloaded->getInstancePaths());
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function federatedVisibilityProvider(): array {
		return [
			'public' => [Stream::TYPE_PUBLIC],
			'unlisted' => [Stream::TYPE_UNLISTED],
			'followers-only' => [Stream::TYPE_FOLLOWERS],
		];
	}

	#[DataProvider('federatedVisibilityProvider')]
	public function testEditingAPostTheFollowersCouldSeeIsFederatedToThem(string $visibility): void {
		$stored = $this->storedNote(self::ACTOR_ID, $visibility);
		$reloaded = $this->storedNote(self::ACTOR_ID, $visibility);
		$this->streamRequest->method('getStreamByNid')
			->willReturnOnConsecutiveCalls($stored, $reloaded);
		$this->activityService->method('updateActivity')->willReturn('token');

		$this->service->editPost(7, $this->actor(), 'new text');

		$paths = $reloaded->getInstancePaths();
		$this->assertCount(1, $paths);
		$this->assertSame(InstancePath::TYPE_FOLLOWERS, $paths[0]->getType());
	}

	/**
	 * A content warning is plain text. `strip_tags()` reads a bare `<` as the
	 * start of a tag and eats the rest of the line, so `I <3 cats` was stored
	 * as `I ` — here and, once it was written, on every instance it reached.
	 */
	public function testAContentWarningKeepsABareLessThan(): void {
		$this->expectCreateActivity($note);

		$post = $this->post('body');
		$post->setSpoilerText('I <3 cats and 1<2');
		$this->service->createPost($post);

		$this->assertSame('I <3 cats and 1<2', $note->getSpoilerText());
	}

	public function testAnEditedContentWarningKeepsABareLessThanToo(): void {
		$stored = $this->storedNote();
		$this->streamRequest->method('getStreamByNid')
			->willReturnOnConsecutiveCalls($stored, $this->storedNote());
		$this->activityService->method('updateActivity')->willReturn('token');

		$this->service->editPost(7, $this->actor(), 'body', 'I <3 cats');

		$this->assertSame('I <3 cats', $stored->getSpoilerText());
	}

	public function testEditPostEscapesHtmlAndTurnsNewlinesIntoBreaks(): void {
		$stored = $this->storedNote();
		$this->streamRequest->method('getStreamByNid')
			->willReturnOnConsecutiveCalls($stored, $this->storedNote());
		$this->activityService->method('updateActivity')->willReturn('token');

		$this->service->editPost(7, $this->actor(), "line one\n<script>x()</script>");

		$this->assertSame(
			'<p>line one<br />&lt;script&gt;x()&lt;/script&gt;</p>',
			$stored->getContent(),
			'edited text goes through the same escaping as new posts'
		);
	}

	public function testEditPostLeavesSpoilerAndSensitivityAloneWhenNotProvided(): void {
		$stored = $this->storedNote();
		$stored->setSensitive(true);
		$this->streamRequest->method('getStreamByNid')->willReturnOnConsecutiveCalls($stored, $this->storedNote());
		$this->activityService->method('updateActivity')->willReturn('token');

		$this->service->editPost(7, $this->actor(), 'new');

		$this->assertSame('<p>new</p>', $stored->getContent());
		$this->assertSame('old cw', $stored->getSpoilerText());
		$this->assertTrue($stored->isSensitive());
	}

	public function testEditPostRefusesSomeoneElsesPost(): void {
		$this->streamRequest->method('getStreamByNid')->willReturn($this->storedNote(self::BOB_ID));
		$this->streamRequest->expects($this->never())->method('update');
		$this->activityService->expects($this->never())->method('updateActivity');

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Not authorized to edit this post');
		$this->service->editPost(7, $this->actor(), 'hijack');
	}

	public function testEditPostOfUnknownPostFails(): void {
		$this->streamRequest->method('getStreamByNid')->willThrowException(new StreamNotFoundException());
		$this->streamRequest->expects($this->never())->method('update');

		$this->expectException(StreamNotFoundException::class);
		$this->service->editPost(404, $this->actor(), 'new');
	}

	public function testEditPostReportsWhenTheLocalChangeCouldNotBeFederated(): void {
		$reloaded = $this->storedNote();
		$this->streamRequest->method('getStreamByNid')->willReturnOnConsecutiveCalls($this->storedNote(), $reloaded);
		$this->streamRequest->expects($this->once())->method('update');
		$this->activityService->method('updateActivity')->willThrowException(new \RuntimeException('remote down'));

		$this->expectException(FederationDeliveryException::class);
		$this->expectExceptionMessage('The post was saved locally, but its edit could not be sent to other instances.');

		$this->service->editPost(7, $this->actor(), 'new');
	}

	// language

	public function testCreatePostDefaultsTheLanguageToThePostersNextcloudLanguage(): void {
		$this->userLanguage = 'de_DE';
		$this->expectCreateActivity($note);

		$this->service->createPost($this->post('Hallo Welt'));

		$this->assertSame('de', $note->getLanguage(), 'the UI locale\'s region says nothing about the text');
		$this->assertSame(['de' => '<p>Hallo Welt</p>'], $note->exportAsActivityPub()['contentMap']);
		$this->assertSame('de', $note->exportAsLocal()['language']);
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function nextcloudLanguageProvider(): array {
		return [
			'a plain language' => ['fr', 'fr'],
			'a locale loses its region' => ['en_GB', 'en'],
			'Brazilian Portuguese keeps it: it is a different written language' => ['pt_BR', 'pt-BR'],
			'so does Traditional Chinese' => ['zh_TW', 'zh-TW'],
			'something unusable ends up English, like Nextcloud itself does' => ['sr@latin', 'en'],
		];
	}

	#[DataProvider('nextcloudLanguageProvider')]
	public function testTheDefaultLanguageIsDerivedFromTheNextcloudSetting(string $nextcloud, string $expected): void {
		$this->userLanguage = $nextcloud;
		$this->expectCreateActivity($note);

		$this->service->createPost($this->post('hi'));

		$this->assertSame($expected, $note->getLanguage());
	}

	public function testCreatePostUsesTheLanguageTheClientSent(): void {
		$this->userLanguage = 'de_DE';
		$this->expectCreateActivity($note);

		$post = $this->post('Bonjour');
		$post->setLanguage('fr');
		$this->service->createPost($post);

		$this->assertSame('fr', $note->getLanguage());
		$this->assertSame(['fr' => '<p>Bonjour</p>'], $note->exportAsActivityPub()['contentMap']);
	}

	public function testCreatePostSnapshotsTheNoteIntoTheSourceSoTheLanguageSurvivesTheDatabase(): void {
		$this->userLanguage = 'de';
		$this->expectCreateActivity($note);

		$this->service->createPost($this->post('Hallo'));

		$source = json_decode($note->getSource(), true);
		$this->assertSame(['de' => '<p>Hallo</p>'], $source['contentMap']);

		$reloaded = new Note();
		$reloaded->importFromDatabase(['id' => $note->getId(), 'type' => 'Note', 'content' => 'Hallo', 'source' => $note->getSource()]);
		$this->assertSame('de', $reloaded->getLanguage());
	}

	public function testEditPostAppliesTheLanguageTheClientSent(): void {
		$stored = $this->storedNote();
		$stored->setLanguage('fr');
		$this->streamRequest->method('getStreamByNid')->willReturnOnConsecutiveCalls($stored, $this->storedNote());
		$this->activityService->method('updateActivity')->willReturn('token');

		$this->service->editPost(7, $this->actor(), 'nuovo', null, null, 'it');

		$this->assertSame('it', $stored->getLanguage());
	}

	public function testEditPostKeepsTheStoredLanguageWhenTheClientSentNone(): void {
		$this->userLanguage = 'de_DE';
		$stored = $this->storedNote();
		$stored->setLanguage('fr');
		$this->streamRequest->method('getStreamByNid')->willReturnOnConsecutiveCalls($stored, $this->storedNote());
		$this->activityService->method('updateActivity')->willReturn('token');

		$this->service->editPost(7, $this->actor(), 'nouveau');

		$this->assertSame('fr', $stored->getLanguage());
	}

	public function testEditPostGivesAPostThatHadNoLanguageTheDefault(): void {
		$this->userLanguage = 'de_DE';
		$stored = $this->storedNote();
		$this->streamRequest->method('getStreamByNid')->willReturnOnConsecutiveCalls($stored, $this->storedNote());
		$this->activityService->method('updateActivity')->willReturn('token');

		$this->service->editPost(7, $this->actor(), 'neu');

		$this->assertSame('de', $stored->getLanguage());
	}

	// the Update on the wire

	/**
	 * Mastodon treats an `Update{Note}` without `updated` as an implicit
	 * update — poll counters are refreshed, the content change is discarded.
	 * The edit used to move `published` instead, which Mastodon ignores.
	 *
	 * The reload is simulated faithfully: the second `getStreamByNid()` rebuilds
	 * the Note from the row the update wrote, so this also proves that
	 * `updated` and the language survive the database.
	 */
	public function testEditPostFederatesAnExplicitUpdate(): void {
		$stored = $this->storedNote();
		$stored->setLanguage('de');
		$calls = 0;
		$this->streamRequest->method('getStreamByNid')->willReturnCallback(
			function () use ($stored, &$calls): Note {
				if (++$calls === 1) {
					return $stored;
				}

				$reloaded = new Note();
				$reloaded->importFromDatabase([
					'nid' => 7,
					'id' => $stored->getId(),
					'type' => 'Note',
					'attributed_to' => $stored->getAttributedTo(),
					'content' => $stored->getContent(),
					'summary' => $stored->getSummary(),
					'published' => $stored->getPublished(),
					'published_time' => '2020-01-01 00:00:00',
					'source' => $stored->getSource(),
					'local' => 1,
				]);

				return $reloaded;
			}
		);
		$this->activityService->expects($this->once())
			->method('updateActivity')
			->willReturnCallback(function (Person $actor, ACore $item) use (&$federated): string {
				$federated = $item;

				return 'token';
			});

		$this->service->editPost(7, $this->actor(), 'neu', 'neue CW');

		$this->assertNotSame($stored, $federated, 'what federates is the reloaded row');
		$wire = json_decode(json_encode($federated), true);

		$this->assertSame('<p>neu</p>', $wire['content']);
		$this->assertSame('2020-01-01T00:00:00+00:00', $wire['published'], 'published is untouched by an edit');
		$this->assertArrayHasKey('updated', $wire, 'without `updated` Mastodon drops the content change');
		$this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $wire['updated'], 'ISO-8601, UTC');
		$this->assertGreaterThan(strtotime($wire['published']), strtotime($wire['updated']));
		$this->assertEqualsWithDelta(time(), strtotime($wire['updated']), 5);
		$this->assertSame(['de' => '<p>neu</p>'], $wire['contentMap']);
		$this->assertSame(['de' => 'neue CW'], $wire['summaryMap']);

		// and the client sees the same edit
		$status = $federated->exportAsLocal();
		$this->assertSame('2020-01-01T00:00:00.000Z', $status['created_at']);
		$this->assertSame(gmdate('Y-m-d\TH:i:s', strtotime($wire['updated'])) . '.000Z', $status['edited_at']);
	}

	public function testEditPostSnapshotsTheEditedNoteIntoTheSource(): void {
		$stored = $this->storedNote();
		$this->streamRequest->method('getStreamByNid')->willReturnOnConsecutiveCalls($stored, $this->storedNote());
		$this->activityService->method('updateActivity')->willReturn('token');

		$this->service->editPost(7, $this->actor(), 'new');

		$source = json_decode($stored->getSource(), true);
		$this->assertSame('<p>new</p>', $source['content'], 'the source is what a re-export reads');
		$this->assertSame($stored->getUpdated(), $source['updated']);
	}

	/**
	 * Nothing else on a Nextcloud server could know that somebody had posted,
	 * so every integration would have had to poll this app's API as a client
	 * from inside the same server.
	 */
	public function testPublishingAPostTellsTheRestOfTheServer(): void {
		$this->service->createPost($this->post('something worth hearing about'));

		$published = array_values(array_filter(
			$this->dispatched,
			static fn (object $event): bool => $event instanceof \OCA\Social\Events\PostPublishedEvent
		));

		$this->assertCount(1, $published);
		$this->assertSame(
			'something worth hearing about',
			strip_tags($published[0]->getPost()->getContent())
		);
		$this->assertSame(self::ACTOR_ID, $published[0]->getAuthorId());
	}

	/** Replying teaches For you the hashtags of the post replied to. */
	public function testAReplyTeachesTheParentsTags(): void {
		$parentId = 'https://remote.example/notes/parent';
		$parent = new Note();
		$parent->setId($parentId);
		$parent->setAttributedTo(self::BOB_ID);
		$this->streamRequest->method('getStreamById')->willReturn($parent);
		$this->cacheActorService->method('getFromId')->willReturn($this->bob());
		$this->expectCreateActivity($note);
		$this->interestService->expects($this->once())->method('recordAction')
			->with($this->isInstanceOf(Person::class), $this->identicalTo($parent), \OCA\Social\Service\InterestService::ACTION_REPLY);

		$post = $this->post('I agree');
		$post->setReplyTo($parentId);
		$this->service->createPost($post);
	}

	public function testAPostThatRepliesToNothingTeachesNothing(): void {
		$this->expectCreateActivity($note);
		$this->interestService->expects($this->never())->method('recordAction');

		$this->service->createPost($this->post('just saying'));
	}
}
