<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Atproto\Client\AppPasswordService;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Tests\Interop\Bluesky\AppClient;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Mutes and muted words, the same here and in a Bluesky app signed in here:
 * a Bluesky account muted here is muted at the AppView the app reads, one
 * the app unmutes is unmuted here, and the app's muted words are the
 * filters here, both ways.
 */
class AtprotoMutesTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $alice;

	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null) {
			$this->markTestSkipped('no Bluesky development network (ATPROTO_NETWORK_FILE)');
		}
		$this->network = $network;
		$this->alice = LocalAccount::create('mu');
	}

	private function app(): AppClient {
		$identity = Server::get(IdentityService::class)->forActor($this->alice->actor);
		$this->assertNotNull($identity);
		$app = AppClient::at((string)getenv('NEXTCLOUD_URL') ?: 'https://nextcloud.test');
		[$status, $answer] = $app->signIn($identity->handle, Server::get(AppPasswordService::class)->create($this->alice->userId, 'mutes')['password']);
		$this->assertSame(200, $status, json_encode($answer));

		return $app;
	}

	public function testAMuteHereIsTheAppsAndTheAppsUnmuteIsHere(): void {
		$app = $this->app();
		$bob = $this->network->createUser('muted' . bin2hex(random_bytes(3)));
		$this->assertNotNull($this->network->await(fn (): ?bool => $this->network->profile($bob) !== null ? true : null), 'the AppView has the account');
		$bobHere = $this->alice->resolve($this->network->userHandle());

		$this->alice->post('/api/v1/accounts/' . $bobHere . '/mute');
		$muted = function () use ($app): array {
			[$status, $answer] = $app->query('app.bsky.graph.getMutes', ['limit' => 50]);

			return $status === 200 ? array_column($answer['mutes'] ?? [], 'did') : [];
		};
		$this->assertNotNull($this->network->await(fn (): ?bool => in_array($bob, $muted(), true) ? true : null), 'muted for the app too');

		[$status, $answer] = $app->procedure('app.bsky.graph.unmuteActor', ['actor' => $bob]);
		$this->assertSame(200, $status, json_encode($answer));
		$this->assertFalse($this->alice->relationship($bobHere)['muting'] ?? true, 'unmuted here too');
	}

	public function testTheAppsMutedWordsAreTheFiltersHere(): void {
		$app = $this->app();
		$this->alice->post('/api/v2/filters', ['title' => 'Spoilers', 'context' => ['home'], 'filter_action' => 'hide', 'keywords_attributes' => [['keyword' => 'finale']]]);

		[$status, $answer] = $app->query('app.bsky.actor.getPreferences');
		$this->assertSame(200, $status, json_encode($answer));
		$words = [];
		foreach ($answer['preferences'] as $preference) {
			if (($preference['$type'] ?? '') === 'app.bsky.actor.defs#mutedWordsPref') {
				$words = $preference['items'];
			}
		}
		$this->assertSame(['finale'], array_column($words, 'value'), 'the filter is the app\'s muted words');

		$words[] = ['value' => 'crypto', 'targets' => ['content', 'tag'], 'actorTarget' => 'all'];
		[$status, $answer] = $app->procedure('app.bsky.actor.putPreferences', ['preferences' => [['$type' => 'app.bsky.actor.defs#mutedWordsPref', 'items' => $words]]]);
		$this->assertSame(200, $status, json_encode($answer));

		$keywords = [];
		foreach ($this->alice->get('/api/v2/filters') as $filter) {
			foreach ($filter['keywords'] ?? [] as $keyword) {
				$keywords[] = $keyword['keyword'];
			}
		}
		sort($keywords);
		$this->assertSame(['crypto', 'finale'], $keywords, 'the word the app added is a filter here');
	}
}
