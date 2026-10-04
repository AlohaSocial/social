<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\AP;
use OCA\Social\Db\MediaTagsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\Object\AnnounceInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\StreamAction;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\BoostService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ChannelService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\EmojiService;
use OCA\Social\Service\LinkPreviewService;
use OCA\Social\Service\ModerationService;
use OCA\Social\Service\PlaceService;
use OCA\Social\Service\ReactionSummaryService;
use OCA\Social\Service\SignatureService;
use OCA\Social\Service\StreamActionService;
use OCA\Social\Service\StreamQueueService;
use OCA\Social\Service\StreamService;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * BoostService relies on StreamService::assignItem() to address the Announce,
 * so a real StreamService (over a mocked StreamRequest) is used here.
 */
#[AllowMockObjectsWithoutExpectations]
class BoostServiceTest extends TestCase {
	private const CLOUD_URL = 'https://cloud.example';
	private const ALICE_ID = 'https://social.example/@alice';
	private const ALICE_FOLLOWERS = 'https://social.example/@alice/followers';
	private const GENERATED_ID = 'https://social.example/@alice/1234567890';
	private const BOB_ID = 'https://remote.example/users/bob';
	private const POST_ID = 'https://remote.example/notes/1';
	private const ANNOUNCE_ID = 'https://social.example/@alice/777';
	private const CAROL_ID = 'https://social.example/@carol';
	private const CAROL_ANNOUNCE_ID = 'https://social.example/@carol/778';

	private StreamRequest|MockObject $streamRequest;
	private SignatureService|MockObject $signatureService;
	private ActivityService|MockObject $activityService;
	private StreamActionService|MockObject $streamActionService;
	private StreamQueueService|MockObject $streamQueueService;
	private CacheActorService|MockObject $cacheActorService;
	private AnnounceInterface|MockObject $announceInterface;
	private ModerationService|Stub $moderationService;
	private BoostService $service;

	protected function setUp(): void {
		$this->moderationService = $this->createStub(ModerationService::class);
		$this->announceInterface = $this->createMock(AnnounceInterface::class);
		$this->bootActivityPub([AnnounceInterface::class => $this->announceInterface]);

		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->signatureService = $this->createMock(SignatureService::class);
		$this->activityService = $this->createMock(ActivityService::class);
		$this->streamActionService = $this->createMock(StreamActionService::class);
		$this->streamQueueService = $this->createMock(StreamQueueService::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);

		$configService = $this->createStub(ConfigService::class);
		$configService->method('generateId')->willReturn(self::GENERATED_ID);
		$configService->method('getSocialUrl')->willReturn('https://social.example/');

		$streamService = new StreamService(
			$this->createStub(IURLGenerator::class),
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
			$this->createStub(AccountService::class),
			$this->createStub(ChannelService::class)
		);

		$this->service = new BoostService(
			$this->streamRequest,
			$streamService,
			$this->signatureService,
			$this->activityService,
			$this->streamActionService,
			$this->streamQueueService,
			$this->cacheActorService,
			new NullLogger(),
			$this->moderationService
		);
	}

	protected function tearDown(): void {
		AP::set(null);
	}

	/**
	 * @param array<class-string, object> $interfaces
	 */
	private function bootActivityPub(array $interfaces): void {
		$args = [];
		foreach ((new ReflectionClass(AP::class))->getConstructor()->getParameters() as $parameter) {
			$class = $parameter->getType()->getName();
			if (isset($interfaces[$class])) {
				$args[] = $interfaces[$class];
				continue;
			}
			$mock = $this->createStub($class);
			if ($class === ConfigService::class) {
				$mock->method('getCloudUrl')->willReturn(self::CLOUD_URL);
			}
			$args[] = $mock;
		}
		AP::set(new AP(...$args));
	}

	private function alice(): Person {
		$alice = new Person();
		$alice->setId(self::ALICE_ID);
		$alice->setPreferredUsername('alice');
		$alice->setFollowers(self::ALICE_FOLLOWERS);
		$alice->setLocal(true);

		return $alice;
	}

