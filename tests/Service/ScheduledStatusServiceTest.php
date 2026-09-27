<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\ScheduledStatusesRequest;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\ScheduledStatus;
use OCA\Social\Model\Client\Status;
use OCA\Social\Model\Post;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\InstanceService;
use OCA\Social\Service\PostService;
use OCA\Social\Service\ScheduledStatusService;
use OCA\Social\Service\StatusAssemblyService;
use OCA\Social\Service\StreamService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A post that was scheduled must not be published now, must be published then,
 * and must be published the way an immediate post is.
 */
#[AllowMockObjectsWithoutExpectations]
class ScheduledStatusServiceTest extends TestCase {
	/** a Friday, 14:00 in whatever zone the test process runs in */
	private const NOW = 1757937600;
	private const ALICE = 'https://social.example/@alice';
	private const BOB = 'https://social.example/@bob';

	private ScheduledStatusesRequest|MockObject $scheduledRequest;
	private AccountService|Stub $accountService;
	private DocumentService|Stub $documentService;
	private StreamService|MockObject $streamService;
	private PostService|Stub $postService;
	private CacheDocumentsRequest|Stub $cacheDocumentsRequest;
	private ScheduledStatusService $service;

	/** the row the service handed to save(), as save() saw it */
	private ?ScheduledStatus $saved = null;
	/** @var Post[] the posts createPost() was handed */
	private array $posts = [];

	protected function setUp(): void {
		$this->scheduledRequest = $this->createMock(ScheduledStatusesRequest::class);
		$this->accountService = $this->createStub(AccountService::class);
		$this->documentService = $this->createStub(DocumentService::class);
		$this->streamService = $this->createMock(StreamService::class);
		$this->postService = $this->createStub(PostService::class);
		$this->cacheDocumentsRequest = $this->createStub(CacheDocumentsRequest::class);

		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		$this->accountService->method('getDefaultPrivacy')->willReturn(Stream::TYPE_PUBLIC);

		// the real assembler over the same mocks: what a waiting request turns
		// back into is half of what this service does, and a mock of it would
		// assert that the service called something rather than that the post
		// came out right
		$assembly = new StatusAssemblyService(
			$this->documentService,
			$this->streamService,
			$this->cacheDocumentsRequest,
			$this->createStub(IURLGenerator::class),
			new NullLogger()
		);

		$this->service = new ScheduledStatusService(
			$this->scheduledRequest,
			$this->accountService,
			$this->documentService,
			$this->postService,
			$assembly,
			$this->createStub(IURLGenerator::class),
			$time,
			new NullLogger()
		);
	}

	private function alice(): Person {
		$actor = new Person();
		$actor->setId(self::ALICE);
		$actor->setUserId('alice');
		$actor->setPreferredUsername('alice');

		return $actor;
	}

	/** @param array<string, mixed> $data */
	private function aStatus(array $data): Status {
		return (new Status())->import($data);
	}

	/** An ISO 8601 instant, the way a Mastodon client sends one. */
	private function iso(int $timestamp): string {
		return gmdate('Y-m-d\TH:i:s', $timestamp) . '.000Z';
	}

	/**
	 * Records the row the service stores, and stamps it with an id the way the
	 * real `save()` does — the id is the one the API hands the client.
	 */
	private function captureSave(): void {
		$this->scheduledRequest->method('save')
			->willReturnCallback(function (ScheduledStatus $scheduled): int {
				$this->saved = $scheduled;
				$scheduled->setId(42);

				return 42;
			});
	}

	// ---------------------------------------------------------------- parsing

	public function testARequestWithoutScheduledAtIsNotAScheduledPost(): void {
		$this->assertSame(0, $this->service->requestedTime(['status' => 'hi']));
		$this->assertSame(0, $this->service->requestedTime(['scheduled_at' => null]));
		$this->assertSame(0, $this->service->requestedTime(['scheduled_at' => '  ']));
	}

