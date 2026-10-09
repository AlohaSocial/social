<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Db\ConversationsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\HiddenReplyService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class HiddenReplyServiceTest extends TestCase {
	private const ALICE = 'https://social.test/@alice';

	public function testTheAuthorOfAThreadHidesAnyReplyInItAndBlueskyIsTold(): void {
		$root = (new Note())->setId(self::ALICE . '/1')->setAttributedTo(self::ALICE)->setLocal(true);
		$reply = (new Note())->setId('https://bsky.app/profile/did:plc:bob/post/3k')->setAttributedTo('https://bsky.app/profile/did:plc:bob');
		$streams = $this->createMock(StreamRequest::class);
		$streams->method('getStreamById')->willReturn($root);
		$streams->expects($this->exactly(2))->method('updateDetails')->with($root);
		$conversations = $this->createMock(ConversationsRequest::class);
		$conversations->method('rootOf')->willReturnCallback(static fn (string $id): string => $root->getId());
		$publisher = $this->createMock(Publisher::class);
		$publisher->expects($this->exactly(2))->method('updateGates')->with($root);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($publisher);
		$service = new HiddenReplyService($streams, $conversations, new NullLogger(), $container);
		$alice = (new Person())->setId(self::ALICE);

		$service->setHidden($alice, $reply, true);
		$this->assertSame([$reply->getId()], $root->getHiddenReplies());
		$service->setHidden($alice, $reply, false);
		$this->assertSame([], $root->getHiddenReplies());

		$this->expectException(InvalidResourceException::class);
		$service->setHidden((new Person())->setId('https://social.test/@bob'), $reply, true);
	}

	public function testTheFirstPostIsNotAReplyToHide(): void {
		$root = (new Note())->setId(self::ALICE . '/1')->setAttributedTo(self::ALICE);
		$conversations = $this->createMock(ConversationsRequest::class);
		$conversations->method('rootOf')->willReturn($root->getId());
		$service = new HiddenReplyService($this->createMock(StreamRequest::class), $conversations, new NullLogger());

		$this->expectException(InvalidResourceException::class);
		$service->setHidden((new Person())->setId(self::ALICE), $root, true);
	}
}
