<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Listeners;

use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Cron\AtprotoPublish;
use OCA\Social\Events\PostPublishedEvent;
use OCA\Social\Listeners\AtprotoPostListener;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class AtprotoPostListenerTest extends TestCase {
	/** @var list<array> */
	private array $queued = [];

	private function listener(bool $enabled = true): AtprotoPostListener {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn($enabled);
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('add')->willReturnCallback(function (string $class, mixed $argument): void {
			$this->assertSame(AtprotoPublish::class, $class);
			$this->queued[] = $argument;
		});

		return new AtprotoPostListener($config, $jobs);
	}

	private static function post(string $visibility): Note {
		$note = new Note();
		$note->setId('https://social.test/users/alice/statuses/' . $visibility);
		$note->setVisibility($visibility);

		return $note;
	}

	public function testAPostIsPublishedAndADirectMessageSentAsOne(): void {
		$listener = $this->listener();
		$listener->handle(new PostPublishedEvent(self::post(Stream::TYPE_PUBLIC)));
		$listener->handle(new PostPublishedEvent(self::post(Stream::TYPE_DIRECT)));

		$this->assertSame([
			['action' => 'publish', 'id' => 'https://social.test/users/alice/statuses/public'],
			['action' => 'message', 'id' => 'https://social.test/users/alice/statuses/direct'],
		], $this->queued);
	}

	public function testNothingWhileBlueskyIsOff(): void {
		$this->listener(false)->handle(new PostPublishedEvent(self::post(Stream::TYPE_DIRECT)));

		$this->assertSame([], $this->queued);
	}
}
