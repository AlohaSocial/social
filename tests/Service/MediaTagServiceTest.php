<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\MediaTagsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\MediaTagService;
use OCA\Social\Service\NotificationService;
use OCA\Social\Service\StreamService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Naming people in a photograph: who may, who is told, and what it does not do.
 */
#[AllowMockObjectsWithoutExpectations]
class MediaTagServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example/@alice';
	private const BOB = 'https://cloud.example/@bob';
	private const CAROL = 'https://pixelfed.example/users/carol';
	private const POST = 'https://cloud.example/@alice/notes/7';

	private MediaTagsRequest|MockObject $mediaTagsRequest;
	private StreamService|MockObject $streamService;
	private CacheActorService|Stub $cacheActorService;
	private NotificationService|MockObject $notificationService;
	private ActivityService|MockObject $activityService;
	private StreamRequest|Stub $streamRequest;
	private MediaTagService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->mediaTagsRequest = $this->createMock(MediaTagsRequest::class);
		$this->streamService = $this->createMock(StreamService::class);
		$this->cacheActorService = $this->createStub(CacheActorService::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->activityService = $this->createMock(ActivityService::class);
		$this->streamRequest = $this->createStub(StreamRequest::class);

		$this->service = new MediaTagService(
			$this->mediaTagsRequest,
			$this->streamRequest,
			$this->streamService,
			$this->cacheActorService,
			$this->activityService,
			$this->notificationService,
			new NullLogger(),
		);
	}

	private function person(string $id, string $handle = ''): Person {
		$person = new Person();
		$person->setId($id);
		$person->setAccount(($handle !== '') ? $handle : 'somebody@cloud.example');

		return $person;
	}

	private function post(string $author = self::ALICE, bool $withPicture = true): Note {
		$note = new Note();
		$note->setId(self::POST);
		$note->setNid(7);
		$note->setAttributedTo($author);
		$note->setLocal(true);
		$note->setVisibility(Stream::TYPE_PUBLIC);
		if ($withPicture) {
			$note->setAttachments([new Document()]);
		}

		return $note;
	}

	/** @param Person[] $resolved keyed by what a client sent */
	private function resolving(array $resolved): void {
		$this->cacheActorService->method('resolve')->willReturnCallback(
			function (string $account) use ($resolved): Person {
				if (!isset($resolved[$account])) {
					throw new ItemNotFoundException('unknown account');
				}

				return $resolved[$account];
			}
		);
	}

	public function testNamingSomebodyWritesTheRowAndTellsThem(): void {
		$bob = $this->person(self::BOB, 'bob@cloud.example');
		$this->resolving(['bob@cloud.example' => $bob]);
		$this->streamService->method('getStreamByNid')->willReturn($this->post());
		$this->mediaTagsRequest->method('forStreams')->willReturn([]);

		$this->mediaTagsRequest->expects($this->once())->method('tag')
			->with(7, md5(self::POST), self::BOB, self::ALICE)
			->willReturn(true);
		$this->notificationService->expects($this->once())->method('onPhotoTag');

		$named = $this->service->tag($this->person(self::ALICE), 7, ['bob@cloud.example']);

		$this->assertSame([self::BOB], array_map(static fn (Person $p): string => $p->getId(), $named));
	}

	/**
	 * Anybody being able to write their own name onto anybody's photograph is a
	 * way to put a post in front of an audience that did not ask for it.
	 */
	public function testOnlyTheAuthorMayNameAnybodyInAPost(): void {
		$this->streamService->method('getStreamByNid')->willReturn($this->post(self::ALICE));

		$this->mediaTagsRequest->expects($this->never())->method('tag');
		$this->expectException(ItemNotFoundException::class);

		$this->service->tag($this->person(self::BOB), 7, ['carol@pixelfed.example']);
	}

	/** The feature is "who is in this picture". */
	public function testAPostWithNoPictureIsRefused(): void {
		$this->streamService->method('getStreamByNid')
			->willReturn($this->post(self::ALICE, false));

		$this->mediaTagsRequest->expects($this->never())->method('tag');
		$this->expectException(InvalidResourceException::class);

		$this->service->tag($this->person(self::ALICE), 7, ['bob@cloud.example']);
	}

	public function testThereIsACeilingOnHowManyPeopleAreInOnePhotograph(): void {
		$this->streamService->method('getStreamByNid')->willReturn($this->post());

		$this->mediaTagsRequest->expects($this->never())->method('tag');
		$this->expectException(InvalidResourceException::class);

		$this->service->tag(
			$this->person(self::ALICE), 7,
			array_fill(0, MediaTagsRequest::MAX_PER_POST + 1, 'bob@cloud.example')
		);
	}

	/**
	 * A client sends the names as they were typed, and one of them being gone
	 * is not a reason to lose the rest.
	 */
	public function testANameThisServerCannotFindIsLeftOutOfTheList(): void {
		$bob = $this->person(self::BOB, 'bob@cloud.example');
		$this->resolving(['bob@cloud.example' => $bob]);
		$this->streamService->method('getStreamByNid')->willReturn($this->post());
		$this->mediaTagsRequest->method('forStreams')->willReturn([]);
		$this->mediaTagsRequest->method('tag')->willReturn(true);

		$named = $this->service->tag(
			$this->person(self::ALICE), 7, ['bob@cloud.example', 'ghost@nowhere.example']
		);

		$this->assertSame([self::BOB], array_map(static fn (Person $p): string => $p->getId(), $named));
	}

	/**
	 * The list is what the post should end up naming, not what to add — which
	 * is what makes a client that re-sends its list on every edit a no-op
	 * rather than a growing pile.
	 */
	public function testSomebodyDroppedFromTheListIsUntagged(): void {
		$bob = $this->person(self::BOB, 'bob@cloud.example');
		$this->resolving(['bob@cloud.example' => $bob]);
		$this->streamService->method('getStreamByNid')->willReturn($this->post());
		$this->mediaTagsRequest->method('forStreams')
			->willReturn([7 => [self::BOB, self::CAROL]]);
		$this->mediaTagsRequest->method('tag')->willReturn(false);

		$this->mediaTagsRequest->expects($this->once())->method('untag')
			->with(7, self::CAROL);

		$this->service->tag($this->person(self::ALICE), 7, ['bob@cloud.example']);
	}

	/** Telling somebody twice about one tag is the unique index's whole job. */
	public function testARowThatWasAlreadyThereTellsNobodyAgain(): void {
		$bob = $this->person(self::BOB, 'bob@cloud.example');
		$this->resolving(['bob@cloud.example' => $bob]);
		$this->streamService->method('getStreamByNid')->willReturn($this->post());
		$this->mediaTagsRequest->method('forStreams')->willReturn([7 => [self::BOB]]);
		$this->mediaTagsRequest->method('tag')->willReturn(false);

		$this->notificationService->expects($this->never())->method('onPhotoTag');

		$this->service->tag($this->person(self::ALICE), 7, ['bob@cloud.example']);
	}

	/**
	 * A tag only this instance knew about would notify local accounts and do
	 * nothing at all for everybody else.
	 */
	public function testTheNameIsWrittenOntoThePostAndThePostIsSentAgain(): void {
		$bob = $this->person(self::BOB, 'bob@cloud.example');
		$this->resolving(['bob@cloud.example' => $bob]);
		$this->streamService->method('getStreamByNid')->willReturn($this->post());
		$this->mediaTagsRequest->method('forStreams')->willReturn([]);
		$this->mediaTagsRequest->method('tag')->willReturn(true);

		$this->streamService->expects($this->once())->method('addRecipient')
			->with($this->anything(), Stream::TYPE_PUBLIC, 'bob@cloud.example');
		$this->streamService->expects($this->once())->method('updateStream');
		$this->activityService->expects($this->once())->method('updateActivity');

		$this->service->tag($this->person(self::ALICE), 7, ['bob@cloud.example']);
	}

	/** Being in somebody's photograph is not something to need their permission to leave. */
	public function testAnybodyNamedMayTakeTheirOwnNameOff(): void {
		$this->streamService->method('getStreamByNid')->willReturn($this->post(self::ALICE));

		$this->mediaTagsRequest->expects($this->once())->method('untag')
			->with(7, self::BOB)->willReturn(true);
		// somebody else's post is not theirs to re-address
		$this->activityService->expects($this->never())->method('updateActivity');

		$this->assertTrue($this->service->untag($this->person(self::BOB), 7));
	}

	/** Taking somebody else's name off somebody else's post is not a thing. */
	public function testTakingAnotherPersonsNameOffAPostThatIsNotYoursIsANotFound(): void {
		$this->streamService->method('getStreamByNid')->willReturn($this->post(self::ALICE));

		$this->mediaTagsRequest->expects($this->never())->method('untag');
		$this->expectException(ItemNotFoundException::class);

		$this->service->untag($this->person(self::BOB), 7, self::CAROL);
	}

	/** An author taking a name off re-sends the post without it. */
	public function testTheAuthorTakingANameOffSendsThePostAgain(): void {
		$this->streamService->method('getStreamByNid')->willReturn($this->post(self::ALICE));
		$this->mediaTagsRequest->method('untag')->willReturn(true);
		$this->mediaTagsRequest->method('forStreams')->willReturn([]);

		$this->activityService->expects($this->once())->method('updateActivity');

		$this->service->untag($this->person(self::ALICE), 7, self::BOB);
	}

	/**
	 * Found on devel: the route answered with actors in the ActivityPub
	 * format, which has no `acct`, no `display_name` and no `avatar` — which
	 * is everything a name under a photograph draws.
	 */
	public function testTheNamesComeBackInTheShapeAClientReads(): void {
		$bob = $this->person(self::BOB, 'bob@cloud.example');
		$this->resolving(['bob@cloud.example' => $bob]);
		$this->streamService->method('getStreamByNid')->willReturn($this->post());
		$this->mediaTagsRequest->method('forStreams')->willReturn([]);
		$this->mediaTagsRequest->method('tag')->willReturn(true);

		$named = $this->service->tag($this->person(self::ALICE), 7, ['bob@cloud.example']);

		$this->assertArrayHasKey('acct', $named[0]->exportAsLocal());
		$this->assertArrayNotHasKey('@context', (array)json_decode((string)json_encode($named[0]), true));
	}

	/**
	 * A reader may not see every post the tag table names, and those are left
	 * out of the page — so a page can be short, or empty, while the table
	 * still holds more. The cursor is the last row read, not the last post
	 * returned, and it is there because the page read as many rows as it
	 * asked for.
	 */
	public function testAPageTheReaderMayNotSeeAllOfStillSaysWhereTheNextOneStarts(): void {
		$this->mediaTagsRequest->expects($this->once())->method('streamsFor')
			->with(self::BOB, 3, '90')
			->willReturn([80, 70, 60]);
		$this->streamRequest->method('getStreamByNid')
			->willReturnCallback(function (int|string $nid): Stream {
				if ($nid !== 80) {
					throw new ItemNotFoundException();
				}

				return $this->post();
			});

		$page = $this->service->photosOf($this->person(self::ALICE), self::BOB, 3, '90');

		$this->assertCount(1, $page['posts']);
		$this->assertSame('60', $page['next']);
	}

	public function testAPageThatReadFewerRowsThanItAskedForIsTheLast(): void {
		$this->mediaTagsRequest->method('streamsFor')->willReturn([80]);
		$this->streamRequest->method('getStreamByNid')->willReturn($this->post());

		$page = $this->service->photosOf($this->person(self::ALICE), self::BOB, 20);

		$this->assertCount(1, $page['posts']);
		$this->assertNull($page['next']);
	}

	public function testTheLimitIsClampedToWhatOneReadOfTheTableAnswers(): void {
		$this->mediaTagsRequest->expects($this->once())->method('streamsFor')
			->with(self::BOB, MediaTagsRequest::MAX_PAGE)
			->willReturn(range(100, 100 - MediaTagsRequest::MAX_PAGE + 1));
		$this->streamRequest->method('getStreamByNid')->willReturn($this->post());

		$page = $this->service->photosOf($this->person(self::ALICE), self::BOB, 500);

		$this->assertSame((string)(100 - MediaTagsRequest::MAX_PAGE + 1), $page['next']);
	}
}
