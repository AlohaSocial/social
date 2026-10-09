<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Client;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Client\ClientSession;
use OCA\Social\Atproto\Client\NotificationSettings;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\NotificationPolicy;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\NotificationPolicyService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class NotificationSettingsTest extends TestCase {
	private string $notFollowing = NotificationPolicy::ACCEPT;
	private array $written = [];
	private array $saved = [];
	private NotificationSettings $settings;
	private ClientSession $session;

	protected function setUp(): void {
		$identity = new Identity(1, 'https://social.test/@alice', 'did:plc:alice', 'alice.social.test', 'sealed', '', '', Identity::STATE_ACTIVE, '', 0);
		$this->session = new ClientSession('alice', $identity, 'jti');
		$appView = $this->createMock(AppViewClient::class);
		$appView->method('queryAs')->willReturn(['preferences' => [
			'like' => ['include' => 'all', 'list' => true, 'push' => false],
			'verified' => ['list' => true, 'push' => true],
		]]);
		$appView->method('procedureAs')->willReturnCallback(function (string $did, PrivateKey $key, string $method, array $input): array {
			$this->written[] = $input;

			return ['preferences' => $input];
		});
		$identities = $this->createMock(IdentityService::class);
		$identities->method('forActor')->willReturn($identity);
		$identities->method('signingKey')->willReturn(PrivateKey::generate(Curve::K256));
		$policies = $this->createMock(NotificationPolicyService::class);
		$policies->method('of')->willReturnCallback(fn (): NotificationPolicy => (new NotificationPolicy())->set(NotificationPolicy::NOT_FOLLOWING, $this->notFollowing));
		$policies->method('save')->willReturnCallback(function (string $user, array $changes): NotificationPolicy {
			$this->saved[] = $changes;
			$this->notFollowing = $changes[NotificationPolicy::NOT_FOLLOWING] ?? $this->notFollowing;

			return new NotificationPolicy();
		});
		$accounts = $this->createMock(AccountService::class);
		$accounts->method('getActorFromUserId')->willReturn((new Person())->setId('https://social.test/@alice'));
		$this->settings = new NotificationSettings($appView, $identities, $policies, $accounts, new NullLogger());
	}

	public function testTheAppReadsWhoMayNotifyFromThePolicyHere(): void {
		$this->notFollowing = NotificationPolicy::FILTER;
		$preferences = $this->settings->get($this->session)['preferences'];

		$this->assertSame('follows', $preferences['like']['include']);
		$this->assertFalse($preferences['like']['push'], 'the rest is the AppView\'s');
		$this->assertSame('follows', $preferences['reply']['include']);
		$this->assertArrayNotHasKey('include', $preferences['verified'], 'a kind without the setting');
	}

	public function testWhatTheAppSavesIsKeptAndWhoMayNotifyBecomesThePolicyHere(): void {
		$this->settings->put($this->session, ['like' => ['include' => 'follows', 'list' => true, 'push' => true]]);
		$this->assertSame([[NotificationPolicy::NOT_FOLLOWING => NotificationPolicy::FILTER]], $this->saved);
		$this->assertSame([['like' => ['include' => 'follows', 'list' => true, 'push' => true]]], $this->written, 'the AppView keeps it');

		$this->settings->put($this->session, ['like' => ['include' => 'follows']]);
		$this->settings->put($this->session, ['verified' => ['push' => false]]);
		$this->assertCount(1, $this->saved, 'nothing changed about who may notify');

		$this->settings->put($this->session, ['reply' => ['include' => 'all']]);
		$this->assertSame([NotificationPolicy::NOT_FOLLOWING => NotificationPolicy::ACCEPT], $this->saved[1]);
	}

	public function testAPolicyChangedHereIsToldToTheAppView(): void {
		$this->notFollowing = NotificationPolicy::DROP;
		$this->settings->policyChanged('alice');

		$this->assertSame(NotificationSettings::FILTERABLE, array_keys($this->written[0]));
		$this->assertSame(['include' => 'follows', 'list' => true, 'push' => false], $this->written[0]['like']);
	}
}