	public function testScheduledAtIsReadAsTheIso8601AClientSends(): void {
		$when = self::NOW + 86400;

		$this->assertSame($when, $this->service->requestedTime(['scheduled_at' => $this->iso($when)]));
	}

	/**
	 * Silently treating an unparseable date as "post now" is the defect this
	 * whole feature exists to fix, one layer down.
	 */
	public function testAScheduledAtThatIsNotADateIsRefusedRatherThanPostedNow(): void {
		$this->expectException(InvalidActionException::class);

		$this->service->requestedTime(['scheduled_at' => 'next tuesday-ish']);
	}

	public function testAScheduledAtThatIsNotEvenScalarIsRefused(): void {
		$this->expectException(InvalidActionException::class);

		$this->service->requestedTime(['scheduled_at' => ['2030-01-01']]);
	}

	// ------------------------------------------------------------ the 5-minute rule

	public function testAPostDueSoonerThanFiveMinutesIsRefused(): void {
		$this->scheduledRequest->expects($this->never())->method('save');
		$this->expectException(InvalidActionException::class);
		$this->expectExceptionMessage('at least 5 minutes in the future');

		$this->service->schedule(
			$this->alice(),
			$this->aStatus(['status' => 'hi']),
			['scheduled_at' => $this->iso(self::NOW + ScheduledStatusService::MIN_LEAD_TIME - 1)]
		);
	}

	public function testAPostDueInExactlyFiveMinutesIsAccepted(): void {
		$this->captureSave();
		$when = self::NOW + ScheduledStatusService::MIN_LEAD_TIME;

		$scheduled = $this->service->schedule(
			$this->alice(), $this->aStatus(['status' => 'hi']), ['scheduled_at' => $this->iso($when)]
		);

		$this->assertSame($when, $scheduled->getScheduledAt());
		$this->assertSame(42, $scheduled->getId());
		$this->assertSame($when, $this->saved->getScheduledAt());
	}

	public function testAPostDueInThePastIsRefused(): void {
		$this->expectException(InvalidActionException::class);

		$this->service->schedule(
			$this->alice(), $this->aStatus(['status' => 'hi']),
			['scheduled_at' => $this->iso(self::NOW - 86400)]
		);
	}

	// ------------------------------------------------------------------- caps

	public function testAnAccountAtTheTotalCapCannotScheduleAnother(): void {
		$this->scheduledRequest->method('countByActor')
			->willReturn(ScheduledStatusService::MAX_PENDING);
		$this->scheduledRequest->expects($this->never())->method('save');
		$this->expectException(InvalidActionException::class);
		$this->expectExceptionMessage('300 scheduled statuses');

		$this->service->schedule(
			$this->alice(), $this->aStatus(['status' => 'hi']),
			['scheduled_at' => $this->iso(self::NOW + 86400)]
		);
	}

	public function testAnAccountOneBelowTheTotalCapStillCanScheduleAnother(): void {
		$this->scheduledRequest->method('countByActor')
			->willReturn(ScheduledStatusService::MAX_PENDING - 1);
		$this->captureSave();

		$scheduled = $this->service->schedule(
			$this->alice(), $this->aStatus(['status' => 'hi']),
			['scheduled_at' => $this->iso(self::NOW + 86400)]
		);

		$this->assertSame(42, $scheduled->getId());
	}

	public function testAnAccountAtTheDailyCapCannotScheduleAnotherForThatDay(): void {
		$this->scheduledRequest->method('countByActorBetween')
			->willReturn(ScheduledStatusService::MAX_PENDING_PER_DAY);
		$this->scheduledRequest->expects($this->never())->method('save');
		$this->expectException(InvalidActionException::class);
		$this->expectExceptionMessage('25 statuses scheduled for that day');

		$this->service->schedule(
			$this->alice(), $this->aStatus(['status' => 'hi']),
			['scheduled_at' => $this->iso(self::NOW + 86400)]
		);
	}

