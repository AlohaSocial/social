<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Atproto\Reader\BlueskyGraphService;
use OCA\Social\Db\AtprotoWatchRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class BlueskyGraphServiceTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	/** @var Publisher&MockObject */
	private Publisher $publisher;
	/** @var AtprotoWatchRequest&MockObject */
	private AtprotoWatchRequest $watches;
	/** @var FollowsRequest&MockObject */
	private FollowsRequest $follows;
	private BlueskyGraphService $service;
	private Person $alice;
	private Person $bob;
	private Follow $follow;

	protected function setUp(): void {
		$this->publisher = $this->createMock(Publisher::class);
		$this->watches = $this->createMock(AtprotoWatchRequest::class);
		$this->follows = $this->createMock(FollowsRequest::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1760000000);
		$this->service = new BlueskyGraphService($this->publisher, $this->watches, $this->follows, $time, new NullLogger());
		$this->alice = new Person();
		$this->alice->setId('https://social.test/@alice');
		$this->alice->setLocal(true);
		$this->bob = new Person();
		$this->bob->setId('https://bsky.app/profile/' . self::DID);
		$this->bob->setAccount('bob.bsky.social');
		$this->follow = new Follow();
		$this->follow->setId('https://social.test/follow/1');
	}

	public function testAFollowIsARecordAndAWatch(): void {
		$this->publisher->expects($this->once())->method('writeRecord')
			->with($this->alice, RecordMapper::FOLLOW, ['$type' => RecordMapper::FOLLOW, 'subject' => self::DID, 'createdAt' => '2025-10-09T08:53:20.000Z'], 'https://social.test/follow/1')
			->willReturn(true);
		$this->watches->expects($this->once())->method('add')->with(self::DID, 'bob.bsky.social');

		$this->service->follow($this->alice, $this->bob, $this->follow);
	}

	public function testTheWatchIsKeptWhenTheRecordCannotBeWritten(): void {
		$this->publisher->method('writeRecord')->willThrowException(new AtprotoException('no key'));
		$this->watches->expects($this->once())->method('add');

		$this->service->follow($this->alice, $this->bob, $this->follow);
	}

	public function testAnUnfollowRemovesTheRecordAndTheLastFollowerTheWatch(): void {
		$this->publisher->expects($this->exactly(2))->method('removeRecord')->with(RecordMapper::FOLLOW, 'https://social.test/follow/1')->willReturn(true);
		$this->follows->method('countFollowers')->with($this->bob->getId())->willReturn(1, 0);
		$this->watches->expects($this->once())->method('remove')->with(self::DID);

		$this->service->unfollow($this->alice, $this->bob, $this->follow);
		$this->service->unfollow($this->alice, $this->bob, $this->follow);
	}

	public function testNothingHappensForAFediverseAccount(): void {
		$mastodon = new Person();
		$mastodon->setId('https://mastodon.test/users/bob');
		$this->publisher->expects($this->never())->method('writeRecord');
		$this->watches->expects($this->never())->method('add');

		$this->service->follow($this->alice, $mastodon, $this->follow);
		$this->service->unfollow($this->alice, $mastodon, $this->follow);
	}
}