	private function bob(): Person {
		$bob = new Person();
		$bob->setId(self::BOB_ID);
		$bob->setPreferredUsername('bob');
		$bob->setInbox(self::BOB_ID . '/inbox');

		return $bob;
	}

	private function publicNote(): Note {
		$note = new Note();
		$note->setId(self::POST_ID);
		$note->setAttributedTo(self::BOB_ID);
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$note->addCc(self::BOB_ID . '/followers');

		return $note;
	}

	/** Nobody has boosted the post yet, this account included. */
	private function noBoostStored(): void {
		$this->streamRequest->method('getAnnounceBy')->willThrowException(new StreamNotFoundException());
	}

	private function storedAnnounce(string $attributedTo = self::ALICE_ID): Announce {
		$announce = new Announce();
		$announce->setId(self::ANNOUNCE_ID);
		$announce->setActorId(self::ALICE_ID);
		$announce->setAttributedTo($attributedTo);
		$announce->setObjectId(self::POST_ID);

		return $announce;
	}

	private function boostBy(string $attributedTo, string $id): Announce {
		$announce = new Announce();
		$announce->setId($id);
		$announce->setActorId($attributedTo);
		$announce->setAttributedTo($attributedTo);
		$announce->setObjectId(self::POST_ID);

		return $announce;
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

	// create()

	public function testCreateBuildsPublicAnnounceSavesFlagsAndFederatesIt(): void {
		$alice = $this->alice();
		$this->noBoostStored();
		$this->streamRequest->expects($this->once())
			->method('getStreamById')
			->with(self::POST_ID, true)
			->willReturn($this->publicNote());
		$this->cacheActorService->method('getFromId')->with(self::BOB_ID)->willReturn($this->bob());

		$saved = null;
		$this->announceInterface->expects($this->once())
			->method('save')
			->with($this->callback(function (ACore $item) use (&$saved): bool {
				$saved = $item;

				return true;
			}));
		$this->streamActionService->expects($this->once())
			->method('setActionBool')
			->with(self::ALICE_ID, self::POST_ID, StreamAction::BOOSTED, true);
		$this->signatureService->expects($this->once())
			->method('signObject')
			->with($this->identicalTo($alice), $this->isInstanceOf(Announce::class));
		$this->activityService->expects($this->once())
			->method('request')
			->with($this->isInstanceOf(Announce::class))
			->willReturn('token-boost');
		$this->streamQueueService->expects($this->once())
			->method('cacheStreamByToken')
			->with($this->callback(fn (string $t): bool => $t !== ''));

		$token = '';
		$announce = $this->service->create($alice, self::POST_ID, $token);

		$this->assertSame('token-boost', $token);
		$this->assertSame($saved, $announce);
		$this->assertInstanceOf(Announce::class, $announce);
		$this->assertSame(self::GENERATED_ID, $announce->getId());
		$this->assertTrue($announce->isLocal());
		$this->assertTrue($announce->isFilterDuplicate());
		$this->assertSame($alice, $announce->getActor());
		$this->assertSame(self::POST_ID, $announce->getObjectId());
		$this->assertSame(ACore::CONTEXT_PUBLIC, $announce->getTo());
		$this->assertSame([self::ALICE_FOLLOWERS], $announce->getCcArray());
		$this->assertTrue($announce->isPublic());
		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $announce->getRequestToken());

		$paths = $announce->getInstancePaths();
		$this->assertCount(2, $paths);
		$this->assertHasInstancePath($paths, self::ALICE_ID, InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW);
		$this->assertHasInstancePath($paths, self::BOB_ID . '/inbox', InstancePath::TYPE_INBOX, InstancePath::PRIORITY_LOW);
	}

	public function testCreateStillReachesFollowersWhenAuthorCannotBeResolved(): void {
		$this->noBoostStored();
		$this->streamRequest->method('getStreamById')->willReturn($this->publicNote());
		$this->cacheActorService->method('getFromId')->willThrowException(new CacheActorDoesNotExistException());
		$this->activityService->method('request')->willReturn('token');

		$announce = $this->service->create($this->alice(), self::POST_ID);

		$paths = $announce->getInstancePaths();
		$this->assertCount(1, $paths);
		$this->assertHasInstancePath($paths, self::ALICE_ID, InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW);
	}

