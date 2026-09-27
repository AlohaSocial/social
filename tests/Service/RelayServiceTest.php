<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\RelayRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Accept;
use OCA\Social\Model\ActivityPub\Activity\Reject;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Relay;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\HttpSignatureService;
use OCA\Social\Service\InstanceActorService;
use OCA\Social\Service\RelayService;
use OCA\Social\Service\SearchService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Relays.
 *
 * Three things are worth holding still here: what goes on the wire when an
 * administrator subscribes, that a relay's `Announce` is read as a pointer at
 * somebody else's post rather than as a boost, and that nothing is taken in
 * from a server nobody subscribed to.
 */
#[AllowMockObjectsWithoutExpectations]
class RelayServiceTest extends TestCase {
	private const RELAY = 'https://relay.example/actor';
	private const INBOX = 'https://relay.example/inbox';
	private const OURS = 'https://cloud.example/apps/social/actor';

	private RelayRequest|MockObject $relayRequest;
	private HttpSignatureService|Stub $httpSignatureService;
	private CurlService|MockObject $curlService;
	private CacheActorService|MockObject $cacheActorService;
	private SearchService|MockObject $searchService;
	private InstanceActorService|Stub $instanceActorService;
	private RelayService $service;

	protected function setUp(): void {
		$this->relayRequest = $this->createMock(RelayRequest::class);
		$this->httpSignatureService = $this->createStub(HttpSignatureService::class);
		$this->curlService = $this->createMock(CurlService::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->searchService = $this->createMock(SearchService::class);
		$this->instanceActorService = $this->createStub(InstanceActorService::class);
		$this->instanceActorService->method('getId')->willReturn(self::OURS);

		$this->service = new RelayService(
			$this->relayRequest,
			$this->httpSignatureService,
			$this->curlService,
			$this->cacheActorService,
			$this->searchService,
			$this->instanceActorService,
			new NullLogger(),
		);
	}

	private function relayActor(string $inbox = self::INBOX): Person {
		$actor = new Person();
		$actor->setId(self::RELAY)->setInbox($inbox);

		return $actor;
	}

	private function subscription(string $status = Relay::STATUS_ACCEPTED): Relay {
		$relay = new Relay();
		$relay->setId(1)->setActorId(self::RELAY)->setInbox(self::INBOX)->setStatus($status)
			->setFollowId(self::OURS . '#relay/' . substr(md5(self::RELAY), 0, 12));

		return $relay;
	}

	// --- subscribing ------------------------------------------------------

	public function testSubscribingSendsTheFollowMastodonSends(): void {
		$this->cacheActorService->method('getFromId')->with(self::RELAY, true)
			->willReturn($this->relayActor());
		$this->relayRequest->method('getByActorId')->willReturn($this->subscription(Relay::STATUS_PENDING));

		$sent = null;
		$this->curlService->expects($this->once())->method('retrieveJson')
			->willReturnCallback(function (string $method, string $url, array $options) use (&$sent): array {
				$sent = ['method' => $method, 'url' => $url, 'body' => json_decode($options['body'], true)];

				return [];
			});

		$this->service->subscribe(self::RELAY);

		$this->assertSame('post', $sent['method']);
		$this->assertSame(self::INBOX, $sent['url']);
		$this->assertSame('Follow', $sent['body']['type']);
		$this->assertSame(self::OURS, $sent['body']['actor'], 'the instance signs it, never a person');
		$this->assertSame(ACore::CONTEXT_PUBLIC, $sent['body']['object']);
	}

	/** The row is written before the Follow goes out, so an instant Accept finds one. */
	public function testSubscribingRecordsThePendingRowBeforeItAsks(): void {
		$this->cacheActorService->method('getFromId')->willReturn($this->relayActor());
		$this->relayRequest->method('getByActorId')->willReturn($this->subscription(Relay::STATUS_PENDING));

		$order = [];
		$this->relayRequest->method('save')->willReturnCallback(static function (Relay $relay) use (&$order): void {
			$order[] = 'saved:' . $relay->getStatus();
		});
		$this->curlService->method('retrieveJson')->willReturnCallback(static function () use (&$order): array {
			$order[] = 'sent';

			return [];
		});

		$this->service->subscribe(self::RELAY);

		$this->assertSame(['saved:pending', 'sent'], $order);
	}

	public function testAnAddressThatIsNotOneIsRefusedBeforeAnythingIsFetched(): void {
		$this->cacheActorService->expects($this->never())->method('getFromId');

		$this->expectException(InvalidResourceException::class);

		$this->service->subscribe('relay.example');
	}

	public function testAnAddressThatDoesNotResolveIsRefused(): void {
		$this->cacheActorService->method('getFromId')->willThrowException(new RuntimeException('404'));
		$this->relayRequest->expects($this->never())->method('save');

		$this->expectException(InvalidResourceException::class);

		$this->service->subscribe(self::RELAY);
	}

	/** Without an inbox there is nowhere to subscribe, and no row worth writing. */
	public function testAnActorWithNoInboxIsRefused(): void {
		$this->cacheActorService->method('getFromId')->willReturn($this->relayActor(''));
		$this->relayRequest->expects($this->never())->method('save');

		$this->expectException(InvalidResourceException::class);

		$this->service->subscribe(self::RELAY);
	}

	public function testARelayThatCannotBeReachedIsRecordedAsRefused(): void {
		$this->cacheActorService->method('getFromId')->willReturn($this->relayActor());
		$this->relayRequest->method('getByActorId')->willReturn($this->subscription(Relay::STATUS_PENDING));
		$this->curlService->method('retrieveJson')->willThrowException(new RuntimeException('connection refused'));

		$this->relayRequest->expects($this->once())->method('setStatus')
			->with(self::RELAY, Relay::STATUS_REJECTED, 'connection refused');

		$this->service->subscribe(self::RELAY);
	}

	// --- unsubscribing ----------------------------------------------------

	public function testUnsubscribingSendsAnUndoAndForgetsTheRow(): void {
		$this->relayRequest->method('getById')->with(1)->willReturn($this->subscription());

		$sent = null;
		$this->curlService->method('retrieveJson')
			->willReturnCallback(function (string $method, string $url, array $options) use (&$sent): array {
				$sent = json_decode($options['body'], true);

				return [];
			});
		$this->relayRequest->expects($this->once())->method('delete')->with(1)->willReturn(true);

		$this->assertTrue($this->service->unsubscribe(1));
		$this->assertSame('Undo', $sent['type']);
		$this->assertSame('Follow', $sent['object']['type']);
	}

	/**
	 * An administrator who pressed this wants to stop taking that relay's
	 * posts; whether the relay heard is the relay's problem.
	 */
	public function testUnsubscribingForgetsTheRowEvenWhenTheRelayIsGone(): void {
		$this->relayRequest->method('getById')->willReturn($this->subscription());
		$this->curlService->method('retrieveJson')->willThrowException(new RuntimeException('gone'));
		$this->relayRequest->expects($this->once())->method('delete')->willReturn(true);

		$this->assertTrue($this->service->unsubscribe(1));
	}

	public function testUnsubscribingFromSomethingThatIsNotThereDoesNothing(): void {
		$this->relayRequest->method('getById')->willReturn(null);
		$this->curlService->expects($this->never())->method('retrieveJson');

		$this->assertFalse($this->service->unsubscribe(9));
	}

	// --- what a relay sends -----------------------------------------------

	private function activity(string $type, string $actorId, string $objectId = ''): ACore {
		$activity = match ($type) {
			'Accept' => new Accept(),
			'Reject' => new Reject(),
			'Undo' => new Undo(),
			default => new Announce(),
		};
		$activity->setActorId($actorId);
		if ($objectId !== '') {
			$activity->setObjectId($objectId);
		}

		return $activity;
	}

	public function testAnActivityFromAServerNobodySubscribedToIsNotARelaysAndIsLeftAlone(): void {
		$this->relayRequest->method('getByActorId')->willReturn(null);

		$this->assertFalse(
			$this->service->handleIncoming($this->activity(Announce::TYPE, 'https://stranger.example/actor', 'https://x/1'))
		);
	}

	public function testAnAcceptOfOurFollowMarksTheSubscriptionAccepted(): void {
		$relay = $this->subscription(Relay::STATUS_PENDING);
		$this->relayRequest->method('getByActorId')->willReturn($relay);
		$this->relayRequest->expects($this->once())->method('setStatus')
			->with(self::RELAY, Relay::STATUS_ACCEPTED);

		$this->assertTrue(
			$this->service->handleIncoming($this->activity('Accept', self::RELAY, $relay->getFollowId()))
		);
	}

	/**
	 * Any Accept from a server that happens to be a relay would otherwise
	 * enable a subscription nobody asked for.
	 */
	public function testAnAcceptOfSomethingElseIsNotTakenAsAnAnswer(): void {
		$this->relayRequest->method('getByActorId')->willReturn($this->subscription(Relay::STATUS_PENDING));
		$this->relayRequest->expects($this->never())->method('setStatus');

		$this->assertTrue(
			$this->service->handleIncoming($this->activity('Accept', self::RELAY, 'https://relay.example/something-else'))
		);
	}

	public function testARejectIsRecordedWithItsReason(): void {
		$this->relayRequest->method('getByActorId')->willReturn($this->subscription(Relay::STATUS_PENDING));
		$this->relayRequest->expects($this->once())->method('setStatus')
			->with(self::RELAY, Relay::STATUS_REJECTED, $this->stringContains('refused'));

		$this->assertTrue($this->service->handleIncoming($this->activity('Reject', self::RELAY)));
	}

	/**
	 * A relay announces a post it does not own. Storing that as a boost would
	 * put "relay.example boosted this" in front of every post and hang it off
	 * an actor nobody follows, so the post is fetched from the server that
	 * wrote it instead.
	 */
	public function testAnAnnounceIsFetchedFromTheServerThatWroteThePost(): void {
		$this->relayRequest->method('getByActorId')->willReturn($this->subscription());
		$this->searchService->expects($this->once())->method('resolveStatus')
			->with('https://other.example/statuses/7')->willReturn(new Stream());

		$this->assertTrue(
			$this->service->handleIncoming(
				$this->activity(Announce::TYPE, self::RELAY, 'https://other.example/statuses/7')
			)
		);
	}

	/** A relay that has not accepted has not been subscribed to. */
	public function testAnAnnounceFromAPendingRelayIsNotTakenIn(): void {
		$this->relayRequest->method('getByActorId')->willReturn($this->subscription(Relay::STATUS_PENDING));
		$this->searchService->expects($this->never())->method('resolveStatus');

		$this->assertTrue(
			$this->service->handleIncoming(
				$this->activity(Announce::TYPE, self::RELAY, 'https://other.example/statuses/7')
			)
		);
	}

	public function testARelayDroppingUsIsRecordedRatherThanForgotten(): void {
		$this->relayRequest->method('getByActorId')->willReturn($this->subscription());
		$this->relayRequest->expects($this->once())->method('setStatus')
			->with(self::RELAY, Relay::STATUS_REJECTED, $this->stringContains('ended'));
		$this->relayRequest->expects($this->never())->method('delete');

		$this->assertTrue($this->service->handleIncoming($this->activity('Undo', self::RELAY)));
	}
}
