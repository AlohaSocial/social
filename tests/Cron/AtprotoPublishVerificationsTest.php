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
use OCA\Social\Service\VerificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * The verifications moved to another verifying account, off the request
 * that chose it.
 */
class AtprotoPublishVerificationsTest extends TestCase {
	private function runJob(array $argument, VerificationService $verifications): void {
		$job = new AtprotoPublish(
			$this->createStub(ITimeFactory::class),
			$this->createStub(Publisher::class),
			$this->createStub(InteractionPublisher::class),
			$this->createStub(StreamService::class),
			$this->createStub(CacheActorService::class),
			new NullLogger(),
			$this->createStub(ChatSender::class),
			$this->createStub(ChatState::class),
			$verifications,
		);
		(new ReflectionMethod(AtprotoPublish::class, 'run'))->invoke($job, $argument);
	}

	public function testTheVerificationsArePublishedAgain(): void {
		$verifications = $this->createMock(VerificationService::class);
		$verifications->expects($this->once())->method('republishAll')->willReturn(3);

		$this->runJob(['action' => 'verifications', 'id' => 'all'], $verifications);
	}

	public function testAFailureIsLoggedAndTheJobEnds(): void {
		$verifications = $this->createMock(VerificationService::class);
		$verifications->expects($this->once())->method('republishAll')->willThrowException(new \RuntimeException('the repository is gone'));

		$this->runJob(['action' => 'verifications', 'id' => 'all'], $verifications);
	}
}
