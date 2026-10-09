<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service\FollowList;

use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\FollowList\ActivityPubFollowListSource;
use OCA\Social\Service\FollowList\FollowListService;
use OCA\Social\Service\RemoteFetchQueue;
use OCA\Social\Tools\Exceptions\RequestContentException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class ActivityPubFollowListSourceTest extends TestCase {
	private const FOLLOWERS = 'https://remote.example/users/bob/followers';

	private CurlService&MockObject $curl;
	private RemoteFetchQueue&MockObject $queue;
	private ActivityPubFollowListSource $source;
	private Person $bob;

	protected function setUp(): void {
		$this->curl = $this->createMock(CurlService::class);
		$cacheActors = $this->createMock(CacheActorService::class);
		$cacheActors->method('getCachedFromIds')->willReturnCallback(static fn (array $ids): array => in_array('https://a.example/users/ann', $ids, true) ? ['https://a.example/users/ann' => new Person()] : []);
		$this->queue = $this->createMock(RemoteFetchQueue::class);
		$this->source = new ActivityPubFollowListSource($this->curl, $cacheActors, $this->queue, new NullLogger());
		$this->bob = (new Person())->setId('https://remote.example/users/bob');
		$this->bob->setFollowers(self::FOLLOWERS);
		$this->bob->setFollowing('https://remote.example/users/bob/following');
	}

	/** @param array<string, array|\Throwable> $documents */
	private function serve(array $documents): void {
		$this->curl->method('retrieveObject')->willReturnCallback(static function (string $url) use ($documents): array {
			$document = $documents[$url] ?? throw new \RuntimeException('not served: ' . $url);
			if ($document instanceof \Throwable) {
				throw $document;
			}

			return $document;
		});
	}

	public function testThePagesOfTheCollectionAreReadAndTheUnknownAccountsFetched(): void {
		$this->serve([
			self::FOLLOWERS => ['type' => 'OrderedCollection', 'totalItems' => 5, 'first' => self::FOLLOWERS . '?page=1'],
			self::FOLLOWERS . '?page=1' => ['type' => 'OrderedCollectionPage', 'orderedItems' => [
				'https://a.example/users/ann',
				['id' => 'https://b.example/users/ben', 'type' => 'Person'],
				'https://c.example/users/cy#main-key',
				'javascript:alert(1)',
				'https://remote.example/users/bob',
			], 'next' => self::FOLLOWERS . '?page=2'],
			self::FOLLOWERS . '?page=2' => ['type' => 'OrderedCollectionPage', 'orderedItems' => ['https://d.example/users/dee', 'https://a.example/users/ann']],
		]);
		$this->queue->expects($this->once())->method('resolveActors')
			->with(['https://b.example/users/ben', 'https://c.example/users/cy', 'https://d.example/users/dee']);

		$this->assertTrue($this->source->supports($this->bob));
		$this->assertSame(
			['https://a.example/users/ann', 'https://b.example/users/ben', 'https://c.example/users/cy', 'https://d.example/users/dee'],
			$this->source->accounts($this->bob, FollowListService::FOLLOWERS, 10),
		);
	}

	public function testAFewPagesAndNoMore(): void {
		$documents = ['https://remote.example/users/bob/following' => ['first' => 'https://remote.example/p/1']];
		for ($page = 1; $page < 10; $page++) {
			$documents['https://remote.example/p/' . $page] = ['orderedItems' => ['https://x.example/users/u' . $page], 'next' => 'https://remote.example/p/' . ($page + 1)];
		}
		$this->serve($documents);
		$this->curl->expects($this->exactly(4))->method('retrieveObject');

		$this->assertCount(3, $this->source->accounts($this->bob, FollowListService::FOLLOWING, 100));
	}

	public function testAnInlineFirstPageIsReadWhereItIs(): void {
		$this->serve([self::FOLLOWERS => ['first' => ['type' => 'OrderedCollectionPage', 'orderedItems' => ['https://a.example/users/ann']]]]);

		$this->assertSame(['https://a.example/users/ann'], $this->source->accounts($this->bob, FollowListService::FOLLOWERS, 10));
	}

	public function testOnlyTheNumberIsAHiddenList(): void {
		$this->serve([self::FOLLOWERS => ['type' => 'OrderedCollection', 'totalItems' => 120]]);

		$this->assertNull($this->source->accounts($this->bob, FollowListService::FOLLOWERS, 10));
	}

	public function testAPageRefusedIsAHiddenListAsMastodonHidesIt(): void {
		$this->serve([
			self::FOLLOWERS => ['type' => 'OrderedCollection', 'first' => self::FOLLOWERS . '?page=1'],
			self::FOLLOWERS . '?page=1' => new RequestContentException(self::FOLLOWERS . '?page=1', 403),
		]);

		$this->assertNull($this->source->accounts($this->bob, FollowListService::FOLLOWERS, 10));
	}

	public function testACollectionThatCannotBeReadListsNobody(): void {
		$this->serve([self::FOLLOWERS => new RequestContentException(self::FOLLOWERS, 403)]);
		$this->assertSame([], $this->source->accounts($this->bob, FollowListService::FOLLOWERS, 10), 'the account, not its list, is what was refused');

		$this->bob->setFollowers('');
		$this->assertSame([], $this->source->accounts($this->bob, FollowListService::FOLLOWERS, 10));
	}

	public function testOnlyAnAccountOnAnotherFediverseServer(): void {
		$this->assertFalse($this->source->supports((new Person())->setId('https://social.test/@alice')->setLocal(true)));
		$this->assertFalse($this->source->supports((new Person())->setId('https://bsky.app/profile/did:plc:carol')));
	}
}
