<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FeedDisplayService;
use PHPUnit\Framework\TestCase;

class FeedDisplayServiceTest extends TestCase {
	/** @var array<string, string> user id => stored preference */
	private array $stored = [];
	private FeedDisplayService $service;

	protected function setUp(): void {
		$config = $this->createStub(ConfigService::class);
		$config->method('getUserValue')->willReturnCallback(fn (string $key, string $userId = ''): string => $this->stored[$userId] ?? '');
		$config->method('setValueForUser')->willReturnCallback(function (string $userId, string $key, string $value): void {
			$this->stored[$userId] = $value;
		});
		$this->service = new FeedDisplayService($config);
	}

	public function testRepliesAreShownUntilTheReaderHidesThem(): void {
		$this->assertFalse($this->service->hidesReplies('alice'));
		$this->assertSame(['hide_replies' => false, 'thread_sort' => 'oldest'], $this->service->export('alice'));

		$this->service->setHidesReplies('alice', true);
		$this->assertSame('1', $this->stored['alice']);
		$this->assertTrue($this->service->hidesReplies('alice'));

		$this->service->setHidesReplies('alice', false);
		$this->assertSame('0', $this->stored['alice']);
		$this->assertFalse($this->service->hidesReplies('alice'));
	}

	public function testNobodyInParticularHasNoFeedPreference(): void {
		$this->assertFalse($this->service->hidesReplies(''));
	}

	public function testThreadOrderComesFromTheSavedBlueskyPreference(): void {
		$this->stored['alice'] = json_encode([
			['$type' => 'app.bsky.actor.defs#threadViewPref', 'sort' => 'most-likes'],
		]);

		$this->assertSame('most-likes', $this->service->threadSort('alice'));
	}
}
