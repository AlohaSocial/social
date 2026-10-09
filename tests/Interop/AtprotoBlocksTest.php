<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Publisher\BlueskyBlocks;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Blocks of Bluesky accounts, published as the person chooses: a block
 * stays here until they publish theirs, then the AppView sees it, and an
 * unblock takes it away there too.
 */
class AtprotoBlocksTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $alice;

	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null) {
			$this->markTestSkipped('no Bluesky development network (ATPROTO_NETWORK_FILE)');
		}
		$this->network = $network;
		$this->alice = LocalAccount::create('bk');
	}

	public function testABlockIsPublishedOnlyWhenThePersonPublishesTheirs(): void {
		$identities = Server::get(IdentityService::class);
		$identity = $identities->forActor($this->alice->actor);
		$this->assertNotNull($identity);
		$bob = $this->network->createUser('blocked' . bin2hex(random_bytes(3)));
		$bobId = $this->network->await(function (): ?string {
			try {
				return $this->alice->resolve($this->network->userHandle());
			} catch (RuntimeException) {
				return null;
			}
		});
		$this->assertNotNull($bobId, 'the handle resolved');
		$blocking = function () use ($identities, $identity, $bob): bool {
			$profile = Server::get(AppViewClient::class)->queryAs($identity->did, $identities->signingKey($identity), 'app.bsky.actor.getProfile', ['actor' => $bob]);

			return is_string($profile['viewer']['blocking'] ?? null);
		};

		$this->alice->post('/api/v1/accounts/' . rawurlencode($bobId) . '/block');
		sleep(DevNetwork::POLL_SECONDS * 2);
		$this->assertFalse($blocking(), 'kept here while blocks are not published');

		Server::get(BlueskyBlocks::class)->setPublished($this->alice->actor, true);
		$this->assertNotNull($this->network->await(fn (): ?bool => $blocking() ? true : null), 'the AppView sees the published block');

		$this->alice->post('/api/v1/accounts/' . rawurlencode($bobId) . '/unblock');
		$this->assertNotNull($this->network->await(fn (): ?bool => $blocking() ? null : true), 'and the unblock');
	}
}
