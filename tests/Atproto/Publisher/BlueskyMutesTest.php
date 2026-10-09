<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Publisher;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Client\ClientSession;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Publisher\BlueskyMutes;
use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\RelationshipService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class BlueskyMutesTest extends TestCase {
	private const DID = 'did:plc:alice';

	public function testAMuteOfABlueskyAccountIsToldToTheAppViewAndAnAppsIsMadeHereWithoutTellingItBack(): void {
		$alice = (new Person())->setId('https://social.test/@alice');
		$bob = (new Person())->setId('https://bsky.app/profile/did:plc:bob');
		$carol = (new Person())->setId('https://remote.example/users/carol');
		$identity = new Identity(1, $alice->getId(), self::DID, 'alice.social.test', 'sealed', '', '', Identity::STATE_ACTIVE, '', 0);
		$identities = $this->createMock(IdentityService::class);
		$identities->method('activeForActor')->willReturn($identity);
		$identities->method('signingKey')->willReturn(PrivateKey::generate(Curve::K256));
		$told = [];
		$appView = $this->createMock(AppViewClient::class);
		$appView->method('procedureAs')->willReturnCallback(static function (string $did, PrivateKey $key, string $method, array $input) use (&$told): array {
			$told[] = $method . ' ' . $input['actor'];

			return [];
		});
		$actors = $this->createMock(BlueskyActorService::class);
		$actors->method('cached')->with('did:plc:bob')->willReturn($bob);
		$accounts = $this->createMock(AccountService::class);
		$accounts->method('getActorFromUserId')->willReturn($alice);
		$mutes = null;
		$relationships = $this->createMock(RelationshipService::class);
		$relationships->expects($this->once())->method('mute')->willReturnCallback(static function (Person $viewer, Person $target) use (&$mutes): void {
			$mutes->muted($viewer, $target);
		});
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(RelationshipService::class)->willReturn($relationships);
		$mutes = new BlueskyMutes($appView, $identities, $actors, $accounts, new NullLogger(), $container);

		$mutes->muted($alice, $bob);
		$mutes->muted($alice, $carol);
		$mutes->unmuted($alice, $bob);
		$mutes->fromApp(new ClientSession('alice', $identity, 'jti'), BlueskyMutes::MUTE, ['actor' => 'did:plc:bob']);

		$this->assertSame([BlueskyMutes::MUTE . ' did:plc:bob', BlueskyMutes::UNMUTE . ' did:plc:bob'], $told, 'only Bluesky accounts, and never an app\'s own mute back');
	}
}
