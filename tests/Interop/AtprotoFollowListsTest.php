<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Service\FollowList\FollowListService;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Who follows an account where it lives, here: two Bluesky accounts nobody
 * here knows follow a third, and the third's list of followers here has
 * them, as any other.
 */
class AtprotoFollowListsTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $alice;

	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null) {
			$this->markTestSkipped('no Bluesky development network (ATPROTO_NETWORK_FILE)');
		}
		$this->network = $network;
		$this->alice = LocalAccount::create('fl');
	}

	public function testTheFollowersWhereTheAccountLivesAreInItsFollowersHere(): void {
		$targetDid = $this->network->createUser('followed' . bin2hex(random_bytes(3)));
		$target = $this->network->userHandle();
		$this->assertNotNull($this->network->await(fn (): ?bool => $this->network->resolveHandle($target) === $targetDid ? true : null), 'the AppView knows the account');

		$fans = [];
		foreach (['fana', 'fanb'] as $name) {
			$did = $this->network->createUser($name . bin2hex(random_bytes(3)));
			$handle = $this->network->userHandle();
			// until the AppView has verified a new handle it lists the account
			// as `handle.invalid`
			$this->assertNotNull($this->network->await(fn (): ?bool => $this->network->resolveHandle($handle) === $did ? true : null), 'the AppView knows ' . $handle);
			$this->network->follow($targetDid);
			$fans[$did] = $handle;
		}
		$this->assertNotNull($this->network->await(fn (): ?bool => array_diff(array_keys($fans), $this->network->followers($targetDid)) === [] ? true : null), 'the AppView has both follows');

		$targetHere = $this->alice->resolve($target);
		$followLists = Server::get(FollowListService::class);
		$listed = [];
		$found = $this->network->await(function () use ($followLists, $targetDid, $targetHere, $fans, &$listed): ?bool {
			$followLists->fill(BlueskyIds::actorId($targetDid), FollowListService::FOLLOWERS);
			$listed = array_column($this->alice->get('/api/v1/accounts/' . $targetHere . '/followers', ['limit' => 40]), 'acct');

			return array_diff(array_values($fans), $listed) === [] ? true : null;
		});
		$this->assertTrue($found ?? false, 'both followers are in its followers here, which has ' . json_encode($listed));
	}
}
