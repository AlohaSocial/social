<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Service\AccountRelationService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The bell on a Bluesky account, rung here, is an activity subscription on
 * Bluesky: the AppView lists it for the account, so a Bluesky app signed in
 * to it shows the same bell, and silencing it here takes it away there.
 */
class AtprotoBellTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $alice;

	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null) {
			$this->markTestSkipped('no Bluesky development network (ATPROTO_NETWORK_FILE)');
		}
		$this->network = $network;
		$this->alice = LocalAccount::create('bl');
	}

	public function testTheBellRungHereIsRungOnBluesky(): void {
		$identities = Server::get(IdentityService::class);
		$identity = $identities->forActor($this->alice->actor);
		$this->assertNotNull($identity);
		$bob = $this->network->createUser('belled' . bin2hex(random_bytes(3)));
		$this->assertNotNull($this->network->await(fn (): ?bool => $this->network->profile($bob) !== null ? true : null), 'the AppView has the account');
		$target = Server::get(CacheActorService::class)->getFromId(BlueskyIds::actorId($bob));
		$subscribed = function () use ($identities, $identity): array {
			$answer = Server::get(AppViewClient::class)->queryAs($identity->did, $identities->signingKey($identity), 'app.bsky.notification.listActivitySubscriptions', ['limit' => 50]);

			return array_map(static fn (array $profile): string => (string)($profile['did'] ?? ''), $answer['subscriptions'] ?? []);
		};

		// the AppView keeps subscriptions in bsync and lists them once it has
		// read the write back, so the list is asked until it says so
		Server::get(AccountRelationService::class)->setNotify($this->alice->actor, $target, true);
		$this->assertNotNull($this->network->await(fn (): ?bool => in_array($bob, $subscribed(), true) ? true : null), 'listed for the account on Bluesky');

		Server::get(AccountRelationService::class)->setNotify($this->alice->actor, $target, false);
		$this->assertNotNull($this->network->await(fn (): ?bool => !in_array($bob, $subscribed(), true) ? true : null), 'and gone when silenced');
	}
}
