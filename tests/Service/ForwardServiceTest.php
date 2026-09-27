<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\InstancePath;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\ForwardService;
use OCA\Social\Service\RequestQueueService;
use OCA\Social\Service\SignatureService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Inbox forwarding (ActivityPub §7.1.2).
 *
 * Everything here turns on one question: does a reply that reached us reach
 * the people who follow the post it replies to? Each test names one reason it
 * should or should not.
 */
#[AllowMockObjectsWithoutExpectations]
class ForwardServiceTest extends TestCase {
	private const LOCAL_URL = 'https://cloud.example';
	private const ALICE = self::LOCAL_URL . '/users/alice';
	private const PARENT = self::LOCAL_URL . '/notes/parent';
	private const REPLY = 'https://remote.example/notes/reply';
	private const ACTIVITY = 'https://remote.example/notes/reply/activity';
	private const SOURCE = '{"type":"Create","signature":{"type":"RsaSignature2017"}}';

	private ActorsRequest|Stub $actorsRequest;
	private FollowsRequest|Stub $followsRequest;
	private StreamRequest|MockObject $streamRequest;
	private RequestQueueService|MockObject $requestQueueService;
	private CurlService|MockObject $curlService;
	private ForwardService $service;

	/** @var array<string, mixed> in-memory stand-in for the distributed cache */
	private array $cached = [];

	protected function setUp(): void {
		$this->actorsRequest = $this->createStub(ActorsRequest::class);
		$this->followsRequest = $this->createStub(FollowsRequest::class);
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->requestQueueService = $this->createMock(RequestQueueService::class);
		$this->curlService = $this->createMock(CurlService::class);

		$cache = $this->createStub(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key) => $this->cached[$key] ?? null);
		$cache->method('set')->willReturnCallback(function (string $key, $value) {
			$this->cached[$key] = $value;

			return true;
		});
		$cacheFactory = $this->createStub(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$configService = $this->createStub(ConfigService::class);
		$configService->method('getCloudUrl')->willReturn(self::LOCAL_URL);

		$this->service = new ForwardService(
			$this->actorsRequest,
			$this->followsRequest,
			$this->streamRequest,
			$this->requestQueueService,
			$this->curlService,
			$configService,
			new NullLogger(),
			$cacheFactory,
		);
	}

	// fixtures

	/** The Create as the inbox leaves it: signed by its author, source intact. */
	private function activity(int $originSource = SignatureService::ORIGIN_SIGNATURE): ACore {
		$activity = new Create();
		$activity->setId(self::ACTIVITY);
		$activity->setSource(self::SOURCE);
		$activity->setOrigin('remote.example', $originSource, time());

		return $activity;
	}

	private function reply(string $inReplyTo = self::PARENT, string $visibility = Stream::TYPE_PUBLIC): Note {
		$note = new Note();
		$note->setId(self::REPLY);
		$note->setAttributedTo('https://remote.example/users/bob');
		$note->setInReplyTo($inReplyTo);
		$note->setVisibility($visibility);

		return $note;
	}

	private function parent(bool $local = true, string $visibility = Stream::TYPE_PUBLIC): Stream {
		$parent = new Note();
		$parent->setId(self::PARENT);
		$parent->setAttributedTo(self::ALICE);
		$parent->setVisibility($visibility);
		$parent->setLocal($local);

		return $parent;
	}

	private function alice(): Person {
		$alice = new Person();
		$alice->setId(self::ALICE);
		$alice->setLocal(true);

		return $alice;
	}

	/**
	 * Everything lined up for a forward: local public parent, and the inboxes
	 * the database names for the author's followers.
	 *
	 * Deduplication and the shared-inbox fallback are the database's job now —
	 * see FollowsRequest::getFollowerInboxes() and its integration test — so
	 * these are the inboxes as they come back from it.
	 *
	 * @param string[] $inboxes
	 */
	private function expectAForwardablePost(array $inboxes): void {
		$this->streamRequest->method('getStreamById')->willReturn($this->parent());
		$this->actorsRequest->method('getFromId')->willReturn($this->alice());
		$this->followsRequest->method('getFollowerInboxes')->willReturn($inboxes);
	}

