<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Atproto\Client\AppPasswordService;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Service\NotificationPolicyService;
use OCA\Social\Tests\Interop\Bluesky\AppClient;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Who may send a person direct messages, the same here and on Bluesky: a
 * choice made here is the `chat.bsky.actor.declaration` the AppView shows on
 * their profile, and one a Bluesky app signed in here makes is the setting
 * here. The development network has no chat service, so whether the chat
 * service then refuses a message is the live test's (Atproto-Live-Test 3.6a).
 */
class AtprotoChatDeclarationTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $alice;

	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null) {
			$this->markTestSkipped('no Bluesky development network (ATPROTO_NETWORK_FILE)');
		}
		$this->network = $network;
		$this->alice = LocalAccount::create('dm');
	}

	/**
	 * Alice signed in to a Bluesky app here, which gives her a Bluesky identity.
	 *
	 * @return array{0: AppClient, 1: Identity}
	 */
	private function app(): array {
		$identity = Server::get(IdentityService::class)->forActor($this->alice->actor);
		$this->assertNotNull($identity);
		$app = AppClient::at((string)getenv('NEXTCLOUD_URL') ?: 'https://nextcloud.test');
		[$status, $answer] = $app->signIn($identity->handle, Server::get(AppPasswordService::class)->create($this->alice->userId, 'declaration')['password']);
		$this->assertSame(200, $status, json_encode($answer));

		return [$app, $identity];
	}

	public function testWhoMaySendDirectMessagesIsTheSameHereAndOnBluesky(): void {
		[$app, $identity] = $this->app();
		$allowed = fn (): ?string => $this->network->profile($identity->did)['associated']['chat']['allowIncoming'] ?? null;

		Server::get(NotificationPolicyService::class)->saveDirectMessagesFrom($this->alice->userId, 'none');
		$this->assertNotNull($this->network->await(fn (): ?bool => $allowed() === 'none' ? true : null), 'nobody, on the profile the AppView shows');

		[$status, $answer] = $app->procedure('com.atproto.repo.putRecord', [
			'repo' => $identity->did,
			'collection' => 'chat.bsky.actor.declaration',
			'rkey' => 'self',
			'record' => ['$type' => 'chat.bsky.actor.declaration', 'allowIncoming' => 'all'],
		]);
		$this->assertSame(200, $status, json_encode($answer));
		$this->assertSame('at://' . $identity->did . '/chat.bsky.actor.declaration/self', $answer['uri']);
		$this->assertSame(['from' => 'all'], $this->alice->get('/api/v1/social/direct_messages'), 'the app\'s choice is the setting here');
		$this->assertNotNull($this->network->await(fn (): ?bool => $allowed() === 'all' ? true : null), 'and the AppView shows it');
	}
}
