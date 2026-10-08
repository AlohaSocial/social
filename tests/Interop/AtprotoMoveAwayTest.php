<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\Move;
use OCA\Social\Atproto\Move\MoveAwayService;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Moving a Bluesky account away (§13.2), to the development network's own
 * PDS, driven from here: the account is made there with a token the
 * account signs, its repository is copied, the DID is pointed there and the
 * account is activated there and switched off here. The followers follow
 * the DID, so what is checked is what the network sees of it afterwards.
 */
class AtprotoMoveAwayTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $alice;

	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null) {
			$this->markTestSkipped('no Bluesky development network (ATPROTO_NETWORK_FILE)');
		}
		$this->network = $network;
		$this->alice = LocalAccount::create('mv');
	}

	public function testABlueskyAccountMovesToAnotherPds(): void {
		$identity = Server::get(IdentityService::class)->forActor($this->alice->actor);
		$this->assertNotNull($identity);
		$words = 'Written before the move ' . bin2hex(random_bytes(4));
		$this->alice->postStatus($words);
		Server::get(Publisher::class)->reconcile();

		$handle = 'moved' . bin2hex(random_bytes(3)) . '.test';
		$password = 'Moved-' . bin2hex(random_bytes(6));
		$moves = Server::get(MoveAwayService::class);
		$move = $moves->start($this->alice->actor->getUserId(), $identity, $this->network->pds, $handle, $handle . '@example.test', $password);
		$moves->run($move->id);
		$done = $moves->latest($this->alice->actor->getUserId());
		$this->assertSame(Move::DONE, $done?->state, 'the move ended at ' . $done?->step . ': ' . $done?->error);

		$document = $this->network->didDocument($identity->did);
		$this->assertSame('at://' . $handle, $document['alsoKnownAs'][0] ?? null, 'the DID names the new handle');
		$this->assertSame(rtrim($this->network->pds, '/'), rtrim((string)($document['service'][0]['serviceEndpoint'] ?? ''), '/'), 'and the new PDS');

		$texts = array_map(static fn (array $record): string => (string)($record['value']['text'] ?? ''), $this->network->pdsRecords($identity->did, 'app.bsky.feed.post'));
		$this->assertContains($words, $texts, 'the posts are there');
		$this->assertSame($identity->did, $this->network->signInAs($handle, $password)['did'] ?? null, 'the account signs in there');

		$here = Server::get(IdentityService::class)->getByDid($identity->did);
		$this->assertSame(Identity::STATE_MOVED_AWAY, $here->state, 'switched off here');
		$this->assertNotNull($this->network->await(fn () => ($this->network->profile($identity->did)['handle'] ?? '') === $handle ? true : null), 'the AppView follows the DID to its new home');
	}
}
