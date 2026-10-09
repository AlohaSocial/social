<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader\Jetstream;

use OCA\Social\Atproto\Model\Watch;
use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Atproto\Reader\FeedPoller;
use OCA\Social\Atproto\Reader\Jetstream\JetstreamEvents;
use OCA\Social\Atproto\Reader\LocalRecordResolver;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Db\AtprotoWatchRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\BlockedBy\BlockedByService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class JetstreamEventsTest extends TestCase {
	private const BOB = 'did:plc:bob';
	private const POST = 'https://bsky.app/profile/did:plc:bob/post/3kpost';

	private int $now = 1760000000;
	/** @var string[] post ids that are here */
	private array $known = [];
	private int $polls = 0;
	/** @var AtprotoWatchRequest&MockObject */
	private AtprotoWatchRequest $watches;
	/** @var PostStore&MockObject */
	private PostStore $store;
	/** @var BlueskyActorService&MockObject */
	private BlueskyActorService $actors;
	/** @var StreamRequest&MockObject */
	private StreamRequest $streams;
	private JetstreamEvents $events;

	protected function setUp(): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$this->watches = $this->createMock(AtprotoWatchRequest::class);
		$this->watches->method('getByDid')->willReturnCallback(static fn (string $did): Watch => new Watch($did, 'bob.test', '', 0, 0, 0, '', 0));
		$poller = $this->createMock(FeedPoller::class);
		$poller->method('pollWatch')->willReturnCallback(function (): int {
			$this->polls++;

			return 0;
		});
		$this->store = $this->createMock(PostStore::class);
		$this->store->method('isKnown')->willReturnCallback(fn (string $id): bool => in_array($id, $this->known, true));
		$this->streams = $this->createMock(StreamRequest::class);
		$this->actors = $this->createMock(BlueskyActorService::class);
		$this->events = new JetstreamEvents($this->watches, $poller, $this->store, $this->streams, $this->actors, $time, new NullLogger());
		$this->events->setWatched([self::BOB]);
	}

	private static function commit(string $operation, string $collection, string $rkey, array $record = [], string $did = self::BOB): array {
		return ['did' => $did, 'time_us' => 1760000000000000, 'kind' => 'commit', 'commit' => ['rev' => '3k', 'operation' => $operation, 'collection' => $collection, 'rkey' => $rkey, 'record' => $record]];
	}

	public function testANewPostIsReadAMomentLaterAsThePollerReadsIt(): void {
		$this->events->handle(self::commit('create', 'app.bsky.feed.post', '3kpost'));
		$this->events->handle(self::commit('create', 'app.bsky.feed.post', '3kother'));
		$this->assertTrue($this->events->hasPending());

		$this->events->due();
		$this->assertSame(0, $this->polls, 'not before the AppView can have it');

		$this->now += JetstreamEvents::DELAYS[0];
		$this->known = [self::POST, 'https://bsky.app/profile/did:plc:bob/post/3kother'];
		$this->events->due();
		$this->assertSame(1, $this->polls, 'one read for both');
		$this->assertFalse($this->events->hasPending());
	}

	public function testAPostTheAppViewDoesNotHaveYetIsAskedForAgainThenLeftToThePoller(): void {
		$this->events->handle(self::commit('create', 'app.bsky.feed.post', '3kpost'));
		$this->watches->expects($this->once())->method('wake')->with(self::BOB, $this->anything());

		foreach (JetstreamEvents::DELAYS as $delay) {
			$this->now += $delay;
			$this->events->due();
		}

		$this->assertSame(count(JetstreamEvents::DELAYS), $this->polls);
		$this->assertFalse($this->events->hasPending());
	}

	public function testARepostIsHereWhenItsBoostIs(): void {
		$this->streams->method('getAnnounceBy')->with(self::POST, 'https://bsky.app/profile/' . self::BOB)->willReturnOnConsecutiveCalls(
			$this->throwException(new StreamNotFoundException()),
			new Note(),
		);
		$this->events->handle(self::commit('create', 'app.bsky.feed.repost', '3krepost', ['subject' => ['uri' => 'at://did:plc:bob/app.bsky.feed.post/3kpost', 'cid' => 'bafy']]));

		$this->now += JetstreamEvents::DELAYS[0];
		$this->events->due();
		$this->assertTrue($this->events->hasPending(), 'not yet');
		$this->now += JetstreamEvents::DELAYS[1];
		$this->events->due();
		$this->assertFalse($this->events->hasPending());
	}

	public function testADeleteAProfileAndAHandleAreActedOnAtOnce(): void {
		$this->store->expects($this->once())->method('delete')->with(self::POST);
		$this->actors->expects($this->exactly(2))->method('resolve')->with(self::BOB, true)->willReturn((new Person())->setAccount('Bob.Example'));
		$this->watches->expects($this->once())->method('setHandle')->with(self::BOB, 'bob.example');

		$this->events->handle(self::commit('delete', 'app.bsky.feed.post', '3kpost'));
		$this->events->handle(self::commit('update', 'app.bsky.actor.profile', 'self', ['displayName' => 'Bob']));
		$this->events->handle(['did' => self::BOB, 'time_us' => 1, 'kind' => 'identity', 'identity' => ['did' => self::BOB, 'handle' => 'Bob.Example']]);
		$this->assertFalse($this->events->hasPending());
	}

	/** The relay passes an identity event on as the account's server sent it, unverified. */
	public function testTheHandleKeptIsTheOneTheAppViewVerifiedNotTheEvents(): void {
		$this->actors->method('resolve')->willReturnOnConsecutiveCalls(
			(new Person())->setAccount('bob.example'),
			(new Person())->setAccount('handle.invalid'),
		);
		$this->watches->expects($this->once())->method('setHandle')->with(self::BOB, 'bob.example');

		$this->events->handle(['did' => self::BOB, 'time_us' => 1, 'kind' => 'identity', 'identity' => ['did' => self::BOB, 'handle' => 'paypal.com']]);
		$this->events->handle(['did' => self::BOB, 'time_us' => 2, 'kind' => 'identity', 'identity' => ['did' => self::BOB, 'handle' => 'paypal.com']]);
	}

	public function testAnAccountNobodyFollowsIsIgnored(): void {
		$this->store->expects($this->never())->method('delete');

		$this->events->handle(self::commit('create', 'app.bsky.feed.post', '3kpost', [], 'did:plc:stranger'));
		$this->events->handle(self::commit('delete', 'app.bsky.feed.post', '3kpost', [], 'did:plc:stranger'));

		$this->assertFalse($this->events->hasPending());
	}

	/**
	 * @param list<array{0: string, 1: array}> $recorded what is recorded
	 * @param list<string> $rechecked the blockers asked about again
	 * @param list<string> $stillBlocking the local accounts each of those still blocks
	 */
	private function eventsWithBlocks(array &$recorded, array &$rechecked, array &$stillBlocking): JetstreamEvents {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$blockedBy = $this->createMock(BlockedByService::class);
		$blockedBy->method('record')->willReturnCallback(static function (string $local, array $answers) use (&$recorded): void {
			$recorded[] = [$local, $answers];
		});
		$blockedBy->method('recheck')->willReturnCallback(static function (string $actorId) use (&$rechecked, &$stillBlocking): array {
			$rechecked[] = $actorId;

			return $stillBlocking;
		});
		$local = $this->createMock(LocalRecordResolver::class);
		$local->method('actorId')->willReturnCallback(static fn (string $did): string => $did === 'did:plc:alice' ? 'https://social.test/@alice' : '');
		$events = new JetstreamEvents($this->watches, $this->createMock(FeedPoller::class), $this->store, $this->streams, $this->actors, $time, new NullLogger(), $blockedBy, $local);
		$events->setWatched([self::BOB]);

		return $events;
	}

	public function testABlockOfALocalAccountIsRecordedAtOnceAndOneOfAnybodyElseIsNot(): void {
		$recorded = $rechecked = $still = [];
		$events = $this->eventsWithBlocks($recorded, $rechecked, $still);

		$events->handle(self::commit('create', 'app.bsky.graph.block', '3kblock', ['$type' => 'app.bsky.graph.block', 'subject' => 'did:plc:alice', 'createdAt' => '2026-10-09T00:00:00Z']));
		$events->handle(self::commit('create', 'app.bsky.graph.block', '3kother', ['$type' => 'app.bsky.graph.block', 'subject' => 'did:plc:somebody', 'createdAt' => '2026-10-09T00:00:00Z']));

		$this->assertSame([['https://social.test/@alice', ['https://bsky.app/profile/did:plc:bob' => true]]], $recorded);
		$this->assertFalse($events->hasPending());
	}

	public function testAWithdrawnBlockIsAskedAboutAgainUntilTheAppViewKnows(): void {
		$recorded = $rechecked = [];
		$still = ['https://social.test/@alice'];
		$events = $this->eventsWithBlocks($recorded, $rechecked, $still);

		$events->handle(self::commit('delete', 'app.bsky.graph.block', '3kblock'));
		$this->assertTrue($events->hasPending());
		$events->due();
		$this->assertSame([], $rechecked, 'not before the AppView can know');

		$this->now += JetstreamEvents::DELAYS[0];
		$events->due();
		$this->assertSame(['https://bsky.app/profile/did:plc:bob'], $rechecked);
		$this->assertTrue($events->hasPending(), 'the AppView still said so');

		$still = [];
		$this->now += JetstreamEvents::DELAYS[1];
		$events->due();
		$this->assertCount(2, $rechecked);
		$this->assertFalse($events->hasPending());
		$this->assertSame([], $recorded, 'the recheck records what it finds');
	}
}
