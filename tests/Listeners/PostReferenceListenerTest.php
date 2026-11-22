<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Listeners;

use OCA\Social\Events\PostDeletedEvent;
use OCA\Social\Events\PostEditedEvent;
use OCA\Social\Events\PostPublishedEvent;
use OCA\Social\Listeners\PostReferenceListener;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\PostReferenceCache;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PostReferenceListenerTest extends TestCase {
	public function testAnEditedOrDeletedPostLosesItsCachedPreview(): void {
		$edited = new Note();
		$deleted = new Note();
		$cache = $this->createMock(PostReferenceCache::class);
		$forgotten = [];
		$cache->expects($this->exactly(2))->method('forgetPost')
			->willReturnCallback(function (Note $post) use (&$forgotten): void {
				$forgotten[] = $post;
			});
		$listener = new PostReferenceListener($cache, $this->createStub(LoggerInterface::class));

		$listener->handle(new PostEditedEvent($edited));
		$listener->handle(new PostDeletedEvent($deleted));
		$listener->handle(new PostPublishedEvent(new Note()));

		$this->assertSame([$edited, $deleted], $forgotten);
	}

	public function testAFailureIsLoggedAndGoesNoFurther(): void {
		$cache = $this->createStub(PostReferenceCache::class);
		$cache->method('forgetPost')->willThrowException(new \RuntimeException('cache down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		(new PostReferenceListener($cache, $logger))->handle(new PostDeletedEvent(new Note()));
	}
}