	public function testCreateRefusesToBoostSomethingThatIsNotANote(): void {
		$this->streamRequest->method('getStreamById')->willReturn($this->storedAnnounce());
		$this->announceInterface->expects($this->never())->method('save');
		$this->streamActionService->expects($this->never())->method('setActionBool');
		$this->activityService->expects($this->never())->method('request');

		$this->expectException(StreamNotFoundException::class);
		$this->expectExceptionMessage('Stream is not a Note');
		$this->service->create($this->alice(), self::POST_ID);
	}

	/**
	 * @return array<string, array{string, string[]}>
	 */
	public static function nonPublicNoteProvider(): array {
		return [
			'followers-only' => [self::BOB_ID . '/followers', []],
			'direct' => ['', [self::ALICE_ID]],
		];
	}

	#[DataProvider('nonPublicNoteProvider')]
	public function testCreateRefusesToBoostNonPublicNotes(string $to, array $toArray): void {
		$note = new Note();
		$note->setId(self::POST_ID);
		$note->setAttributedTo(self::BOB_ID);
		$note->setTo($to);
		$note->setToArray($toArray);
		$this->streamRequest->method('getStreamById')->willReturn($note);
		$this->announceInterface->expects($this->never())->method('save');
		$this->streamActionService->expects($this->never())->method('setActionBool');

		$this->expectException(StreamNotFoundException::class);
		$this->expectExceptionMessage('Stream is not Public');
		$this->service->create($this->alice(), self::POST_ID);
	}

	public function testCreateFailsForUnknownPost(): void {
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException('Stream not found'));
		$this->announceInterface->expects($this->never())->method('save');
		$this->streamActionService->expects($this->never())->method('setActionBool');

