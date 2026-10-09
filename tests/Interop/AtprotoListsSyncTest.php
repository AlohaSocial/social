<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Atproto\Client\AppPasswordService;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Publisher\BlueskyLists;
use OCA\Social\Tests\Interop\Bluesky\AppClient;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * A person's lists are one set, here and in a Bluesky app signed in here: a
 * list made public here is a Bluesky list with its members, withdrawn when
 * it is made private again, and a list the app makes is a public list here
 * with the app's members, gone here when the app deletes it.
 */
class AtprotoListsSyncTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $alice;

	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null) {
			$this->markTestSkipped('no Bluesky development network (ATPROTO_NETWORK_FILE)');
		}
		$this->network = $network;
		$this->alice = LocalAccount::create('ls');
	}

	private function app(): AppClient {
		$identity = Server::get(IdentityService::class)->forActor($this->alice->actor);
		$this->assertNotNull($identity);
		$app = AppClient::at((string)getenv('NEXTCLOUD_URL') ?: 'https://nextcloud.test');
		[$status, $answer] = $app->signIn($identity->handle, Server::get(AppPasswordService::class)->create($this->alice->userId, 'lists')['password']);
		$this->assertSame(200, $status, json_encode($answer));

		return $app;
	}

	public function testAListMadePublicHereIsOnBlueskyWithItsMembersUntilMadePrivate(): void {
		$this->app();
		$bob = $this->network->createUser('onlist' . bin2hex(random_bytes(3)));
		$this->assertNotNull($this->network->await(fn (): ?bool => $this->network->resolveHandle($this->network->userHandle()) === $bob ? true : null), 'the AppView knows bob');
		$bobHere = $this->alice->resolve($this->network->userHandle());
		$this->alice->follow($bobHere);
		$list = $this->alice->post('/api/v1/lists', ['title' => 'People I read']);
		$this->alice->post('/api/v1/lists/' . $list['id'] . '/accounts', ['account_ids' => [$bobHere]]);
		$this->assertNull(Server::get(BlueskyLists::class)->uriOf((int)$list['id']), 'a private list stays here');

		$made = $this->alice->put('/api/v1/lists/' . $list['id'], ['title' => 'People I read', 'public' => true]);
		$this->assertTrue($made['public'] ?? null);
		$uri = Server::get(BlueskyLists::class)->uriOf((int)$list['id']);
		$this->assertNotNull($uri, 'published');
		$this->assertNotNull($this->network->await(fn (): ?bool => in_array($bob, $this->network->listItems($uri), true) ? true : null), 'bob is on the Bluesky list');

		$this->alice->put('/api/v1/lists/' . $list['id'], ['title' => 'People I read', 'public' => false]);
		$this->assertNull(Server::get(BlueskyLists::class)->uriOf((int)$list['id']), 'withdrawn');
		$this->assertNotNull($this->network->await(fn (): ?bool => $this->network->listItems($uri) === [] ? true : null), 'the AppView has it no more');
	}

	public function testAListABlueskyAppMakesHereIsAListHereWithItsMembers(): void {
		$app = $this->app();
		$identity = Server::get(IdentityService::class)->forActor($this->alice->actor);
		$this->assertNotNull($identity);
		$bob = $this->network->createUser('applist' . bin2hex(random_bytes(3)));
		$this->assertNotNull($this->network->await(fn (): ?bool => $this->network->profile($bob) !== null ? true : null), 'the AppView has the account');
		$name = 'From the app ' . bin2hex(random_bytes(3));
		$now = gmdate('Y-m-d\TH:i:s.000\Z');

		[$status, $made] = $app->procedure('com.atproto.repo.createRecord', [
			'repo' => $identity->did, 'collection' => 'app.bsky.graph.list',
			'record' => ['$type' => 'app.bsky.graph.list', 'purpose' => 'app.bsky.graph.defs#curatelist', 'name' => $name, 'createdAt' => $now],
		]);
		$this->assertSame(200, $status, json_encode($made));
		[$status, $item] = $app->procedure('com.atproto.repo.createRecord', [
			'repo' => $identity->did, 'collection' => 'app.bsky.graph.listitem',
			'record' => ['$type' => 'app.bsky.graph.listitem', 'list' => $made['uri'], 'subject' => $bob, 'createdAt' => $now],
		]);
		$this->assertSame(200, $status, json_encode($item));

		$here = array_values(array_filter($this->alice->get('/api/v1/lists'), static fn (array $list): bool => $list['title'] === $name));
		$this->assertCount(1, $here, 'the app\'s list is a list here');
		$this->assertTrue($here[0]['public'], 'and public, as on Bluesky');
		$members = $this->alice->get('/api/v1/lists/' . $here[0]['id'] . '/accounts');
		$this->assertCount(1, $members, json_encode($members));
		$this->assertSame($made['uri'], Server::get(BlueskyLists::class)->uriOf((int)$here[0]['id']), 'one list, not a second one published');

		[$status] = $app->procedure('com.atproto.repo.deleteRecord', [
			'repo' => $identity->did, 'collection' => 'app.bsky.graph.list', 'rkey' => substr((string)$made['uri'], (int)strrpos((string)$made['uri'], '/') + 1),
		]);
		$this->assertSame(200, $status);
		$this->assertSame([], array_values(array_filter($this->alice->get('/api/v1/lists'), static fn (array $list): bool => $list['title'] === $name)), 'deleted here too');
	}
}
