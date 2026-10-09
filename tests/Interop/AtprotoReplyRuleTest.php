<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Who may reply to a post written here: the post goes to Bluesky with its
 * threadgate, which the AppView then holds every reply to, and a later
 * change of mind rewrites the gate. A reply from here that the rule does
 * not let through is refused with the reason.
 */
class AtprotoReplyRuleTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $alice;

	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null) {
			$this->markTestSkipped('no Bluesky development network (ATPROTO_NETWORK_FILE)');
		}
		$this->network = $network;
		$this->alice = LocalAccount::create('rr');
	}

	public function testThePostGoesWithItsThreadgateAndAChangeRewritesIt(): void {
		$identity = Server::get(IdentityService::class)->forActor($this->alice->actor);
		$this->assertNotNull($identity);
		$words = 'Only my followers ' . bin2hex(random_bytes(4));
		$status = $this->alice->postStatus($words, null, ['reply_policy' => 'followers']);
		$this->assertSame('followers', $status['reply_policy'] ?? null);
		Server::get(Publisher::class)->reconcile();
		$uri = $this->network->await(function () use ($identity, $words): ?string {
			foreach ($this->network->authorFeed($identity->did) as $item) {
				if (str_contains((string)($item['post']['record']['text'] ?? ''), $words)) {
					return (string)$item['post']['uri'];
				}
			}

			return null;
		});
		$this->assertNotNull($uri, 'the AppView indexed the post');
		$allow = fn (): ?array => $this->network->postView($uri)['threadgate']['record']['allow'] ?? null;
		$this->assertSame(
			[['$type' => 'app.bsky.feed.threadgate#followerRule']],
			$this->network->await($allow),
			'the AppView has the gate',
		);

		$this->alice->put('/api/v1/statuses/' . $status['id'] . '/interaction_policy', ['reply_policy' => 'nobody']);
		$this->assertSame([], $this->network->await(fn (): ?array => $allow() === [] ? [] : null), 'the gate now lets nobody through');

		$this->alice->put('/api/v1/statuses/' . $status['id'] . '/interaction_policy', ['reply_policy' => 'everyone']);
		$this->assertTrue($this->network->await(fn (): ?bool => !isset($this->network->postView($uri)['threadgate']) ? true : null), 'the gate is gone');
	}

	public function testAReplyFromHereTheRuleDoesNotLetThroughIsRefused(): void {
		$status = $this->alice->postStatus('Nobody answers this ' . bin2hex(random_bytes(3)), null, ['reply_policy' => 'nobody']);
		$bob = LocalAccount::create('rrb');

		try {
			$bob->postStatus('Answering anyway', (string)$status['id']);
			$this->fail('the reply went out');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('allows no replies', $e->getMessage());
		}
		$this->assertNotEmpty($this->alice->postStatus('I still may', (string)$status['id'])['id'] ?? null, 'the author always may');
	}

	/**
	 * A combination with one of the author's lists: a rule for each part in
	 * the threadgate, and the list a Bluesky list with its member on it.
	 */
	public function testACombinationWithAListIsOneRuleEachAndTheListIsOnBluesky(): void {
		$identity = Server::get(IdentityService::class)->forActor($this->alice->actor);
		$this->assertNotNull($identity);
		$bobDid = $this->network->createUser('listed' . bin2hex(random_bytes(3)));
		$this->assertNotNull($this->network->await(fn (): ?bool => $this->network->resolveHandle($this->network->userHandle()) === $bobDid ? true : null), 'the AppView knows bob');
		$bobHere = $this->alice->resolve($this->network->userHandle());
		$this->alice->follow($bobHere);
		$list = $this->alice->post('/api/v1/lists', ['title' => 'Close friends']);
		$this->alice->post('/api/v1/lists/' . $list['id'] . '/accounts', ['account_ids' => [$bobHere]]);

		$words = 'Friends only ' . bin2hex(random_bytes(4));
		$status = $this->alice->postStatus($words);
		Server::get(Publisher::class)->reconcile();
		$uri = $this->network->await(function () use ($identity, $words): ?string {
			foreach ($this->network->authorFeed($identity->did) as $item) {
				if (str_contains((string)($item['post']['record']['text'] ?? ''), $words)) {
					return (string)$item['post']['uri'];
				}
			}

			return null;
		});
		$this->assertNotNull($uri, 'the AppView indexed the post');

		$this->alice->put('/api/v1/statuses/' . $status['id'] . '/interaction_policy', ['reply_policy' => 'followers,list:' . $list['id']]);

		$allow = $this->network->await(function () use ($uri): ?array {
			$allow = $this->network->postView($uri)['threadgate']['record']['allow'] ?? [];

			return count($allow) === 2 ? $allow : null;
		});
		$this->assertSame(['app.bsky.feed.threadgate#followerRule', 'app.bsky.feed.threadgate#listRule'], array_column($allow ?? [], '$type'));
		$listUri = (string)($allow[1]['list'] ?? '');
		$this->assertNotNull($this->network->await(function () use ($listUri, $bobDid): ?bool {
			$items = $this->network->listItems($listUri);

			return in_array($bobDid, $items, true) ? true : null;
		}), 'bob is on the Bluesky list');
	}
}
