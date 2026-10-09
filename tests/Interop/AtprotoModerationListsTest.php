<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Atproto\Client\AppPasswordService;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Service\ModerationList\ModerationListService;
use OCA\Social\Tests\Interop\Bluesky\AppClient;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * A moderation list somebody keeps on Bluesky, subscribed to here: everybody
 * on it is muted here, the list is muted at the AppView for a Bluesky app
 * signed in here, and ending the subscription undoes both.
 */
class AtprotoModerationListsTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $alice;

	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null) {
			$this->markTestSkipped('no Bluesky development network (ATPROTO_NETWORK_FILE)');
		}
		$this->network = $network;
		$this->alice = LocalAccount::create('ml');
	}

	public function testEverybodyOnASubscribedListIsMutedHereAndAtTheAppView(): void {
		$suffix = bin2hex(random_bytes(3));
		$spammer = $this->network->createUser('spam' . $suffix);
		$this->network->createUser('keeper' . $suffix);
		[$status, $list] = $this->network->asUser('POST', 'com.atproto.repo.createRecord', [
			'repo' => $this->network->userDid(),
			'collection' => 'app.bsky.graph.list',
			'record' => ['$type' => 'app.bsky.graph.list', 'purpose' => 'app.bsky.graph.defs#modlist', 'name' => 'Spammers', 'createdAt' => gmdate('Y-m-d\TH:i:s.v\Z')],
		]);
		$this->assertSame(200, $status, json_encode($list));
		[$status, $item] = $this->network->asUser('POST', 'com.atproto.repo.createRecord', [
			'repo' => $this->network->userDid(),
			'collection' => 'app.bsky.graph.listitem',
			'record' => ['$type' => 'app.bsky.graph.listitem', 'subject' => $spammer, 'list' => $list['uri'], 'createdAt' => gmdate('Y-m-d\TH:i:s.v\Z')],
		]);
		$this->assertSame(200, $status, json_encode($item));
		$this->assertNotNull($this->network->await(fn (): ?bool => in_array($spammer, $this->network->listItems($list['uri']), true) ? true : null), 'the AppView has the list');
		$this->assertNotNull($this->network->await(fn (): ?bool => $this->network->resolveHandle('spam' . $suffix . '.test') === $spammer ? true : null), 'the AppView verified the handle');

		// signed in first: that makes alice's Bluesky identity, as opening
		// Social does, and a list is muted at the AppView as that identity
		$app = $this->app();
		$lists = Server::get(ModerationListService::class);
		$subscription = $lists->subscribe($this->alice->actor, $list['uri'], 'mute');
		$this->assertSame('Spammers', $subscription['name']);
		$this->assertSame(1, $subscription['accounts']);

		$spammerHere = $this->alice->resolve('spam' . $suffix . '.test');
		$this->assertTrue($this->alice->relationship($spammerHere)['muting'] ?? false, 'muted here');

		$listMutes = function () use ($app): array {
			[$status, $answer] = $app->query('app.bsky.graph.getListMutes', ['limit' => 50]);

			return $status === 200 ? array_column($answer['lists'] ?? [], 'uri') : [];
		};
		$this->assertNotNull($this->network->await(fn (): ?bool => in_array($list['uri'], $listMutes(), true) ? true : null), 'the list is muted at the AppView');

		$lists->unsubscribe($this->alice->actor, $list['uri']);
		$this->assertFalse($this->alice->relationship($spammerHere)['muting'] ?? true, 'unmuted here');
		$this->assertNotNull($this->network->await(fn (): ?bool => !in_array($list['uri'], $listMutes(), true) ? true : null), 'and at the AppView');
	}

	private function app(): AppClient {
		$identity = Server::get(IdentityService::class)->forActor($this->alice->actor);
		$this->assertNotNull($identity);
		$app = AppClient::at((string)getenv('NEXTCLOUD_URL') ?: 'https://nextcloud.test');
		[$status, $answer] = $app->signIn($identity->handle, Server::get(AppPasswordService::class)->create($this->alice->userId, 'lists')['password']);
		$this->assertSame(200, $status, json_encode($answer));

		return $app;
	}
}
