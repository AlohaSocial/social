<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use InvalidArgumentException;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Reader\StarterPacks;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * A Bluesky starter pack opened here: an account on the development PDS
 * makes one of two accounts, an account here reads it by its bsky.app
 * address and follows everybody in it, and the AppView sees the follows.
 */
class AtprotoStarterPackTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $alice;

	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null) {
			$this->markTestSkipped('no Bluesky development network (ATPROTO_NETWORK_FILE)');
		}
		$this->network = $network;
		$this->alice = LocalAccount::create('sp');
	}

	public function testAStarterPackIsReadAndItsMembersFollowedFromHere(): void {
		$identity = Server::get(IdentityService::class)->forActor($this->alice->actor);
		$this->assertNotNull($identity);
		$suffix = bin2hex(random_bytes(3));
		$members = [$this->network->createUser('packa' . $suffix), $this->network->createUser('packb' . $suffix)];
		$owner = $this->network->createUser('packer' . $suffix);
		$now = gmdate('Y-m-d\TH:i:s.000\Z');
		$write = function (string $collection, array $record) use ($owner): array {
			[$status, $answer] = $this->network->asUser('POST', 'com.atproto.repo.createRecord', ['repo' => $owner, 'collection' => $collection, 'record' => ['$type' => $collection] + $record]);
			$this->assertSame(200, $status, json_encode($answer));

			return $answer;
		};
		$list = $write('app.bsky.graph.list', ['purpose' => 'app.bsky.graph.defs#referencelist', 'name' => 'Pack ' . $suffix, 'createdAt' => $now]);
		foreach ($members as $did) {
			$write('app.bsky.graph.listitem', ['list' => $list['uri'], 'subject' => $did, 'createdAt' => $now]);
		}
		$pack = $write('app.bsky.graph.starterpack', ['name' => 'Start here ' . $suffix, 'list' => $list['uri'], 'createdAt' => $now]);
		$rkey = substr((string)$pack['uri'], (int)strrpos((string)$pack['uri'], '/') + 1);
		$address = 'https://bsky.app/starter-pack/packer' . $suffix . '.test/' . $rkey;

		$packs = Server::get(StarterPacks::class);
		$read = $this->network->await(function () use ($packs, $address): ?array {
			try {
				$read = $packs->read($this->alice->actor, $address);
			} catch (InvalidArgumentException) {
				return null;
			}

			return count($read['members']) === 2 ? $read : null;
		});
		$this->assertNotNull($read, 'the AppView has the pack and both members');
		$this->assertSame('Start here ' . $suffix, $read['name']);

		$result = $packs->follow($this->alice->actor, $address, $members, false);
		$this->assertSame($members, $result['followed'], json_encode($result));
		foreach ($members as $did) {
			$this->assertNotNull($this->network->await(fn (): ?bool => in_array($identity->did, $this->network->followers($did), true) ? true : null), 'the AppView sees the follow of ' . $did);
		}
		$this->assertSame([true, true], array_column($packs->read($this->alice->actor, $address)['members'], 'following'), 'and the pack says so');
	}
}