	/**
	 * The cap is per day, so the window it counts over has to be the day the
	 * post is being scheduled *for* — counting the whole table, or today,
	 * refuses posts that are nowhere near a full day.
	 */
	public function testTheDailyCapCountsTheDayThePostIsScheduledFor(): void {
		$when = self::NOW + 3 * 86400;
		$window = [];
		$this->scheduledRequest->method('countByActorBetween')
			->willReturnCallback(function (string $actorId, int $from, int $until) use (&$window): int {
				$window = [$actorId, $from, $until];

				return 0;
			});
		$this->captureSave();

		$this->service->schedule(
			$this->alice(), $this->aStatus(['status' => 'hi']), ['scheduled_at' => $this->iso($when)]
		);

		$this->assertSame(self::ALICE, $window[0]);
		$this->assertSame((int)strtotime('today', $when), $window[1]);
		$this->assertSame((int)strtotime('tomorrow', $when), $window[2]);
		$this->assertLessThanOrEqual($when, $window[1]);
		$this->assertGreaterThan($when, $window[2]);
		$this->assertSame(86400, $window[2] - $window[1]);
	}

	// ------------------------------------------------------- what gets stored

	public function testTheStoredParamsAreTheRequestMastodonEchoesBack(): void {
		$this->captureSave();
		$when = self::NOW + 86400;

		$this->service->schedule(
			$this->alice(),
			$this->aStatus([
				'status' => 'hello #fediverse',
				'visibility' => 'unlisted',
				'spoiler_text' => 'cw',
				'sensitive' => true,
				'media_ids' => ['7', '8'],
				'in_reply_to_id' => '99',
				'quote_id' => '5',
				'language' => 'de',
			]),
			['scheduled_at' => $this->iso($when)]
		);

		$params = $this->saved->getParams();
		// the body is `status` on the way in and `text` in a ScheduledStatus
		$this->assertSame('hello #fediverse', $params['text']);
		$this->assertSame(['7', '8'], $params['media_ids']);
		$this->assertSame('unlisted', $params['visibility']);
		$this->assertSame('cw', $params['spoiler_text']);
		$this->assertTrue($params['sensitive']);
		$this->assertSame('99', $params['in_reply_to_id']);
		$this->assertSame('5', $params['quoted_status_id']);
		$this->assertSame('de', $params['language']);
		$this->assertNull($params['poll']);
	}

	/**
	 * The account's default is read now, so that changing it later cannot
	 * widen — or narrow — the audience of a post that is already waiting.
	 */
	public function testAnAbsentVisibilityIsResolvedToTheAccountDefaultAndStored(): void {
		$this->captureSave();

		$this->service->schedule(
			$this->alice(), $this->aStatus(['status' => 'hi']),
			['scheduled_at' => $this->iso(self::NOW + 86400)]
		);

		$this->assertSame(Stream::TYPE_PUBLIC, $this->saved->getParams()['visibility']);
	}

	public function testAVisibilityThisAppDoesNotPostWithIsRefused(): void {
		$this->scheduledRequest->expects($this->never())->method('save');
		$this->expectException(InvalidActionException::class);

		$this->service->schedule(
			$this->alice(), $this->aStatus(['status' => 'hi', 'visibility' => 'secret']),
			['scheduled_at' => $this->iso(self::NOW + 86400)]
		);
	}

	/**
	 * `params.scheduled_at` would be the stale half of two copies of the same
	 * fact the moment the post is moved.
	 */
	public function testTheStoredParamsDoNotCarryASecondCopyOfTheTime(): void {
		$this->captureSave();

		$this->service->schedule(
			$this->alice(), $this->aStatus(['status' => 'hi']),
			['scheduled_at' => $this->iso(self::NOW + 86400)]
		);

		$this->assertArrayHasKey('scheduled_at', $this->saved->getParams());
		$this->assertNull($this->saved->getParams()['scheduled_at']);
	}