	/** Nothing may be queued and nothing delivered. */
	private function expectNothingForwarded(): void {
		$this->requestQueueService->expects($this->never())->method('generateRequestQueueFromSource');
		$this->curlService->expects($this->never())->method('asyncWithToken');
	}

	// what gets forwarded

	public function testAReplyToALocalPostReachesThatPostsFollowers(): void {
		$this->expectAForwardablePost([
			'https://a.example/inbox',
			'https://b.example/inbox',
		]);

		$paths = null;
		$body = null;
		$author = null;
		$this->requestQueueService->expects($this->once())
			->method('generateRequestQueueFromSource')
			->willReturnCallback(function (array $p, string $b, string $a) use (&$paths, &$body, &$author): string {
				$paths = $p;
				$body = $b;
				$author = $a;

				return 'token-1';
			});
		$this->curlService->expects($this->once())->method('asyncWithToken')->with('token-1');

		$this->service->forwardReply($this->activity(), $this->reply());

		$this->assertSame(
			['https://a.example/inbox', 'https://b.example/inbox'],
			array_map(fn (InstancePath $path): string => $path->getUri(), $paths)
		);
		// the bytes that arrived, so the author's signature still covers them
		$this->assertSame(self::SOURCE, $body);
		// signed as the local author, whose thread this is
		$this->assertSame(self::ALICE, $author);
	}

	public function testForwardingIsLeftToTheBackgroundQueue(): void {
		$this->expectAForwardablePost(['https://a.example/inbox']);

		$paths = null;
		$this->requestQueueService->method('generateRequestQueueFromSource')
			->willReturnCallback(function (array $p) use (&$paths): string {
				$paths = $p;

				return 'token-1';
			});

		$this->service->forwardReply($this->activity(), $this->reply());

		// nothing about delivering to strangers may hold up the inbox response
		$this->assertSame(InstancePath::PRIORITY_LOW, $paths[0]->getPriority());
	}

	public function testAFollowerWithoutASharedInboxIsReachedAtItsOwn(): void {
		// what getFollowerInboxes() returns for a follower whose instance
		// publishes no endpoints.sharedInbox
		$this->expectAForwardablePost(['https://a.example/users/one/inbox']);

		$paths = null;
		$this->requestQueueService->method('generateRequestQueueFromSource')
			->willReturnCallback(function (array $p) use (&$paths): string {
				$paths = $p;

				return 'token-1';
			});

		$this->service->forwardReply($this->activity(), $this->reply());

		$this->assertSame(
			['https://a.example/users/one/inbox'],
			array_map(fn (InstancePath $path): string => $path->getUri(), $paths)
		);
	}

	/** Two followers on one instance are one shared inbox and one delivery. */
	public function testEachInstanceIsSentTheReplyOnce(): void {
		$this->expectAForwardablePost(['https://a.example/inbox']);

		$paths = null;
		$this->requestQueueService->method('generateRequestQueueFromSource')
			->willReturnCallback(function (array $p) use (&$paths): string {
				$paths = $p;

				return 'token-1';
			});

		$this->service->forwardReply($this->activity(), $this->reply());

		$this->assertCount(1, $paths);
	}

	public function testTheSenderAndOurselvesAreLeftOut(): void {
		$this->expectAForwardablePost([
			// the instance that just delivered it to us
			'https://remote.example/inbox',
			// a follower on this very instance, who already has the note
			self::LOCAL_URL . '/inbox',
			'https://a.example/inbox',
		]);

		$paths = null;
		$this->requestQueueService->method('generateRequestQueueFromSource')
			->willReturnCallback(function (array $p) use (&$paths): string {
				$paths = $p;

				return 'token-1';
			});

		$this->service->forwardReply($this->activity(), $this->reply());

		$this->assertSame(
			['https://a.example/inbox'],
			array_map(fn (InstancePath $path): string => $path->getUri(), $paths)
		);
	}

