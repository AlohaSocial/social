<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\DetailsService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\StreamService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class DetailsServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example.com/apps/social/@alice';
	private const ALICE_FOLLOWERS = 'https://cloud.example.com/apps/social/@alice/followers';
	private const BOB = 'https://cloud.example.com/apps/social/@bob';

	private StreamService|MockObject $streamService;
	private AccountService|MockObject $accountService;
	private FollowService|MockObject $followService;
	private DetailsService $service;

	protected function setUp(): void {
		$this->streamService = $this->createMock(StreamService::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->followService = $this->createMock(FollowService::class);
		$this->service = new DetailsService(
			$this->streamService,
			$this->accountService,
			$this->followService,
		);
	}

	private function person(string $id): Person {
		$person = new Person();
		$person->setId($id);

		return $person;
	}

	private function follower(string $actorId): Follow {
		$follow = new Follow();
		$follow->setActor($this->person($actorId));

		return $follow;
	}

	public function testPublicLocalPostIsFlaggedPublicAndReachesFollowers(): void {
		$stream = new Note();
		$stream->setId('https://cloud.example.com/apps/social/@alice/1');
		$stream->setLocal(true);
		$stream->setTimeline(Stream::TYPE_PUBLIC);
		$stream->setTo(ACore::CONTEXT_PUBLIC);
		$stream->setCcArray([self::ALICE_FOLLOWERS]);
		$this->streamService->expects($this->once())->method('detectType')->with($this->identicalTo($stream));
		$this->accountService->method('getFromId')
			->with(self::ALICE_FOLLOWERS)
			->willThrowException(new ActorDoesNotExistException());
		$this->followService->expects($this->once())
			->method('getFollowersFromFollowId')
			->with(self::ALICE_FOLLOWERS)
			->willReturn([$this->follower(self::BOB), new Follow()]);

		$details = $this->service->generateDetailsFromStream($stream);

		$this->assertSame($stream, $details->getStream());
		$this->assertTrue($details->isPublic());
		$this->assertFalse($details->isFederated());
		$this->assertSame([], $details->getDirectViewers());
		$this->assertCount(1, $details->getHomeViewers(), 'follows without a loaded actor are skipped');
		$this->assertSame(self::BOB, $details->getHomeViewers()[0]->getId());
	}

	public function testPublicRemotePostIsFederated(): void {
		$stream = new Note();
		$stream->setLocal(false);
		$stream->setTimeline(Stream::TYPE_PUBLIC);
		$stream->setTo(ACore::CONTEXT_PUBLIC);
		$this->accountService->expects($this->never())->method('getFromId');
		$this->followService->expects($this->never())->method('getFollowersFromFollowId');

		$details = $this->service->generateDetailsFromStream($stream);

		$this->assertFalse($details->isPublic());
		$this->assertTrue($details->isFederated());
	}

	public function testDirectMessageListsTheRecipientAsDirectViewer(): void {
		$stream = new Note();
		$stream->setTimeline(Stream::TYPE_DIRECT);
		$stream->setTo(self::BOB);
		$bob = $this->person(self::BOB);
		$this->accountService->expects($this->once())
			->method('getFromId')
			->with(self::BOB)
			->willReturn($bob);
		$this->followService->method('getFollowersFromFollowId')->with(self::BOB)->willReturn([]);

		$details = $this->service->generateDetailsFromStream($stream);

		$this->assertSame([$bob], $details->getDirectViewers());
		$this->assertSame([], $details->getHomeViewers());
		$this->assertFalse($details->isPublic());
		$this->assertFalse($details->isFederated());
	}

	public function testFollowersOnlyPostSkipsThePublicCollectionAndKeepsHomeViewers(): void {
		$stream = new Note();
		$stream->setTimeline(Stream::TYPE_FOLLOWERS);
		$stream->setTo(self::ALICE_FOLLOWERS);
		$stream->setCcArray([ACore::CONTEXT_PUBLIC]);
		$this->accountService->method('getFromId')->willThrowException(new ActorDoesNotExistException());
		$this->followService->expects($this->once())
			->method('getFollowersFromFollowId')
			->with(self::ALICE_FOLLOWERS)
			->willReturn([$this->follower(self::BOB), $this->follower(self::ALICE)]);

		$details = $this->service->generateDetailsFromStream($stream);

		$this->assertCount(2, $details->getHomeViewers());
		$this->assertFalse($details->isPublic());
	}
}
