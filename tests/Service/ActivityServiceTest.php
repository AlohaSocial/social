<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\AP;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\HostBreakerRequest;
use OCA\Social\Db\RelayRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\EmptyQueueException;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\NoHighPriorityRequestException;
use OCA\Social\Exceptions\QueueStatusException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Interfaces\Object\AnnounceInterface;
use OCA\Social\Interfaces\Object\NoteInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Activity\Update;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Tombstone;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\RequestQueueService;
use OCA\Social\Service\SignatureService;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

#[AllowMockObjectsWithoutExpectations]
class ActivityServiceTest extends TestCase {
	private const CLOUD_URL = 'https://cloud.example';
	private const CLOUD_HOST = 'cloud.example';
	private const ALICE_ID = 'https://social.example/@alice';
	private const NOTE_ID = 'https://social.example/@alice/42';
	private const BOB_INBOX = 'https://remote.example/users/bob/inbox';
	private const TOKEN = 'a0b1c2d3-e4f5-4678-9abc-def012345678';

	private FollowsRequest|MockObject $followsRequest;
	private CacheActorsRequest|MockObject $cacheActorsRequest;
	private SignatureService|MockObject $signatureService;
	private RequestQueueService|MockObject $requestQueueService;
	private CurlService|MockObject $curlService;
	private ConfigService|Stub $configService;
	private ActorsRequest|MockObject $actorsRequest;
	private RelayRequest|Stub $relayRequest;
	private LoggerInterface|MockObject $logger;
	private NoteInterface|MockObject $noteInterface;
	private AnnounceInterface|MockObject $announceInterface;
	private ActivityService $service;
	private HostBreakerRequest|MockObject $hostBreakerRequest;
	private \OCA\Social\Db\StreamRequest|MockObject $streamRequest;
	/** @var array<string, array{strikes: int, open_until: int, last_failure: int}> */
	private array $breakerRows = [];

