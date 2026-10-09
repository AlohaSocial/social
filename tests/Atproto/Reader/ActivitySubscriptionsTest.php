<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Reader\ActivitySubscriptions;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class ActivitySubscriptionsTest extends TestCase {
	private array $sent = [];
	private bool $hasIdentity = true;
	private bool $fails = false;

	private function subscriptions(): ActivitySubscriptions {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$appView = $this->createMock(AppViewClient::class);
		$appView->method('procedureAs')->willReturnCallback(function (string $did, PrivateKey $key, string $method, array $input): array {
			if ($this->fails) {
				throw new AtprotoException('down');
			}
			$this->sent[] = [$did, $method, $input];

			return [];
		});
		$identities = $this->createMock(IdentityService::class);
		$identities->method('forActor')->willReturnCallback(fn (): ?Identity => $this->hasIdentity ? new Identity(1, 'https://social.test/@alice', 'did:plc:alice', 'alice.social.test', '', '', '', Identity::STATE_ACTIVE, '', 0) : null);
		$identities->method('signingKey')->willReturn(PrivateKey::generate(Curve::K256));

		return new ActivitySubscriptions($config, $appView, $identities, new NullLogger());
	}

	private static function account(string $id): Person {
		return (new Person())->setId($id);
	}

	public function testTheBellIsRungOnBlueskyAsThePersonForPostsNotReplies(): void {
		$this->subscriptions()->set(new Person(), self::account('https://bsky.app/profile/did:plc:bob'), true);
		$this->subscriptions()->set(new Person(), self::account('https://bsky.app/profile/did:plc:bob'), false);

		$this->assertSame([
			['did:plc:alice', 'app.bsky.notification.putActivitySubscription', ['subject' => 'did:plc:bob', 'activitySubscription' => ['post' => true, 'reply' => false]]],
			['did:plc:alice', 'app.bsky.notification.putActivitySubscription', ['subject' => 'did:plc:bob', 'activitySubscription' => ['post' => false, 'reply' => false]]],
		], $this->sent);
	}

	public function testNothingIsSentForAnotherAccountAPersonNotOnBlueskyOrWhenBlueskyIsDown(): void {
		$this->subscriptions()->set(new Person(), self::account('https://remote.example/users/bob'), true);
		$this->hasIdentity = false;
		$this->subscriptions()->set(new Person(), self::account('https://bsky.app/profile/did:plc:bob'), true);
		$this->hasIdentity = true;
		$this->fails = true;
		$this->subscriptions()->set(new Person(), self::account('https://bsky.app/profile/did:plc:bob'), true);

		$this->assertSame([], $this->sent);
	}
}
