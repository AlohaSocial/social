<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCA\Social\Atproto\Chat\ChatSender;
use OCA\Social\Atproto\Chat\ChatState;
use OCA\Social\Atproto\Lexicon\LexiconException;
use OCA\Social\Atproto\Publisher\BlueskyBookmarks;
use OCA\Social\Atproto\Publisher\InteractionPublisher;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Cron\AtprotoPublish;
use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\Backoff;
use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Exceptions\AppViewNotFoundException;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\BlockedBy\BlockedByService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\StreamService;
use OCA\Social\Service\VerificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Before a like, a repost or a new post's mentions reach accounts on
 * Bluesky, whether they have blocked the local account is asked; what is
 * written does not wait on the answer. A failure that may pass is tried
 * again later, up to the last attempt; one that will not is left.
 */
#[AllowMockObjectsWithoutExpectations]
class AtprotoPublishTest extends TestCase {
	private const ALICE = 'https://social.test/@alice';
	private const BOB = 'https://bsky.app/profile/did:plc:bob';
	private const NOW = 1760000000;
	private const GONE = 'https://social.test/announce/gone';

	/** @var Publisher&MockObject */
	private Publisher $publisher;
	/** @var InteractionPublisher&MockObject */
	private InteractionPublisher $interactions;
	/** @var BlockedByService&MockObject */
	private BlockedByService $blockedBy;
	/** @var ActionsRequest&MockObject */
	private ActionsRequest $actions;
	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;
	/** @var list<array{0: int, 1: array}> when and with what the job was queued again */
	private array $requeued = [];
	private Note $post;
	private AtprotoPublish $job;

	protected function setUp(): void {
		$this->publisher = $this->createMock(Publisher::class);
		$this->interactions = $this->createMock(InteractionPublisher::class);
		$this->blockedBy = $this->createMock(BlockedByService::class);
		$this->post = new Note();
		$this->post->setId('https://social.test/@alice/1');
		$this->post->setAttributedTo(self::ALICE);
		$streams = $this->createMock(StreamService::class);
		$streams->method('getStreamById')->willReturnCallback(function (string $id): Note {
			if ($id === self::GONE) {
				throw new StreamNotFoundException('Stream not found');
			}
			if ($id === $this->post->getId()) {
				return $this->post;
			}
			$theirs = new Note();
			$theirs->setId($id);
			$theirs->setAttributedTo(self::BOB);

			return $theirs;
		});
		$actors = $this->createMock(CacheActorService::class);
		$actors->method('getFromId')->willReturnCallback(static fn (string $id): Person => (new Person())->setId($id));
		$this->actions = $this->createMock(ActionsRequest::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('scheduleAfter')->willReturnCallback(function (string $job, int $runAfter, array $argument): void {
			$this->assertSame(AtprotoPublish::class, $job);
			$this->requeued[] = [$runAfter, $argument];
		});
		$this->job = new AtprotoPublish(
			$time, $this->publisher, $this->interactions, $streams, $actors,
			$this->logger, $this->createMock(ChatSender::class), $this->createMock(ChatState::class), $this->createMock(VerificationService::class),
			$this->actions, $jobs, $this->createMock(BlueskyBookmarks::class), $this->blockedBy,
		);
	}

	private function runJob(array $argument): void {
		(new \ReflectionMethod(AtprotoPublish::class, 'run'))->invoke($this->job, $argument);
	}

	public function testALikeAsksWhetherThePostsAuthorHasBlockedTheLiker(): void {
		$this->blockedBy->expects($this->once())->method('ask')->with(self::ALICE, [self::BOB]);
		$this->interactions->expects($this->once())->method('like');

		$this->runJob(['action' => 'like', 'id' => 'https://social.test/like/1', 'post' => self::BOB . '/post/3k', 'actor' => self::ALICE]);
	}

	public function testANewPostAsksAboutTheAccountsItMentions(): void {
		$this->post->setTags([
			['type' => 'Mention', 'href' => self::BOB, 'name' => '@bob.test'],
			['type' => 'Hashtag', 'href' => 'https://social.test/tags/cats', 'name' => '#cats'],
		]);
		$this->blockedBy->expects($this->once())->method('ask')->with(self::ALICE, [self::BOB]);
		$this->publisher->expects($this->once())->method('publishPost')->with($this->post);

		$this->runJob(['action' => 'publish', 'id' => $this->post->getId()]);
	}

	public function testAQuestionThatFailsHoldsNothingBack(): void {
		$this->blockedBy->method('ask')->willThrowException(new RuntimeException('database gone'));
		$this->interactions->expects($this->once())->method('repost');

		$this->runJob(['action' => 'repost', 'id' => 'https://social.test/announce/1', 'post' => self::BOB . '/post/3k', 'actor' => self::ALICE]);
	}

	public function testAnEditAsksNobody(): void {
		$this->blockedBy->expects($this->never())->method('ask');
		$this->publisher->expects($this->once())->method('editPost');

		$this->runJob(['action' => 'edit', 'id' => $this->post->getId()]);
	}

	public function testAFailureThatMayPassIsTriedAgainAfterTheFirstWait(): void {
		$this->interactions->method('unlike')->willThrowException(new AtprotoException('Repository write failed: lock wait timeout'));

		$this->runJob(['action' => 'unlike', 'id' => 'https://social.test/like/1']);

		$this->assertSame([[self::NOW + Backoff::bluesky()->delay(1), ['tries' => 1, 'action' => 'unlike', 'id' => 'https://social.test/like/1']]], $this->requeued);
	}

	public function testABlueskyBookmarkSyncFailureIsRetried(): void {
		$bookmarks = $this->createMock(BlueskyBookmarks::class);
		$bookmarks->expects($this->once())->method('syncCurrent')->with($this->isInstanceOf(Person::class), $this->post->getId())
			->willThrowException(new AtprotoException('AppView answered 503: unavailable', 503));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->once())->method('scheduleAfter')->willReturnCallback(function (string $job, int $runAfter, array $argument): void {
			$this->assertSame(AtprotoPublish::class, $job);
			$this->assertSame(self::NOW + Backoff::bluesky()->delay(1), $runAfter);
			$this->assertSame(['tries' => 1, 'action' => 'bookmark', 'id' => $this->post->getId(), 'actor' => self::ALICE], $argument);
		});
		$this->job = new AtprotoPublish(
			$time, $this->publisher, $this->interactions, $this->createMock(StreamService::class),
			$this->createMock(CacheActorService::class), $this->logger, $this->createMock(ChatSender::class),
			$this->createMock(ChatState::class), $this->createMock(VerificationService::class), $this->actions,
			$jobs, $bookmarks, $this->blockedBy,
		);

		$this->runJob(['action' => 'bookmark', 'id' => $this->post->getId(), 'actor' => self::ALICE]);
	}

