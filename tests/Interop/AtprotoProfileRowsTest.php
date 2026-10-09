<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Service\AccountService;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Pronouns and a website, both ways: the profile rows written here are the
 * Bluesky profile's `pronouns` and `website`, and a Bluesky account's are
 * profile rows here.
 */
class AtprotoProfileRowsTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $alice;

	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null) {
			$this->markTestSkipped('no Bluesky development network (ATPROTO_NETWORK_FILE)');
		}
		$this->network = $network;
		$this->alice = LocalAccount::create('pr');
	}

	public function testTheRowsWrittenHereAreTheBlueskyProfiles(): void {
		$identity = Server::get(IdentityService::class)->forActor($this->alice->actor);
		$this->assertNotNull($identity);
		$accounts = Server::get(AccountService::class);
		$accounts->setFields($this->alice->userId, [['name' => 'Pronouns', 'value' => 'she/her'], ['name' => 'Website', 'value' => 'https://alice.example/']]);
		Server::get(Publisher::class)->publishProfile($accounts->getActorFromUserId($this->alice->userId));

		$profile = $this->network->await(fn (): ?array => ($this->network->profile($identity->did)['pronouns'] ?? '') === 'she/her' ? $this->network->profile($identity->did) : null);
		$this->assertNotNull($profile, 'the AppView shows the pronouns');
		$this->assertSame('https://alice.example/', $profile['website'] ?? null);
	}

	public function testABlueskyAccountsRowsShowHere(): void {
		$did = $this->network->createUser('rows' . bin2hex(random_bytes(3)));
		[$code, $answer] = $this->network->asUser('POST', 'com.atproto.repo.putRecord', [
			'repo' => $did, 'collection' => 'app.bsky.actor.profile', 'rkey' => 'self',
			'record' => ['$type' => 'app.bsky.actor.profile', 'displayName' => 'Rows', 'pronouns' => 'they/them', 'website' => 'https://rows.example/'],
		]);
		$this->assertSame(200, $code, json_encode($answer));
		$this->assertNotNull($this->network->await(fn (): ?bool => ($this->network->profile($did)['pronouns'] ?? '') === 'they/them' ? true : null), 'the AppView has the profile');

		$fields = $this->alice->account($this->alice->resolve($this->network->userHandle()))['fields'] ?? [];
		$this->assertSame(['Pronouns', 'Website'], array_column($fields, 'name'));
		$this->assertSame('they/them', $fields[0]['value']);
		$this->assertStringContainsString('https://rows.example/', $fields[1]['value']);
	}
}
