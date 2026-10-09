<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Service\Discovery\FollowedTagsFill;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Following a hashtag here brings its posts into the home timeline wherever
 * they were written: a post on Bluesky by an account nobody here follows is
 * read by the background read of followed hashtags and is then in the home
 * timeline of the account that follows the tag.
 */
class AtprotoFollowedTagsTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $alice;

	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null) {
			$this->markTestSkipped('no Bluesky development network (ATPROTO_NETWORK_FILE)');
		}
		$this->network = $network;
		$this->alice = LocalAccount::create('ft');
	}

	public function testAPostWithAFollowedHashtagWrittenOnBlueskyIsInTheHomeTimeline(): void {
		$tag = 'aloha' . bin2hex(random_bytes(4));
		$words = 'Followed from afar ' . bin2hex(random_bytes(4));
		$this->network->createUser('tagged' . bin2hex(random_bytes(3)));
		$uri = $this->network->postText($words . ' #' . $tag)['uri'];
		// the background read is throttled per tag, so it is run once the
		// AppView's search has the post
		$this->assertNotNull(
			$this->network->await(fn (): ?bool => in_array($uri, $this->network->searchPosts('#' . $tag), true) ? true : null),
			'the AppView finds the post by its hashtag'
		);

		$this->alice->post('/api/v1/tags/' . $tag . '/follow');
		Server::get(FollowedTagsFill::class)->fill($tag);

		$home = $this->network->await(function () use ($words): ?bool {
			foreach ($this->alice->get('/api/v1/timelines/home') as $status) {
				if (str_contains((string)($status['content'] ?? ''), $words)) {
					return true;
				}
			}

			return null;
		});
		$this->assertTrue($home ?? false, 'the home timeline of the account following the hashtag has the post written on Bluesky');
	}
}
