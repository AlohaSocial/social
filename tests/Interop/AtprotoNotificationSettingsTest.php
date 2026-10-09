<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Client\AppPasswordService;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Tests\Interop\Bluesky\AppClient;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Who may notify, one setting in Social and in a Bluesky app signed in
 * here: the app's "only people I follow" is the policy here, and the policy
 * set here is what the AppView sends the app's notifications by.
 */
class AtprotoNotificationSettingsTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $alice;

	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null) {
			$this->markTestSkipped('no Bluesky development network (ATPROTO_NETWORK_FILE)');
		}
		$this->network = $network;
		$this->alice = LocalAccount::create('ns');
	}

	public function testWhoMayNotifyIsOneSetting(): void {
		$identities = Server::get(IdentityService::class);
		$identity = $identities->forActor($this->alice->actor);
		$this->assertNotNull($identity);
		$app = AppClient::at((string)getenv('NEXTCLOUD_URL') ?: 'https://nextcloud.test');
		[$status, $answer] = $app->signIn($identity->handle, Server::get(AppPasswordService::class)->create($this->alice->userId, 'notifications')['password']);
		$this->assertSame(200, $status, json_encode($answer));

		[$status, $answer] = $app->procedure('app.bsky.notification.putPreferencesV2', ['reply' => ['include' => 'follows', 'list' => true, 'push' => true]]);
		$this->assertSame(200, $status, json_encode($answer));
		$this->assertSame('filter', $this->alice->get('/api/v2/notifications/policy')['for_not_following'] ?? null, 'the app\'s choice is the policy here');

		$this->alice->patch('/api/v2/notifications/policy', ['for_not_following' => 'accept']);
		$appView = Server::get(AppViewClient::class);
		$include = $this->network->await(function () use ($appView, $identities, $identity): ?string {
			$preferences = $appView->queryAs($identity->did, $identities->signingKey($identity), 'app.bsky.notification.getPreferences')['preferences'] ?? [];

			return ($preferences['reply']['include'] ?? '') === 'all' ? 'all' : null;
		});
		$this->assertSame('all', $include, 'the policy set here is the AppView\'s');
	}
}