	public function testNothingTheClientSmuggledInIsStored(): void {
		$this->captureSave();

		$this->service->schedule(
			$this->alice(), $this->aStatus(['status' => 'hi']),
			['scheduled_at' => $this->iso(self::NOW + 86400), 'payload' => 'anything']
		);

		$this->assertArrayNotHasKey('payload', $this->saved->getParams());
	}

	// -------------------------------- refusals brought forward from publishing

	public function testAPostTooLongToEverBePublishedIsRefusedNow(): void {
		$this->scheduledRequest->expects($this->never())->method('save');
		$this->expectException(InvalidActionException::class);
		$this->expectExceptionMessage('may not be longer than');

		$this->service->schedule(
			$this->alice(),
			$this->aStatus(['status' => str_repeat('a', InstanceService::MAX_CHARACTERS + 1)]),
			['scheduled_at' => $this->iso(self::NOW + 86400)]
		);
	}

	public function testTheSpoilerCountsTowardsTheSameLengthBudget(): void {
		$this->expectException(InvalidActionException::class);

		$this->service->schedule(
			$this->alice(),
			$this->aStatus([
				'status' => str_repeat('a', InstanceService::MAX_CHARACTERS),
				'spoiler_text' => 'x',
			]),
			['scheduled_at' => $this->iso(self::NOW + 86400)]
		);
	}

	/**
	 * `createPost()` raises an InvalidArgumentException for this, which the API
	 * answers 500 to and the cron answers nothing to at all: from a scheduled
	 * post it would be a silent drop weeks after the author was told yes.
	 */
	public function testAPollWithTooFewUsableOptionsIsRefusedNow(): void {
		$this->scheduledRequest->expects($this->never())->method('save');
		$this->expectException(InvalidActionException::class);
		$this->expectExceptionMessage('at least two options');

		$this->service->schedule(
			$this->alice(),
			$this->aStatus(['status' => 'pick one', 'poll' => ['options' => ['yes', '  ']]]),
			['scheduled_at' => $this->iso(self::NOW + 86400)]
		);
	}

	public function testAUsablePollIsScheduled(): void {
		$this->captureSave();

		$this->service->schedule(
			$this->alice(),
			$this->aStatus(['status' => 'pick one', 'poll' => ['options' => ['yes', 'no']]]),
			['scheduled_at' => $this->iso(self::NOW + 86400)]
		);

		$this->assertSame(['yes', 'no'], $this->saved->getParams()['poll']['options']);
	}

	// --------------------------------------------------------------- read/edit

	public function testEveryReadIsScopedToTheAccountThatAsked(): void {
		$asked = [];
		$this->scheduledRequest->method('getById')
			->willReturnCallback(function (int $id, string $actorId) use (&$asked): ScheduledStatus {
				$asked[] = [$id, $actorId];

				return (new ScheduledStatus())->setId($id);
			});
		$this->scheduledRequest->method('getByActor')
			->willReturnCallback(function (string $actorId) use (&$asked): array {
				$asked[] = ['all', $actorId];

				return [];
			});

		$this->service->getOne($this->alice(), 7);
		$this->service->getAll($this->alice());

		$this->assertSame([[7, self::ALICE], ['all', self::ALICE]], $asked);
	}

	public function testDeletingSomethingThatIsNotYoursIsANotFound(): void {
		$this->scheduledRequest->method('delete')->willReturn(false);
		$this->expectException(ItemNotFoundException::class);

		$this->service->delete($this->alice(), 7);
	}

	public function testDeletingIsScopedToTheAccountThatAsked(): void {
		$asked = [];
		$this->scheduledRequest->method('delete')
			->willReturnCallback(function (int $id, string $actorId) use (&$asked): bool {
				$asked = [$id, $actorId];

				return true;
			});

		$this->service->delete($this->alice(), 7);

		$this->assertSame([7, self::ALICE], $asked);
	}

