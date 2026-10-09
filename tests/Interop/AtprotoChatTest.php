<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Atproto\Client\AppPasswordService;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Tests\Interop\Bluesky\AppClient;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * A Bluesky app's direct messages through this server: only an app signed
 * in with a privileged app password reaches them, as on Bluesky. The
 * development network has no chat service, so the way through is shown
 * with direct messages turned off — the privileged app is told so, the
 * other is refused before anything is asked.
 */
class AtprotoChatTest extends TestCase {
	private LocalAccount $alice;
	private string $chat = '';

	protected function setUp(): void {
		if (DevNetwork::fromEnvironment() === null) {
			$this->markTestSkipped('no Bluesky development network (ATPROTO_NETWORK_FILE)');
		}
		$this->alice = LocalAccount::create('ch');
		$this->chat = Server::get(ConfigService::class)->getAppValue(ConfigService::ATPROTO_CHAT);
		Server::get(ConfigService::class)->setAppValue(ConfigService::ATPROTO_CHAT, '');
	}

	protected function tearDown(): void {
		Server::get(ConfigService::class)->setAppValue(ConfigService::ATPROTO_CHAT, $this->chat);
	}

	public function testOnlyAPrivilegedAppPasswordReachesTheDirectMessages(): void {
		$identity = Server::get(IdentityService::class)->forActor($this->alice->actor);
		$this->assertNotNull($identity);
		$passwords = Server::get(AppPasswordService::class);
		$asked = function (string $password) use ($identity): array {
			$app = AppClient::at((string)getenv('NEXTCLOUD_URL') ?: 'https://nextcloud.test');
			$this->assertSame(200, $app->signIn($identity->handle, $password)[0]);

			return $app->query('chat.bsky.convo.listConvos', ['limit' => 10]);
		};

		[$status, $answer] = $asked($passwords->create($this->alice->userId, 'reader')['password']);
		$this->assertSame([403, 'InsufficientScope'], [$status, $answer['error'] ?? ''], 'an app password that is not privileged');

		[$status, $answer] = $asked($passwords->create($this->alice->userId, 'chatter', true)['password']);
		$this->assertSame([501, 'MethodNotImplemented'], [$status, $answer['error'] ?? ''], 'a privileged one gets through to the chat service, which is off here');
	}
}
