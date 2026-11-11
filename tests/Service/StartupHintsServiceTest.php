<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\StoriesRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AnnouncementService;
use OCA\Social\Service\MemoriesService;
use OCA\Social\Service\StartupHintsService;
use OCA\Social\Service\TeamService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class StartupHintsServiceTest extends TestCase {
	private AnnouncementService&MockObject $announcements;
	private StoriesRequest&MockObject $stories;
	private MemoriesService&MockObject $memories;
	private TeamService&MockObject $teams;
	private StartupHintsService $service;

	protected function setUp(): void {
		$this->announcements = $this->createMock(AnnouncementService::class);
		$this->stories = $this->createMock(StoriesRequest::class);
		$this->memories = $this->createMock(MemoriesService::class);
		$this->teams = $this->createMock(TeamService::class);
		$this->service = new StartupHintsService(
			$this->announcements, $this->stories, $this->memories, $this->teams, new NullLogger()
		);
	}

	public function testEachHintIsTheAnswerToItsOwnQuestion(): void {
		$actor = (new Person())->setId('https://cloud.example/users/alice');
		$this->announcements->method('hasActive')->willReturn(true);
		$this->stories->method('hasLive')->willReturn(false);
		$this->memories->method('recapEnabled')->with('alice')->willReturn(true);
		$this->memories->method('mayHaveMemories')->with($actor)->willReturn(false);
		$this->teams->method('hasTeamsFor')->with('alice')->willReturn(false);

		$this->assertSame([
			'has_announcements' => true,
			'has_stories' => false,
			'recap_enabled' => true,
			'has_memories' => false,
			'has_teams' => false,
		], $this->service->forViewer($actor, 'alice'));
	}

	/** A hint that cannot be worked out has the app ask, as it always did. */
	public function testACheckThatFailsLetsTheAppAsk(): void {
		$this->announcements->method('hasActive')->willThrowException(new \RuntimeException('db'));
		$this->stories->method('hasLive')->willReturn(false);
		$this->teams->method('hasTeamsFor')->willThrowException(new \RuntimeException('groups'));

		$hints = $this->service->forViewer(new Person(), 'alice');

		$this->assertTrue($hints['has_announcements']);
		$this->assertTrue($hints['has_teams']);
		$this->assertFalse($hints['has_stories']);
	}
}