	public function testReschedulingSomethingThatIsNotYoursChangesNothing(): void {
		$this->scheduledRequest->method('getById')
			->willThrowException(new ItemNotFoundException('Record not found'));
		$this->scheduledRequest->expects($this->never())->method('reschedule');
		$this->expectException(ItemNotFoundException::class);

		$this->service->reschedule(
			$this->alice(), 7, ['scheduled_at' => $this->iso(self::NOW + 86400)]
		);
	}

	public function testReschedulingObeysTheFiveMinuteRuleToo(): void {
		$this->scheduledRequest->expects($this->never())->method('reschedule');
		$this->expectException(InvalidActionException::class);

		$this->service->reschedule(
			$this->alice(), 7, ['scheduled_at' => $this->iso(self::NOW + 60)]
		);
	}

	public function testReschedulingWithoutATimeIsRefused(): void {
		$this->scheduledRequest->expects($this->never())->method('reschedule');
		$this->expectException(InvalidActionException::class);

		$this->service->reschedule($this->alice(), 7, []);
	}

	public function testMovingAPostWithinItsOwnDayIsNotCountedAgainstThatDay(): void {
		$when = self::NOW + 86400;
		$this->scheduledRequest->method('getById')
			->willReturn((new ScheduledStatus())->setId(7)->setScheduledAt($when));
		// an account at the cap would otherwise be unable to move any of that
		// day's posts by an hour, because the post counts against itself
		$this->scheduledRequest->method('countByActorBetween')
			->willReturn(ScheduledStatusService::MAX_PENDING_PER_DAY);
		$moved = [];
		$this->scheduledRequest->method('reschedule')
			->willReturnCallback(function (int $id, string $actorId, int $at) use (&$moved): void {
				$moved = [$id, $actorId, $at];
			});

		$scheduled = $this->service->reschedule(
			$this->alice(), 7, ['scheduled_at' => $this->iso($when + 3600)]
		);

		$this->assertSame([7, self::ALICE, $when + 3600], $moved);
		$this->assertSame($when + 3600, $scheduled->getScheduledAt());
	}

	public function testMovingAPostIntoAFullDayIsRefused(): void {
		$this->scheduledRequest->method('getById')
			->willReturn((new ScheduledStatus())->setId(7)->setScheduledAt(self::NOW + 86400));
		$this->scheduledRequest->method('countByActorBetween')
			->willReturn(ScheduledStatusService::MAX_PENDING_PER_DAY);
		$this->scheduledRequest->expects($this->never())->method('reschedule');
		$this->expectException(InvalidActionException::class);

		$this->service->reschedule(
			$this->alice(), 7, ['scheduled_at' => $this->iso(self::NOW + 5 * 86400)]
		);
	}

	// ----------------------------------------------------------- publishing

	/** @param array<string, mixed> $params */
	private function waiting(int $id, array $params, string $actorId = self::ALICE): ScheduledStatus {
		return (new ScheduledStatus())->setId($id)->setActorId($actorId)->setParams($params);
	}

	private function expectActorLookup(): void {
		$this->accountService->method('getFromId')
			->willReturnCallback(function (string $id): Person {
				$actor = new Person();
				$actor->setId($id);
				$actor->setPreferredUsername(($id === self::BOB) ? 'bob' : 'alice');

				return $actor;
			});
	}

	private function capturePosts(): void {
		$this->postService->method('createPost')
			->willReturnCallback(function (Post $post): ?ACore {
				$this->posts[] = $post;

				return null;
			});
	}

	public function testOnlyWhatIsDueIsPublished(): void {
		$asked = [];
		$this->scheduledRequest->method('getDue')
			->willReturnCallback(function (int $now, int $limit) use (&$asked): array {
				$asked = [$now, $limit];

				return [];
			});

		$this->assertSame(0, $this->service->publishDue());
		$this->assertSame([self::NOW, ScheduledStatusService::PUBLISH_PER_RUN], $asked);
	}

