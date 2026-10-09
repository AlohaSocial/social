<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Publisher\BlueskyVerifications;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\VerificationService;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The instance as a verifier: the administrator's verifying account
 * publishes a verification of each account a moderator verifies here, a
 * Bluesky one and a local one alike, and once the AppView trusts that
 * account, the profiles it serves carry the verifications; one taken back
 * here is gone there too.
 */
class AtprotoVerificationTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $company;
	private LocalAccount $anna;

	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null) {
			$this->markTestSkipped('no Bluesky development network (ATPROTO_NETWORK_FILE)');
		}
		$this->network = $network;
		$this->company = LocalAccount::create('vc');
		$this->anna = LocalAccount::create('va');
	}

	protected function tearDown(): void {
		if (isset($this->network)) {
			Server::get(VerificationService::class)->setVerifier('');
		}
	}

	/**
	 * The issuers of the verifications the AppView shows on a profile, each
	 * with whether it holds.
	 *
	 * @return array<string, bool>
	 */
	private function verifiedBy(string $did): array {
		$issuers = [];
		foreach ($this->network->profile($did)['verification']['verifications'] ?? [] as $verification) {
			$issuers[(string)($verification['issuer'] ?? '')] = ($verification['isValid'] ?? false) === true;
		}

		return $issuers;
	}

	/**
	 * @return list<string> the DIDs of the repositories a verification of $subject is in
	 */
	private function recordsAbout(string $subject): array {
		$repositories = [];
		foreach (Server::get(RepositoryService::class)->getRecordsByLocalId(BlueskyVerifications::localId($subject)) as $record) {
			if ($record->collection === BlueskyVerifications::COLLECTION) {
				$repositories[] = $record->did;
			}
		}

		return $repositories;
	}

	public function testTheVerifyingAccountsVerificationsAreOnTheProfilesTheAppViewServes(): void {
		$verifications = Server::get(VerificationService::class);
		$identities = Server::get(IdentityService::class);
		$accounts = Server::get(AccountService::class);

		$accounts->setDisplayName($this->company->userId, 'Example Inc');
		$verifications->setVerifier($this->company->actor->getPreferredUsername());
		$verifier = $identities->forActor($this->company->actor, false);
		$this->assertNotNull($verifier, 'the verifying account was given a Bluesky identity');
		$this->assertNotNull($this->network->await(fn (): ?bool => $this->network->profile($verifier->did) !== null ? true : null), 'the AppView knows the verifying account');
		$this->assertNotNull($this->network->await(fn (): ?bool => $this->network->trustVerifier($verifier->did) ? true : null), 'and trusts it as a verifier');

		// a Bluesky account, with a name a verification can name
		$bob = $this->network->createUser('vbob' . bin2hex(random_bytes(3)));
		[$code, $answer] = $this->network->asUser('POST', 'com.atproto.repo.putRecord', [
			'repo' => $bob, 'collection' => 'app.bsky.actor.profile', 'rkey' => 'self',
			'record' => ['$type' => 'app.bsky.actor.profile', 'displayName' => 'Bob'],
		]);
		$this->assertSame(200, $code, json_encode($answer));
		$this->assertNotNull($this->network->await(fn (): ?bool => ($this->network->profile($bob)['displayName'] ?? '') === 'Bob' ? true : null), 'the AppView has the name');
		$bobHere = Server::get(BlueskyActorService::class)->resolve($bob, true);
		$verifications->verify($bobHere, 'admin');

		// a local account, its profile on Bluesky first
		$anna = $accounts->getActorFromUserId($this->anna->userId);
		$annaIdentity = $identities->forActor($anna);
		$this->assertNotNull($annaIdentity);
		Server::get(Publisher::class)->publishProfile($anna);
		$this->assertNotNull($this->network->await(fn (): ?bool => $this->network->profile($annaIdentity->did) !== null ? true : null), 'the AppView knows the local account');
		$verifications->verify($anna, 'admin');

		$this->assertSame([$verifier->did], $this->recordsAbout($bob), 'the record is in the verifying account\'s repository');
		$this->assertSame([$verifier->did], $this->recordsAbout($annaIdentity->did));
		$this->assertSame('Example Inc', $verifications->exportOf($bobHere->getId())['by'] ?? null, 'and the check is shown here under its name');

		$this->assertNotNull($this->network->await(fn (): ?bool => ($this->verifiedBy($bob)[$verifier->did] ?? false) ? true : null), 'the Bluesky account\'s profile carries the verification, and it holds');
		$this->assertNotNull($this->network->await(fn (): ?bool => ($this->verifiedBy($annaIdentity->did)[$verifier->did] ?? false) ? true : null), 'so does the local account\'s');

		$verifications->unverify($bobHere->getId());
		$this->assertSame([], $this->recordsAbout($bob));
		$this->assertNotNull($this->network->await(fn (): ?bool => isset($this->verifiedBy($bob)[$verifier->did]) ? null : true), 'taken back there too');
	}
}
