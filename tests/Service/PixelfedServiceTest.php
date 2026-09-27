<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StoriesRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Collection;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Client\Story;
use OCA\Social\Model\Post;
use OCA\Social\Model\Report;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\AvatarService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CollectionService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\InstanceService;
use OCA\Social\Service\PixelfedService;
use OCA\Social\Service\PostService;
use OCA\Social\Service\ReportService;
use OCA\Social\Service\SearchService;
use OCA\Social\Service\StoryService;
use OCA\Social\Service\StreamService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class PixelfedServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example/apps/social/@alice';
	private const BOB = 'https://cloud.example/apps/social/@bob';
	private const CAROL = 'https://remote.example/users/carol';

	private StoryService|Stub $storyService;
	private StoriesRequest|MockObject $storiesRequest;
	private CollectionService|Stub $collectionService;
	private FollowService|MockObject $followService;
	private CacheActorService|MockObject $cacheActorService;
	private SearchService|Stub $searchService;
	private ReportService|MockObject $reportService;
	private StreamService|MockObject $streamService;
	private InstanceService|Stub $instanceService;
	private StreamRequest|MockObject $streamRequest;
	private FollowsRequest|Stub $followsRequest;
	private CacheActorsRequest|MockObject $cacheActorsRequest;
	private PostService|MockObject $postService;
	private ConfigService|Stub $configService;
	private PixelfedService $service;

	/** @var array<string, string> what setValueForUser() was given */
	private array $userConfig = [];

	protected function setUp(): void {
		parent::setUp();

		$this->storyService = $this->createStub(StoryService::class);
		$this->storiesRequest = $this->createMock(StoriesRequest::class);
		$this->collectionService = $this->createStub(CollectionService::class);
		$this->followService = $this->createMock(FollowService::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->searchService = $this->createStub(SearchService::class);
		$this->reportService = $this->createMock(ReportService::class);
		$this->streamService = $this->createMock(StreamService::class);
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->followsRequest = $this->createStub(FollowsRequest::class);
		$this->cacheActorsRequest = $this->createMock(CacheActorsRequest::class);
		$this->postService = $this->createMock(PostService::class);
		$this->instanceService = $this->createStub(InstanceService::class);
		$this->instanceService->method('supportedMimeTypes')->willReturn(['image/jpeg', 'video/mp4']);
		$this->instanceService->method('maxUploadSize')->willReturn(10 * 1024 * 1024);

		// a user config that remembers, so what is written can be read back
		$this->configService = $this->createStub(ConfigService::class);
		$this->configService->method('getSocialUrl')->willReturn('https://cloud.example/apps/social/');
		$this->configService->method('setValueForUser')
			->willReturnCallback(function (string $user, string $key, string $value): void {
				$this->userConfig[$user . '|' . $key] = $value;
			});
		$this->configService->method('getValueForUser')
			->willReturnCallback(fn (string $user, string $key): string
				=> $this->userConfig[$user . '|' . $key] ?? '');

		$this->cacheActorService->method('getFromId')->willReturnCallback(fn (string $id): Person => $this->person($id));

		$this->service = new PixelfedService(
			$this->storyService,
			$this->storiesRequest,
			$this->collectionService,
			$this->followService,
			$this->cacheActorService,
			$this->searchService,
			$this->reportService,
			$this->streamService,
			$this->createStub(AvatarService::class),
			$this->createStub(AccountService::class),
			$this->instanceService,
			$this->configService,
			$this->streamRequest,
			$this->followsRequest,
			$this->cacheActorsRequest,
			$this->postService,
		);
	}

	private function person(string $id, int $nid = 0): Person {
		$person = new Person();
		$person->setId($id)
			->setPreferredUsername(ltrim(basename($id), '@'))
			->setAccount(ltrim(basename($id), '@') . '@' . parse_url($id, PHP_URL_HOST));
		$person->setNid($nid > 0 ? $nid : crc32($id) % 1000 + 1);

		return $person;
	}

	private function story(int $id, string $owner, bool $seen, string $type = 'image'): Story {
		$media = (new MediaAttachment())->setType($type)->setUrl('https://cloud.example/media/' . $id . '.jpg');

		return (new Story())->setId($id)->setOwnerId($owner)->setSeen($seen)->setDuration(5)
			->setCreation(1_700_000_000 + $id)->setMedia($media)->setAuthor($this->person($owner));
	}

	/** One node per account, the viewer's own apart, each unseen until every story in it was. */
	public function testTheCarouselIsGroupedByAccountWithTheViewersOwnApart(): void {
		$this->storyService->method('carousel')->willReturn([
			$this->story(1, self::ALICE, true),
			$this->story(2, self::BOB, true),
			$this->story(3, self::BOB, false, 'video'),
			$this->story(4, self::CAROL, true),
		]);

		$carousel = $this->service->carousel($this->person(self::ALICE));

		$this->assertSame('alice', $carousel['self']['user']['username']);
		$this->assertTrue($carousel['self']['user']['is_author']);
		$this->assertCount(2, $carousel['nodes']);
		$bob = $carousel['nodes'][0];
		$this->assertSame('bob', $bob['user']['username']);
		$this->assertFalse($bob['seen'], 'one unseen story is an unseen ring');
		$this->assertSame(['photo', 'video'], array_column($bob['nodes'], 'type'));
		$this->assertSame('https://cloud.example/media/3.jpg', $bob['nodes'][1]['src']);
		$this->assertTrue($carousel['nodes'][1]['seen']);
	}

	public function testAViewerWithNoStoryOfTheirOwnHasNoSelfNode(): void {
		$this->storyService->method('carousel')->willReturn([$this->story(2, self::BOB, false)]);

		$carousel = $this->service->carousel($this->person(self::ALICE));

		$this->assertNull($carousel['self']);
		$this->assertCount(1, $carousel['nodes']);
	}

	/** Pixelfed's words for the visibility, a thumb from the first picture, a page of this app's own. */
	public function testACollectionIsReshapedTheWayTheAppReadsIt(): void {
		$post = new Note();
		$post->setAttachments([(new MediaAttachment())->setUrl('https://cloud.example/media/full.jpg')->setPreviewUrl('https://cloud.example/media/small.jpg')]);
		$collection = (new Collection())->setId(9)->setOwnerId(self::ALICE)->setTitle('Coast')
			->setDescription('grey days')->setVisibility(Collection::VISIBILITY_FOLLOWERS)
			->setCreation(1_700_000_000)->setUpdated(1_700_000_500)->setSize(4)->setPreview([$post]);
		$this->collectionService->method('forProfile')->willReturn([$collection]);
		$this->collectionService->method('withPreview')->willReturnArgument(0);

		$entity = $this->service->collections($this->person(self::ALICE))[0];

		$this->assertSame('9', $entity['id']);
		$this->assertSame('private', $entity['visibility']);
		$this->assertSame('https://cloud.example/media/small.jpg', $entity['thumb']);
		$this->assertSame('https://cloud.example/apps/social/collections/9', $entity['url']);
		$this->assertSame(4, $entity['post_count']);
		$this->assertNull($entity['published_at'], 'a followers-only collection is not published');
		$this->assertSame(date('c', 1_700_000_500), $entity['updated_at']);
	}

	/** A post is reported against its author and carried with the report; the reason survives as the comment. */
	public function testAReportAboutAPostNamesItsAuthorAndCarriesThePost(): void {
		$post = new Note();
		$post->setNid(17);
		$post->setAttributedTo(self::BOB);
		$this->streamService->method('getStreamByNid')->with(17)->willReturn($post);
		$this->reportService->expects($this->once())->method('reportFromLocal')
			->with(
				$this->callback(fn (Person $reporter): bool => $reporter->getId() === self::ALICE),
				$this->callback(fn (Person $target): bool => $target->getId() === self::BOB),
				['17'],
				'underage: looks young',
				Report::CATEGORY_VIOLATION,
				false
			);

		$this->service->report($this->person(self::ALICE), 'underage', '17', 'post', 'looks young');
	}

	public function testAReportAboutAStoryNamesItsOwnerAndCarriesNoPost(): void {
		$this->storiesRequest->method('getLiveById')->with(5)->willReturn((new Story())->setId(5)->setOwnerId(self::CAROL));
		$this->reportService->expects($this->once())->method('reportFromLocal')
			->with($this->anything(), $this->callback(fn (Person $t): bool => $t->getId() === self::CAROL), [], 'copyright', Report::CATEGORY_LEGAL, false);

		$this->service->report($this->person(self::ALICE), 'copyright', '5', 'story', '');
	}

	public function testAReasonTheAppDoesNotOfferIsRefused(): void {
		$this->reportService->expects($this->never())->method('reportFromLocal');

		$this->expectException(InvalidResourceException::class);
		$this->service->report($this->person(self::ALICE), 'rude', '17', 'post', '');
	}

	public function testNobodyReportsThemselves(): void {
		$this->cacheActorService->method('resolve')->willReturn($this->person(self::ALICE));
		$this->reportService->expects($this->never())->method('reportFromLocal');

		$this->expectException(InvalidResourceException::class);
		$this->service->report($this->person(self::ALICE), 'spam', '3', 'user', '');
	}

	/** Pixelfed's mutuals are Mastodon's familiar followers. */
	public function testMutualsAreTheFamiliarFollowers(): void {
		$this->cacheActorService->method('resolve')->willReturn($this->person(self::BOB, 7));
		$this->followService->expects($this->once())->method('familiarFollowers')
			->with($this->anything(), $this->callback(fn (Person $t): bool => $t->getId() === self::BOB), PixelfedService::ACCOUNTS_LIMIT)
			->willReturn([$this->person(self::CAROL)]);

		$mutuals = $this->service->mutuals($this->person(self::ALICE), '7');

		$this->assertSame(self::CAROL, $mutuals[0]->getId());
	}

	/** Push is off, has no token, and says so. */
	public function testThePushStateIsHonest(): void {
		$state = $this->service->pushState($this->person(self::ALICE, 12));

		$this->assertFalse($state['notify_enabled']);
		$this->assertFalse($state['has_token']);
		$this->assertSame('12', $state['profile_id']);
		$this->assertSame(['active' => false], $this->service->nagState());
		$this->assertFalse($this->service->pushCompare($this->person(self::ALICE))['match']);
	}

	public function testTheComposerSettingsSpeakPixelfedsWords(): void {
		$alice = $this->person(self::ALICE);
		$alice->setPrivacy(Stream::TYPE_FOLLOWERS);

		$settings = $this->service->composeSettings($alice);

		$this->assertSame('private', $settings['default_scope']);
		$this->assertSame(Stream::MAX_ATTACHMENTS, $settings['max_media_attachments']);
		$this->assertSame(10 * 1024 * 1024, $settings['max_file_size']);
		$this->assertFalse($settings['media_descriptions']);
	}

	public function testAnAccountIsResolvedThroughTheOneResolver(): void {
		$this->cacheActorService->expects($this->once())
			->method('resolve')
			->with('@carol@remote.example')
			->willReturn($this->person(self::CAROL));

		$this->assertSame(self::CAROL, $this->service->resolveAccount('@carol@remote.example')->getId());
	}
	private function directPost(int|string $nid, string $author, string $html, int $published): Note {
		$post = new Note();
		$post->setNid($nid);
		$post->setAttributedTo($author);
		$post->setContent($html);
		$post->setPublishedTime($published);
		$post->setVisibility(Stream::TYPE_DIRECT);

		return $post;
	}

	/**
	 * A nid is compared as the number it is, whichever type it arrives as.
	 *
	 * Asserted rather than assumed: a bug report held that `<=>` on two nids
	 * read as strings compares them character by character. It does not — PHP
	 * compares two integer-like strings numerically, exactly and at any
	 * length — and this is here so that a change to how these are held does
	 * not quietly reorder a conversation.
	 */
	public function testAThreadIsOrderedByTheNumberANidIsAndNotByItsText(): void {
		$bob = $this->person(self::BOB, 7);
		$bob->setLocal(true);
		$this->cacheActorService->method('resolve')->willReturn($bob);
		$this->streamRequest->method('directBetween')->willReturn([
			$this->directPost('9000000000000000000', self::BOB, '<p>later</p>', 1_700_000_100),
			$this->directPost('10000000000000000000', self::ALICE, '<p>latest</p>', 1_700_000_200),
			$this->directPost('900000000000000000', self::BOB, '<p>earliest</p>', 1_700_000_000),
		]);

		$thread = $this->service->thread($this->person(self::ALICE), '7');

		$this->assertSame(
			['900000000000000000', '9000000000000000000', '10000000000000000000'],
			array_column($thread['messages'], 'id')
		);
	}

	/** The other party on top, the messages oldest first, each saying whose it is. */
	public function testAThreadIsTheDirectPostsBetweenTwoAccountsOldestFirst(): void {
		$bob = $this->person(self::BOB, 7);
		$bob->setLocal(true);
		$this->cacheActorService->method('resolve')->willReturn($bob);
		$this->streamRequest->method('directBetween')
			->with($this->anything(), self::BOB, PixelfedService::THREAD_PAGE, 0, 0)
			->willReturn([
				$this->directPost(12, self::BOB, '<p><span class="h-card">@alice</span> and you?</p>', 1_700_000_100),
				$this->directPost(11, self::ALICE, '<p>@bob@cloud.example fine &amp; well</p>', 1_700_000_000),
			]);

		$thread = $this->service->thread($this->person(self::ALICE), '7');

		$this->assertSame('7', $thread['id']);
		// a local account's `acct` is its bare username, as Mastodon spells it
		$this->assertSame('bob', $thread['username']);
		$this->assertTrue($thread['isLocal']);
		$this->assertSame(['11', '12'], array_column($thread['messages'], 'id'));
		$this->assertTrue($thread['messages'][0]['isAuthor']);
		$this->assertFalse($thread['messages'][1]['isAuthor']);
		$this->assertSame('@bob@cloud.example fine & well', $thread['messages'][0]['text'], 'the text, not its markup');
		$this->assertSame('text', $thread['messages'][0]['type']);
		$this->assertSame('@alice and you?', $thread['lastMessage']);
	}

	/** A message is a direct post to the account, sent the way the composer sends one. */
	public function testSendingAMessageIsADirectPostNamingTheRecipient(): void {
		$this->cacheActorService->method('resolve')->willReturn($this->person(self::BOB, 7));
		$sent = null;
		$this->postService->expects($this->once())->method('createPost')
			->willReturnCallback(function (Post $post) use (&$sent): Note {
				$sent = $post;

				return $this->directPost(31, self::ALICE, '<p>' . $post->getContent() . '</p>', time());
			});

		$message = $this->service->sendMessage($this->person(self::ALICE), '7', 'see you at eight', 'text');

		$this->assertSame(Stream::TYPE_DIRECT, $sent->getType());
		$this->assertSame('@bob@cloud.example see you at eight', $sent->getContent());
		$this->assertSame('31', $message['id']);
		$this->assertTrue($message['isAuthor']);
	}

	public function testAnEmptyOrOverlongMessageIsRefusedBeforeAnythingIsSent(): void {
		$this->postService->expects($this->never())->method('createPost');

		$this->expectException(InvalidResourceException::class);
		$this->service->sendMessage($this->person(self::ALICE), '7', str_repeat('x', PixelfedService::MESSAGE_MAX + 1));
	}

	/** Somebody else's message is the same 404 as one that never existed. */
	public function testOnlyTheAuthorTakesAMessageBack(): void {
		$this->streamService->method('getStreamByNid')->with(12)->willReturn($this->directPost(12, self::BOB, '<p>hi</p>', 1));
		$this->streamService->expects($this->never())->method('deleteLocalItem');

		$this->expectException(ItemNotFoundException::class);
		$this->service->deleteMessage($this->person(self::ALICE), 12);
	}

	public function testTheComposeScreenIsOfferedTheAccountsThatFollowBack(): void {
		$follow = function (string $actor, string $object): Follow {
			$f = new Follow();
			$f->setActorId($actor);
			$f->setObjectId($object);

			return $f;
		};
		$this->followsRequest->method('getFollowingByActorId')->willReturn([$follow(self::ALICE, self::BOB), $follow(self::ALICE, self::CAROL)]);
		$this->followsRequest->method('getFollowersByActorId')->willReturn([$follow(self::CAROL, self::ALICE), $follow('https://x.example/u/dave', self::ALICE)]);
		$this->cacheActorsRequest->expects($this->once())->method('getFromIds')->with([self::CAROL])->willReturn([self::CAROL => $this->person(self::CAROL)]);

		$mutuals = $this->service->composeMutuals($this->person(self::ALICE));

		$this->assertSame([self::CAROL], array_map(static fn (Person $p): string => $p->getId(), $mutuals));
	}

	// app/settings

	private function alice(): Person {
		$person = $this->person(self::ALICE);
		$person->setUserId('alice');

		return $person;
	}

	/**
	 * Pixelfed's own defaults, so an app talking to this server behaves on
	 * first run the way it does against the server it was written for.
	 */
	public function testTheAppSettingsStartAtPixelfedsOwnDefaults(): void {
		$settings = $this->service->appSettings($this->alice());

		$this->assertSame('alice', $settings['username']);
		// the id the app keys its own per-account state on
		$this->assertSame((string)$this->person(self::ALICE)->getNid(), $settings['id']);
		// null, not a date: how the app tells "never set" from "set to the
		// defaults"
		$this->assertNull($settings['updated_at']);
		$this->assertFalse($settings['common']['timelines']['show_public']);
		$this->assertTrue($settings['common']['media']['hide_public_behind_cw']);
		$this->assertSame('system', $settings['common']['appearance']['theme']);
	}

	public function testWhatTheAppSavesIsWhatItReadsBack(): void {
		$this->service->saveAppSettings($this->alice(), [
			'timelines' => ['show_public' => true, 'show_network' => true, 'hide_likes_shares' => false],
			'media' => ['hide_public_behind_cw' => false, 'always_show_cw' => true, 'show_alt_text' => true],
			'appearance' => ['links_use_in_app_browser' => false, 'theme' => 'dark'],
		]);

		$settings = $this->service->appSettings($this->alice());

		$this->assertTrue($settings['common']['timelines']['show_public']);
		$this->assertTrue($settings['common']['media']['show_alt_text']);
		$this->assertSame('dark', $settings['common']['appearance']['theme']);
		$this->assertNotNull($settings['updated_at']);
	}

	/**
	 * A blob written by a client and handed back to a client that kept
	 * whatever it was given would be a place to park arbitrary content under
	 * somebody's account.
	 */
	public function testNothingButTheEightSwitchesIsKept(): void {
		$saved = $this->service->saveAppSettings($this->alice(), [
			'timelines' => ['show_public' => true, 'smuggled' => str_repeat('x', 100)],
			'anything' => ['at' => 'all'],
		]);

		$this->assertSame(['timelines', 'media', 'appearance'], array_keys($saved['common']));
		$this->assertArrayNotHasKey('smuggled', $saved['common']['timelines']);
		$this->assertArrayNotHasKey('anything', $saved['common']);
		// and what the client did not send keeps the default rather than
		// becoming false
		$this->assertTrue($saved['common']['media']['hide_public_behind_cw']);
	}

	/** A theme the app does not have is the default, not whatever was sent. */
	public function testAThemeThatIsNotOneOfTheThreeIsRefused(): void {
		$saved = $this->service->saveAppSettings($this->alice(), [
			'appearance' => ['theme' => 'neon'],
		]);

		$this->assertSame('system', $saved['common']['appearance']['theme']);
	}

	/**
	 * The viewer a route holds comes from `social_actor`, which is what this
	 * instance decides about an account and carries no numeric id. Without
	 * the fallback every account answered `0`, and two accounts on one phone
	 * shared whatever the app stored under it.
	 */
	public function testAnAccountWithNoNumberOfItsOwnIsStillNamed(): void {
		$viewer = new Person();
		$viewer->setId(self::ALICE)->setPreferredUsername('alice');
		$viewer->setUserId('alice');

		$settings = $this->service->appSettings($viewer);

		$this->assertNotSame('0', $settings['id']);
		$this->assertSame((string)$this->person(self::ALICE)->getNid(), $settings['id']);
	}

	/** One account's switches are not another's. */
	public function testTheSwitchesAreKeptPerAccount(): void {
		$bob = $this->person(self::BOB);
		$bob->setUserId('bob');

		$this->service->saveAppSettings($this->alice(), ['appearance' => ['theme' => 'dark']]);

		$this->assertSame('dark', $this->service->appSettings($this->alice())['common']['appearance']['theme']);
		$this->assertSame('system', $this->service->appSettings($bob)['common']['appearance']['theme']);
	}
}