	public function testADuePostIsPublishedThroughTheSamePathAnImmediatePostTakes(): void {
		$this->expectActorLookup();
		$this->capturePosts();
		$this->scheduledRequest->method('getDue')->willReturn([
			$this->waiting(1, [
				'text' => 'later, world',
				'visibility' => 'unlisted',
				'spoiler_text' => 'cw',
				'sensitive' => true,
				'language' => 'de',
				'quoted_status_id' => '5',
			]),
		]);
		$this->scheduledRequest->method('claim')->willReturn(true);

		$this->assertSame(1, $this->service->publishDue());

		$this->assertCount(1, $this->posts);
		$this->assertSame('later, world', $this->posts[0]->getContent());
		$this->assertSame(self::ALICE, $this->posts[0]->getActor()->getId());
		$this->assertSame(Stream::TYPE_UNLISTED, $this->posts[0]->getType());
		$this->assertSame('cw', $this->posts[0]->getSpoilerText());
		$this->assertTrue($this->posts[0]->isSensitive());
		$this->assertSame('de', $this->posts[0]->getLanguage());
		$this->assertSame('5', $this->posts[0]->getQuotedId());
	}

	public function testAPostIsClaimedBeforeItIsPublished(): void {
		$this->expectActorLookup();
		$order = [];
		$this->scheduledRequest->method('getDue')->willReturn([$this->waiting(1, ['text' => 'x'])]);
		$this->scheduledRequest->method('claim')
			->willReturnCallback(function (int $id) use (&$order): bool {
				$order[] = 'claim:' . $id;

				return true;
			});
		$this->postService->method('createPost')
			->willReturnCallback(function (Post $post) use (&$order): ?ACore {
				$order[] = 'post';

				return null;
			});

		$this->service->publishDue();

		$this->assertSame(['claim:1', 'post'], $order);
	}

	/**
	 * Two cron workers can read the same due row. Only the one whose DELETE
	 * affected a row may publish it, or the post is federated twice — and a
	 * duplicate post cannot be taken back.
	 */
	public function testARowAnotherWorkerClaimedIsNotPublishedAgain(): void {
		$this->expectActorLookup();
		$this->capturePosts();
		$this->scheduledRequest->method('getDue')->willReturn([
			$this->waiting(1, ['text' => 'mine']),
			$this->waiting(2, ['text' => 'theirs']),
		]);
		$this->scheduledRequest->method('claim')
			->willReturnCallback(static fn (int $id): bool => $id === 1);

		$this->assertSame(1, $this->service->publishDue());

		$this->assertSame(['mine'], array_map(static fn (Post $p): string => $p->getContent(), $this->posts));
	}

	public function testOnePostThatCannotBePublishedCostsOnlyThatPost(): void {
		$this->expectActorLookup();
		$this->scheduledRequest->method('getDue')->willReturn([
			$this->waiting(1, ['text' => 'boom']),
			$this->waiting(2, ['text' => 'fine']),
		]);
		$this->scheduledRequest->method('claim')->willReturn(true);
		$published = [];
		$this->postService->method('createPost')
			->willReturnCallback(function (Post $post) use (&$published): ?ACore {
				if ($post->getContent() === 'boom') {
					throw new \RuntimeException('the account is suspended');
				}
				$published[] = $post->getContent();

				return null;
			});

		$this->assertSame(1, $this->service->publishDue());

		$this->assertSame(['fine'], $published);
	}