	public function testEachAttemptWaitsLongerThanTheOneBefore(): void {
		$this->publisher->method('publishProfile')->willThrowException(new AtprotoException('AppView answered 503 for getProfile: down', 503));

		$this->runJob(['action' => 'profile', 'id' => self::ALICE, 'tries' => 3]);

		$this->assertSame([[self::NOW + Backoff::bluesky()->delay(4), ['tries' => 4, 'action' => 'profile', 'id' => self::ALICE]]], $this->requeued);
		$this->assertGreaterThan(Backoff::bluesky()->delay(3), Backoff::bluesky()->delay(4));
	}

	public function testTooManyRequestsIsTriedAgain(): void {
		$this->publisher->method('deletePost')->willThrowException(new AtprotoException('AppView answered 429 for x: slow down', 429));

		$this->runJob(['action' => 'delete', 'id' => $this->post->getId()]);

		$this->assertCount(1, $this->requeued);
	}

	public function testADeleteWhoseLikesWereNotAllRemovedIsTriedAgain(): void {
		$this->interactions->method('removeAllOf')->willThrowException(new AtprotoException('Repository write failed: lock wait timeout'));

		$this->runJob(['action' => 'delete', 'id' => $this->post->getId()]);

		$this->assertSame([[self::NOW + Backoff::bluesky()->delay(1), ['tries' => 1, 'action' => 'delete', 'id' => $this->post->getId()]]], $this->requeued);
	}

	public function testTheLastAttemptGivesUpAndSaysSo(): void {
		$this->publisher->method('publishPost')->willThrowException(new RuntimeException('database gone'));
		$this->logger->expects($this->once())->method('error')->with('Bluesky publication given up after the last attempt', $this->callback(
			static fn (array $context): bool => $context['tries'] === Backoff::BLUESKY_MAX_TRIES && $context['post'] === 'https://social.test/@alice/1',
		));

		$this->runJob(['action' => 'publish', 'id' => $this->post->getId(), 'tries' => Backoff::BLUESKY_MAX_TRIES - 1]);

		$this->assertSame([], $this->requeued);
	}

	public static function permanentFailures(): array {
		return [
			'the record is gone on Bluesky' => [new AppViewNotFoundException('getConvo: not found', 'NotFound')],
			'a request Bluesky refuses as such' => [new AtprotoException('AppView answered 400 for sendMessage: too long', 400)],
			'the post is gone here' => [new StreamNotFoundException('Stream not found')],
			'a record that does not fit its lexicon' => [new AtprotoException('Record does not fit its lexicon', 0, new LexiconException('text too long'))],
		];
	}

	#[DataProvider('permanentFailures')]
	public function testAFailureNoAttemptWouldChangeIsNotTriedAgain(Throwable $failure): void {
		$this->publisher->method('editPost')->willThrowException($failure);
		$this->logger->expects($this->once())->method('warning')->with('Bluesky publication failed');

		$this->runJob(['action' => 'edit', 'id' => $this->post->getId()]);

		$this->assertSame([], $this->requeued);
	}

	public function testALikeUndoneBeforeTheJobRunsWritesNothing(): void {
		$this->actions->method('getById')->willThrowException(new ActionDoesNotExistException('gone'));
		$this->interactions->expects($this->never())->method('like');

		$this->runJob(['action' => 'like', 'id' => 'https://social.test/like/1', 'post' => self::BOB . '/post/3k', 'actor' => self::ALICE, 'tries' => 2]);

		$this->assertSame([], $this->requeued);
	}

	public function testARepostUndoneBeforeTheJobRunsWritesNothing(): void {
		$this->interactions->expects($this->never())->method('repost');

		$this->runJob(['action' => 'repost', 'id' => self::GONE, 'post' => self::BOB . '/post/3k', 'actor' => self::ALICE]);

		$this->assertSame([], $this->requeued);
	}
}
