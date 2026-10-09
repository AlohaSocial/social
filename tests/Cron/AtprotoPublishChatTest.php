<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCA\Social\Atproto\Chat\ChatSender;
use OCA\Social\Atproto\Chat\ChatState;
use OCA\Social\Atproto\Publisher\InteractionPublisher;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Cron\AtprotoPublish;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\StreamService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * What a queued `chat` does: the person's Bluesky conversations, as
 * `ChatState` was asked.
 */
#[AllowMockObjectsWithoutExpectations]
class AtprotoPublishChatTest extends TestCase {
	public function testAChatStateIsAppliedAsQueued(): void {
		$state = $this->createMock(ChatState::class);
		$state->expects($this->once())->method('apply')->with('https://social.test/users/alice', ChatState::DECLINE, ['c1'], 'did:plc:z72i7hdynmk6r22z27h6tvur');
		$publisher = $this->createMock(Publisher::class);
		$publisher->expects($this->never())->method('publishPost');

		$this->runJob($state, $publisher, ['action' => 'chat', 'id' => 'https://social.test/users/alice', 'chat' => ChatState::DECLINE, 'convos' => ['c1', 7], 'member' => 'did:plc:z72i7hdynmk6r22z27h6tvur']);
	}

	public function testAChatServiceThatFailsIsLoggedNotThrown(): void {
		$state = $this->createMock(ChatState::class);
		$state->method('apply')->willThrowException(new \RuntimeException('down'));

		$this->runJob($state, $this->createMock(Publisher::class), ['action' => 'chat', 'id' => 'https://social.test/users/alice', 'chat' => ChatState::READ_ALL]);
		$this->addToAssertionCount(1);
	}

	private function runJob(ChatState $state, Publisher $publisher, array $argument): void {
		$job = new AtprotoPublish(
			$this->createMock(ITimeFactory::class), $publisher, $this->createMock(InteractionPublisher::class),
			$this->createMock(StreamService::class), $this->createMock(CacheActorService::class), new NullLogger(),
			$this->createMock(ChatSender::class), $state,
		);
		(new ReflectionMethod($job, 'run'))->invoke($job, $argument);
	}
}
