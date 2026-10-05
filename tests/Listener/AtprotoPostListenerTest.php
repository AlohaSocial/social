<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Listener;

use OCA\Social\Cron\AtprotoMirrorJob;
use OCA\Social\Events\PostPublishedEvent;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Listeners\AtprotoPostListener;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\Atproto\AtprotoEgress;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class AtprotoPostListenerTest extends TestCase {
	public function testPdsFailureIsQueuedWithAJsonSafeSnapshot(): void {
		$egress = $this->createMock(AtprotoEgress::class);
		$egress->expects($this->once())->method('publish')
			->willThrowException(new AtprotoException('PDS unavailable', 503));
		$logger = $this->createMock(LoggerInterface::class);
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->once())->method('add')->with(
			AtprotoMirrorJob::class,
			$this->callback(static function (array $argument): bool {
				return $argument['operation'] === 'publish'
					&& is_array($argument['post'])
					&& !array_filter($argument['post'], static fn (mixed $value): bool => is_object($value));
			}),
		);

		$post = (new Stream())
			->setId('https://cloud.example/apps/social/@alice/posts/1')
			->setAttributedTo('https://cloud.example/apps/social/@alice')
			->setContent('<p>Hello</p>')
			->setVisibility(Stream::TYPE_PUBLIC);
		$listener = new AtprotoPostListener($egress, $logger, $jobList);

		$listener->handle(new PostPublishedEvent($post));
	}
}
