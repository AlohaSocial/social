<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Service\Discovery\PostDiscoveryService;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * A hashtag's timeline and a search of posts here have the posts written on
 * Bluesky by accounts nobody here follows, as any other post.
 */
class AtprotoSearchTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $alice;

	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null) {
			$this->markTestSkipped('no Bluesky development network (ATPROTO_NETWORK_FILE)');
		}
		$this->network = $network;
		$this->alice = LocalAccount::create('se');
	}

	public function testAHashtagAndASearchFindPostsWrittenOnBluesky(): void {
		$tag = 'aloha' . bin2hex(random_bytes(4));
		$words = 'Unheard of ' . bin2hex(random_bytes(4));
		$this->network->createUser('tagger' . bin2hex(random_bytes(3)));
		$this->network->postText($words . ' #' . $tag);
		$discovery = Server::get(PostDiscoveryService::class);

		$tagged = $this->network->await(function () use ($discovery, $tag, $words): ?bool {
			$discovery->fill(PostDiscoveryService::TAG, $tag);
			foreach ($this->alice->get('/api/v1/timelines/tag/' . $tag) as $status) {
				if (str_contains((string)($status['content'] ?? ''), $words)) {
					return true;
				}
			}

			return null;
		});
		$this->assertTrue($tagged ?? false, 'the timeline of the hashtag here has the post written on Bluesky');

		$found = $this->network->await(function () use ($discovery, $words): ?bool {
			$discovery->fill(PostDiscoveryService::SEARCH, $words, $this->alice->actor->getId());
			foreach ($this->alice->get('/api/v2/search', ['q' => $words, 'type' => 'statuses'])['statuses'] ?? [] as $status) {
				if (str_contains((string)($status['content'] ?? ''), $words)) {
					return true;
				}
			}

			return null;
		});
		$this->assertTrue($found ?? false, 'a search here finds the post written on Bluesky');
	}
}
