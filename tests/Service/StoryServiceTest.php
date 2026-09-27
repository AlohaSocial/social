<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\StoriesRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Interfaces\Object\DocumentInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Story as ApStory;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Client\Story;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\MediaPurgeService;
use OCA\Social\Service\StoryInteractionService;
use OCA\Social\Service\StoryService;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class StoryServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example.org/users/alice';
	private const BOB = 'https://cloud.example.org/users/bob';

	private StoriesRequest|MockObject $storiesRequest;
	private StoryInteractionService|Stub $storyInteractionService;
	private MediaPurgeService|MockObject $mediaPurgeService;
	private DocumentService|MockObject $documentService;
	private FollowService|MockObject $followService;
	private StoryService $service;
	private ActivityService|Stub $activityService;
	/** @var ACore[] what was queued for delivery */
	private array $sent = [];

	protected function setUp(): void {
		parent::setUp();
		$this->storiesRequest = $this->createMock(StoriesRequest::class);
		$this->storyInteractionService = $this->createStub(StoryInteractionService::class);
		$this->mediaPurgeService = $this->createMock(MediaPurgeService::class);
		$this->documentService = $this->createMock(DocumentService::class);
		$this->followService = $this->createMock(FollowService::class);
		$this->activityService = $this->createStub(ActivityService::class);
		$this->activityService->method('request')
			->willReturnCallback(function (ACore $activity): string {
				$this->sent[] = $activity;

				return 'token';
			});

		$configService = $this->createStub(ConfigService::class);
		$configService->method('getSocialUrl')->willReturn('https://cloud.example/apps/social/');
		$configService->method('getStorySecret')->willReturn('a-secret-of-this-instances-own');

		$this->service = new StoryService(
			$this->storiesRequest,
			$this->documentService,
			$this->followService,
			$this->createStub(CacheActorService::class),
			$this->createStub(IURLGenerator::class),
			$this->activityService,
			$configService,
			$this->createStub(DocumentInterface::class),
			$this->storyInteractionService,
			$this->mediaPurgeService,
			new NullLogger(),
		);
	}

	/** The service with a cache of actors of the test's own choosing. */
	private function serviceWith(CacheActorService $cacheActorService): StoryService {
		$configService = $this->createStub(ConfigService::class);
		$configService->method('getSocialUrl')->willReturn('https://cloud.example/apps/social/');
		$configService->method('getStorySecret')->willReturn('a-secret-of-this-instances-own');

		return new StoryService(
			$this->storiesRequest,
			$this->documentService,
			$this->followService,
			$cacheActorService,
			$this->createStub(IURLGenerator::class),
			$this->activityService,
			$configService,
			$this->createStub(DocumentInterface::class),
			$this->storyInteractionService,
			$this->mediaPurgeService,
			new NullLogger(),
		);
	}

	private function person(string $id): Person {
		$person = new Person();
		$person->setId($id);
		$person->setPreferredUsername(basename($id));
		// where a story is addressed, which is the only audience one has
		$person->setFollowers($id . '/followers');

		return $person;
	}

	private function story(string $owner, int $id = 1): Story {
		return (new Story())->setId($id)->setOwnerId($owner)
			->setExpiresAt(time() + Story::LIFETIME);
	}

	/** Who watched is told to the poster and to nobody else, like the view count. */
	/**
	 * A carousel draws a face, a name and a handle; an actor in ActivityPub
	 * shape has none of the three.
	 */
	public function testTheAuthorIsHandedToAClientInTheClientFormat(): void {
		$this->storiesRequest->method('getLiveByActor')->willReturn([$this->story(self::ALICE, 3)]);
		$this->storiesRequest->method('seenAmong')->willReturn([]);
		$cacheActorService = $this->createStub(CacheActorService::class);
		$cacheActorService->method('getFromId')->willReturn($this->person(self::ALICE));
		$service = $this->serviceWith($cacheActorService);

		$author = $service->forAccount($this->person(self::ALICE), $this->person(self::ALICE))[0]->getAuthor();

		$this->assertSame(ACore::FORMAT_LOCAL, $author?->getExportFormat());
	}

	public function testOnlyTheOwnerIsToldWhoWatchedAStory(): void {
		$this->storiesRequest->method('getLiveById')->willReturn($this->story(self::ALICE, 7));
		$this->storiesRequest->expects($this->never())->method('viewersOf');

		$this->expectException(ItemNotFoundException::class);
		$this->service->viewers($this->person(self::BOB), 7);
	}

	public function testTheOwnerGetsTheViewersThisServerCanStillName(): void {
		$this->storiesRequest->method('getLiveById')->willReturn($this->story(self::ALICE, 7));
		$this->storiesRequest->method('viewersOf')->with(7)->willReturn([self::BOB, 'https://gone.example/users/x']);
		$cacheActorService = $this->createStub(CacheActorService::class);
		$cacheActorService->method('getFromId')->willReturnCallback(function (string $id): Person {
			if ($id === self::BOB) {
				return $this->person(self::BOB);
			}
			throw new CacheActorDoesNotExistException();
		});
		$service = $this->serviceWith($cacheActorService);

		$viewers = $service->viewers($this->person(self::ALICE), 7);

		$this->assertCount(1, $viewers);
		$this->assertSame(self::BOB, $viewers[0]->getId());
	}

	public function testPostingSomebodyElsesUploadIsRefused(): void {
		// the account filter is part of the lookup, so a foreign id finds nothing
		$this->documentService->method('getMediaFromArray')->willReturn([]);

		$this->expectException(InvalidResourceException::class);
		$this->expectExceptionMessageMatches('/unknown media/');

		$this->service->add($this->person(self::ALICE), 99, '', 5);
	}

	public function testAnAccountCannotKeepUnlimitedLiveStories(): void {
		$this->documentService->method('getMediaFromArray')->willReturn([new Document()]);
		$this->storiesRequest->method('countLiveByActor')->willReturn(Story::MAX_PER_ACTOR);

		$this->expectException(InvalidResourceException::class);
		$this->expectExceptionMessageMatches('/already has/');

		$this->service->add($this->person(self::ALICE), 99, '', 5);
	}

	public function testDeletingSomebodyElsesStoryIsANotFoundAndDeletesNothing(): void {
		$this->storiesRequest->method('getLiveById')->willReturn($this->story(self::BOB));
		$this->storiesRequest->expects($this->never())->method('delete');

		$this->expectException(ItemNotFoundException::class);

		$this->service->delete($this->person(self::ALICE), 1);
	}

	/**
	 * Whether an account has a story up is itself only told to its followers,
	 * so a stranger gets "there are none" rather than "not allowed".
	 */
	public function testAStrangerIsToldThereAreNoneRatherThanBeingRefused(): void {
		$this->followService->method('getLinksBetweenPersons')
			->willReturn(['follower' => false, 'following' => false]);

		$this->expectException(ItemNotFoundException::class);
		$this->expectExceptionMessageMatches('/no stories/');

		$this->service->forAccount($this->person(self::BOB), $this->person(self::ALICE));
	}

	public function testAFollowerSeesThem(): void {
		$this->followService->method('getLinksBetweenPersons')
			->willReturn(['follower' => false, 'following' => true]);
		$this->storiesRequest->method('getLiveByActor')->willReturn([]);

		$this->assertSame([], $this->service->forAccount($this->person(self::BOB), $this->person(self::ALICE)));
	}

	public function testAnOwnerSeesTheirOwnWithoutFollowingThemselves(): void {
		$this->followService->expects($this->never())->method('getLinksBetweenPersons');
		$this->storiesRequest->method('getLiveByActor')->willReturn([]);

		$this->service->forAccount($this->person(self::ALICE), $this->person(self::ALICE));
	}

	public function testMarkingAStrangersStorySeenIsRefused(): void {
		$this->storiesRequest->method('getLiveById')->willReturn($this->story(self::BOB));
		$this->followService->method('getLinksBetweenPersons')
			->willReturn(['follower' => false, 'following' => false]);
		$this->storiesRequest->expects($this->never())->method('markSeen');

		$this->expectException(ItemNotFoundException::class);

		$this->service->markSeen($this->person(self::ALICE), 1);
	}

	/** Only the poster is told how many people watched. */
	public function testTheViewCountIsFilledInOnlyForTheOwner(): void {
		$this->storiesRequest->method('seenAmong')->willReturn([]);
		$this->storiesRequest->method('countViews')->willReturn(17);
		$this->storiesRequest->method('getLiveByActor')->willReturn([$this->story(self::ALICE)]);

		$own = $this->service->forAccount($this->person(self::ALICE), $this->person(self::ALICE));
		$this->assertSame(17, $own[0]->getViewCount());

		$this->followService->method('getLinksBetweenPersons')
			->willReturn(['follower' => false, 'following' => true]);
		$theirs = $this->service->forAccount($this->person(self::BOB), $this->person(self::ALICE));
		$this->assertSame(0, $theirs[0]->getViewCount(), 'a viewer was told how many people watched');
	}

	public function testSeenIsResolvedForTheWholeCarouselInOneQuery(): void {
		$this->storiesRequest->method('getLiveByActor')->willReturn([$this->story(self::ALICE, 1)]);
		$this->storiesRequest->method('getLiveByActors')->willReturn([$this->story(self::BOB, 2)]);
		$this->followService->method('getFollowing')->willReturn([]);
		$this->storiesRequest->expects($this->once())->method('seenAmong')->willReturn([2]);

		$carousel = $this->service->carousel($this->person(self::ALICE));

		$this->assertCount(2, $carousel);
		$this->assertFalse($carousel[0]->isSeen());
		$this->assertTrue($carousel[1]->isSeen());
	}
	// ── what travels ───────────────────────────────────────────────────────

	/** A story goes out as Pixelfed's verb, to the followers and nobody else. */
	public function testPostingAStorySendsAnAddToTheFollowers(): void {
		$this->documentService->method('getMediaFromArray')->willReturn([$this->document()]);
		$this->storiesRequest->method('countLiveByActor')->willReturn(0);
		$this->storiesRequest->method('save')
			->willReturnCallback(static fn (Story $story): Story => $story->setId(7)->setCreation(1_700_000_000)->setExpiresAt(1_700_086_400));
		$this->storiesRequest->method('seenAmong')->willReturn([]);
		$this->storiesRequest->expects($this->once())
			->method('setSourceId')
			->with(7, 'https://cloud.example/apps/social/@alice/stories/7');

		$this->service->add($this->person(self::ALICE), 4, 'Late shift', 7);

		$this->assertCount(1, $this->sent);
		$activity = $this->sent[0];
		$this->assertSame('Add', $activity->getType());
		$this->assertSame(self::ALICE . '/followers', $activity->getTo());

		$story = $activity->getObject();
		$this->assertInstanceOf(ApStory::class, $story);
		$this->assertSame('Story', $story->getType());
		$this->assertSame('https://cloud.example/apps/social/@alice/stories/7', $story->getId());
		$this->assertSame(self::ALICE, $story->getAttributedTo());
		$this->assertSame(7, $story->getDuration());
		$this->assertSame(1_700_086_400, $story->getExpiresAt());
	}

	/**
	 * The shape Pixelfed's `StoryFetch` validates, field by field, read off
	 * its source at `472b4c4`. Every one of these is a gate: it drops the
	 * activity without a word when any of them is missing, which is what this
	 * app's stories did before there was a bearcap on them.
	 */
	public function testTheWireShapeIsTheOnePixelfedValidates(): void {
		$story = $this->story(self::ALICE, 7)
			->setSourceId('https://cloud.example/apps/social/@alice/stories/7')
			->setCaption('Late shift')
			->setCreation(1_700_000_000)
			->setExpiresAt(1_700_086_400);
		$story->setMedia(
			(new MediaAttachment())->setUrl('https://cloud.example/media/7.jpg')->setMediaType('image/jpeg')
		);

		$json = $this->service->asActivityPub($this->person(self::ALICE), $story)->jsonSerialize();

		// `object.object` — required, a string, and the only thing Pixelfed
		// reads the story out of
		$this->assertSame(
			'bear:?t=' . hash_hmac('sha256', $story->getSourceId(), 'a-secret-of-this-instances-own')
			. '&u=' . $story->getSourceId(),
			$json['object']
		);
		// its decoder splits on `&` without unescaping, so neither half may be
		// encoded, and the url must be one it can parse
		$this->assertStringStartsWith('bear:?t=', $json['object']);
		$this->assertStringNotContainsString('%', $json['object']);

		// `attachment.url` / `.type` / `.mediaType` — one object, not a list,
		// and a type out of the pair it accepts
		$this->assertSame('Image', $json['attachment']['type']);
		$this->assertSame('image/jpeg', $json['attachment']['mediaType']);
		$this->assertSame('https://cloud.example/media/7.jpg', $json['attachment']['url']);

		// `published` and an `expiresAt` after it, both dates
		$this->assertNotFalse(strtotime($json['published']));
		$this->assertGreaterThan(strtotime($json['published']), strtotime($json['expiresAt']));

		// and the two it reads as booleans
		$this->assertFalse($json['can_reply']);
		$this->assertFalse($json['can_react']);
	}

	/**
	 * A story looked up by its address — which is what the fetch route does,
	 * and what a capability leads to — has no picture on it yet: only the
	 * timeline paths hydrate. Serialised without one it is not a story, and
	 * the receiver that followed the capability has nothing to show.
	 */
	public function testAStoryReadOffItsRowIsStillServedWithItsPicture(): void {
		$story = $this->story(self::ALICE, 7)
			->setSourceId('https://cloud.example/apps/social/@alice/stories/7')
			->setDocumentId('https://cloud.example/documents/4');
		$this->assertNull($story->getMedia(), 'the row itself carries none');

		$document = new Document();
		$document->setId('https://cloud.example/documents/4');
		$document->setUrl('https://cloud.example/media/4.jpg');
		$document->setMediaType('image/jpeg');
		$this->documentService->method('getDocumentById')
			->with('https://cloud.example/documents/4')->willReturn($document);

		$json = $this->service->asActivityPub($this->person(self::ALICE), $story)->jsonSerialize();

		// the picture the row named, typed the way Pixelfed requires
		$this->assertSame('image/jpeg', $json['attachment']['mediaType']);
		$this->assertSame('Image', $json['attachment']['type']);
	}

	/** A video story is a `Video`; Pixelfed takes those two types and no others. */
	public function testAVideoStoryIsTypedAsOne(): void {
		$story = $this->story(self::ALICE, 7)->setSourceId('https://cloud.example/apps/social/@alice/stories/7');
		$story->setMedia(
			(new MediaAttachment())->setUrl('https://cloud.example/media/7.mp4')->setMediaType('video/mp4')
		);

		$json = $this->service->asActivityPub($this->person(self::ALICE), $story)->jsonSerialize();

		$this->assertSame('Video', $json['attachment']['type']);
	}

	/**
	 * The capability names one story. A token for another, or none at all, is
	 * not a way in — it is the only credential a fetch of somebody's story
	 * carries, since Pixelfed follows the bearcap rather than signing.
	 */
	public function testACapabilityOpensTheStoryItNamesAndNoOther(): void {
		$mine = 'https://cloud.example/apps/social/@alice/stories/7';
		$other = 'https://cloud.example/apps/social/@alice/stories/8';

		$this->assertTrue($this->service->bearcapMatches($mine, $this->service->bearcapToken($mine)));
		$this->assertFalse($this->service->bearcapMatches($mine, $this->service->bearcapToken($other)));
		$this->assertFalse($this->service->bearcapMatches($mine, ''));
		$this->assertFalse($this->service->bearcapMatches($mine, 'not-a-token'));
	}

	/**
	 * A story promises to stop existing after a day. Until the picture went
	 * with the row that was true of the row and false of everything else:
	 * `/media/{uuid}` went on serving the file for ever, to anybody who had
	 * been shown the story, and the disk was never reclaimed.
	 */
	public function testTheExpiredStorysPictureGoesWithIt(): void {
		$story = $this->story(self::ALICE, 3)->setDocumentId('https://cloud.example/documents/g/1');
		$this->storiesRequest->method('getExpired')->willReturn([$story]);
		$this->storiesRequest->expects($this->once())->method('deleteByIds')->with([3])->willReturn(1);
		$document = new Document();
		$document->setId('https://cloud.example/documents/g/1');
		$this->documentService->method('getDocumentById')->willReturn($document);

		$this->mediaPurgeService->expects($this->once())->method('purge')
			->with($this->identicalTo($document));

		$this->assertSame(1, $this->service->purgeExpired());
	}

	public function testDeletingAStoryTakesItsPictureAway(): void {
		$story = $this->story(self::ALICE, 7)->setDocumentId('https://cloud.example/documents/g/1');
		$this->storiesRequest->method('getLiveById')->willReturn($story);
		$document = new Document();
		$document->setId('https://cloud.example/documents/g/1');
		$this->documentService->method('getDocumentById')->willReturn($document);

		$this->mediaPurgeService->expects($this->once())->method('purge');

		$this->service->delete($this->person(self::ALICE), 7);
	}

	/**
	 * A remote story's picture hangs off the story; a local one hangs off
	 * nothing. Anything else is a picture a post is showing, and a story going
	 * is not a reason to take a post's picture away.
	 */
	public function testAPictureThatBelongsToAPostIsLeftAlone(): void {
		$story = $this->story(self::ALICE, 3)->setDocumentId('https://cloud.example/documents/g/1');
		$this->storiesRequest->method('getExpired')->willReturn([$story]);
		$document = new Document();
		$document->setId('https://cloud.example/documents/g/1');
		$document->setParentId('https://cloud.example/apps/social/@alice/1');
		$this->documentService->method('getDocumentById')->willReturn($document);

		$this->mediaPurgeService->expects($this->never())->method('purge');

		$this->service->purgeExpired();
	}

	public function testAStoryWhosePictureIsAlreadyGoneStillGoes(): void {
		$story = $this->story(self::ALICE, 3)->setDocumentId('https://cloud.example/documents/g/1');
		$this->storiesRequest->method('getExpired')->willReturn([$story]);
		$this->documentService->method('getDocumentById')
			->willThrowException(new CacheDocumentDoesNotExistException());
		$this->storiesRequest->expects($this->once())->method('deleteByIds')->willReturn(1);

		$this->assertSame(1, $this->service->purgeExpired());
	}

	public function testAWithdrawnStorysPictureGoesOnItsAuthorsWord(): void {
		$story = $this->story(self::ALICE, 9)->setDocumentId('https://cloud.example/documents/g/1');
		$this->storiesRequest->method('getBySourceId')->willReturn($story);
		$document = new Document();
		$document->setId('https://cloud.example/documents/g/1');
		$this->documentService->method('getDocumentById')->willReturn($document);

		$this->mediaPurgeService->expects($this->once())->method('purge');
		$this->storiesRequest->expects($this->once())->method('deleteBySourceId');

		$this->service->withdrawn('https://remote.example/stories/9', self::ALICE);
	}

	/** A `Delete` naming somebody else's story takes nothing away. */
	public function testAWithdrawalBySomebodyElseTakesNoPicture(): void {
		$story = $this->story(self::BOB, 9)->setDocumentId('https://cloud.example/documents/g/1');
		$this->storiesRequest->method('getBySourceId')->willReturn($story);

		$this->mediaPurgeService->expects($this->never())->method('purge');

		$this->service->withdrawn('https://remote.example/stories/9', self::ALICE);
	}

	public function testDeletingAStoryWithdrawsItFromTheFollowers(): void {
		$story = $this->story(self::ALICE, 7)->setSourceId('https://cloud.example/apps/social/@alice/stories/7');
		$this->storiesRequest->method('getLiveById')->willReturn($story);

		$this->service->delete($this->person(self::ALICE), 7);

		$this->assertCount(1, $this->sent);
		$this->assertSame('Delete', $this->sent[0]->getType());
		$this->assertSame('https://cloud.example/apps/social/@alice/stories/7', $this->sent[0]->getObjectId());
	}

	/** A story is published to followers, so one for an account nobody follows is nothing to keep. */
	public function testAStoryIsKeptOnlyWhereSomebodyHereFollowsItsAuthor(): void {
		$this->followService->method('getFollowers')->willReturn([]);
		$this->storiesRequest->expects($this->never())->method('save');

		$this->expectException(InvalidResourceException::class);
		$this->expectExceptionMessageMatches('/nobody here follows/');
		$this->service->receive($this->incoming(), $this->person(self::BOB));
	}

	public function testAnArrivingStoryIsWrittenWithItsAuthorsTimesBounded(): void {
		$this->followService->method('getFollowers')->willReturn([$this->createMock(Follow::class)]);
		$this->storiesRequest->method('getBySourceId')
			->willThrowException(new ItemNotFoundException());
		$saved = null;
		$this->storiesRequest->method('save')
			->willReturnCallback(static function (Story $story) use (&$saved): Story {
				$saved = $story;

				return $story->setId(3);
			});

		$this->service->receive($this->incoming(), $this->person(self::BOB));

		$this->assertFalse($saved->isLocal());
		$this->assertSame(self::BOB, $saved->getOwnerId());
		$this->assertSame('https://pixelfed.example/stories/9', $saved->getSourceId());
		$this->assertSame('a pier at low tide', $saved->getCaption());
		// the author's expiry, and never more than a day from now
		$this->assertLessThanOrEqual(time() + ApStory::MAX_LIFETIME, $saved->getExpiresAt());
		$this->assertGreaterThan(time(), $saved->getExpiresAt());
	}

	public function testAStoryThatHasAlreadyExpiredIsNotKept(): void {
		$this->followService->method('getFollowers')->willReturn([$this->createMock(Follow::class)]);
		$this->storiesRequest->expects($this->never())->method('save');
		$story = $this->incoming();
		$story->setExpiresAt(time() - 60);

		$this->expectException(InvalidResourceException::class);
		$this->service->receive($story, $this->person(self::BOB));
	}

	/** A fan-out reaches one instance once per follower on it; the second finds the first. */
	public function testTheSameStoryDeliveredTwiceIsOneRow(): void {
		$this->followService->method('getFollowers')->willReturn([$this->createMock(Follow::class)]);
		$this->storiesRequest->method('getBySourceId')->willReturn($this->story(self::BOB, 3));
		$this->storiesRequest->expects($this->never())->method('save');

		$this->assertSame(3, $this->service->receive($this->incoming(), $this->person(self::BOB))->getId());
	}

	private function document(): Document {
		$document = new Document();
		$document->setId('https://cloud.example/documents/1');
		$document->setMediaType('image/jpeg');

		return $document;
	}

	private function incoming(): ApStory {
		$story = new ApStory();
		$story->setId('https://pixelfed.example/stories/9');
		$story->setAttributedTo(self::BOB);
		$story->setCaption('a pier at low tide');
		$story->setDuration(7);
		$story->setPublished(gmdate('Y-m-d\TH:i:s\Z', time() - 120));
		$story->setExpiresAt(time() + 3600);
		$story->setAttachment($this->document());

		return $story;
	}
}