		$this->expectException(StreamNotFoundException::class);
		$this->service->create($this->alice(), 'https://remote.example/notes/missing');
	}

	/** Boosting twice would make two rows of the same post by the same account. */
	public function testCreateRefusesASecondBoostByTheSameAccount(): void {
		$this->streamRequest->method('getStreamById')->willReturn($this->publicNote());
		$this->streamRequest->method('getAnnounceBy')->with(self::POST_ID, self::ALICE_ID)->willReturn($this->storedAnnounce());
		$this->announceInterface->expects($this->never())->method('save');
		$this->streamActionService->expects($this->never())->method('setActionBool');
		$this->activityService->expects($this->never())->method('request');

		$this->expectException(ItemAlreadyExistsException::class);
		$this->service->create($this->alice(), self::POST_ID);
	}

	public function testCreateIsNotStoppedByAnotherAccountsBoostOfTheSamePost(): void {
		$this->streamRequest->method('getStreamById')->willReturn($this->publicNote());
		// carol's row is carol's: the lookup is by booster, so it is not seen here
		$this->streamRequest->method('getStreamByObjectId')->willReturn($this->boostBy(self::CAROL_ID, self::CAROL_ANNOUNCE_ID));
		$this->noBoostStored();
		$this->cacheActorService->method('getFromId')->willReturn($this->bob());
		$this->announceInterface->expects($this->once())->method('save');
		$this->activityService->method('request')->willReturn('token');

		$this->assertInstanceOf(Announce::class, $this->service->create($this->alice(), self::POST_ID));
	}

	public function testCreateDoesNotFlagOrFederateWhenTheBoostAlreadyExists(): void {
		$this->noBoostStored();
		$this->streamRequest->method('getStreamById')->willReturn($this->publicNote());
		$this->cacheActorService->method('getFromId')->willReturn($this->bob());
		$this->announceInterface->method('save')->willThrowException(new ItemAlreadyExistsException());
		$this->streamActionService->expects($this->never())->method('setActionBool');
		$this->activityService->expects($this->never())->method('request');
		$this->streamQueueService->expects($this->never())->method('cacheStreamByToken');

		$this->expectException(ItemAlreadyExistsException::class);
		$this->service->create($this->alice(), self::POST_ID);
	}

	// get()

	public function testGetLooksUpAnnounceByBoostedObject(): void {
		$announce = $this->storedAnnounce();
		$this->streamRequest->expects($this->once())
			->method('getStreamByObjectId')
			->with(self::POST_ID, Announce::TYPE)
			->willReturn($announce);

		$this->assertSame($announce, $this->service->get(self::POST_ID));
	}

	public function testGetPropagatesMissingBoost(): void {
		$this->streamRequest->method('getStreamByObjectId')->willThrowException(new StreamNotFoundException());

		$this->expectException(StreamNotFoundException::class);
		$this->service->get(self::POST_ID);
	}

	// delete()

	public function testTheUndoEmbedsTheAnnounceItTakesBack(): void {
		// PeerTube dispatches on object.type; a bare id is "unknown activity
		// object type" there and the share is never removed
		$alice = $this->alice();
		$this->streamRequest->method('getStreamById')->with(self::POST_ID, true)->willReturn($this->publicNote());
		$this->streamRequest->method('getAnnounceBy')->willReturn($this->storedAnnounce());
		$this->cacheActorService->method('getFromId')->willReturn($this->bob());

		$undo = $this->service->delete($alice, self::POST_ID);

		$object = json_decode((string)json_encode($undo), true)['object'] ?? null;
		$this->assertIsArray($object);
		$this->assertSame('Announce', $object['type']);
		$this->assertSame(self::ANNOUNCE_ID, $object['id']);
		$this->assertSame(self::ALICE_ID, $object['actor']);
		$this->assertSame(self::POST_ID, $object['object']);
		$this->assertArrayNotHasKey('source', $object);
	}

	public function testDeleteSendsUndoRemovesAnnounceAndClearsFlag(): void {
		$alice = $this->alice();
		$announce = $this->storedAnnounce();
		$this->streamRequest->method('getStreamById')->with(self::POST_ID, true)->willReturn($this->publicNote());
		$this->streamRequest->method('getAnnounceBy')->with(self::POST_ID, self::ALICE_ID)->willReturn($announce);
		$this->cacheActorService->method('getFromId')->willReturn($this->bob());

		$this->announceInterface->expects($this->once())->method('delete')->with($this->identicalTo($announce));
		$this->streamRequest->expects($this->once())->method('deleteById')->with(self::ANNOUNCE_ID, Announce::TYPE);
		$this->signatureService->expects($this->once())
			->method('signObject')
			->with($this->identicalTo($alice), $this->isInstanceOf(Undo::class));
		$this->activityService->expects($this->once())
			->method('request')
			->with($this->isInstanceOf(Undo::class))
			->willReturn('token-undo');
		$this->streamActionService->expects($this->once())
			->method('setActionBool')
			->with(self::ALICE_ID, self::POST_ID, StreamAction::BOOSTED, false);

		$token = '';
		$undo = $this->service->delete($alice, self::POST_ID, $token);

		$this->assertSame('token-undo', $token);
		$this->assertInstanceOf(Undo::class, $undo);
		$this->assertSame(self::GENERATED_ID, $undo->getId());
		$this->assertTrue($undo->isLocal());
		$this->assertSame($alice, $undo->getActor());
		$this->assertSame($alice, $announce->getActor());
		$this->assertSame(self::ANNOUNCE_ID, $undo->getObjectId());
		$this->assertSame(ACore::CONTEXT_PUBLIC, $undo->getTo());
		$this->assertSame([self::ALICE_FOLLOWERS], $undo->getCcArray());

		$paths = $undo->getInstancePaths();
		$this->assertCount(2, $paths);
		$this->assertHasInstancePath($paths, self::ALICE_ID, InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW);
		$this->assertHasInstancePath($paths, self::BOB_ID . '/inbox', InstancePath::TYPE_INBOX, InstancePath::PRIORITY_LOW);
	}

	public function testDeleteStillClearsFlagWhenNoBoostIsStored(): void {
		$this->streamRequest->method('getStreamById')->willReturn($this->publicNote());
		$this->noBoostStored();
		$this->cacheActorService->method('getFromId')->willReturn($this->bob());
		$this->announceInterface->expects($this->never())->method('delete');
		$this->streamRequest->expects($this->never())->method('deleteById');
		$this->signatureService->expects($this->never())->method('signObject');
		$this->activityService->expects($this->never())->method('request');
		$this->streamActionService->expects($this->once())
			->method('setActionBool')
			->with(self::ALICE_ID, self::POST_ID, StreamAction::BOOSTED, false);

		$token = 'untouched';
		$undo = $this->service->delete($this->alice(), self::POST_ID, $token);

		$this->assertSame('untouched', $token);
		$this->assertInstanceOf(Undo::class, $undo);
		$this->assertSame('', $undo->getObjectId());
	}

	/**
	 * With two local accounts boosting one post, a lookup by object and type
	 * answers with whichever row came first: un-boosting deleted the other
	 * account's Announce and federated an Undo naming it, signed by somebody
	 * who never made it. The lookup is by booster, so carol's row is never
	 * the one found.
	 */
	public function testAnUnBoostNeverTouchesAnotherAccountsBoost(): void {
		$this->streamRequest->method('getStreamById')->willReturn($this->publicNote());
		$this->streamRequest->method('getStreamByObjectId')
			->willReturn($this->boostBy(self::CAROL_ID, self::CAROL_ANNOUNCE_ID));
		$this->noBoostStored();
		$this->cacheActorService->method('getFromId')->willReturn($this->bob());
		$this->announceInterface->expects($this->never())->method('delete');
		$this->streamRequest->expects($this->never())->method('deleteById');
		$this->activityService->expects($this->never())->method('request');
		$this->streamActionService->expects($this->once())
			->method('setActionBool')
			->with(self::ALICE_ID, self::POST_ID, StreamAction::BOOSTED, false);

		$undo = $this->service->delete($this->alice(), self::POST_ID);

		$this->assertSame('', $undo->getObjectId());
	}

	public function testAnUnBoostUndoesThisAccountsOwnBoostAmongSeveral(): void {
		$mine = $this->storedAnnounce();
		$this->streamRequest->method('getStreamById')->willReturn($this->publicNote());
		$this->streamRequest->method('getStreamByObjectId')
			->willReturn($this->boostBy(self::CAROL_ID, self::CAROL_ANNOUNCE_ID));
		$this->streamRequest->method('getAnnounceBy')->with(self::POST_ID, self::ALICE_ID)->willReturn($mine);
		$this->streamRequest->expects($this->never())->method('getAnnouncesAndRepliesTo');
		$this->cacheActorService->method('getFromId')->willReturn($this->bob());
		$this->announceInterface->expects($this->once())->method('delete')->with($this->identicalTo($mine));
		$this->streamRequest->expects($this->once())->method('deleteById')->with(self::ANNOUNCE_ID, Announce::TYPE);
		$this->activityService->method('request')->willReturn('token-undo');

		$undo = $this->service->delete($this->alice(), self::POST_ID);

		$this->assertSame(self::ANNOUNCE_ID, $undo->getObjectId());
	}

	public function testDeleteRefusesSomethingThatIsNotANote(): void {
		$this->streamRequest->method('getStreamById')->willReturn($this->storedAnnounce());
		$this->streamRequest->expects($this->never())->method('getStreamByObjectId');
		$this->streamActionService->expects($this->never())->method('setActionBool');

		$this->expectException(StreamNotFoundException::class);
		$this->expectExceptionMessage('Stream is not a Note');
		$this->service->delete($this->alice(), self::POST_ID);
	}

	public function testDeleteFailsForUnknownPost(): void {
		$this->streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$this->streamRequest->expects($this->never())->method('getStreamByObjectId');
		$this->streamActionService->expects($this->never())->method('setActionBool');

		$this->expectException(StreamNotFoundException::class);
		$this->service->delete($this->alice(), 'https://remote.example/notes/missing');
	}

	/**
	 * A suspended account may not act. The guard is on the service, so it holds
	 * for every entry point rather than for whichever controller was checked.
	 */
	public function testASuspendedAccountCannotBoost(): void {
		$this->moderationService->method('assertNotSuspended')
			->willThrowException(new InvalidActionException('account is suspended'));
		$this->streamRequest->expects($this->never())->method('save');

		$this->expectException(InvalidActionException::class);
		$this->service->create($this->alice(), self::POST_ID);
	}
}
