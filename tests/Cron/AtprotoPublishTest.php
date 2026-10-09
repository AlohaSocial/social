<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCA\Social\Atproto\Chat\ChatSender;
use OCA\Social\Atproto\Publisher\InteractionPublisher;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Cron\AtprotoPublish;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\BlockedBy\BlockedByService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\StreamService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Before a like, a repost or a new post's mentions reach accounts on
 * Bluesky, whether they have blocked the local account is asked; what is
 * written does not wait on the answer.
 */
#[AllowMockObjectsWithoutExpectations]
class AtprotoPublishTest extends TestCase {
	private const ALICE = 'https://social.test/@alice';
	private const BOB = 'https://bsky.app/profile/did:plc:bob';

	/** @var Publisher&MockObject */
	private Publisher $publisher;
	/** @var InteractionPublisher&MockObject */
	private InteractionPublisher $interactions;
	/** @var BlockedByService&MockObject */
	private BlockedByService $blockedBy;
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
		$this->job = new AtprotoPublish(
			$this->createMock(ITimeFactory::class), $this->publisher, $this->interactions, $streams, $actors,
			new NullLogger(), $this->createMock(ChatSender::class), $this->blockedBy,
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
}
