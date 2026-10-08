<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Atproto\Identity\CustomHandleService;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * A custom handle (§4.2) against the development network: a person puts
 * their DID on a domain they own, this server checks it and names the
 * domain in the DID document, and the AppView — told by the firehose —
 * checks it too and shows it. The assigned handle keeps resolving.
 */
class AtprotoHandleTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $alice;

	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null || $network->customHandle === '') {
			$this->markTestSkipped('no Bluesky development network with a handle server');
		}
		$this->network = $network;
		$this->alice = LocalAccount::create('hd');
	}

	public function testAPersonUsesTheirOwnDomainAsTheirHandle(): void {
		$identity = Server::get(IdentityService::class)->forActor($this->alice->actor);
		$this->assertNotNull($identity);
		$assigned = $identity->handle;
		$handles = Server::get(CustomHandleService::class);

		try {
			$handles->set($identity, $this->network->customHandle);
			$this->fail('a domain that does not name the DID was used');
		} catch (\InvalidArgumentException $e) {
			$this->assertStringContainsString('names ' . $identity->did, $e->getMessage());
		}

		$this->network->serveHandleDid($identity->did);
		$updated = $handles->set($identity, $this->network->customHandle);
		$this->assertSame($this->network->customHandle, $updated->handle);
		$this->assertSame($assigned, $updated->assignedHandle());

		$this->assertSame('at://' . $this->network->customHandle, $this->network->didDocument($identity->did)['alsoKnownAs'][0] ?? null, 'the DID document names the domain');
		// the AppView takes the new handle from the firehose's #identity, in its own time
		$shown = $this->network->await(fn () => ($this->network->profile($identity->did)['handle'] ?? '') === $this->network->customHandle ? true : null);
		$this->assertNotNull($shown, 'the AppView shows the new handle: ' . json_encode($this->network->profile($identity->did)));
		$this->assertSame($identity->did, $this->network->await(fn () => $this->network->resolveHandle($this->network->customHandle) ?: null), 'the network resolves it');
		$this->assertSame($identity->did, Server::get(IdentityService::class)->getByHandle($assigned)->did, 'the assigned handle still resolves here');

		$back = $handles->clear($updated);
		$this->assertSame($assigned, $back->handle);
		$this->assertNotNull($this->network->await(fn () => ($this->network->profile($identity->did)['handle'] ?? '') === $assigned ? true : null), 'and back');
	}
}
