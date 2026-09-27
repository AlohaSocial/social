<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\ChannelsRequest;
use OCA\Social\Db\TeamsRequest;
use OCA\Social\Model\Channel;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The two kinds of actor an account can own besides itself: PeerTube-style
 * channels and team accounts that speak for a group.
 */
class ChannelsAndTeamsRequestTest extends TestCase {
	private const OWNER = 'https://itest.example/users/channel-owner';
	private const CHANNEL_A = 'https://itest.example/users/channel-a';
	private const CHANNEL_B = 'https://itest.example/users/channel-b';
	private const TEAM = 'https://itest.example/users/team-itest';
	private const POST = 'https://itest.example/posts/team-1';

	private ChannelsRequest $channels;
	private TeamsRequest $teams;

	protected function setUp(): void {
		parent::setUp();
		$this->channels = Server::get(ChannelsRequest::class);
		$this->teams = Server::get(TeamsRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		$this->channels->deleteRelatedId(self::OWNER);
		$this->teams->delete(self::TEAM);
		$this->teams->deleteByStream(self::POST);
	}

	private function channel(string $actorId, string $name, bool $default = false): Channel {
		return (new Channel())
			->setActorId($actorId)
			->setOwnerId(self::OWNER)
			->setName($name)
			->setDescription('')
			->setHandle('itest_' . md5($actorId))
			->setDefault($default);
	}

	public function testAnOwnersChannelsAreListedInTheOrderTheyWereMade(): void {
		$this->channels->create($this->channel(self::CHANNEL_A, 'Main', true));
		$this->channels->create($this->channel(self::CHANNEL_B, 'Travel'));

		$names = array_map(static fn (Channel $c): string => $c->getName(), $this->channels->getByOwner(self::OWNER));
		$this->assertSame(['Main', 'Travel'], $names);
		$this->assertSame(self::OWNER, $this->channels->ownerOf(self::CHANNEL_B));
		$this->assertTrue($this->channels->getByActorId(self::CHANNEL_A)?->isDefault());
	}

	public function testAChannelCanBeRenamedAndDeleted(): void {
		$this->channels->create($this->channel(self::CHANNEL_A, 'Main'));

		$this->channels->update($this->channel(self::CHANNEL_A, 'Renamed'));
		$this->assertSame('Renamed', $this->channels->getByActorId(self::CHANNEL_A)?->getName());

		$this->assertTrue($this->channels->delete(self::CHANNEL_A));
		$this->assertNull($this->channels->getByActorId(self::CHANNEL_A));
		$this->assertSame('', $this->channels->ownerOf(self::CHANNEL_A));
	}

	public function testATeamBelongsToOneGroupAndIsMadeOnce(): void {
		$this->assertTrue($this->teams->create(self::TEAM, 'itest-group'));
		$this->assertFalse($this->teams->create(self::TEAM, 'itest-group'), 'the second one is refused, not an error');

		$this->assertSame('itest-group', $this->teams->groupOf(self::TEAM));
		$this->assertContains(self::TEAM, array_column($this->teams->getByGroups(['itest-group']), 'actor_id'));
		$this->assertSame([], $this->teams->getByGroups([]));
	}

	public function testWhoWroteATeamPostIsRecordedOnce(): void {
		$this->teams->recordAuthor(self::POST, 'https://itest.example/users/member-1');
		$this->teams->recordAuthor(self::POST, 'https://itest.example/users/member-1');

		$this->assertSame([md5(self::POST) => 'https://itest.example/users/member-1'], $this->teams->authorsOf([self::POST]));
		$this->teams->deleteByStream(self::POST);
		$this->assertSame([], $this->teams->authorsOf([self::POST]));
	}
}