	protected function setUp(): void {
		$this->noteInterface = $this->createMock(NoteInterface::class);
		$this->announceInterface = $this->createMock(AnnounceInterface::class);
		$this->bootActivityPub([
			NoteInterface::class => $this->noteInterface,
			AnnounceInterface::class => $this->announceInterface,
		]);

		$this->followsRequest = $this->createMock(FollowsRequest::class);
		$this->cacheActorsRequest = $this->createMock(CacheActorsRequest::class);
		$this->signatureService = $this->createMock(SignatureService::class);
		$this->requestQueueService = $this->createMock(RequestQueueService::class);
		$this->curlService = $this->createMock(CurlService::class);

		$this->configService = $this->createStub(ConfigService::class);
		$this->configService->method('getCloudHost')->willReturn(self::CLOUD_HOST);

		$this->actorsRequest = $this->createMock(ActorsRequest::class);
		$this->relayRequest = $this->createStub(RelayRequest::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->streamRequest = $this->createMock(\OCA\Social\Db\StreamRequest::class);

		// the breaker's table, held in memory: a failure one pass records is
		// what the next pass reads
		$this->breakerRows = [];
		$this->hostBreakerRequest = $this->createMock(HostBreakerRequest::class);
		$this->hostBreakerRequest->method('failingSince')->willReturnCallback(
			fn (int $since): array => array_filter($this->breakerRows, fn (array $row): bool => $row['last_failure'] > $since)
		);
		$this->hostBreakerRequest->method('open')->willReturnCallback(
			function (string $host, int $strikes, int $openUntil, int $now): void {
				$this->breakerRows[$host] = ['strikes' => $strikes, 'open_until' => $openUntil, 'last_failure' => $now];
			}
		);
		$this->hostBreakerRequest->method('close')->willReturnCallback(function (string $host): void {
			unset($this->breakerRows[$host]);
		});

		$this->service = new ActivityService(
			$this->followsRequest,
			$this->cacheActorsRequest,
			$this->signatureService,
			$this->requestQueueService,
			$this->curlService,
			$this->configService,
			$this->actorsRequest,
			$this->relayRequest,
			$this->hostBreakerRequest,
			$this->logger,
			$this->streamRequest
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
		$alice->setFollowers(self::ALICE_ID . '/followers');
		$alice->setLocal(true);

		return $alice;
	}

	private function note(): Note {
		$note = new Note();
		$note->setId(self::NOTE_ID);
		$note->setAttributedTo(self::ALICE_ID);
		$note->setContent('hello');
		$note->addInstancePath(new InstancePath(self::BOB_INBOX, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_MEDIUM));

		return $note;
	}

	private function queue(string $inbox = self::BOB_INBOX, int $type = InstancePath::TYPE_INBOX): RequestQueue {
		$queue = new RequestQueue(
			'{"type":"Like","id":"https://social.example/@alice#like/1"}',
			new InstancePath($inbox, $type, InstancePath::PRIORITY_MEDIUM),
			self::ALICE_ID
		);
		$queue->setToken(self::TOKEN);
		$queue->setTimeout(10);

		return $queue;
	}

	/** Queue a token and let the request flow run to the async hand-off without any inline delivery. */
	private function expectQueuedWithoutInlineDelivery(): void {
		$this->requestQueueService->method('generateRequestQueue')->willReturn(self::TOKEN);
		$this->requestQueueService->method('getPriorityRequest')->willThrowException(new NoHighPriorityRequestException());
		$this->requestQueueService->method('getRequestFromToken')->willReturn([]);
	}

	/**
	 * Records the resolved InstancePath[] handed to the request queue.
	 *
	 * @param InstancePath[] $paths
	 */
	private function capturePaths(array &$paths): void {
		$paths = [];
		$this->requestQueueService->expects($this->once())
			->method('generateRequestQueue')
			->with($this->callback(function (array $instancePaths) use (&$paths): bool {
				$paths = $instancePaths;

				return true;
			}), $this->isInstanceOf(ACore::class), $this->isString())
			// the real queue hands back no token when it was given nothing to
			// send, and callers key off that
			->willReturnCallback(fn (array $instancePaths): string => $instancePaths === [] ? '' : self::TOKEN);
		$this->requestQueueService->method('getPriorityRequest')->willThrowException(new NoHighPriorityRequestException());
		$this->requestQueueService->method('getRequestFromToken')->willReturn([]);
	}

	// createActivity()

	public function testCreateActivityWrapsItemSignsSavesAndQueuesIt(): void {
		$alice = $this->alice();
		$note = $this->note();

		$this->signatureService->expects($this->once())
			->method('signObject')
			->with($this->identicalTo($alice), $this->isInstanceOf(Create::class));
		$this->noteInterface->expects($this->once())->method('save')->with($this->identicalTo($note));

		$queued = null;
		$this->requestQueueService->expects($this->once())
			->method('generateRequestQueue')
			->with(
				$this->identicalTo($note->getInstancePaths()),
				$this->callback(function (ACore $item) use (&$queued): bool {
					$queued = $item;

					return true;
				}),
				self::ALICE_ID
			)
			->willReturn(self::TOKEN);
		$this->requestQueueService->method('getPriorityRequest')->willThrowException(new NoHighPriorityRequestException());
		$this->requestQueueService->method('getRequestFromToken')->with(self::TOKEN, RequestQueue::STATUS_STANDBY)->willReturn([]);

		$activity = null;
		$token = $this->service->createActivity($alice, $note, $activity);

		$this->assertSame(self::TOKEN, $token);
		$this->assertInstanceOf(Create::class, $activity);
		$this->assertSame($activity, $queued);
		$this->assertSame(self::NOTE_ID . '/activity', $activity->getId());
		$this->assertSame($note, $activity->getObject());
		$this->assertSame($activity, $note->getParent());
		$this->assertSame($alice, $activity->getActor());
		$this->assertSame(self::ALICE_ID, $activity->getActorId());
		$this->assertSame($note->getInstancePaths(), $activity->getInstancePaths());
		$this->assertTrue($activity->isRoot());
	}

	public function testCreateActivitySavesNestedObjectsInnermostFirst(): void {
		$note = $this->note();
		$announce = new Announce();
		$announce->setId(self::ALICE_ID . '/boost/1');
		$announce->setObject($note);
		$this->expectQueuedWithoutInlineDelivery();

		$order = [];
		$this->noteInterface->expects($this->once())->method('save')->willReturnCallback(function () use (&$order): void {
			$order[] = 'note';
		});
		$this->announceInterface->expects($this->once())->method('save')->willReturnCallback(function () use (&$order): void {
			$order[] = 'announce';
		});

		$this->service->createActivity($this->alice(), $announce);

		$this->assertSame(['note', 'announce'], $order);
	}

	public function testCreateActivityToleratesAnAlreadyStoredObject(): void {
		$this->noteInterface->method('save')->willThrowException(new ItemAlreadyExistsException());
		$this->expectQueuedWithoutInlineDelivery();

		$this->assertSame(self::TOKEN, $this->service->createActivity($this->alice(), $this->note()));
	}

	public function testCreateActivityToleratesObjectsWithoutStorage(): void {
		$stream = new Stream();
		$stream->setId(self::NOTE_ID);
		$stream->setType('Unknown');
		$this->expectQueuedWithoutInlineDelivery();

		$this->assertSame(self::TOKEN, $this->service->createActivity($this->alice(), $stream, $activity));
		$this->assertSame($stream, $activity->getObject());
	}

	// updateActivity()

	public function testUpdateActivityWrapsItemInSignedUpdateWithoutSavingIt(): void {
		$alice = $this->alice();
		$note = $this->note();
		$this->signatureService->expects($this->once())
			->method('signObject')
			->with($this->identicalTo($alice), $this->isInstanceOf(Update::class));
		$this->noteInterface->expects($this->never())->method('save');

		$queued = null;
		$this->requestQueueService->expects($this->once())
			->method('generateRequestQueue')
			->with($this->identicalTo($note->getInstancePaths()), $this->callback(function (ACore $item) use (&$queued): bool {
				$queued = $item;

				return true;
			}), self::ALICE_ID)
			->willReturn(self::TOKEN);
		$this->requestQueueService->method('getPriorityRequest')->willThrowException(new NoHighPriorityRequestException());
		$this->requestQueueService->method('getRequestFromToken')->willReturn([]);

		$this->assertSame(self::TOKEN, $this->service->updateActivity($alice, $note));

		$this->assertInstanceOf(Update::class, $queued);
		$this->assertStringStartsWith(self::NOTE_ID . '#updates/', $queued->getId());
		$this->assertSame($note, $queued->getObject());
		$this->assertSame($queued, $note->getParent());
		$this->assertSame($alice, $queued->getActor());
	}

	/**
	 * An edited post is read back in the client format, whose `id` is the nid.
	 * The Update has to carry the ActivityPub object, or no peer can tell
	 * which post it is about.
	 */
	public function testAnUpdateCarriesTheActivityPubObjectWhateverFormatThePostWasReadIn(): void {
		$note = $this->note();
		$note->setNid('1791109680485581174');
		$note->setUpdated('2026-10-04T10:00:00Z');
		$note->setExportFormat(ACore::FORMAT_LOCAL);

		$body = '';
		$this->requestQueueService->method('generateRequestQueue')
			->willReturnCallback(function (array $paths, ACore $item) use (&$body): string {
				$body = json_encode($item);

				return self::TOKEN;
			});
		$this->requestQueueService->method('getPriorityRequest')->willThrowException(new NoHighPriorityRequestException());
		$this->requestQueueService->method('getRequestFromToken')->willReturn([]);

		$this->service->updateActivity($this->alice(), $note);

		$object = json_decode($body, true)['object'] ?? [];
		$this->assertSame(self::NOTE_ID, $object['id'] ?? null);
		$this->assertSame('Note', $object['type'] ?? null);
		$this->assertSame('2026-10-04T10:00:00Z', $object['updated'] ?? null);
		$this->assertSame(ACore::FORMAT_LOCAL, $note->getExportFormat(), 'the caller keeps its post as it was');
	}

	/**
	 * An actor carries no audience, and an Update that copied none named no
	 * recipient: Loops routes its shared inbox by `to` and `cc` and dropped
	 * every profile change.
	 */
	public function testAnActorUpdateIsAddressedToThePublicAndTheFollowers(): void {
		$alice = $this->alice();
		$update = clone $alice;
		$update->addInstancePath(new InstancePath(self::ALICE_ID, InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW));

		$queued = null;
		$this->requestQueueService->method('generateRequestQueue')
			->willReturnCallback(function (array $paths, ACore $item) use (&$queued): string {
				$queued = $item;

				return self::TOKEN;
			});
		$this->requestQueueService->method('getPriorityRequest')->willThrowException(new NoHighPriorityRequestException());
		$this->requestQueueService->method('getRequestFromToken')->willReturn([]);

		$this->service->updateActivity($alice, $update);

		$this->assertInstanceOf(Update::class, $queued);
		$wire = json_decode(json_encode($queued), true);
		$this->assertContains(ACore::CONTEXT_PUBLIC, (array)($wire['to'] ?? []), 'the Update names no public audience');
		$this->assertSame([self::ALICE_ID . '/followers'], $wire['cc'] ?? null, 'the Update is not copied to the followers');
	}

	public function testAPostUpdateKeepsThePostsOwnAudience(): void {
		$note = $this->note();
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$note->setCcArray([self::ALICE_ID . '/followers', 'https://other.example/@bob']);

		$queued = null;
		$this->requestQueueService->method('generateRequestQueue')
			->willReturnCallback(function (array $paths, ACore $item) use (&$queued): string {
				$queued = $item;

				return self::TOKEN;
			});
		$this->requestQueueService->method('getPriorityRequest')->willThrowException(new NoHighPriorityRequestException());
		$this->requestQueueService->method('getRequestFromToken')->willReturn([]);

		$this->service->updateActivity($this->alice(), $note);

		$this->assertSame([self::ALICE_ID . '/followers', 'https://other.example/@bob'], $queued->getCcArray());
	}

	// deleteActivity()

	public function testDeleteActivitySendsTombstoneOnBehalfOfItemAuthor(): void {
		$note = $this->note();
		$note->setActorId(self::ALICE_ID);
		$this->actorsRequest->method('getFromId')->with(self::ALICE_ID)->willReturn($this->alice());
		$this->noteInterface->expects($this->never())->method('save');

		$queued = null;
		$this->requestQueueService->expects($this->once())
			->method('generateRequestQueue')
			->with(
				$this->callback(static fn (array $paths): bool
					=> count($paths) === 1
						&& $paths[0]->getUri() === self::BOB_INBOX
						&& $paths[0]->getPriority() === InstancePath::PRIORITY_TOP),
				$this->callback(function (ACore $item) use (&$queued): bool {
					$queued = $item;

					return true;
				}),
				self::ALICE_ID
			)
			->willReturn(self::TOKEN);
		$this->requestQueueService->method('getPriorityRequest')->willThrowException(new NoHighPriorityRequestException());
		$this->requestQueueService->method('getRequestFromToken')->willReturn([]);

		$this->assertSame(self::TOKEN, $this->service->deleteActivity($note));

		$this->assertInstanceOf(Delete::class, $queued);
		$this->assertSame(self::NOTE_ID . '#delete', $queued->getId());
		$this->assertSame(self::ALICE_ID, $queued->getActorId());
		$this->assertFalse($queued->hasActor());
		$this->assertInstanceOf(Tombstone::class, $queued->getObject());
		$this->assertSame(self::NOTE_ID, $queued->getObject()->getId());
		$this->assertSame(self::NOTE_ID, $queued->getObjectId());
		$this->assertSame($queued, $queued->getObject()->getParent());
	}

	/**
	 * A recipient may only pass an activity on (AP §7.1.2) if it carries the
	 * author's own signature. Unsigned, a Delete of a reply cannot travel the
	 * way the reply itself did, so the post stays visible on every instance
	 * that only ever received it forwarded.
	 */
	public function testADeleteIsSignedByItsAuthor(): void {
		$note = $this->note();
		$note->setActorId(self::ALICE_ID);
		$alice = $this->alice();
		$this->actorsRequest->expects($this->once())
			->method('getFromId')->with(self::ALICE_ID)->willReturn($alice);
		$this->signatureService->expects($this->once())
			->method('signObject')
			->with($this->identicalTo($alice), $this->isInstanceOf(Delete::class));
		$this->expectQueuedWithoutInlineDelivery();

		$this->service->deleteActivity($note);
	}

	/** An author we cannot load is a Delete that still has to go out. */
	public function testADeleteWithoutASignableAuthorIsStillSent(): void {
		$note = $this->note();
		$note->setActorId(self::ALICE_ID);
		$this->actorsRequest->method('getFromId')
			->willThrowException(new ActorDoesNotExistException());
		$this->signatureService->expects($this->never())->method('signObject');
		$this->expectQueuedWithoutInlineDelivery();

		$this->assertSame(self::TOKEN, $this->service->deleteActivity($note));
	}

	// request(): recipient resolution

	public function testRequestKeepsDirectInboxTargets(): void {
		$paths = [];
		$this->capturePaths($paths);
		$like = new Like();
		$like->setActorId(self::ALICE_ID);
		$direct = new InstancePath(self::BOB_INBOX, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_HIGH);
		$like->addInstancePath($direct);

		$this->assertSame(self::TOKEN, $this->service->request($like));
		$this->assertSame([$direct], $paths);
	}

	// --- relays -----------------------------------------------------------

	/**
	 * Subscribing to a relay and sending it nothing is taking without giving:
	 * the point of a relay is that every instance on it sees the others, and
	 * one that only reads is invisible to all of them.
	 */
	public function testAPublicLocalPostAlsoGoesToEveryAcceptedRelay(): void {
		$this->configService->method('getSocialUrl')->willReturn('https://social.example/');
		$this->relayRequest->method('acceptedInboxes')->willReturn(['https://relay.example/inbox']);
		$paths = [];
		$this->capturePaths($paths);
		$note = new Note();
		$note->setId(self::NOTE_ID);
		$note->setActorId(self::ALICE_ID);
		$note->setTo(ACore::CONTEXT_PUBLIC);

		$this->service->request($note);

		$this->assertContains(
			'https://relay.example/inbox',
			array_map(static fn (InstancePath $path): string => $path->getUri(), $paths)
		);
	}

	/**
	 * A followers-only post has an audience that was chosen, and a relay is
	 * the opposite of a chosen audience.
	 */
	public function testAPostThatIsNotPublicNeverReachesARelay(): void {
		$this->configService->method('getSocialUrl')->willReturn('https://social.example/');
		$this->relayRequest->method('acceptedInboxes')->willReturn(['https://relay.example/inbox']);
		$paths = [];
		$this->capturePaths($paths);
		$note = new Note();
		$note->setId(self::NOTE_ID);
		$note->setActorId(self::ALICE_ID);
		$note->addInstancePath(new InstancePath(self::BOB_INBOX, InstancePath::TYPE_INBOX));

		$this->service->request($note);

		$this->assertNotContains(
			'https://relay.example/inbox',
			array_map(static fn (InstancePath $path): string => $path->getUri(), $paths)
		);
	}

	/**
	 * Forwarding a third party's activity to a relay would put this instance's
	 * name on somebody else's post, and loop where two instances both relay.
	 */
	public function testSomebodyElsesPublicPostIsNotPassedOnToARelay(): void {
		$this->configService->method('getSocialUrl')->willReturn('https://social.example/');
		$this->relayRequest->method('acceptedInboxes')->willReturn(['https://relay.example/inbox']);
		$paths = [];
		$this->capturePaths($paths);
		$note = new Note();
		$note->setId('https://elsewhere.example/statuses/1');
		$note->setActorId('https://elsewhere.example/users/carol');
		$note->setTo(ACore::CONTEXT_PUBLIC);

		$this->service->request($note);

		$this->assertNotContains(
			'https://relay.example/inbox',
			array_map(static fn (InstancePath $path): string => $path->getUri(), $paths)
		);
	}

	/**
	 * What a relay is subscribed for is the posts, and a post goes out wrapped
	 * in a Create: the activity carries the audience of the note it wraps, so
	 * the gate that reads it can tell a public post from a private one.
	 */
	public function testAPublicPostWrappedInACreateAlsoGoesToEveryAcceptedRelay(): void {
		$this->configService->method('getSocialUrl')->willReturn('https://social.example/');
		$this->relayRequest->method('acceptedInboxes')->willReturn(['https://relay.example/inbox']);
		$paths = [];
		$this->capturePaths($paths);
		$note = $this->note();
		$note->setActorId(self::ALICE_ID);
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$note->addCc(self::ALICE_ID . '/followers');

		$activity = null;
		$this->service->createActivity($this->alice(), $note, $activity);

		$this->assertSame(ACore::CONTEXT_PUBLIC, $activity->getTo());
		$this->assertSame([self::ALICE_ID . '/followers'], $activity->getCcArray());
		$this->assertContains(
			'https://relay.example/inbox',
			array_map(static fn (InstancePath $path): string => $path->getUri(), $paths)
		);
	}

	public function testAFollowersOnlyPostWrappedInACreateNeverReachesARelay(): void {
		$this->configService->method('getSocialUrl')->willReturn('https://social.example/');
		$this->relayRequest->method('acceptedInboxes')->willReturn(['https://relay.example/inbox']);
		$paths = [];
		$this->capturePaths($paths);
		$note = $this->note();
		$note->setTo(self::ALICE_ID . '/followers');

		$this->service->createActivity($this->alice(), $note);

		$this->assertNotContains(
			'https://relay.example/inbox',
			array_map(static fn (InstancePath $path): string => $path->getUri(), $paths)
		);
	}

	/** The ids of the Updates queued for these items, in order. */
	private function updateIds(ACore ...$items): array {
		$ids = [];
		$this->requestQueueService->method('generateRequestQueue')->willReturnCallback(
			function (array $paths, ACore $item) use (&$ids): string {
				$ids[] = $item->getId();

				return self::TOKEN;
			}
		);
		$this->requestQueueService->method('getPriorityRequest')->willThrowException(new NoHighPriorityRequestException());
		$this->requestQueueService->method('getRequestFromToken')->willReturn([]);

		foreach ($items as $item) {
			$this->service->updateActivity($this->alice(), $item);
		}

		return $ids;
	}

	public function testTwoEditsOfOnePostAreTwoActivities(): void {
		// a peer that remembers activities by id dropped the second edit
		$first = $this->note();
		$first->setUpdated('2026-09-01T10:00:00+00:00');
		$second = $this->note();
		$second->setUpdated('2026-09-01T10:05:00+00:00');

		$ids = $this->updateIds($first, $second);

		$this->assertSame(self::NOTE_ID . '#updates/' . strtotime('2026-09-01T10:00:00+00:00'), $ids[0]);
		$this->assertNotSame($ids[0], $ids[1]);
	}

	public function testTheSameVersionKeepsItsId(): void {
		$first = $this->note();
		$first->setUpdated('2026-09-01T10:00:00+00:00');
		$again = $this->note();
		$again->setUpdated('2026-09-01T10:00:00+00:00');

		$ids = $this->updateIds($first, $again);

		$this->assertSame($ids[0], $ids[1]);
	}

	public function testAnUpdateOfAPublicPostAlsoGoesToEveryAcceptedRelay(): void {
		$this->configService->method('getSocialUrl')->willReturn('https://social.example/');
		$this->relayRequest->method('acceptedInboxes')->willReturn(['https://relay.example/inbox']);
		$paths = [];
		$this->capturePaths($paths);
		$note = $this->note();
		$note->setActorId(self::ALICE_ID);
		$note->setTo(ACore::CONTEXT_PUBLIC);

		$this->service->updateActivity($this->alice(), $note);

		$this->assertContains(
			'https://relay.example/inbox',
			array_map(static fn (InstancePath $path): string => $path->getUri(), $paths)
		);
	}

	/**
	 * A relay that was told about the post has to be told it is gone; the
	 * Tombstone that replaces the post names nobody, so the Delete is
	 * addressed from the post itself.
	 */
	public function testADeleteOfAPublicPostAlsoGoesToEveryAcceptedRelay(): void {
		$this->configService->method('getSocialUrl')->willReturn('https://social.example/');
		$this->relayRequest->method('acceptedInboxes')->willReturn(['https://relay.example/inbox']);
		$paths = [];
		$this->capturePaths($paths);
		$note = $this->note();
		$note->setActorId(self::ALICE_ID);
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$this->actorsRequest->method('getFromId')->willReturn($this->alice());

		$this->service->deleteActivity($note);

		$this->assertContains(
			'https://relay.example/inbox',
			array_map(static fn (InstancePath $path): string => $path->getUri(), $paths)
		);
	}

	public function testADeleteOfADirectMessageNeverReachesARelay(): void {
		$this->configService->method('getSocialUrl')->willReturn('https://social.example/');
		$this->relayRequest->method('acceptedInboxes')->willReturn(['https://relay.example/inbox']);
		$paths = [];
		$this->capturePaths($paths);
		$note = $this->note();
		$note->setActorId(self::ALICE_ID);
		$note->setToArray(['https://remote.example/users/bob']);
		$this->actorsRequest->method('getFromId')->willReturn($this->alice());

		$this->service->deleteActivity($note);

		$this->assertNotContains(
			'https://relay.example/inbox',
			array_map(static fn (InstancePath $path): string => $path->getUri(), $paths)
		);
	}

	/**
	 * The fan-out asks the database for the distinct inboxes instead of
	 * hydrating every follower into a Follow with a Person and its details just
	 * to read one string off each: the number of inboxes involved is the number
	 * of instances, not of followers. Deduplication and the shared-inbox
	 * fallback happen there — see FollowsRequest::getFollowerInboxes() and its
	 * integration test.
	 */
	public function testRequestExpandsFollowersToTheInboxesTheDatabaseNames(): void {
		$paths = [];
		$this->capturePaths($paths);
		$this->followsRequest->expects($this->once())
			->method('getFollowerInboxes')
			->with(self::ALICE_ID)
			->willReturn(['https://remote.example/inbox', 'https://other.example/inbox']);
		$this->followsRequest->expects($this->never())->method('getFollowersByActorId');

		$note = new Note();
		$note->setActorId(self::ALICE_ID);
		$note->addInstancePath(new InstancePath(self::ALICE_ID, InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW));

		$this->service->request($note);

		$this->assertCount(2, $paths);
		$this->assertSame('https://remote.example/inbox', $paths[0]->getUri());
		$this->assertSame(InstancePath::TYPE_GLOBAL, $paths[0]->getType());
		$this->assertSame(InstancePath::PRIORITY_LOW, $paths[0]->getPriority());
		$this->assertSame('https://other.example/inbox', $paths[1]->getUri());
		$this->assertSame(InstancePath::TYPE_GLOBAL, $paths[1]->getType());
	}

	public function testAFollowersFanOutWithNoInboxesSendsNothing(): void {
		$paths = [];
		$this->capturePaths($paths);
		$this->followsRequest->method('getFollowerInboxes')->willReturn([]);

		$note = new Note();
		$note->setActorId(self::ALICE_ID);
		$note->addInstancePath(
			new InstancePath(self::ALICE_ID, InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW)
		);

		$this->service->request($note);

		$this->assertSame([], $paths);
	}

	public function testRequestNeverPostsToThisInstance(): void {
		$paths = [];
		$this->capturePaths($paths);
		$this->followsRequest->method('getFollowerInboxes')->willReturn([
			// a follower on this very instance
			self::CLOUD_URL . '/inbox',
			'https://remote.example/inbox',
		]);

		$note = new Note();
		$note->setActorId(self::ALICE_ID);
		$note->addInstancePath(new InstancePath(self::ALICE_ID, InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW));

		$this->service->request($note);

		// carol already has it: recipients are written into stream_dest when the
		// item is saved, and the round trip would hand us back our own writing
		$this->assertSame(['https://remote.example/inbox'], array_map(
			fn (InstancePath $path): string => $path->getUri(), $paths
		));
	}

	/**
	 * Local ids and inboxes are generated from the social URL, while the
	 * self-delivery filter read the *cloud* address: an administrator who sets
	 * a cloud address on another host left the server posting its own
	 * activities back to itself, which behind a reverse proxy or an SSRF guard
	 * it cannot do — fifteen failures and a drop, with the remote deliveries
	 * queued behind them.
	 */
	public function testAnInboxOnTheSocialUrlHostIsOursEvenWhenTheCloudAddressDiffers(): void {
		$this->configService->method('getSocialUrl')->willReturn('https://social.example/apps/social/');
		$paths = [];
		$this->capturePaths($paths);
		$this->followsRequest->method('getFollowerInboxes')->willReturn([
			'https://social.example/apps/social/@carol/inbox',
			'https://remote.example/inbox',
		]);

		$note = new Note();
		$note->setActorId(self::ALICE_ID);
		$note->addInstancePath(new InstancePath(self::ALICE_ID, InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW));

		$this->service->request($note);

		$this->assertSame(['https://remote.example/inbox'], array_map(
			fn (InstancePath $path): string => $path->getUri(), $paths
		));
	}

	public function testADirectTargetOnThisInstanceIsDroppedToo(): void {
		$paths = [];
		$this->capturePaths($paths);
		$like = new Like();
		$like->setActorId(self::ALICE_ID);
		$like->addInstancePath(
			new InstancePath(self::CLOUD_URL . '/users/carol/inbox', InstancePath::TYPE_INBOX, InstancePath::PRIORITY_HIGH)
		);

		// nothing left to send, so no token is needed
		$this->assertSame('<request token not needed>', $this->service->request($like));
		$this->assertSame([], $paths);
	}

	public function testRequestExpandsAllToEveryKnownSharedInbox(): void {
		$paths = [];
		$this->capturePaths($paths);
		$this->cacheActorsRequest->expects($this->once())
			->method('getSharedInboxes')
			->willReturn(['https://remote.example/inbox', 'https://other.example/inbox']);

		$item = new Update();
		$item->setActorId(self::ALICE_ID);
		$item->addInstancePath(new InstancePath('https://social.example/', InstancePath::TYPE_ALL, InstancePath::PRIORITY_HIGH));

		$this->service->request($item);

		$this->assertCount(2, $paths);
		$this->assertSame('https://remote.example/inbox', $paths[0]->getUri());
		$this->assertSame(InstancePath::TYPE_GLOBAL, $paths[0]->getType());
		$this->assertSame(InstancePath::PRIORITY_LOW, $paths[0]->getPriority());
	}

	public function testRequestCombinesFollowersAndDirectTargets(): void {
		$paths = [];
		$this->capturePaths($paths);
		$this->followsRequest->method('getFollowerInboxes')
			->willReturn(['https://remote.example/inbox']);

		$note = $this->note();
		$note->setActorId(self::ALICE_ID);
		$note->addInstancePath(new InstancePath(self::ALICE_ID, InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW));

		$this->service->request($note);

		$this->assertCount(2, $paths);
		$this->assertSame(self::BOB_INBOX, $paths[0]->getUri());
		$this->assertSame('https://remote.example/inbox', $paths[1]->getUri());
	}

	public function testRequestAuthorIsTheActorObjectWhenPresent(): void {
		$item = new Like();
		$item->setActorId('https://social.example/@someone-else');
		$item->setActor($this->alice());
		$this->requestQueueService->expects($this->once())
			->method('generateRequestQueue')
			->with([], $this->identicalTo($item), self::ALICE_ID)
			->willReturn('');

		$this->service->request($item);
	}

	// request(): queue lifecycle

	public function testRequestWithoutAnyTargetNeedsNoToken(): void {
		$this->requestQueueService->method('generateRequestQueue')->willReturn('');
		$this->requestQueueService->expects($this->never())->method('getPriorityRequest');
		$this->requestQueueService->expects($this->never())->method('getRequestFromToken');
		$this->curlService->expects($this->never())->method('asyncWithToken');

		$like = new Like();
		$like->setActorId(self::ALICE_ID);

		$this->assertSame('<request token not needed>', $this->service->request($like));
	}

	public function testPublicLocalActivityWithNoResolvedRecipientsIsLogged(): void {
		$this->configService->method('getSocialUrl')->willReturn('https://social.example/');
		$this->followsRequest->method('getFollowerInboxes')->willReturn([]);
		$this->relayRequest->method('acceptedInboxes')->willReturn([]);
		$paths = [];
		$this->capturePaths($paths);
		$this->logger->expects($this->once())
			->method('notice')
			->with(
				'public activity resolved no remote inboxes; activity was not delivered',
				$this->callback(static fn (array $context): bool
					=> $context['actorId'] === self::ALICE_ID
						&& $context['addressingPaths'] === [InstancePath::TYPE_FOLLOWERS])
			);

		$note = $this->note();
		$note->setActorId(self::ALICE_ID);
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$note->setInstancePaths([
			new InstancePath(self::ALICE_ID, InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW),
		]);

		$this->service->request($note);

		$this->assertSame([], $paths);
	}

	public function testDeletePromotesOneRemoteRetractionWithoutChangingSavedPaths(): void {
		$paths = [
			new InstancePath(self::BOB_INBOX, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_LOW),
			new InstancePath('https://other.example/inbox', InstancePath::TYPE_GLOBAL, InstancePath::PRIORITY_LOW),
		];
		$delete = new Delete();
		$delete->setId('https://social.example/@alice/1#delete');
		$delete->setActorId(self::ALICE_ID);
		$delete->addInstancePaths($paths);

		$this->requestQueueService->expects($this->once())
			->method('generateRequestQueue')
			->with(
				$this->callback(static fn (array $outbound): bool
					=> count($outbound) === 2
						&& $outbound[0]->getUri() === self::BOB_INBOX
						&& $outbound[0]->getPriority() === InstancePath::PRIORITY_TOP
						&& $outbound[1]->getPriority() === InstancePath::PRIORITY_LOW),
				$this->identicalTo($delete),
				self::ALICE_ID
			)
			->willReturn('');

		$this->service->request($delete);

		$this->assertSame(InstancePath::PRIORITY_LOW, $paths[0]->getPriority());
		$this->assertSame(InstancePath::PRIORITY_LOW, $paths[1]->getPriority());
	}

	public function testRequestDeliversPriorityTargetInlineAndHandsTheRestToAsync(): void {
		$direct = $this->queue();
		$this->requestQueueService->method('generateRequestQueue')->willReturn(self::TOKEN);
		$this->requestQueueService->expects($this->once())
			->method('getPriorityRequest')
			->with(self::TOKEN)
			->willReturn($direct);
		$this->requestQueueService->expects($this->once())->method('initRequest')->with($this->identicalTo($direct));
		$this->signatureService->expects($this->once())->method('signRequest')
			->with($this->isString(), $this->isString(), $this->identicalTo($direct))
			->willReturn([]);
		$this->curlService->expects($this->once())->method('retrieveJson')->willReturn([]);
		$this->requestQueueService->expects($this->once())->method('endRequest')->with($this->identicalTo($direct), true);
		$this->requestQueueService->expects($this->once())
			->method('getRequestFromToken')
			->with(self::TOKEN, RequestQueue::STATUS_STANDBY)
			->willReturn([$this->queue('https://other.example/inbox')]);
		$this->curlService->expects($this->once())->method('asyncWithToken')->with(self::TOKEN);

		$like = new Like();
		$like->setActorId(self::ALICE_ID);
		$like->addInstancePath(new InstancePath(self::BOB_INBOX, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_HIGH));

		$this->assertSame(self::TOKEN, $this->service->request($like));
		$this->assertSame(ActivityService::TIMEOUT_LIVE, $direct->getTimeout());
	}

	public function testRequestSkipsAsyncWhenNothingIsLeftInStandby(): void {
		$this->requestQueueService->method('generateRequestQueue')->willReturn(self::TOKEN);
		$this->requestQueueService->method('getPriorityRequest')->willReturn($this->queue());
		$this->curlService->method('retrieveJson')->willReturn([]);
		$this->requestQueueService->method('getRequestFromToken')->willReturn([]);
		$this->curlService->expects($this->never())->method('asyncWithToken');

		$like = new Like();
		$like->setActorId(self::ALICE_ID);
		$like->addInstancePath(new InstancePath(self::BOB_INBOX, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_TOP));

		$this->assertSame(self::TOKEN, $this->service->request($like));
	}

	public function testRequestWithoutPriorityTargetGoesStraightToAsync(): void {
		$this->requestQueueService->method('generateRequestQueue')->willReturn(self::TOKEN);
		$this->requestQueueService->method('getPriorityRequest')->willThrowException(new NoHighPriorityRequestException());
		$this->requestQueueService->expects($this->never())->method('initRequest');
		$this->curlService->expects($this->never())->method('retrieveJson');
		$this->requestQueueService->method('getRequestFromToken')->willReturn([$this->queue()]);
		$this->curlService->expects($this->once())->method('asyncWithToken')->with(self::TOKEN);

		$note = $this->note();
		$note->setActorId(self::ALICE_ID);

		$this->assertSame(self::TOKEN, $this->service->request($note));
	}

	public function testRequestReturnsTokenWhenQueueTurnsOutEmpty(): void {
		$this->requestQueueService->method('generateRequestQueue')->willReturn(self::TOKEN);
		$this->requestQueueService->method('getPriorityRequest')->willThrowException(new EmptyQueueException());
		$this->requestQueueService->expects($this->never())->method('getRequestFromToken');
		$this->curlService->expects($this->never())->method('asyncWithToken');

		$note = $this->note();
		$note->setActorId(self::ALICE_ID);

		$this->assertSame(self::TOKEN, $this->service->request($note));
	}

	// holding a post back, and letting it go

	/**
	 * A post waiting for its video is queued, and nothing goes: not the inline
	 * delivery, not the async drain. The cron delivers it once the time given
	 * has passed, if nothing released it before.
	 */
	public function testAHeldPostIsQueuedWithItsTimeAndNothingIsSentYet(): void {
		$until = time() + 600;
		$this->requestQueueService->expects($this->once())->method('generateRequestQueue')
			->with($this->isArray(), $this->isInstanceOf(Create::class), self::ALICE_ID, $until)
			->willReturn(self::TOKEN);
		$this->requestQueueService->expects($this->never())->method('getPriorityRequest');
		$this->curlService->expects($this->never())->method('retrieveJson');
		$this->curlService->expects($this->never())->method('asyncWithToken');

		$this->assertSame(self::TOKEN, $this->service->createActivity($this->alice(), $this->note(), $activity, $until));
	}

	/** A post that is not held is queued with no time, and goes as it always did. */
	public function testAPostThatIsNotHeldIsQueuedDue(): void {
		$this->requestQueueService->expects($this->once())->method('generateRequestQueue')
			->with($this->isArray(), $this->isInstanceOf(Create::class), self::ALICE_ID, 0)
			->willReturn(self::TOKEN);
		$this->requestQueueService->method('getPriorityRequest')->willThrowException(new NoHighPriorityRequestException());
		$this->requestQueueService->method('getRequestFromToken')->willReturn([$this->queue()]);
		$this->curlService->expects($this->once())->method('asyncWithToken')->with(self::TOKEN);

		$this->service->createActivity($this->alice(), $this->note());
	}

	private function heldRow(string $type, string $inbox = self::BOB_INBOX): RequestQueue {
		$queue = new RequestQueue(
			(string)json_encode(['type' => $type, 'object' => ['id' => self::NOTE_ID, 'attachment' => [
				['type' => 'Document', 'mediaType' => 'video/quicktime', 'url' => 'https://social.example/media/old.quicktime'],
			]]], JSON_UNESCAPED_SLASHES),
			new InstancePath($inbox, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_LOW),
			self::ALICE_ID
		);
		$queue->setToken(self::TOKEN);
		$queue->setLast(time() + 600);

		return $queue;
	}

	/** The post as the conversion left it: the attachment names the MP4. */
	private function convertedNote(): Note {
		$note = $this->note();
		$note->setAttachments([
			(new \OCA\Social\Model\Client\MediaAttachment())
				->setId('5')
				->setType('video')
				->setMediaType('video/mp4')
				->setUrl('https://social.example/media/new.mp4'),
		]);

		return $note;
	}

	/**
	 * The release is the whole point: every held Create goes out rebuilt from
	 * the post, so it names the converted file and `video/mp4` — Pixelfed
	 * refuses the `.mov` on arrival and is never asked again.
	 */
	public function testReleasingAHeldPostSendsItWithTheConvertedFile(): void {
		$rows = [$this->heldRow('Create'), $this->heldRow('Create', 'https://third.example/inbox')];
		$this->requestQueueService->method('getHeld')->with(self::NOTE_ID)->willReturn($rows);
		$this->streamRequest->method('getStreamById')->with(self::NOTE_ID)->willReturn($this->convertedNote());
		$this->actorsRequest->method('getFromId')->with(self::ALICE_ID)->willReturn($this->alice());
		// one signature for the one body every inbox shares
		$this->signatureService->expects($this->once())->method('signObject');

		$bodies = [];
		$this->requestQueueService->expects($this->exactly(2))->method('releaseRequest')
			->willReturnCallback(function (RequestQueue $queue, string $body) use (&$bodies): bool {
				$bodies[] = $body;
				$queue->setLast(0);

				return true;
			});
		$this->curlService->expects($this->once())->method('asyncWithToken')->with(self::TOKEN);

		$this->assertSame(2, $this->service->releaseHeld(self::NOTE_ID));

		$this->assertSame($bodies[0], $bodies[1]);
		$sent = json_decode($bodies[0], true);
		$this->assertSame('Create', $sent['type']);
		$this->assertSame(self::NOTE_ID . '/activity', $sent['id']);
		$this->assertSame('video/mp4', $sent['object']['attachment'][0]['mediaType']);
		$this->assertSame('https://social.example/media/new.mp4', $sent['object']['attachment'][0]['url']);
		$this->assertStringNotContainsString('quicktime', $bodies[0]);
	}

	/** An edit made while the post waited goes out rebuilt too, as an Update. */
	public function testAHeldEditIsRebuiltAsAnUpdate(): void {
		$this->requestQueueService->method('getHeld')->willReturn([$this->heldRow('Update')]);
		$this->streamRequest->method('getStreamById')->willReturn($this->convertedNote());
		$this->actorsRequest->method('getFromId')->willReturn($this->alice());

		$body = '';
		$this->requestQueueService->method('releaseRequest')->willReturnCallback(
			function (RequestQueue $queue, string $sent) use (&$body): bool {
				$body = $sent;

				return true;
			}
		);

		$this->service->releaseHeld(self::NOTE_ID);

		$sent = json_decode($body, true);
		$this->assertSame('Update', $sent['type']);
		$this->assertSame('video/mp4', $sent['object']['attachment'][0]['mediaType']);
	}

	/** Deleted while it waited: sending it now would put it back everywhere. */
	public function testAHeldPostDeletedMeanwhileIsNeverSent(): void {
		$rows = [$this->heldRow('Create')];
		$this->requestQueueService->method('getHeld')->willReturn($rows);
		$this->streamRequest->method('getStreamById')
			->willThrowException(new \OCA\Social\Exceptions\StreamNotFoundException());

		$this->requestQueueService->expects($this->once())->method('deleteRequest')->with($this->identicalTo($rows[0]));
		$this->requestQueueService->expects($this->never())->method('releaseRequest');
		$this->curlService->expects($this->never())->method('asyncWithToken');

		$this->assertSame(0, $this->service->releaseHeld(self::NOTE_ID));
	}

	/** A row a drain already took is not ours to change, and nothing is started for it. */
	public function testARowThatIsNoLongerHeldIsLeftAlone(): void {
		$this->requestQueueService->method('getHeld')->willReturn([$this->heldRow('Create')]);
		$this->streamRequest->method('getStreamById')->willReturn($this->convertedNote());
		$this->actorsRequest->method('getFromId')->willReturn($this->alice());
		$this->requestQueueService->method('releaseRequest')->willReturn(false);
		$this->curlService->expects($this->never())->method('asyncWithToken');

		$this->assertSame(0, $this->service->releaseHeld(self::NOTE_ID));
	}

	public function testNothingHeldIsNothingToRelease(): void {
		$this->requestQueueService->method('getHeld')->willReturn([]);
		$this->streamRequest->expects($this->never())->method('getStreamById');

		$this->assertSame(0, $this->service->releaseHeld(self::NOTE_ID));
	}

	/**
	 * A Delete takes what has not gone yet with it. A held Create sent after
	 * the Delete would put the post back on every server that got both.
	 */
	public function testADeleteDropsTheDeliveriesStillHeld(): void {
		$held = $this->heldRow('Create');
		$note = $this->note();
		$note->setActorId(self::ALICE_ID);
		$this->actorsRequest->method('getFromId')->willReturn($this->alice());
		$this->requestQueueService->method('getHeld')->with(self::NOTE_ID)->willReturn([$held]);
		$this->requestQueueService->expects($this->once())->method('deleteRequest')->with($this->identicalTo($held));
		$this->expectQueuedWithoutInlineDelivery();

		$this->service->deleteActivity($note);
	}

	// manageRequest()

	public function testManageRequestPostsSignedActivityToInboxAndEndsRequestOnSuccess(): void {
		$queue = $this->queue();
		$this->requestQueueService->expects($this->once())->method('initRequest')->with($this->identicalTo($queue));

		$signed = null;
		$this->signatureService->expects($this->once())
			->method('signRequest')
			->with(
				$this->callback(function (string $url) use (&$signed): bool {
					$signed = $url;

					return true;
				}),
				$this->isString(),
				$this->identicalTo($queue)
			)
			->willReturn(['Signature' => 'keyId="k"']);
		$sent = null;
		$this->curlService->expects($this->once())
			->method('retrieveJson')
			->willReturnCallback(function (string $method, string $url, array $options) use (&$sent): array {
				$sent = ['method' => $method, 'url' => $url, 'options' => $options];

				return ['ok' => true];
			});
		$this->requestQueueService->expects($this->once())->method('endRequest')->with($this->identicalTo($queue), true);
		$this->requestQueueService->expects($this->never())->method('deleteRequest');

		$this->service->manageInit();
		$this->service->manageRequest($queue);

		// the URL that is signed is the URL that is sent to — anything else
		// verifies here and on no peer anywhere
		$this->assertSame($signed, $sent['url']);
		$this->assertSame('post', $sent['method']);
		$this->assertSame(self::BOB_INBOX, $sent['url']);
		$this->assertSame(10, $sent['options']['timeout']);
		$this->assertSame(['Signature' => 'keyId="k"'], $sent['options']['headers']);
		// byte for byte, not merely the same JSON: a forwarded activity's Linked
		// Data signature survives only if nothing here re-encodes it
		$this->assertSame($queue->getActivity(), $sent['options']['body']);
	}

	/**
	 * @return array<string, array{int, string}>
	 */
	public static function requestTypeProvider(): array {
		return [
			'inbox is posted to' => [InstancePath::TYPE_INBOX, 'post'],
			'shared inbox is posted to' => [InstancePath::TYPE_GLOBAL, 'post'],
			'followers is posted to' => [InstancePath::TYPE_FOLLOWERS, 'post'],
			'public path is fetched' => [InstancePath::TYPE_PUBLIC, 'get'],
		];
	}

	#[DataProvider('requestTypeProvider')]
	public function testManageRequestPicksHttpMethodFromTargetType(int $pathType, string $expectedMethod): void {
		$sent = null;
		$this->curlService->method('retrieveJson')->willReturnCallback(
			function (string $method, string $url, array $options) use (&$sent): array {
				$sent = $method;

				return [];
			}
		);

		$this->service->manageInit();
		$this->service->manageRequest($this->queue(self::BOB_INBOX, $pathType));

		$this->assertSame($expectedMethod, $sent);
	}

	/** An inbox that answers 202 with an empty body has taken the activity. */
	public function testManageRequestTreatsNonJsonAnswersAsDelivered(): void {
		$queue = $this->queue();
		$this->curlService->method('retrieveJson')->willThrowException(new RequestResultNotJsonException());
		$this->requestQueueService->expects($this->once())->method('endRequest')->with($this->identicalTo($queue), true);
		$this->requestQueueService->expects($this->never())->method('deleteRequest');

		$this->service->manageInit();
		$this->service->manageRequest($queue);
	}

	/**
	 * Nothing left this server: the domain is not one it federates with. Kept
	 * as a success, the row told the author their post had reached a server it
	 * was never offered to.
	 */
	public function testADeliveryBlockedByTheInstancePolicyIsNotRecordedAsDelivered(): void {
		$queue = $this->queue();
		$this->curlService->method('retrieveJson')->willThrowException(new UnauthorizedFediverseException());
		$this->requestQueueService->expects($this->never())->method('endRequest');
		$this->requestQueueService->expects($this->once())->method('deleteRequest')->with($this->identicalTo($queue));

		$this->service->manageInit();
		$this->service->manageRequest($queue);
	}

	/**
	 * @return array<string, array{\Exception}>
	 */
	public static function hardErrorProvider(): array {
		return [
			'bad content' => [new RequestContentException()],
			'answer too large' => [new RequestResultSizeException()],
			'actor is gone' => [new ActorDoesNotExistException()],
		];
	}

	#[DataProvider('hardErrorProvider')]
	public function testManageRequestDropsRequestOnHardErrors(\Exception $e): void {
		$queue = $this->queue();
		$this->curlService->method('retrieveJson')->willThrowException($e);
		$this->requestQueueService->expects($this->once())->method('deleteRequest')->with($this->identicalTo($queue));
		$this->requestQueueService->expects($this->never())->method('endRequest');

		$this->service->manageInit();
		$this->service->manageRequest($queue);
	}

	/**
	 * @return array<string, array{int}>
	 */
	public static function transientHttpStatusProvider(): array {
		return [
			'request timeout' => [408],
			'rate limited' => [429],
			'internal server error' => [500],
			'bad gateway' => [502],
			'service unavailable, e.g. the peer is upgrading' => [503],
			'gateway timeout' => [504],
		];
	}

	/**
	 * A peer that is briefly unwell must not cost us the activity: these used
	 * to be indistinguishable from a permanent rejection, so every post queued
	 * for an instance having a bad minute was deleted outright.
	 */
	#[DataProvider('transientHttpStatusProvider')]
	public function testManageRequestRetriesWhenThePeerAnswersWithATransientStatus(int $status): void {
		$queue = $this->queue();
		$this->curlService->method('retrieveJson')
			->willThrowException(new RequestContentException('', $status));

		$this->requestQueueService->expects($this->once())
			->method('endRequest')->with($this->identicalTo($queue), false);
		$this->requestQueueService->expects($this->never())->method('deleteRequest');

		$this->service->manageInit();
		$this->service->manageRequest($queue);
	}

	/**
	 * @return array<string, array{int}>
	 */
	public static function permanentHttpStatusProvider(): array {
		return [
			'bad request' => [400],
			'unauthorized' => [401],
			'forbidden' => [403],
			'gone' => [410],
			'unprocessable' => [422],
		];
	}

	#[DataProvider('permanentHttpStatusProvider')]
	public function testManageRequestDropsWhenThePeerRejectsTheActivityForGood(int $status): void {
		$queue = $this->queue();
		$this->curlService->method('retrieveJson')
			->willThrowException(new RequestContentException('', $status));

		$this->requestQueueService->expects($this->once())
			->method('deleteRequest')->with($this->identicalTo($queue));
		$this->requestQueueService->expects($this->never())->method('endRequest');

		$this->service->manageInit();
		$this->service->manageRequest($queue);
	}

	/**
	 * A host that just answered 503 is skipped for the rest of the run rather
	 * than being asked once per queued activity.
	 */
	public function testManageRequestStopsAskingAHostThatAnsweredATransientStatus(): void {
		$this->curlService->method('retrieveJson')
			->willThrowException(new RequestContentException('', 503));
		$this->requestQueueService->expects($this->once())->method('endRequest');

		$this->service->manageInit();
		$this->service->manageRequest($this->queue());
		// same host, second activity: not attempted again
		$this->service->manageRequest($this->queue());
	}

	/**
	 * @return array<string, array{\Exception}>
	 */
	public static function temporaryErrorProvider(): array {
		return [
			'network error' => [new RequestNetworkException()],
			'server error' => [new RequestServerException()],
		];
	}

	#[DataProvider('temporaryErrorProvider')]
	public function testManageRequestMarksFailureAndSkipsSameInstanceForTheRestOfTheRun(\Exception $e): void {
		$first = $this->queue(self::BOB_INBOX);
		$second = $this->queue('https://remote.example/users/carol/inbox');
		$elsewhere = $this->queue('https://other.example/inbox', InstancePath::TYPE_GLOBAL);

		$this->curlService->expects($this->exactly(2))
			->method('retrieveJson')
			->willReturnCallback(function (string $method, string $url) use ($e): array {
				if (parse_url($url, PHP_URL_HOST) === 'remote.example') {
					throw $e;
				}

				return [];
			});
		$inits = [];
		$this->requestQueueService->expects($this->exactly(2))
			->method('initRequest')
			->willReturnCallback(function (RequestQueue $queue) use (&$inits): void {
				$inits[] = $queue->getInstance()->getUri();
			});
		$ended = [];
		$this->requestQueueService->expects($this->exactly(2))
			->method('endRequest')
			->willReturnCallback(function (RequestQueue $queue, bool $success) use (&$ended): void {
				$ended[] = [$queue->getInstance()->getUri(), $success];
			});
		$this->requestQueueService->expects($this->never())->method('deleteRequest');

		$this->service->manageInit();
		$this->service->manageRequest($first);
		$this->service->manageRequest($second);
		$this->service->manageRequest($elsewhere);

		$this->assertSame([self::BOB_INBOX, 'https://other.example/inbox'], $inits);
		$this->assertSame([[self::BOB_INBOX, false], ['https://other.example/inbox', true]], $ended);
	}

	/**
	 * A skipped row keeps `tries = 0` and its old `last`, so it sorts ahead of
	 * every row ever attempted and every row queued since: a few hundred of
	 * them to instances that are gone filled the whole 200-row window on every
	 * pass, and nothing else was ever delivered. It is held back instead,
	 * until the host is worth asking again.
	 */
	public function testARowSkippedForAFailingHostIsHeldBackAndReportsNoAttempt(): void {
		$this->curlService->method('retrieveJson')->willThrowException(new RequestNetworkException());

		$this->service->manageInit();
		$this->assertTrue($this->service->manageRequest($this->queue()));

		$skipped = $this->queue();
		$postponed = [];
		$this->requestQueueService->expects($this->once())
			->method('postponeRequest')
			->willReturnCallback(function (RequestQueue $queue, int $until) use (&$postponed): void {
				$postponed[] = [$queue->getInstance()->getUri(), $until];
			});

		$this->assertFalse($this->service->manageRequest($skipped));
		$this->assertSame(self::BOB_INBOX, $postponed[0][0]);
		$this->assertGreaterThan(time(), $postponed[0][1]);
	}

	/**
	 * The inline delivery has three seconds where every other path has ten to
	 * thirty, so a large but healthy peer fails it and would succeed
	 * everywhere else. Holding the whole host on the strength of that put the
	 * cron, the async drain and the worker off it too, for up to an hour.
	 */
	public function testALiveDeliveryThatTimesOutDoesNotHoldTheHostBack(): void {
		$this->curlService->method('retrieveJson')->willThrowException(new RequestNetworkException());
		$this->requestQueueService->expects($this->never())->method('postponeRequest');

		$this->service->manageInit();
		$this->service->manageRequest($this->queue(), true);
		// same host, from the cron: still attempted
		$this->assertTrue($this->service->manageRequest($this->queue()));
	}

	/**
	 * Without a memcache the breaker held nothing: every pass — and every
	 * process — found each dead host again, one thirty-second timeout at a
	 * time. Kept in the database, a failure one pass records holds the host
	 * back in the next.
	 */
	public function testAHostFoundFailingInOnePassIsHeldBackInTheNext(): void {
		$this->curlService->method('retrieveJson')->willThrowException(new RequestNetworkException());
		$this->requestQueueService->expects($this->once())->method('initRequest');
		$this->requestQueueService->expects($this->once())->method('postponeRequest');

		$this->service->manageInit();
		$this->assertTrue($this->service->manageRequest($this->queue()));
		$this->service->manageInit();
		$this->assertFalse($this->service->manageRequest($this->queue()));

		$this->assertSame(1, $this->breakerRows['remote.example']['strikes']);
		$this->assertEqualsWithDelta(time() + ActivityService::BREAKER_BASE, $this->breakerRows['remote.example']['open_until'], 2);
	}

	/** Each failure in a row doubles the wait, up to the ceiling. */
	public function testAHostThatKeepsFailingIsLeftAloneLongerEachTime(): void {
		$this->curlService->method('retrieveJson')->willThrowException(new RequestNetworkException());
		$this->breakerRows['remote.example'] = ['strikes' => 3, 'open_until' => time() - 1, 'last_failure' => time() - 300];

		$this->service->manageInit();
		$this->service->manageRequest($this->queue());

		$this->assertSame(4, $this->breakerRows['remote.example']['strikes']);
		$this->assertEqualsWithDelta(time() + 8 * ActivityService::BREAKER_BASE, $this->breakerRows['remote.example']['open_until'], 2);
	}

	/** Strikes older than the ceiling are forgotten: a host that failed yesterday starts again at one. */
	public function testOldStrikesDoNotCount(): void {
		$this->curlService->method('retrieveJson')->willThrowException(new RequestNetworkException());
		$this->breakerRows['remote.example'] = ['strikes' => 6, 'open_until' => 0, 'last_failure' => time() - 2 * ActivityService::BREAKER_MAX];

		$this->service->manageInit();
		$this->service->manageRequest($this->queue());

		$this->assertSame(1, $this->breakerRows['remote.example']['strikes']);
	}

	public function testAHostThatAnswersIsClearedAndAHealthyOneCostsNoWrite(): void {
		$this->curlService->method('retrieveJson')->willReturn([]);
		$this->breakerRows['remote.example'] = ['strikes' => 2, 'open_until' => time() - 1, 'last_failure' => time() - 120];
		$this->hostBreakerRequest->expects($this->once())->method('close')->with('remote.example');

		$this->service->manageInit();
		$this->service->manageRequest($this->queue());
		// a second success, and one to a host with no record: no more writes
		$this->service->manageRequest($this->queue());
		$this->service->manageRequest($this->queue('https://other.example/inbox', InstancePath::TYPE_GLOBAL));

		$this->assertArrayNotHasKey('remote.example', $this->breakerRows);
	}

	/** One query per drain, not one per row. */
	public function testTheBreakerIsReadOncePerDrain(): void {
		$this->curlService->method('retrieveJson')->willReturn([]);
		$this->hostBreakerRequest->expects($this->once())->method('failingSince');

		$this->service->manageInit();
		for ($i = 0; $i < 5; $i++) {
			$this->service->manageRequest($this->queue());
		}
	}

	/** A breaker that cannot be read — the table not there yet — falls back to the per-pass list. */
	public function testAnUnreadableBreakerFallsBackToThePerPassList(): void {
		$service = new ActivityService(
			$this->followsRequest, $this->cacheActorsRequest,
			$this->signatureService, $this->requestQueueService, $this->curlService, $this->configService,
			$this->actorsRequest, $this->relayRequest, $broken = $this->createStub(HostBreakerRequest::class), $this->logger, $this->streamRequest
		);
		$broken->method('failingSince')->willThrowException(new \RuntimeException('no such table'));
		$broken->method('open')->willThrowException(new \RuntimeException('no such table'));
		$this->curlService->method('retrieveJson')->willThrowException(new RequestNetworkException());
		$this->requestQueueService->expects($this->once())->method('initRequest');

		$service->manageInit();
		$this->assertTrue($service->manageRequest($this->queue()));
		$this->assertFalse($service->manageRequest($this->queue()));
	}

	public function testManageRequestGivesUpWhenQueueEntryCannotBeClaimed(): void {
		$this->requestQueueService->method('initRequest')->willThrowException(new QueueStatusException());
		$this->signatureService->expects($this->never())->method('signRequest');
		$this->curlService->expects($this->never())->method('retrieveJson');
		$this->requestQueueService->expects($this->never())->method('endRequest');
		$this->requestQueueService->expects($this->never())->method('deleteRequest');

		$this->service->manageInit();
		$this->service->manageRequest($this->queue());
	}

	private function nothingHandedBack(): callable {
		return function (RequestQueue $queue, \Throwable $e): void {
			$this->fail('handed back: ' . $e->getMessage());
		};
	}

	/**
	 * One after another, a dead peer's timeout was paid in front of every
	 * other delivery. A batch goes out as one wave, one row per host, and
	 * each row is settled as a single delivery would be.
	 */
	public function testABatchToDifferentHostsGoesOutAsOneWave(): void {
		$waves = [];
		$this->curlService->expects($this->once())->method('sendMany')
			->willReturnCallback(function (array $requests) use (&$waves): array {
				$waves[] = array_column($requests, 'url');

				return [0 => null, 1 => new RequestContentException('', 503), 2 => new RequestContentException('', 410)];
			});
		$ended = [];
		$this->requestQueueService->method('endRequest')
			->willReturnCallback(function (RequestQueue $queue, bool $success) use (&$ended): void {
				$ended[] = [$queue->getInstance()->getUri(), $success];
			});
		$this->requestQueueService->expects($this->once())->method('deleteRequest');

		$this->service->manageInit();
		$attempted = $this->service->manageRequests([
			$this->queue('https://a.example/inbox'),
			$this->queue('https://b.example/inbox'),
			$this->queue('https://c.example/inbox'),
		], time() + 60, $this->nothingHandedBack());

		$this->assertSame(3, $attempted);
		$this->assertSame([['https://a.example/inbox', 'https://b.example/inbox', 'https://c.example/inbox']], $waves);
		$this->assertSame([['https://a.example/inbox', true], ['https://b.example/inbox', false]], $ended);
		$this->assertArrayHasKey('b.example', $this->breakerRows, 'the host that answered 503 is held back');
	}

	/**
	 * Two rows for one host are not sent at once; and once the first has
	 * failed, the second is held back by the breaker instead of spending a
	 * timeout of its own.
	 */
	public function testASecondRowForAFailingHostIsHeldBackWithoutBeingSent(): void {
		$waves = [];
		$this->curlService->method('sendMany')
			->willReturnCallback(function (array $requests) use (&$waves): array {
				$waves[] = array_column($requests, 'url');

				return array_map(
					fn (array $r) => str_contains($r['url'], 'dead.example') ? new RequestNetworkException('timed out') : null,
					$requests
				);
			});
		$this->requestQueueService->expects($this->once())->method('postponeRequest');

		$this->service->manageInit();
		$attempted = $this->service->manageRequests([
			$this->queue('https://dead.example/users/a/inbox'),
			$this->queue('https://dead.example/users/b/inbox'),
			$this->queue('https://alive.example/inbox'),
		], time() + 60, $this->nothingHandedBack());

		$this->assertSame(2, $attempted);
		$this->assertSame([['https://dead.example/users/a/inbox', 'https://alive.example/inbox']], $waves);
	}

	public function testAWaveHoldsAtMostTheParallelLimit(): void {
		$sizes = [];
		$this->curlService->method('sendMany')
			->willReturnCallback(function (array $requests) use (&$sizes): array {
				$sizes[] = count($requests);

				return array_fill_keys(array_keys($requests), null);
			});
		$queues = [];
		for ($i = 0; $i < ActivityService::PARALLEL + 5; $i++) {
			$queues[] = $this->queue('https://host' . $i . '.example/inbox');
		}

		$this->service->manageInit();
		$this->assertSame(ActivityService::PARALLEL + 5, $this->service->manageRequests($queues, time() + 60, $this->nothingHandedBack()));

		$this->assertSame([ActivityService::PARALLEL, 5], $sizes);
	}

	/** A row that cannot even be signed is handed back; the rest of its wave still goes. */
	public function testARowThatFailsUnexpectedlyIsHandedBackAndTheWaveStillGoes(): void {
		$this->signatureService->method('signRequest')->willReturnCallback(
			function (string $url): array {
				if (str_contains($url, 'bad.example')) {
					throw new \RuntimeException('the private key is empty');
				}

				return [];
			}
		);
		$this->curlService->expects($this->once())->method('sendMany')
			->with($this->countOf(1))->willReturn([1 => null]);
		$handedBack = [];

		$this->service->manageInit();
		$this->service->manageRequests(
			[$this->queue('https://bad.example/inbox'), $this->queue('https://good.example/inbox')],
			time() + 60,
			function (RequestQueue $queue, \Throwable $e) use (&$handedBack): void {
				$handedBack[] = $queue->getInstance()->getUri();
			}
		);

		$this->assertSame(['https://bad.example/inbox'], $handedBack);
	}

	public function testNoWaveStartsPastTheDeadline(): void {
		$this->curlService->expects($this->never())->method('sendMany');
		$this->requestQueueService->expects($this->never())->method('initRequest');

		$this->assertSame(0, $this->service->manageRequests([$this->queue()], time() - 1, $this->nothingHandedBack()));
	}

	/** A host another process found down is held back from the first wave on, without a request. */
	public function testAHostTheBreakerHoldsIsPostponedWithoutBeingSent(): void {
		$this->breakerRows['dead.example'] = ['strikes' => 1, 'open_until' => time() + 600, 'last_failure' => time()];
		$this->curlService->expects($this->once())->method('sendMany')
			->with($this->countOf(1))->willReturn([1 => null]);
		$this->requestQueueService->expects($this->once())->method('postponeRequest');
		$this->requestQueueService->expects($this->once())->method('initRequest');

		$this->service->manageInit();
		$attempted = $this->service->manageRequests(
			[$this->queue('https://dead.example/inbox'), $this->queue('https://alive.example/inbox')],
			time() + 60,
			$this->nothingHandedBack()
		);

		$this->assertSame(1, $attempted);
	}

	/** A row another worker took between the read and the claim is left to that worker. */
	public function testARowSomebodyElseTookIsNeitherSentNorHandedBack(): void {
		$this->requestQueueService->method('initRequest')->willReturnCallback(
			function (RequestQueue $queue): void {
				if (str_contains($queue->getInstance()->getUri(), 'taken.example')) {
					throw new QueueStatusException();
				}
			}
		);
		$this->curlService->expects($this->once())->method('sendMany')
			->with($this->countOf(1))->willReturn([1 => null]);

		$this->service->manageInit();
		$attempted = $this->service->manageRequests(
			[$this->queue('https://taken.example/inbox'), $this->queue('https://free.example/inbox')],
			time() + 60,
			$this->nothingHandedBack()
		);

		$this->assertSame(1, $attempted);
	}

	/** An outcome the delivery does not know how to end is the caller's, as a single delivery's is. */
	public function testAnOutcomeThatCannotBeSettledIsHandedBack(): void {
		$this->curlService->method('sendMany')->willReturn([0 => new \RuntimeException('the database is gone')]);
		$this->requestQueueService->expects($this->never())->method('endRequest');
		$handedBack = [];

		$this->service->manageInit();
		$this->service->manageRequests(
			[$this->queue('https://a.example/inbox')],
			time() + 60,
			function (RequestQueue $queue, \Throwable $e) use (&$handedBack): void {
				$handedBack[] = $e->getMessage();
			}
		);

		$this->assertSame(['the database is gone'], $handedBack);
	}
	public function testNativeOnlyPublicationDoesNotResolveOrQueueActivityPubDelivery(): void {
		$note = new \OCA\Social\Model\ActivityPub\Object\Note();
		$note->setId('https://cloud.example/native-only')->setAttributedTo('https://cloud.example/@alice')->setLocal(true)->setTo('https://www.w3.org/ns/activitystreams#Public');
		$note->setDetailArray(\OCA\Social\Model\Details::PUBLICATION, ['fediverse' => false, 'atproto' => true]);
		$activity = new \OCA\Social\Model\ActivityPub\Activity\Create();
		$activity->setObject($note);
		$this->requestQueueService->expects(self::never())->method('generateRequestQueue');
		self::assertSame('<ATProto only>', $this->service->request($activity));
		self::assertSame('<ATProto only>', $this->service->deleteActivity($note));
	}

}