	public function testAttachmentsAreCarriedOntoThePublishedPostInTheFederationFormat(): void {
		$this->expectActorLookup();
		$this->capturePosts();
		$document = (new Document())->setMediaType('image/jpeg');
		$document->setNid(7);
		$asked = [];
		$this->documentService->method('getMediaFromArray')
			->willReturnCallback(function (array $ids, string $account) use (&$asked, $document): array {
				$asked = [$ids, $account];

				return [$document];
			});
		$this->scheduledRequest->method('getDue')->willReturn([
			$this->waiting(1, ['text' => 'look', 'media_ids' => ['7'], 'visibility' => 'public']),
		]);
		$this->scheduledRequest->method('claim')->willReturn(true);

		$this->service->publishDue();

		$this->assertSame([[7], 'alice'], $asked);
		$this->assertCount(1, $this->posts[0]->getMedias());
		$this->assertSame(ACore::FORMAT_ACTIVITYPUB, $this->posts[0]->getMedias()[0]->getExportFormat());
	}

	/**
	 * Whether an attachment may be kept by a shared proxy is decided by the
	 * visibility of the post it ends up on, which is only known here.
	 */
	public function testAttachmentsOfAPublicPostAreMarkedPublicWhenItGoesOut(): void {
		$this->expectActorLookup();
		$this->capturePosts();
		$document = (new Document())->setMediaType('image/jpeg');
		$document->setNid(7);
		$this->documentService->method('getMediaFromArray')->willReturn([$document]);
		$updated = [];
		$this->cacheDocumentsRequest->method('update')
			->willReturnCallback(function (Document $document) use (&$updated): void {
				$updated[] = $document->isPublic();
			});
		$this->scheduledRequest->method('getDue')->willReturn([
			$this->waiting(1, ['text' => 'look', 'media_ids' => ['7'], 'visibility' => 'public']),
		]);
		$this->scheduledRequest->method('claim')->willReturn(true);

		$this->service->publishDue();

		$this->assertSame([true], $updated);
	}

	public function testAReplyIsPublishedAgainstThePostItAnswers(): void {
		$this->expectActorLookup();
		$this->capturePosts();
		$note = new Note();
		$note->setId('https://social.example/@bob/1');
		$this->streamService->method('getStreamByNid')->with(99)->willReturn($note);
		$this->scheduledRequest->method('getDue')->willReturn([
			$this->waiting(1, ['text' => 'agreed', 'in_reply_to_id' => '99']),
		]);
		$this->scheduledRequest->method('claim')->willReturn(true);

		$this->service->publishDue();

		$this->assertSame('https://social.example/@bob/1', $this->posts[0]->getReplyTo());
	}

	/**
	 * The post being answered is likelier to be gone here than on an immediate
	 * reply — it had until the scheduled time to be deleted. The reply still
	 * goes out rather than being lost with it.
	 */
	public function testAReplyToAPostThatWasDeletedMeanwhileIsStillPublished(): void {
		$this->expectActorLookup();
		$this->capturePosts();
		$this->streamService->method('getStreamByNid')
			->willThrowException(new StreamNotFoundException('gone'));
		$this->scheduledRequest->method('getDue')->willReturn([
			$this->waiting(1, ['text' => 'agreed', 'in_reply_to_id' => '99']),
		]);
		$this->scheduledRequest->method('claim')->willReturn(true);

		$this->assertSame(1, $this->service->publishDue());

		$this->assertSame('', $this->posts[0]->getReplyTo());
		$this->assertSame('agreed', $this->posts[0]->getContent());
	}

	public function testEachPostIsPublishedAsItsOwnAuthor(): void {
		$this->expectActorLookup();
		$this->capturePosts();
		$this->scheduledRequest->method('getDue')->willReturn([
			$this->waiting(1, ['text' => 'a'], self::ALICE),
			$this->waiting(2, ['text' => 'b'], self::BOB),
		]);
		$this->scheduledRequest->method('claim')->willReturn(true);

		$this->service->publishDue();

		$this->assertSame(
			[self::ALICE, self::BOB],
			array_map(static fn (Post $p): string => $p->getActor()->getId(), $this->posts)
		);
	}
}