	// what does not

	public function testAnUnsignedActivityIsNotPassedOn(): void {
		$this->expectAForwardablePost(['https://a.example/inbox']);
		$this->expectNothingForwarded();

		// verified by the HTTP signature alone: nobody downstream could check it
		$this->service->forwardReply(
			$this->activity(SignatureService::ORIGIN_HEADER), $this->reply()
		);
	}

	public function testAnActivityWithoutItsSourceIsNotPassedOn(): void {
		$this->expectAForwardablePost(['https://a.example/inbox']);
		$this->expectNothingForwarded();

		$activity = $this->activity();
		$activity->setSource('');

		$this->service->forwardReply($activity, $this->reply());
	}

	public function testAPostThatIsNotAReplyIsNotPassedOn(): void {
		$this->expectNothingForwarded();
		$this->streamRequest->expects($this->never())->method('getStreamById');

		$this->service->forwardReply($this->activity(), $this->reply(''));
	}

	public function testAReplyToSomebodyElsesPostIsNotPassedOn(): void {
		$this->streamRequest->method('getStreamById')->willReturn($this->parent(false));
		$this->expectNothingForwarded();

		// the instance holding the post owes its followers the thread, not us
		$this->service->forwardReply($this->activity(), $this->reply());
	}

	public function testAReplyToAPostWeNeverSawIsNotPassedOn(): void {
		$this->streamRequest->method('getStreamById')
			->willThrowException(new StreamNotFoundException());
		$this->expectNothingForwarded();

		$this->service->forwardReply($this->activity(), $this->reply());
	}

	#[DataProvider('privateVisibilityProvider')]
	public function testAPrivatePostsThreadStaysWhereItIs(string $visibility): void {
		$this->streamRequest->method('getStreamById')->willReturn($this->parent(true, $visibility));
		$this->actorsRequest->method('getFromId')->willReturn($this->alice());
		// followers the post would reach if visibility were the only thing wrong
		$this->followsRequest->method('getFollowersByActorId')
			->willReturn(['https://a.example/inbox']);
		$this->expectNothingForwarded();

		$this->service->forwardReply($this->activity(), $this->reply());
	}

	#[DataProvider('privateVisibilityProvider')]
	public function testAPrivateReplyIsNotPassedOn(string $visibility): void {
		$this->expectAForwardablePost(['https://a.example/inbox']);
		$this->expectNothingForwarded();

		$this->service->forwardReply($this->activity(), $this->reply(self::PARENT, $visibility));
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function privateVisibilityProvider(): array {
		return [
			'followers-only' => [Stream::TYPE_FOLLOWERS],
			'direct' => [Stream::TYPE_DIRECT],
		];
	}

	public function testAReplyToAPostWhoseAuthorIsNotOursIsNotPassedOn(): void {
		$this->streamRequest->method('getStreamById')->willReturn($this->parent());
		$this->actorsRequest->method('getFromId')
			->willThrowException(new ActorDoesNotExistException());
		$this->expectNothingForwarded();

		$this->service->forwardReply($this->activity(), $this->reply());
	}

	public function testAPostWithNoFollowersElsewhereQueuesNothing(): void {
		$this->expectAForwardablePost([]);
		$this->expectNothingForwarded();

		$this->service->forwardReply($this->activity(), $this->reply());
	}

	public function testTheSameActivityIsForwardedOnlyOnce(): void {
		$this->expectAForwardablePost(['https://a.example/inbox']);

		$this->requestQueueService->expects($this->once())
			->method('generateRequestQueueFromSource')
			->willReturn('token-1');

		// two deliveries of the same activity racing each other
		$this->service->forwardReply($this->activity(), $this->reply());
		$this->service->forwardReply($this->activity(), $this->reply());
	}
}
