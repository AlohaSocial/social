<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\PresenceService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * A person switches their presence on Bluesky off and on again (§4.6),
 * against the official software: off, the AppView no longer shows the
 * account and nothing written here goes there; on again, the account is
 * back with what it had, and what was written while off stays off.
 */
class AtprotoOptOutTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $alice;

	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null) {
			$this->markTestSkipped('no Bluesky development network (ATPROTO_NETWORK_FILE)');
		}
		$this->network = $network;
		$this->alice = LocalAccount::create('opt');
	}

	/** @return bool|null true once the author feed has a post with these words */
	private function inFeed(string $did, string $words): ?bool {
		foreach ($this->network->authorFeed($did) as $item) {
			if (str_contains((string)($item['post']['record']['text'] ?? ''), $words)) {
				return true;
			}
		}

		return null;
	}

	public function testAnAccountSwitchedOffIsGoneFromBlueskyAndBackWhenOnAgain(): void {
		$identity = Server::get(IdentityService::class)->forActor($this->alice->actor);
		$this->assertNotNull($identity);
		$before = 'On Bluesky ' . bin2hex(random_bytes(4));
		$this->alice->postStatus($before);
		Server::get(Publisher::class)->reconcile();
		$this->assertTrue($this->network->await(fn (): ?bool => $this->inFeed($identity->did, $before)), 'the AppView shows the post');
		$this->assertNotNull($this->network->await(fn (): ?array => $this->network->profile($identity->did)), 'and the profile');

		$presence = Server::get(PresenceService::class);
		$this->assertSame(Identity::STATE_DEACTIVATED, $presence->switchOff($this->alice->actor)->state);
		$this->assertTrue($this->network->await(fn (): ?bool => $this->network->profile($identity->did) === null ? true : null), 'the AppView no longer shows the account');
		if ($this->network->relay !== '') {
			$status = $this->network->await(fn (): ?array => ($this->network->relayRepoStatus($identity->did)['active'] ?? true) === false ? $this->network->relayRepoStatus($identity->did) : null, 30);
			$this->assertSame('deactivated', $status['status'] ?? null, 'the relay has it deactivated');
		}

		$repositories = Server::get(RepositoryService::class);
		$rev = $repositories->getHead($identity->did)?->rev;
		$whileOff = 'Written while off ' . bin2hex(random_bytes(4));
		$this->alice->postStatus($whileOff);
		Server::get(Publisher::class)->reconcile();
		$this->assertSame($rev, $repositories->getHead($identity->did)?->rev, 'nothing is written to the repository while off');
		// what was written while off is told apart by the second it was switched back on
		sleep(1);

		$this->assertTrue($presence->switchOn($this->alice->actor)->isActive());
		Server::get(Publisher::class)->reconcile();
		$this->assertNotNull($this->network->await(fn (): ?array => $this->network->profile($identity->did)), 'the AppView shows the account again');
		$this->assertTrue($this->network->await(fn (): ?bool => $this->inFeed($identity->did, $before)), 'with the post it had');
		$this->assertNull($this->inFeed($identity->did, $whileOff), 'what was written while off stays off');
		$this->assertSame($rev, $repositories->getHead($identity->did)?->rev, 'and is not published once on again');
	}
}
