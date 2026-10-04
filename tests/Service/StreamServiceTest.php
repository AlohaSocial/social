<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use DateTime;
use OCA\Social\AP;
use OCA\Social\Db\MediaTagsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Group;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\InstancePath;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ChannelService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\EmojiService;
use OCA\Social\Service\LinkPreviewService;
use OCA\Social\Service\PlaceService;
use OCA\Social\Service\ReactionSummaryService;
use OCA\Social\Service\StreamService;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class StreamServiceTest extends TestCase {
	private const SOCIAL_URL = 'https://social.example/';
	private const ACTOR_ID = 'https://social.example/@alice';
	private const ACTOR_FOLLOWERS = 'https://social.example/@alice/followers';
	private const GENERATED_ID = 'https://social.example/@alice/1234567890';
	private const CHANNEL_ID = 'https://social.example/@alice_channel';

	private StreamRequest|MockObject $streamRequest;
	private ActivityService|MockObject $activityService;
	private CacheActorService|MockObject $cacheActorService;
	private ConfigService|MockObject $configService;
	private CurlService|MockObject $curlService;
	private LinkPreviewService|MockObject $linkPreviewService;
	private EmojiService|Stub $emojiService;
	private LoggerInterface|MockObject $logger;

	/** @var array[] the Emoji tags the instance has for whatever it is handed */
	private array $emojiTags = [];
	/** The text the emoji service was asked to find shortcodes in. */
	private string $emojiScanned = '';
	private IURLGenerator|MockObject $urlGenerator;
	private ChannelService|MockObject $channelService;
	private bool $publishVideo = true;
	private StreamService $service;

	protected function setUp(): void {
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('linkToRouteAbsolute')
			->willReturnCallback(
				static fn (string $route, array $args): string => 'https://social.example/index.php/apps/social/'
					. $route . '/' . ($args['path'] ?? '')
			);
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->activityService = $this->createMock(ActivityService::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->curlService = $this->createMock(CurlService::class);
		$this->linkPreviewService = $this->createMock(LinkPreviewService::class);

		$this->configService->method('generateId')->willReturn(self::GENERATED_ID);
		$this->configService->method('getSocialUrl')->willReturn(self::SOCIAL_URL);
		$this->configService->method('getAppValueBool')->willReturnCallback(
			fn (string $key): bool => ($key === ConfigService::SOCIAL_PUBLISH_VIDEO) && $this->publishVideo
		);
		$this->channelService = $this->createMock(ChannelService::class);
		$this->channelService->method('attributionOf')->willReturnCallback(
			static fn (string $authorId): array => ($authorId === self::ACTOR_ID) ? [
				['type' => Group::TYPE, 'id' => self::CHANNEL_ID],
				['type' => Person::TYPE, 'id' => self::ACTOR_ID],
			] : []
		);

		$this->emojiService = $this->createStub(EmojiService::class);
		$this->emojiService->method('tagsFor')->willReturnCallback(
			function (string $text): array {
				$this->emojiScanned = $text;

				return $this->emojiTags;
			}
		);

		$this->accountService = $this->createMock(AccountService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new StreamService(
			$this->urlGenerator,
			$this->streamRequest,
			$this->activityService,
			$this->cacheActorService,
			$this->configService,
			$this->curlService,
			$this->linkPreviewService,
			$this->emojiService,
			$this->createStub(\OCP\EventDispatcher\IEventDispatcher::class),
			$this->logger,
			$this->createStub(PlaceService::class),
			$this->createStub(ReactionSummaryService::class),
			$this->createStub(MediaTagsRequest::class),
			$this->accountService,
			$this->channelService
		);

		// `Note::fillMentions()` asks the registry for the Person interface, so
		// a sync of a post that names anybody needs one; a mention nobody here
		// knows keeps the handle the post wrote, which is what this produces.
		$personInterface = $this->createStub(IActivityPubInterface::class);
		$personInterface->method('getItemById')
			->willThrowException(new ItemNotFoundException());
		AP::set($this->createStub(AP::class));
		AP::instance()->method('getInterfaceFromType')->willReturn($personInterface);
	}

	protected function tearDown(): void {
		AP::set(null);

		parent::tearDown();
	}

	/** A post by alice, with a video attached or a picture. */
	private function videoPost(string $visibility, string $mediaType = 'video'): Note {
		$note = new Note();
		$note->setAttributedTo(self::ACTOR_ID);
		$note->setVisibility($visibility);
		$note->setAttachments([(new MediaAttachment())->setId('1')->setType($mediaType)]);

		return $note;
	}

	/** @return string[] the followers collections an activity about $note is delivered to */
	private function followersPaths(Note $note): array {
		return array_values(array_map(
			static fn (InstancePath $path): string => $path->getUri(),
			array_filter(
				$note->getInstancePaths(),
				static fn (InstancePath $path): bool => $path->getType() === InstancePath::TYPE_FOLLOWERS
			)
		));
	}

	/**
	 * A PeerTube follows the channel a video is filed under, not the person
	 * behind it, so a video delivered to the author's followers alone never
	 * reached it.
	 */
	public function testAPublicVideoReachesTheFollowersOfItsChannel(): void {
		$note = $this->videoPost(Stream::TYPE_PUBLIC);

		$this->service->addChannelFollowers($note);

		$this->assertSame([self::CHANNEL_ID], $this->followersPaths($note));
	}

	public function testAnUnlistedVideoReachesTheFollowersOfItsChannel(): void {
		$note = $this->videoPost(Stream::TYPE_UNLISTED);

		$this->service->addChannelFollowers($note);

		$this->assertSame([self::CHANNEL_ID], $this->followersPaths($note));
	}

	/** Following a channel is not following the author's followers-only posts. */
	public function testAFollowersOnlyVideoNeverReachesTheChannelsFollowers(): void {
		$note = $this->videoPost(Stream::TYPE_FOLLOWERS);

		$this->service->addChannelFollowers($note);

		$this->assertSame([], $this->followersPaths($note));
	}

	public function testAPostWithoutAVideoNeverReachesTheChannelsFollowers(): void {
		$note = $this->videoPost(Stream::TYPE_PUBLIC, 'image');

		$this->service->addChannelFollowers($note);

		$this->assertSame([], $this->followersPaths($note));
	}

	/** With video publishing off a video goes out as a Note, which a channel's follower has no use for. */
	public function testNoChannelIsAddressedWhileVideosArePublishedAsNotes(): void {
		$this->publishVideo = false;
		$note = $this->videoPost(Stream::TYPE_PUBLIC);

		$this->service->addChannelFollowers($note);

		$this->assertSame([], $this->followersPaths($note));
	}

	private function actor(): Person {
		$actor = new Person();
		$actor->setId(self::ACTOR_ID);
		$actor->setPreferredUsername('alice');
		$actor->setFollowers(self::ACTOR_FOLLOWERS);
		$actor->setInbox(self::ACTOR_ID . '/inbox');
		$actor->setOutbox(self::ACTOR_ID . '/outbox');
		$actor->setLocal(true);

		return $actor;
	}

	private function remoteActor(string $id = 'https://remote.example/users/bob'): Person {
		$actor = new Person();
		$actor->setId($id);
		$actor->setPreferredUsername('bob');
		$actor->setAccount('bob@remote.example');
		$actor->setInbox($id . '/inbox');
		// one shared inbox per instance, which is what makes two actors on one
		// host a single delivery
		$actor->setSharedInbox('https://' . parse_url($id, PHP_URL_HOST) . '/inbox');
		$actor->setFollowers($id . '/followers');
		$actor->setOutbox($id . '/outbox');

		return $actor;
	}

	private AccountService|MockObject $accountService;

	private function note(
		string $id,
		string $attributedTo = self::ACTOR_ID,
		string $inReplyTo = '',
		string $visibility = Stream::TYPE_PUBLIC,
	): Note {
		$note = new Note();
		$note->setId($id);
		$note->setAttributedTo($attributedTo);
		$note->setInReplyTo($inReplyTo);
		// every stored post carries one, and it decides whether an activity
		// about the post may go to the author's followers
		$note->setVisibility($visibility);

		return $note;
	}

	/**
	 * @param InstancePath[] $paths
	 */
	private function assertHasInstancePath(array $paths, string $uri, int $type, int $priority): void {
		foreach ($paths as $path) {
			if ($path->getUri() === $uri && $path->getType() === $type && $path->getPriority() === $priority) {
				$this->addToAssertionCount(1);

				return;
			}
		}

		$this->fail('No instance path for ' . $uri . ' (type ' . $type . ', priority ' . $priority . ') in ' . json_encode($paths));
	}

	// assignItem() / assignStream()

	public function testAssignItemGeneratesIdFromActorAndMarksItemLocal(): void {
		$this->configService->expects($this->once())
			->method('generateId')
			->with('@alice');

		$note = new Note();
		$this->service->assignItem($note, $this->actor(), Stream::TYPE_PUBLIC);

		$this->assertSame(self::GENERATED_ID, $note->getId());
		$this->assertTrue($note->isLocal());
		$this->assertNotSame('', $note->getPublished());
		$this->assertEqualsWithDelta(time(), (new DateTime($note->getPublished()))->getTimestamp(), 5);
		// Stream items also get their numeric publication time
		$this->assertEqualsWithDelta(time(), $note->getPublishedTime(), 5);
	}

	public function testAssignItemOnNonStreamItemDoesNotNeedAStream(): void {
		$announce = new Announce();
		$this->service->assignItem($announce, $this->actor(), Stream::TYPE_ANNOUNCE);

		$this->assertTrue($announce->isLocal());
		$this->assertSame(self::GENERATED_ID, $announce->getId());
	}

	public function testAssignStreamConvertsPublishedDateToTimestamp(): void {
		$note = new Note();
		$note->setPublished('2026-01-02T03:04:05+00:00');

		$this->service->assignStream($note);

		$this->assertSame((new DateTime('2026-01-02T03:04:05+00:00'))->getTimestamp(), $note->getPublishedTime());
	}

	/**
	 * @return array<string, array{string, string, string[], bool, bool}>
	 */
	public static function visibilityProvider(): array {
		return [
			'public: as:Public in to, followers in cc' => [
				Stream::TYPE_PUBLIC, ACore::CONTEXT_PUBLIC, [self::ACTOR_FOLLOWERS], true, true,
			],
			'unlisted: followers in to, as:Public in cc' => [
				Stream::TYPE_UNLISTED, self::ACTOR_FOLLOWERS, [ACore::CONTEXT_PUBLIC], true, true,
			],
			'followers-only: followers in to, nobody in cc' => [
				Stream::TYPE_FOLLOWERS, self::ACTOR_FOLLOWERS, [], false, true,
			],
			'direct: nobody addressed until mentions are added' => [
				Stream::TYPE_DIRECT, '', [], false, false,
			],
			'announce: followers in cc only' => [
				Stream::TYPE_ANNOUNCE, '', [self::ACTOR_FOLLOWERS], false, true,
			],
			// An unknown visibility must never be treated as public: that is how
			// a Mastodon client's `private` (which this app calls `followers`)
			// used to be published to the whole Fediverse.
			'unknown type is addressed to nobody' => [
				'', '', [], false, false,
			],
		];
	}

	#[DataProvider('visibilityProvider')]
	public function testAssignItemAddressesRecipientsPerVisibility(
		string $type,
		string $expectedTo,
		array $expectedCc,
		bool $expectedPublic,
		bool $expectFollowersPath,
	): void {
		$note = new Note();
		$this->service->assignItem($note, $this->actor(), $type);

		$this->assertSame($expectedTo, $note->getTo());
		$this->assertSame([], $note->getToArray());
		$this->assertSame($expectedCc, $note->getCcArray());
		$this->assertSame($expectedPublic, $note->isPublic());

		if ($expectFollowersPath) {
			$this->assertCount(1, $note->getInstancePaths());
			$this->assertHasInstancePath(
				$note->getInstancePaths(), self::ACTOR_ID, InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW
			);
		} else {
			$this->assertSame([], $note->getInstancePaths());
		}
	}

	// detectType()

	public function testDetectTypeMarksPublicStreamsAsPublicTimeline(): void {
		$note = new Note();
		$note->setTo(ACore::CONTEXT_PUBLIC);

		$this->cacheActorService->expects($this->never())->method('getFromId');

		$this->service->detectType($note);

		$this->assertSame(Stream::TYPE_PUBLIC, $note->getTimeline());
		$this->assertSame(Note::TYPE, $note->getType());
	}

	public function testDetectTypeFindsPublicInToArrayToo(): void {
		$note = new Note();
		$note->setTo(self::ACTOR_FOLLOWERS);
		$note->addToArray(ACore::CONTEXT_PUBLIC);

		$this->service->detectType($note);

		$this->assertSame(Stream::TYPE_PUBLIC, $note->getTimeline());
	}

	public function testDetectTypeMarksUnlistedStreamsAsUnlistedTimelineWithoutRewritingTheirType(): void {
		$note = new Note();
		$note->setTo(self::ACTOR_FOLLOWERS);
		$note->addCc(ACore::CONTEXT_PUBLIC);

		$this->cacheActorService->expects($this->never())->method('getFromId');

		$this->service->detectType($note);

		$this->assertSame(Stream::TYPE_UNLISTED, $note->getTimeline());
		// the timeline is not the ActivityPub type: an unlisted Note is still a Note
		$this->assertSame(Note::TYPE, $note->getType());
	}

	/**
	 * @return array<string, array{string, string[]}>
	 */
	public static function followersAddressingProvider(): array {
		return [
			'followers collection in to' => [self::ACTOR_FOLLOWERS, []],
			'followers collection in cc' => ['', [self::ACTOR_FOLLOWERS]],
		];
	}

	#[DataProvider('followersAddressingProvider')]
	public function testDetectTypeMarksStreamsAddressedToTheFollowersCollectionAsFollowers(
		string $to,
		array $cc,
	): void {
		$note = new Note();
		$note->setAttributedTo(self::ACTOR_ID);
		$note->setTo($to);
		$note->setCcArray($cc);

		$this->cacheActorService->expects($this->once())
			->method('getFromId')
			->with(self::ACTOR_ID)
			->willReturn($this->actor());

		$this->service->detectType($note);

		$this->assertSame(Stream::TYPE_FOLLOWERS, $note->getTimeline());
	}

	public function testDetectTypeMarksStreamsAddressedToASingleRecipientAsDirect(): void {
		$note = new Note();
		$note->setAttributedTo(self::ACTOR_ID);
		$note->setTo($this->remoteActor()->getId());

		$this->cacheActorService->method('getFromId')->with(self::ACTOR_ID)->willReturn($this->actor());

		// classifying a stream never writes anything to the HTTP response body
		$this->expectOutputString('');

		$this->service->detectType($note);

		$this->assertSame(Stream::TYPE_DIRECT, $note->getTimeline());
	}

	public function testDetectTypeLeavesTheTimelineUnsetWhenTheAuthorCannotBeResolved(): void {
		$note = new Note();
		$note->setAttributedTo('https://remote.example/users/ghost');
		$note->setTo($this->remoteActor()->getId());

		$this->cacheActorService->method('getFromId')
			->willThrowException(new CacheActorDoesNotExistException());

		$this->service->detectType($note);

		$this->assertSame('', $note->getTimeline());
	}

	// addRecipient() / addRecipients()

	public function testAddRecipientOnDirectMessageAddressesMentionedActorInTo(): void {
		$bob = $this->remoteActor();
		$this->cacheActorService->expects($this->once())
			->method('getFromAccount')
			->with('bob@remote.example', true)
			->willReturn($bob);

		$note = new Note();
		$this->service->addRecipient($note, Stream::TYPE_DIRECT, 'bob@remote.example');

		$this->assertSame([$bob->getId()], $note->getToArray());
		$this->assertSame([], $note->getCcArray());
		$this->assertTrue($note->isFilterDuplicate());
		$this->assertSame(
			[['type' => 'Mention', 'href' => $bob->getId(), 'name' => '@bob@remote.example']],
			$note->getTags()
		);
		$this->assertCount(1, $note->getInstancePaths());
		$this->assertHasInstancePath(
			$note->getInstancePaths(), $bob->getSharedInbox(), InstancePath::TYPE_INBOX,
			InstancePath::PRIORITY_HIGH
		);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function nonDirectTypeProvider(): array {
		return [
			'public' => [Stream::TYPE_PUBLIC],
			'unlisted' => [Stream::TYPE_UNLISTED],
			'followers' => [Stream::TYPE_FOLLOWERS],
		];
	}

	#[DataProvider('nonDirectTypeProvider')]
	public function testAddRecipientOnNonDirectPostAddressesMentionedActorInCc(string $type): void {
		$bob = $this->remoteActor();
		$this->cacheActorService->method('getFromAccount')->willReturn($bob);

		$note = new Note();
		$this->service->addRecipient($note, $type, 'bob@remote.example');

		$this->assertSame([], $note->getToArray());
		$this->assertSame([$bob->getId()], $note->getCcArray());
		$this->assertFalse($note->isFilterDuplicate());
		$this->assertSame('Mention', $note->getTags()[0]['type']);
		$this->assertHasInstancePath(
			$note->getInstancePaths(), $bob->getSharedInbox(), InstancePath::TYPE_INBOX,
			InstancePath::PRIORITY_MEDIUM
		);
	}

	public function testAddRecipientIgnoresEmptyAccount(): void {
		$this->cacheActorService->expects($this->never())->method('getFromAccount');

		$note = new Note();
		$this->service->addRecipient($note, Stream::TYPE_PUBLIC, '');

		$this->assertSame([], $note->getTags());
		$this->assertSame([], $note->getInstancePaths());
	}

	public function testAddRecipientIgnoresUnresolvableAccount(): void {
		$this->cacheActorService->method('getFromAccount')
			->willThrowException(new CacheActorDoesNotExistException());
		$this->logger->expects($this->once())
			->method('notice')
			->with(
				'cannot resolve a mentioned account for outbound delivery',
				$this->callback(static fn (array $context): bool
					=> $context['account'] === 'nobody@remote.example'
						&& $context['exception'] instanceof CacheActorDoesNotExistException)
			);

		$note = new Note();
		$this->service->addRecipient($note, Stream::TYPE_DIRECT, 'nobody@remote.example');

		$this->assertSame([], $note->getToArray());
		$this->assertSame([], $note->getCcArray());
		$this->assertSame([], $note->getTags());
		$this->assertSame([], $note->getInstancePaths());
	}

	public function testAddRecipientsResolvesEveryAccount(): void {
		$bob = $this->remoteActor('https://remote.example/users/bob');
		$carol = $this->remoteActor('https://other.example/users/carol');
		$this->cacheActorService->expects($this->exactly(2))
			->method('getFromAccount')
			->willReturnMap([
				['bob@remote.example', true, $bob],
				['carol@other.example', true, $carol],
			]);

		$note = new Note();
		$this->service->addRecipients($note, Stream::TYPE_PUBLIC, ['bob@remote.example', 'carol@other.example']);

		$this->assertSame([$bob->getId(), $carol->getId()], $note->getCcArray());
		$this->assertCount(2, $note->getTags('Mention'));
		$this->assertCount(2, $note->getInstancePaths());
	}

	/**
	 * Mastodon addresses a mention at `Account#preferred_inbox_url`: the shared
	 * inbox where the instance publishes one. Sending to the personal inbox of
	 * each mentioned account meant three people on one server were three POSTs
	 * of the same post to the same server.
	 */
	public function testTwoMentionsOnOneInstanceAddressOneInbox(): void {
		$bob = $this->remoteActor('https://remote.example/users/bob');
		$dan = $this->remoteActor('https://remote.example/users/dan');
		$this->cacheActorService->method('getFromAccount')
			->willReturnMap([
				['bob@remote.example', true, $bob],
				['dan@remote.example', true, $dan],
			]);

		$note = new Note();
		$this->service->addRecipients($note, Stream::TYPE_PUBLIC, ['bob@remote.example', 'dan@remote.example']);

		$uris = array_map(static fn (InstancePath $path): string => $path->getUri(), $note->getInstancePaths());
		$this->assertSame(['https://remote.example/inbox', 'https://remote.example/inbox'], $uris);
		$this->assertCount(2, $note->getTags('Mention'));
	}

	/**
	 * `endpoints.sharedInbox` is optional, and an instance that publishes none
	 * must keep receiving what it is mentioned in.
	 */
	public function testAMentionFallsBackToThePersonalInbox(): void {
		$bob = $this->remoteActor();
		$bob->setSharedInbox('');
		$this->cacheActorService->method('getFromAccount')->willReturn($bob);

		$note = new Note();
		$this->service->addRecipient($note, Stream::TYPE_DIRECT, 'bob@remote.example');

		$this->assertHasInstancePath(
			$note->getInstancePaths(), $bob->getInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_HIGH
		);
	}

	public function testAMentionWithNoInboxAtAllIsStillAddressedAndTagged(): void {
		$bob = $this->remoteActor();
		$bob->setSharedInbox('');
		$bob->setInbox('');
		$this->cacheActorService->method('getFromAccount')->willReturn($bob);

		$note = new Note();
		$this->service->addRecipient($note, Stream::TYPE_PUBLIC, 'bob@remote.example');

		$this->assertSame([$bob->getId()], $note->getCcArray());
		$this->assertCount(1, $note->getTags('Mention'));
		$this->assertSame([], $note->getInstancePaths());
	}

	// addHashtag() / addHashtags()

	/**
	 * The href used to be `<social url>tag/<tag>`, a path no route serves. The
	 * hashtag timeline lives under the Navigation route at `tags/<tag>` — the
	 * same URL UnifiedSearchProvider hands out for a hashtag.
	 */
	public function testAddHashtagLinksToTheHashtagTimelineRoute(): void {
		$this->urlGenerator->expects($this->once())
			->method('linkToRouteAbsolute')
			->with('social.Navigation.timeline', ['path' => 'tags/nextcloud']);

		$note = new Note();
		$this->service->addHashtag($note, 'NextCloud');

		$this->assertSame(
			[[
				'type' => 'Hashtag',
				'href' => 'https://social.example/index.php/apps/social/social.Navigation.timeline/tags/nextcloud',
				'name' => '#NextCloud',
			]],
			$note->getTags()
		);
	}

	public function testAddHashtagsStoresListAndTagsEachEntry(): void {
		$note = new Note();
		$this->service->addHashtags($note, ['Fediverse', 'Nextcloud']);

		$this->assertSame(['Fediverse', 'Nextcloud'], $note->getHashtags());
		$this->assertCount(2, $note->getTags('Hashtag'));
		$this->assertSame('#Fediverse', $note->getTags()[0]['name']);
		$this->assertStringEndsWith('/tags/nextcloud', $note->getTags()[1]['href']);
	}

	public function testAddHashtagDoesNotNeedTheSocialUrl(): void {
		$configService = $this->createStub(ConfigService::class);
		$configService->method('getSocialUrl')->willThrowException(new SocialAppConfigException());
		$service = new StreamService(
			$this->urlGenerator,
			$this->streamRequest,
			$this->activityService,
			$this->cacheActorService,
			$configService,
			$this->curlService,
			$this->linkPreviewService,
			$this->emojiService,
			$this->createStub(\OCP\EventDispatcher\IEventDispatcher::class),
			new NullLogger(),
			$this->createStub(PlaceService::class),
			$this->createStub(ReactionSummaryService::class),
			$this->createStub(MediaTagsRequest::class),
			$this->createStub(AccountService::class),
			$this->createStub(ChannelService::class)
		);

		$note = new Note();
		$service->addHashtags($note, ['Nextcloud']);

		$this->assertSame(['Nextcloud'], $note->getHashtags());
		$this->assertStringEndsWith('/tags/nextcloud', $note->getTags()[0]['href']);
	}

	// replyTo()

	public function testReplyToWiresParentAndAddressesItsAuthor(): void {
		$parentId = 'https://remote.example/notes/parent';
		$bob = $this->remoteActor();
		$this->streamRequest->expects($this->once())
			->method('getStreamById')
			->with($parentId)
			->willReturn($this->note($parentId, $bob->getId()));
		$this->cacheActorService->expects($this->once())
			->method('getFromId')
			->with($bob->getId())
			->willReturn($bob);

		$note = new Note();
		$this->service->replyTo($note, $parentId);

		$this->assertSame($parentId, $note->getInReplyTo());
		$this->assertCount(1, $note->getInstancePaths());
		$this->assertHasInstancePath(
			$note->getInstancePaths(), $bob->getSharedInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_HIGH
		);
	}

	public function testReplyToWithoutParentIsANoop(): void {
		$this->streamRequest->expects($this->never())->method('getStreamById');

		$note = new Note();
		$this->service->replyTo($note, '');

		$this->assertSame('', $note->getInReplyTo());
		$this->assertSame([], $note->getInstancePaths());
	}

	public function testReplyToUnknownParentFails(): void {
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$this->cacheActorService->expects($this->never())->method('getFromId');

		$note = new Note();
		try {
			$this->service->replyTo($note, 'https://remote.example/notes/missing');
			$this->fail('expected StreamNotFoundException');
		} catch (StreamNotFoundException $e) {
			$this->assertSame('', $note->getInReplyTo());
			$this->assertSame([], $note->getInstancePaths());
		}
	}

	// deleteLocalItem()

	public function testDeleteLocalItemFederatesDeleteAndRemovesRow(): void {
		$item = $this->note('https://social.example/@alice/1');
		$item->setLocal(true);

		$this->cacheActorService->method('getFromId')->with(self::ACTOR_ID)->willReturn($this->actor());
		$this->activityService->expects($this->once())
			->method('deleteActivity')
			->with($this->identicalTo($item))
			->willReturn('token');
		$this->streamRequest->expects($this->once())
			->method('deleteById')
			->with('https://social.example/@alice/1', Note::TYPE);

		$this->service->deleteLocalItem($item, Note::TYPE);

		$this->assertSame(self::ACTOR_ID, $item->getActorId());
		$this->assertHasInstancePath(
			$item->getInstancePaths(), self::ACTOR_ID, InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW
		);
	}

	/**
	 * A post travels further than the author's followers: every boost carried it
	 * to the booster's followers, every reply to the replier's. Mastodon sends the
	 * Delete to those actors' inboxes too; without that the post lingers on every
	 * instance that only ever saw it through a boost.
	 */
	public function testDeleteLocalItemAlsoAddressesBoostersAndRepliers(): void {
		$item = $this->note('https://social.example/@alice/1');
		$item->setLocal(true);
		$this->cacheActorService->method('getFromId')->willReturn($this->actor());

		$bob = $this->remoteActor();
		$boost = new Announce();
		$boost->setId('https://remote.example/users/bob/statuses/9/activity');
		$boost->setAttributedTo($bob->getId());
		$boost->setActor($bob);

		$secondBoost = new Announce(); // same server as bob: one shared inbox, one delivery
		$secondBoost->setId('https://remote.example/users/dan/statuses/3/activity');
		$secondBoost->setActor($this->remoteActor('https://remote.example/users/dan'));

		$carol = $this->remoteActor('https://other.example/users/carol');
		$carol->setSharedInbox(''); // only a personal inbox
		$reply = $this->note('https://other.example/notes/5', $carol->getId(), $item->getId());
		$reply->setActor($carol);

		$localBoost = new Announce();
		$localBoost->setActor($this->actor()); // our own boost: nothing to deliver

		$unresolved = new Announce(); // author not in the actor cache: no inbox to use
		$unresolved->setAttributedTo('https://nowhere.example/users/x');

		$this->streamRequest->expects($this->once())
			->method('getAnnouncesAndRepliesTo')
			->with($item->getId())
			->willReturn([$boost, $secondBoost, $reply, $localBoost, $unresolved]);
		$this->activityService->expects($this->once())->method('deleteActivity')->willReturn('token');

		$this->service->deleteLocalItem($item, Note::TYPE);

		$paths = $item->getInstancePaths();
		$this->assertHasInstancePath($paths, 'https://remote.example/inbox', InstancePath::TYPE_INBOX, InstancePath::PRIORITY_MEDIUM);
		$this->assertHasInstancePath($paths, $carol->getInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_MEDIUM);
		$this->assertHasInstancePath($paths, self::ACTOR_ID, InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW);
		$this->assertCount(3, $paths);
	}

	/**
	 * A direct message was delivered to the people it named. The followers path
	 * expands to the shared inbox of every instance with a follower on it, so
	 * adding it to the Delete tells servers that were never recipients that the
	 * message existed.
	 */
	public function testDeletingADirectMessageIsNotAnnouncedToTheFollowers(): void {
		$item = $this->note('https://social.example/@alice/1', self::ACTOR_ID, '', Stream::TYPE_DIRECT);
		$item->setLocal(true);
		$this->cacheActorService->method('getFromId')->willReturn($this->actor());
		$this->activityService->expects($this->once())->method('deleteActivity')->willReturn('token');

		$this->service->deleteLocalItem($item, Note::TYPE);

		$this->assertSame([], $item->getInstancePaths());
	}

	/**
	 * A row written before the visibility column existed carries none, and is
	 * judged by what it addresses — which is where the column came from.
	 */
	public function testAPostWithoutAStoredVisibilityIsJudgedByItsAddressing(): void {
		$item = $this->note('https://social.example/@alice/1', self::ACTOR_ID, '', '');
		$item->setLocal(true);
		$item->setTo(self::ACTOR_FOLLOWERS);
		$this->cacheActorService->method('getFromId')->willReturn($this->actor());
		$this->activityService->method('deleteActivity')->willReturn('token');

		$this->service->deleteLocalItem($item, Note::TYPE);

		$this->assertHasInstancePath(
			$item->getInstancePaths(), self::ACTOR_ID, InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW
		);
	}

	public function testDeleteLocalItemStillDeletesWhenAuthorCannotBeResolved(): void {
		$item = $this->note('https://social.example/@alice/1');
		$item->setLocal(true);

		$this->cacheActorService->method('getFromId')->willThrowException(new CacheActorDoesNotExistException());
		$this->activityService->expects($this->once())->method('deleteActivity')->willReturn('token');
		$this->streamRequest->expects($this->once())->method('deleteById')->with($item->getId(), '');

		$this->service->deleteLocalItem($item);

		$this->assertSame([], $item->getInstancePaths());
	}

	public function testDeleteLocalItemRefusesRemoteItems(): void {
		$item = $this->note('https://remote.example/notes/1', 'https://remote.example/users/bob');
		$item->setLocal(false);

		$this->activityService->expects($this->never())->method('deleteActivity');
		$this->streamRequest->expects($this->never())->method('deleteById');

		$this->service->deleteLocalItem($item, Note::TYPE);

		$this->assertSame('', $item->getActorId());
	}

	// lookups delegating to StreamRequest

	public function testSetViewerIsPassedToStreamRequest(): void {
		$viewer = $this->actor();
		$this->streamRequest->expects($this->once())->method('setViewer')->with($this->identicalTo($viewer));

		$this->service->setViewer($viewer);
	}

	public function testGetStreamByIdPassesViewerFlagAndFormat(): void {
		$note = $this->note('https://social.example/@alice/1');
		$this->streamRequest->expects($this->once())
			->method('getStreamById')
			->with('https://social.example/@alice/1', true, ACore::FORMAT_LOCAL)
			->willReturn($note);

		$this->assertSame($note, $this->service->getStreamById('https://social.example/@alice/1', true, ACore::FORMAT_LOCAL));
	}

	public function testGetStreamByIdDefaultsToActivityPubFormatWithoutViewer(): void {
		$note = $this->note('https://social.example/@alice/1');
		$this->streamRequest->expects($this->once())
			->method('getStreamById')
			->with('https://social.example/@alice/1', false, ACore::FORMAT_ACTIVITYPUB)
			->willReturn($note);

		$this->assertSame($note, $this->service->getStreamById('https://social.example/@alice/1'));
	}

	public function testGetStreamByNidDelegates(): void {
		$note = $this->note('https://social.example/@alice/1');
		$this->streamRequest->expects($this->once())->method('getStreamByNid')->with(42)->willReturn($note);

		$this->assertSame($note, $this->service->getStreamByNid(42));
	}

	public function testGetStreamByNidPropagatesNotFound(): void {
		$this->streamRequest->method('getStreamByNid')->willThrowException(new StreamNotFoundException());

		$this->expectException(StreamNotFoundException::class);
		$this->service->getStreamByNid(42);
	}

	public function testUpdateStreamDelegates(): void {
		$note = $this->note('https://social.example/@alice/1');
		$this->streamRequest->expects($this->once())->method('update')->with($this->identicalTo($note), false);

		$this->service->updateStream($note);
	}

	public function testUpdateStreamCanRegenerateDestinationsForAnEditedAudience(): void {
		$note = $this->note('https://social.example/@alice/1');
		$this->streamRequest->expects($this->once())->method('update')->with($this->identicalTo($note), true);

		$this->service->updateStream($note, true);
	}

	public function testGetTimelinePassesProbeOptions(): void {
		$options = new ProbeOptions();
		$note = $this->note('https://social.example/@alice/1');
		$this->streamRequest->expects($this->once())
			->method('getTimeline')
			->with($this->identicalTo($options))
			->willReturn([$note]);

		$this->assertSame([$note], $this->service->getTimeline($options));
	}

	private function boostOf(string $id, string $of): Announce {
		$boost = new Announce();
		$boost->setId($id);
		$boost->setObjectId($of);

		return $boost;
	}

	/**
	 * Following both an author and somebody who boosts them used to put the
	 * same post in the timeline twice: once on its own and once again under
	 * "X boosted", with the same text, the same picture and the same actions.
	 */
	public function testABoostOfAPostAlreadyInThePageIsDropped(): void {
		$note = $this->note('https://social.example/@alice/1');
		$boost = $this->boostOf('https://social.example/@bob/boost/1', $note->getId());
		$this->streamRequest->method('getTimeline')->willReturn([$boost, $note]);

		$this->assertSame([$note], $this->service->getTimeline(new ProbeOptions()));
	}

	public function testTwoBoostsOfTheSamePostCollapseToTheFirst(): void {
		$first = $this->boostOf('https://social.example/@bob/boost/1', 'https://remote.example/notes/9');
		$second = $this->boostOf('https://social.example/@carol/boost/1', 'https://remote.example/notes/9');
		$this->streamRequest->method('getTimeline')->willReturn([$first, $second]);

		$this->assertSame([$first], $this->service->getTimeline(new ProbeOptions()));
	}

	public function testABoostOfAPostThatIsNotInThePageIsKept(): void {
		$note = $this->note('https://social.example/@alice/1');
		$boost = $this->boostOf('https://social.example/@bob/boost/1', 'https://remote.example/notes/9');
		$this->streamRequest->method('getTimeline')->willReturn([$boost, $note]);

		$this->assertSame([$boost, $note], $this->service->getTimeline(new ProbeOptions()));
	}

	/**
	 * Whether a further page exists is a question about what the query read,
	 * not about what survived deduplication. A page of twenty that dropped one
	 * boost came back as nineteen, and the caller — which decides `rel="next"`
	 * from the page it is handed — stopped offering the next page, so every
	 * client that follows the Link header stopped scrolling there.
	 */
	public function testThePageReportsHowManyRowsTheQueryRead(): void {
		$note = $this->note('https://social.example/@alice/1');
		$boost = $this->boostOf('https://social.example/@bob/boost/1', $note->getId());
		$this->streamRequest->method('getTimeline')->willReturn([$boost, $note]);

		$page = $this->service->getTimeline(new ProbeOptions());

		$this->assertCount(1, $page);
		$this->assertSame(2, $this->service->lastTimelineRowCount());
	}

	public function testTheRowCountIsOfTheLastPageRead(): void {
		$this->streamRequest->method('getTimeline')->willReturn([]);

		$this->assertSame(0, $this->service->lastTimelineRowCount(), 'nothing has been read yet');

		$this->service->getTimeline(new ProbeOptions());

		$this->assertSame(0, $this->service->lastTimelineRowCount());
	}

	/**
	 * A favourite and a boost of the same post are two different things that
	 * happened to you, so a page of notifications is left alone.
	 */
	public function testNotificationsAreNotDeduplicated(): void {
		$first = $this->boostOf('https://social.example/notif/1', 'https://social.example/@alice/1');
		$second = $this->boostOf('https://social.example/notif/2', 'https://social.example/@alice/1');
		$options = new ProbeOptions();
		$options->setProbe(ProbeOptions::NOTIFICATIONS);
		$this->streamRequest->method('getTimeline')->willReturn([$first, $second]);

		$this->assertSame([$first, $second], $this->service->getTimeline($options));
	}

	/**
	 * @return array<string, array{string, array, string, array}>
	 */
	public static function timelineDelegationProvider(): array {
		return [
			'replies' => ['getRepliesByParentId', ['https://social.example/@alice/1', 10, 20, true], 'getRepliesByParentId', ['https://social.example/@alice/1', 10, 20, true]],
			'replies defaults' => ['getRepliesByParentId', ['https://social.example/@alice/1'], 'getRepliesByParentId', ['https://social.example/@alice/1', 0, 5, false]],
		];
	}

	#[DataProvider('timelineDelegationProvider')]
	public function testTimelineGettersPassFiltersThrough(
		string $serviceMethod,
		array $args,
		string $requestMethod,
		array $expectedArgs,
	): void {
		$note = $this->note('https://social.example/@alice/1');
		$this->streamRequest->expects($this->once())
			->method($requestMethod)
			->with(...$expectedArgs)
			->willReturn([$note]);

		$this->assertSame([$note], $this->service->$serviceMethod(...$args));
	}

	// getContextByNid()

	public function testGetContextByNidCollectsAncestorsInThreadOrderAndDescendants(): void {
		$post = $this->note('https://social.example/@alice/3', self::ACTOR_ID, 'https://social.example/@alice/2');
		$parent = $this->note('https://social.example/@alice/2', self::ACTOR_ID, 'https://social.example/@alice/1');
		$root = $this->note('https://social.example/@alice/1');
		$reply = $this->note('https://social.example/@alice/4', self::ACTOR_ID, $post->getId());

		$this->streamRequest->method('getStreamByNid')->with(3)->willReturn($post);
		$this->streamRequest->expects($this->exactly(2))
			->method('getStreamById')
			->willReturnMap([
				[$parent->getId(), true, ACore::FORMAT_ACTIVITYPUB, $parent],
				[$root->getId(), true, ACore::FORMAT_ACTIVITYPUB, $root],
			]);
		$this->streamRequest->method('getDescendants')->with($post->getId())->willReturn([$reply]);
		// the opened post among them, in thread order
		$this->linkPreviewService->expects($this->once())->method('attachCards')->with([$root, $parent, $post, $reply]);

		$context = $this->service->getContextByNid(3);

		$this->assertSame([$root, $parent], $context['ancestors']);
		$this->assertSame([$reply], $context['descendants']);
		$this->assertSame(ACore::FORMAT_LOCAL, $root->getExportFormat());
		$this->assertSame(ACore::FORMAT_LOCAL, $parent->getExportFormat());
	}

	public function testGetContextByNidNormalizesTheStringFromAClientRoute(): void {
		$post = $this->note('https://social.example/@alice/1789250751711653456', self::ACTOR_ID);
		$this->streamRequest->expects($this->once())->method('getStreamByNid')
			->with(1789250751711653456)->willReturn($post);
		$this->streamRequest->method('getDescendants')->willReturn([]);

		$this->service->getContextByNid('1789250751711653456');
	}

	public function testGetContextByNidStopsAtAncestorsTheViewerCannotSee(): void {
		$post = $this->note('https://social.example/@alice/3', self::ACTOR_ID, 'https://social.example/@alice/2');
		$this->streamRequest->method('getStreamByNid')->willReturn($post);
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$this->streamRequest->method('getDescendants')->willReturn([]);

		$context = $this->service->getContextByNid(3);

		$this->assertSame(['ancestors' => [], 'descendants' => []], $context);
	}

	/** Ancestors are walked up as long as they are known, capped where Mastodon caps them. */
	public function testGetContextByNidLimitsAncestorDepth(): void {
		$chain = static fn (int $n): string => 'https://social.example/@alice/' . $n;
		$post = $this->note($chain(50), self::ACTOR_ID, $chain(49));
		$this->streamRequest->method('getStreamByNid')->willReturn($post);
		$this->streamRequest->method('getStreamById')->willReturnCallback(function (string $id) use ($chain): Note {
			$n = (int)basename($id);

			return $this->note($id, self::ACTOR_ID, $n > 1 ? $chain($n - 1) : '');
		});
		$this->streamRequest->method('getDescendants')->willReturn([]);

		$context = $this->service->getContextByNid(50);

		$this->assertCount(40, $context['ancestors']);
		$this->assertSame($chain(10), $context['ancestors'][0]->getId());
		$this->assertSame($chain(49), $context['ancestors'][39]->getId());
	}

	// getAuthorFromPostId()

	public function testGetAuthorFromPostIdResolvesAttributedActor(): void {
		$bob = $this->remoteActor();
		$this->streamRequest->method('getStreamById')
			->with('https://remote.example/notes/1')
			->willReturn($this->note('https://remote.example/notes/1', $bob->getId()));
		$this->cacheActorService->expects($this->once())->method('getFromId')->with($bob->getId())->willReturn($bob);

		$this->assertSame($bob, $this->service->getAuthorFromPostId('https://remote.example/notes/1'));
	}

	public function testGetAuthorFromPostIdFailsOnUnknownPost(): void {
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$this->cacheActorService->expects($this->never())->method('getFromId');

		$this->expectException(StreamNotFoundException::class);
		$this->service->getAuthorFromPostId('https://remote.example/notes/missing');
	}

	// getOutboxCollection()

	public function testGetOutboxCollectionDescribesActorOutbox(): void {
		$actor = $this->actor();
		$actor->setDetailArray('count', ['post' => 17, 'followers' => 3]);

		$collection = $this->service->getOutboxCollection($actor);

		$this->assertSame('OrderedCollection', $collection->getType());
		$this->assertSame(self::ACTOR_ID . '/outbox', $collection->getId());
		$this->assertSame(17, $collection->getTotalItems());
		$this->assertSame(self::ACTOR_ID . '/outbox?page=1', $collection->getFirst());
		$this->assertSame(self::ACTOR_ID . '/outbox?page=1', $collection->getLast());
	}

	public function testGetOutboxCollectionWithoutCountsIsEmpty(): void {
		$collection = $this->service->getOutboxCollection($this->actor());

		$this->assertSame(0, $collection->getTotalItems());
	}

	// syncRemoteTimeline()

	public function testSyncRemoteTimelineIgnoresLocalActors(): void {
		$this->curlService->expects($this->never())->method('retrieveObject');
		$this->streamRequest->expects($this->never())->method('save');

		$this->assertSame(0, $this->service->syncRemoteTimeline($this->actor()));
	}

	public function testSyncRemoteTimelineNeedsAnOutbox(): void {
		$bob = $this->remoteActor();
		$bob->setOutbox('');
		$this->curlService->expects($this->never())->method('retrieveObject');

		$this->assertSame(0, $this->service->syncRemoteTimeline($bob));
	}

	public function testSyncRemoteTimelineFollowsFirstPageAndStoresUnknownNotes(): void {
		$bob = $this->remoteActor();
		$noteData = [
			'id' => 'https://remote.example/notes/new',
			'type' => 'Note',
			'url' => 'https://remote.example/@bob/new',
			'attributedTo' => $bob->getId(),
			'published' => '2026-01-02T03:04:05Z',
			'content' => '<p>Hello #Fedi</p>',
			'summary' => 'cw',
			'sensitive' => true,
			'inReplyTo' => 'https://remote.example/notes/0',
			'conversation' => 'https://remote.example/contexts/1',
			'to' => [ACore::CONTEXT_PUBLIC],
			'cc' => $bob->getFollowers(),
			'tag' => [
				['type' => 'Hashtag', 'name' => '#Fedi', 'href' => 'https://remote.example/tags/fedi'],
				['type' => 'Mention', 'name' => '@alice@social.example', 'href' => self::ACTOR_ID],
			],
			'likes' => ['totalItems' => 3],
			'shares' => ['totalItems' => 2],
			'replies' => ['totalItems' => 1],
		];
		$page = [
			'type' => 'OrderedCollectionPage',
			'orderedItems' => [
				['type' => 'Create', 'object' => $noteData],
				['type' => 'Note', 'id' => 'https://remote.example/notes/known', 'content' => 'already here'],
				['type' => 'Announce', 'object' => 'https://other.example/notes/1'],
				['type' => 'Note', 'content' => 'no id'],
			],
		];

		$this->curlService->expects($this->exactly(2))
			->method('retrieveObject')
			->willReturnMap([
				[$bob->getOutbox(), true, ['type' => 'OrderedCollection', 'first' => $bob->getOutbox() . '?page=true']],
				[$bob->getOutbox() . '?page=true', true, $page],
			]);
		$this->streamRequest->method('getStreamById')->willReturnCallback(function (string $id): Stream {
			if ($id === 'https://remote.example/notes/known') {
				return $this->note($id);
			}
			throw new StreamNotFoundException();
		});

		$saved = null;
		$this->streamRequest->expects($this->once())
			->method('save')
			->willReturnCallback(function (Stream $stream) use (&$saved): void {
				$saved = $stream;
			});

		$this->assertSame(1, $this->service->syncRemoteTimeline($bob));

		$this->assertInstanceOf(Note::class, $saved);
		$this->assertSame('https://remote.example/notes/new', $saved->getId());
		$this->assertSame('https://remote.example/@bob/new', $saved->getUrl());
		$this->assertSame($bob->getId(), $saved->getAttributedTo());
		$this->assertSame('<p>Hello #Fedi</p>', $saved->getContent());
		$this->assertSame('cw', $saved->getSummary());
		$this->assertTrue($saved->isSensitive());
		$this->assertFalse($saved->isLocal());
		$this->assertSame('https://remote.example/notes/0', $saved->getInReplyTo());
		$this->assertSame('https://remote.example/contexts/1', $saved->getConversation());
		$this->assertSame([ACore::CONTEXT_PUBLIC], $saved->getToArray());
		$this->assertSame([$bob->getFollowers()], $saved->getCcArray());
		$this->assertSame(['Fedi'], $saved->getHashtags());
		$this->assertCount(2, $saved->getTags());
		// what to/cc say the audience is: stored blank, the row exported as
		// public to every client whatever the post actually said
		$this->assertSame(Stream::TYPE_PUBLIC, $saved->getVisibility());
		// and the people it names, which `mentions` is built from
		$this->assertSame(
			[['id' => 0, 'username' => 'alice@social.example', 'url' => self::ACTOR_ID, 'acct' => 'alice@social.example']],
			$saved->getDetails('mentions')
		);
		$this->assertSame((new DateTime('2026-01-02T03:04:05Z'))->getTimestamp(), $saved->getPublishedTime());
		$this->assertSame(3, $saved->getDetailInt('likes'));
		$this->assertSame(3, $saved->getDetailInt('remote_likes'));
		$this->assertSame(2, $saved->getDetailInt('boosts'));
		$this->assertSame(1, $saved->getDetailInt('replies'));
		$this->assertSame($noteData, json_decode($saved->getSource(), true));
	}

	/**
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function syncedVisibilityProvider(): array {
		$followers = 'https://remote.example/users/bob/followers';

		return [
			'public' => [['to' => [ACore::CONTEXT_PUBLIC], 'cc' => [$followers]], Stream::TYPE_PUBLIC],
			'unlisted' => [['to' => [$followers], 'cc' => [ACore::CONTEXT_PUBLIC]], Stream::TYPE_UNLISTED],
			'followers-only' => [['to' => [$followers], 'cc' => []], Stream::TYPE_FOLLOWERS],
			'direct' => [['to' => [self::ACTOR_ID], 'cc' => []], Stream::TYPE_DIRECT],
		];
	}

	/**
	 * A synced post used to be stored with no visibility at all, which the
	 * client format reads as public: a followers-only note fetched this way was
	 * offered a Boost button, while the trends and the place timelines — which
	 * compare the column — left it out.
	 *
	 * @param array<string, mixed> $addressing
	 */
	#[DataProvider('syncedVisibilityProvider')]
	public function testSyncRemoteTimelineStoresWhatTheAddressingSaysTheAudienceIs(
		array $addressing,
		string $expected,
	): void {
		$bob = $this->remoteActor();
		$this->cacheActorService->method('getFromId')->with($bob->getId())->willReturn($bob);
		$this->curlService->method('retrieveObject')->willReturn([
			'orderedItems' => [
				['type' => 'Note', 'id' => 'https://remote.example/notes/v', 'content' => 'x'] + $addressing,
			],
		]);
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());

		$saved = null;
		$this->streamRequest->method('save')
			->willReturnCallback(function (Stream $stream) use (&$saved): void {
				$saved = $stream;
			});

		$this->service->syncRemoteTimeline($bob);

		$this->assertInstanceOf(Note::class, $saved);
		$this->assertSame($expected, $saved->getVisibility());
	}

	public function testSyncRemoteTimelineUsesEmbeddedFirstPage(): void {
		$bob = $this->remoteActor();
		$this->curlService->expects($this->once())
			->method('retrieveObject')
			->with($bob->getOutbox())
			->willReturn([
				'type' => 'OrderedCollection',
				'first' => [
					'items' => [
						['type' => 'Note', 'id' => 'https://remote.example/notes/a', 'content' => 'a'],
						['type' => 'Note', 'id' => 'https://remote.example/notes/b', 'content' => 'b'],
					],
				],
			]);
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$this->streamRequest->expects($this->exactly(2))->method('save');

		$this->assertSame(2, $this->service->syncRemoteTimeline($bob));
	}

	public function testSyncRemoteTimelineFallsBackToActorForMissingAttribution(): void {
		$bob = $this->remoteActor();
		$this->curlService->method('retrieveObject')->willReturn([
			'orderedItems' => [['type' => 'Note', 'id' => 'https://remote.example/notes/a', 'content' => 'a']],
		]);
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$this->streamRequest->expects($this->once())
			->method('save')
			->with($this->callback(fn (Stream $s): bool => $s->getAttributedTo() === $bob->getId()));

		$this->assertSame(1, $this->service->syncRemoteTimeline($bob));
	}

	public function testSyncRemoteTimelineSkipsItemsForgedForAnotherServersActor(): void {
		// The outbox is what a remote server says about itself, so it may only yield
		// notes that live on that server and are attributed to the actor whose outbox
		// is being read. A note whose attributedTo points at another instance is that
		// server speaking for someone it does not host and must never be stored.
		$bob = $this->remoteActor();
		$legit = [
			'type' => 'Note',
			'id' => 'https://remote.example/notes/1',
			'attributedTo' => $bob->getId(),
			'content' => 'genuine',
		];
		$forged = [
			'type' => 'Note',
			'id' => 'https://remote.example/notes/2',
			'attributedTo' => 'https://victim.example/users/alice',
			'content' => 'impersonation',
		];
		$this->curlService->method('retrieveObject')->willReturn([
			'orderedItems' => [$legit, $forged],
		]);
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());

		$saved = [];
		$this->streamRequest->expects($this->once())
			->method('save')
			->willReturnCallback(function (Stream $stream) use (&$saved): void {
				$saved[] = $stream->getId();
			});

		$this->assertSame(1, $this->service->syncRemoteTimeline($bob));
		$this->assertSame(['https://remote.example/notes/1'], $saved);
	}

	public function testSyncRemoteTimelineSkipsNotesHostedOnAnotherServer(): void {
		// Even when attribution is consistent with the note, a note whose id lives on
		// a different host than the actor is not something this outbox may vouch for.
		$bob = $this->remoteActor();
		$foreign = [
			'type' => 'Note',
			'id' => 'https://victim.example/notes/9',
			'attributedTo' => 'https://victim.example/users/alice',
			'content' => 'not bobs to publish',
		];
		$this->curlService->method('retrieveObject')->willReturn([
			'orderedItems' => [$foreign],
		]);
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$this->streamRequest->expects($this->never())->method('save');

		$this->assertSame(0, $this->service->syncRemoteTimeline($bob));
	}

	public function testSyncRemoteTimelineSwallowsNetworkFailures(): void {
		$bob = $this->remoteActor();
		$this->curlService->method('retrieveObject')->willThrowException(new RequestNetworkException());
		$this->streamRequest->expects($this->never())->method('save');

		$this->assertSame(0, $this->service->syncRemoteTimeline($bob));
	}

	public function testSyncRemoteTimelineKeepsGoingWhenOneItemFailsToSave(): void {
		$bob = $this->remoteActor();
		$this->curlService->method('retrieveObject')->willReturn([
			'orderedItems' => [
				['type' => 'Note', 'id' => 'https://remote.example/notes/a', 'content' => 'a'],
				['type' => 'Note', 'id' => 'https://remote.example/notes/b', 'content' => 'b'],
			],
		]);
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$this->streamRequest->expects($this->exactly(2))
			->method('save')
			->willReturnCallback(function (Stream $stream): void {
				if ($stream->getId() === 'https://remote.example/notes/a') {
					throw new \RuntimeException('db hiccup');
				}
			});

		$this->assertSame(1, $this->service->syncRemoteTimeline($bob));
	}

	public function testSyncRemoteTimelineStopsAfterOnePageWorthOfItems(): void {
		// The page comes from a server the caller names and is bounded only by the
		// body size cap: without a cut, one anonymous request for a handle on that
		// server walks the whole page, a lookup and an INSERT per entry.
		$bob = $this->remoteActor();
		$items = [];
		for ($i = 0; $i < 500; $i++) {
			$items[] = [
				'type' => 'Note',
				'id' => 'https://remote.example/notes/' . $i,
				'attributedTo' => $bob->getId(),
				'content' => 'flood',
			];
		}
		$this->curlService->method('retrieveObject')->willReturn(['orderedItems' => $items]);
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());

		$saved = 0;
		$this->streamRequest->method('save')->willReturnCallback(function () use (&$saved): void {
			$saved++;
		});

		$synced = $this->service->syncRemoteTimeline($bob);

		$this->assertSame(20, $synced);
		$this->assertSame(20, $saved);
	}

	public function testSyncRemoteTimelineSanitisesWhatItStores(): void {
		// This path bypasses NoteInterface::save(), so nothing else validates what
		// the remote server sent. Content and summary are saved by a read-time
		// filter; a hashtag has none, and reaches the composer autocomplete as it
		// was stored.
		$bob = $this->remoteActor();
		$this->curlService->method('retrieveObject')->willReturn([
			'orderedItems' => [[
				'type' => 'Note',
				'id' => 'https://remote.example/notes/1',
				'attributedTo' => $bob->getId(),
				'content' => '<p>hello</p><script>alert(1)</script>',
				'summary' => '<b>cw</b>',
				'tag' => [
					['type' => 'Hashtag', 'name' => '#foo<img src=x onerror=alert(1)>bar'],
				],
			]],
		]);
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());

		$saved = null;
		$this->streamRequest->expects($this->once())
			->method('save')
			->willReturnCallback(function (Stream $stream) use (&$saved): void {
				$saved = $stream;
			});

		$this->assertSame(1, $this->service->syncRemoteTimeline($bob));

		$this->assertSame(['foobar'], $saved->getHashtags());
		$this->assertStringNotContainsString('<script', $saved->getContent());
		$this->assertSame('cw', $saved->getSummary());
		foreach ($saved->getTags() as $tag) {
			$this->assertStringNotContainsString('<', $tag['name']);
		}
	}

	public function testSyncRemoteTimelineSurvivesAPageOfBareIdsAndOddTypes(): void {
		// `orderedItems` may list ids rather than objects, and every field below is
		// whatever the remote server put in its JSON: reading either as a string
		// used to be a TypeError, which is fatal rather than caught.
		$bob = $this->remoteActor();
		$this->curlService->method('retrieveObject')->willReturn([
			'orderedItems' => [
				'https://remote.example/notes/bare',
				[
					'type' => 'Note',
					'id' => 'https://remote.example/notes/1',
					'attributedTo' => $bob->getId(),
					'content' => ['not' => 'a string'],
					'url' => ['https://remote.example/@bob/1'],
					'inReplyTo' => ['nested'],
					'to' => ['https://remote.example/followers', ['nested']],
					'cc' => 'https://www.w3.org/ns/activitystreams#Public',
					'tag' => 'not-a-list',
				],
			],
		]);
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());

		$saved = null;
		$this->streamRequest->expects($this->once())
			->method('save')
			->willReturnCallback(function (Stream $stream) use (&$saved): void {
				$saved = $stream;
			});

		$this->assertSame(1, $this->service->syncRemoteTimeline($bob));

		$this->assertSame('', $saved->getContent());
		// `url` may be a list, and a list of one plain address is a valid one:
		// what used to be dropped as an odd shape is now read
		$this->assertSame('https://remote.example/@bob/1', $saved->getUrl());
		$this->assertSame('', $saved->getInReplyTo());
		$this->assertSame(['https://remote.example/followers'], $saved->getToArray());
		$this->assertSame([ACore::CONTEXT_PUBLIC], $saved->getCcArray());
		$this->assertSame([], $saved->getHashtags());
	}

	// the replies collection

	private function post(string $id = self::GENERATED_ID): Note {
		$note = new Note();
		$note->setId($id);
		$note->setLocal(true);

		return $note;
	}

	public function testTheRepliesCollectionSaysWhereItsPagesAre(): void {
		$this->streamRequest->method('countPublicRepliesTo')
			->with(self::GENERATED_ID)
			->willReturn(45);

		$collection = $this->service->getRepliesCollection($this->post());

		$this->assertSame(self::GENERATED_ID . '/replies', $collection->getId());
		$this->assertSame(45, $collection->getTotalItems());
		$this->assertSame(self::GENERATED_ID . '/replies?page=1', $collection->getFirst());
		$this->assertSame(self::GENERATED_ID . '/replies?page=2', $collection->getLast());
	}

	public function testAPostWithNoRepliesStillHasAPageToOffer(): void {
		$this->streamRequest->method('countPublicRepliesTo')->willReturn(0);

		$collection = $this->service->getRepliesCollection($this->post());

		$this->assertSame(0, $collection->getTotalItems());
		$this->assertSame(self::GENERATED_ID . '/replies?page=1', $collection->getLast());
	}

	/**
	 * Ids, not the replies themselves: a reply is its author's document and is
	 * served by their instance, which may since have changed or deleted it.
	 */
	public function testARepliesPageListsTheIdsOfTheReplies(): void {
		$this->streamRequest->method('getPublicRepliesTo')
			->with(self::GENERATED_ID, 40, 0)
			->willReturn([
				$this->post('https://remote.example/notes/1'),
				$this->post('https://remote.example/notes/2'),
			]);

		$page = $this->service->getRepliesPage($this->post(), 1);

		$this->assertSame(self::GENERATED_ID . '/replies?page=1', $page->getId());
		$this->assertSame(self::GENERATED_ID . '/replies', $page->getPartOf());
		$this->assertSame(
			['https://remote.example/notes/1', 'https://remote.example/notes/2'],
			$page->getOrderedItems()
		);
		$this->assertSame('', $page->getNext(), 'a page that came back short is the last one');
		$this->assertSame('', $page->getPrev());
	}

	public function testAFurtherPageIsAskedForAtTheRightOffsetAndLinksBack(): void {
		$this->streamRequest->expects($this->once())
			->method('getPublicRepliesTo')
			->with(self::GENERATED_ID, 40, 40)
			->willReturn([]);

		$page = $this->service->getRepliesPage($this->post(), 2);

		$this->assertSame(self::GENERATED_ID . '/replies?page=2', $page->getId());
		$this->assertSame(self::GENERATED_ID . '/replies?page=1', $page->getPrev());
	}

	/** A full page is the only evidence there may be another one. */
	public function testAFullPagePointsAtTheNextOne(): void {
		$this->streamRequest->method('getPublicRepliesTo')
			->willReturn(array_map(
				function (int $i): Note {
					$reply = $this->post('https://remote.example/notes/' . $i);
					$reply->setNid((string)(1790000000000000000 + $i));

					return $reply;
				},
				range(1, 40)
			));

		$page = $this->service->getRepliesPage($this->post(), 1);

		$this->assertCount(40, $page->getOrderedItems());
		// the next page starts after this one's last reply, not at an offset
		$this->assertSame(self::GENERATED_ID . '/replies?page=true&min_id=1790000000000000040', $page->getNext());
	}

	public function testARepliesCursorPageIsReadAfterItsCursor(): void {
		$this->streamRequest->expects($this->once())->method('getPublicRepliesTo')
			->with(self::GENERATED_ID, 40, 0, '1790000000000000040')
			->willReturn([]);

		$page = $this->service->getRepliesPage($this->post(), 1, '1790000000000000040');

		$this->assertSame(self::GENERATED_ID . '/replies?page=true&min_id=1790000000000000040', $page->getId());
		$this->assertSame([], $page->getOrderedItems());
	}

	public function testARepliesCursorThatIsNotANidStartsNoPage(): void {
		$this->streamRequest->expects($this->never())->method('getPublicRepliesTo');

		$this->assertSame([], $this->service->getRepliesPage($this->post(), 1, 'x')->getOrderedItems());
	}

	// the Emoji tags a post carries

	/**
	 * The shortcode stays in the content as text; the tag beside it says where
	 * the picture is, which is why an instance that has never heard of
	 * `:blobcat:` still renders the post.
	 */
	public function testAPostCarriesATagForEachEmojiWrittenInIt(): void {
		$this->emojiTags = [
			['type' => 'Emoji', 'name' => ':blobcat:', 'icon' => ['url' => 'https://cloud.example/e/1']],
		];
		$note = new Note();

		$this->service->addCustomEmojis($note, 'hello :blobcat:');

		$this->assertSame($this->emojiTags, $note->getTags('Emoji'));
	}

	/** An edit that takes a shortcode out must take its tag with it. */
	public function testTheEmojiTagsAreRebuiltRatherThanAppendedTo(): void {
		$note = new Note();
		$note->addTag(['type' => 'Emoji', 'name' => ':gone:', 'icon' => []]);
		$this->emojiTags = [['type' => 'Emoji', 'name' => ':kept:', 'icon' => []]];

		$this->service->addCustomEmojis($note, ':kept:');

		$this->assertSame([':kept:'], array_column($note->getTags('Emoji'), 'name'));
	}

	/** Everything else the post named is still named. */
	public function testRebuildingTheEmojiTagsLeavesTheOtherTagsAlone(): void {
		$note = new Note();
		$note->addTag(['type' => 'Mention', 'href' => 'https://remote.example/users/bob', 'name' => '@bob']);
		$note->addTag(['type' => 'Hashtag', 'href' => 'https://cloud.example/t/x', 'name' => '#x']);
		$this->emojiTags = [['type' => 'Emoji', 'name' => ':blobcat:', 'icon' => []]];

		$this->service->addCustomEmojis($note, ':blobcat:');

		$this->assertCount(1, $note->getTags('Mention'));
		$this->assertCount(1, $note->getTags('Hashtag'));
		$this->assertCount(1, $note->getTags('Emoji'));
	}

	// deleteLocalItem()

	public function testDeletingYourOwnReplyRecountsThePostItAnswered(): void {
		$note = $this->note('https://social.example/notes/reply', self::ACTOR_ID, 'https://social.example/notes/parent');
		$note->setLocal(true);

		$this->streamRequest->expects($this->once())->method('deleteById');
		// after the row has gone, or the reply it just removed is counted again
		$this->streamRequest->expects($this->once())->method('recountReplies')
			->with('https://social.example/notes/parent');

		$this->service->deleteLocalItem($note);
	}

	public function testDeletingAPostOfYourOwnThatAnsweredNothingRecountsNothing(): void {
		$note = $this->note('https://social.example/notes/post');
		$note->setLocal(true);

		// the recount is the one place that decides what an empty parent means
		$this->streamRequest->expects($this->once())->method('recountReplies')->with('');

		$this->service->deleteLocalItem($note);
	}

	/**
	 * Nothing moved this counter down at all, so an account that wrote and
	 * deleted a post all day climbed until the cron's recount put it back. The
	 * condition is the recount's own: a public status, which is what it counts.
	 */
	public function testDeletingAPublicPostTakesItOffTheProfilesCount(): void {
		$note = $this->note('https://social.example/notes/post');
		$note->setLocal(true);
		$note->setTo(ACore::CONTEXT_PUBLIC);

		$this->accountService->expects($this->once())
			->method('bumpActorCount')
			->with(self::ACTOR_ID, 'count_posts', -1);

		$this->service->deleteLocalItem($note, Note::TYPE);
	}

	/** A followers-only post was never counted, so it is not counted down. */
	public function testDeletingAPostNobodyCountedLeavesTheCountAlone(): void {
		$note = $this->note('https://social.example/notes/post');
		$note->setLocal(true);
		$note->setTo(self::ACTOR_ID . '/followers');

		$this->accountService->expects($this->never())->method('bumpActorCount');

		$this->service->deleteLocalItem($note, Note::TYPE);
	}

	public function testARemotePostIsNotDeletedFromHereAndNothingIsRecounted(): void {
		$note = $this->note('https://remote.example/notes/post', 'https://remote.example/users/bob', 'https://remote.example/notes/parent');

		$this->streamRequest->expects($this->never())->method('deleteById');
		$this->streamRequest->expects($this->never())->method('recountReplies');

		$this->service->deleteLocalItem($note);
	}

	/**
	 * A content warning is written by the same person in the same composer,
	 * and was the one place a shortcode showed through unrendered.
	 */
	public function testTheSpoilerTextIsScannedTogetherWithTheContent(): void {
		$this->service->addCustomEmojis(new Note(), 'body :a:', 'warning :b:');

		$this->assertStringContainsString(':a:', $this->emojiScanned);
		$this->assertStringContainsString(':b:', $this->emojiScanned);
	}

	/**
	 * The opened post gets its link preview too.
	 *
	 * The cards were attached to the ancestors and the descendants and not to
	 * the post the reader had actually opened — which in the Direct messages
	 * view is the message on screen, rendered from `last_status`. It was the
	 * one post in the thread with a bare link where every other had a card.
	 */
	public function testTheOpenedPostIsInTheBatchOfCardsWithItsThread(): void {
		$post = new Note();
		$post->setId('https://cloud.example.org/notes/opened');
		$post->setNid(17);
		$descendant = new Note();
		$descendant->setId('https://cloud.example.org/notes/reply');

		$this->streamRequest->method('getStreamByNid')->willReturn($post);
		$this->streamRequest->method('getDescendants')->willReturn([$descendant]);

		$batched = [];
		$this->linkPreviewService->method('attachCards')
			->willReturnCallback(function (array $posts) use (&$batched): void {
				$batched = array_map(static fn (Stream $one): string => $one->getId(), $posts);
			});

		$this->service->getContextByNid(17);

		$this->assertContains('https://cloud.example.org/notes/opened', $batched);
		$this->assertContains('https://cloud.example.org/notes/reply', $batched);
	}
}
