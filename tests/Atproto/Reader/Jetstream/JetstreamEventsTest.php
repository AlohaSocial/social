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
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Db\AtprotoWatchRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
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
		$this->actors->expects($this->exactly(2))->method('resolve')->with(self::BOB, true)->willReturn(new Person());
		$this->watches->expects($this->once())->method('setHandle')->with(self::BOB, 'bob.example');

		$this->events->handle(self::commit('delete', 'app.bsky.feed.post', '3kpost'));
		$this->events->handle(self::commit('update', 'app.bsky.actor.profile', 'self', ['displayName' => 'Bob']));
		$this->events->handle(['did' => self::BOB, 'time_us' => 1, 'kind' => 'identity', 'identity' => ['did' => self::BOB, 'handle' => 'Bob.Example']]);
		$this->assertFalse($this->events->hasPending());
	}

	public function testAnAccountNobodyFollowsIsIgnored(): void {
		$this->store->expects($this->never())->method('delete');

		$this->events->handle(self::commit('create', 'app.bsky.feed.post', '3kpost', [], 'did:plc:stranger'));
		$this->events->handle(self::commit('delete', 'app.bsky.feed.post', '3kpost', [], 'did:plc:stranger'));

		$this->assertFalse($this->events->hasPending());
	}
}
