<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Atproto\Client\AppPasswordService;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Tests\Interop\Bluesky\AppClient;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Bookmarks, the same in Social and in a Bluesky app signed in here: one
 * made in the app is a bookmark here, and one made here is in the app's
 * list and on the app's bookmark button.
 */
class AtprotoBookmarksTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $alice;

	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null) {
			$this->markTestSkipped('no Bluesky development network (ATPROTO_NETWORK_FILE)');
		}
		$this->network = $network;
		$this->alice = LocalAccount::create('bm');
	}

	public function testABookmarkIsTheSameInEitherApp(): void {
		$identity = Server::get(IdentityService::class)->forActor($this->alice->actor);
		$this->assertNotNull($identity);
		$app = AppClient::at((string)getenv('NEXTCLOUD_URL') ?: 'https://nextcloud.test');
		[$status, $answer] = $app->signIn($identity->handle, Server::get(AppPasswordService::class)->create($this->alice->userId, 'bookmarks')['password']);
		$this->assertSame(200, $status, json_encode($answer));

		$this->network->createUser('marked' . bin2hex(random_bytes(3)));
		$first = $this->network->postText('Bookmark me in the app ' . bin2hex(random_bytes(4)));
		$second = $this->network->postText('Bookmark me here ' . bin2hex(random_bytes(4)));
		foreach ([$first, $second] as $post) {
			$this->assertNotNull($this->network->await(fn (): ?bool => Server::get(PostStore::class)->storeByUri($post['uri']) ? true : null), 'the post is stored here');
		}
		$nid = static fn (array $post): string => (string)Server::get(StreamRequest::class)->getStreamById(BlueskyIds::postIdOfUri($post['uri']))->getNid();

		[$status, $answer] = $app->procedure('app.bsky.bookmark.createBookmark', ['uri' => $first['uri'], 'cid' => $first['cid']]);
		$this->assertSame(200, $status, json_encode($answer));
		$this->assertContains($nid($first), array_column($this->alice->get('/api/v1/bookmarks'), 'id'), 'the app\'s bookmark is a bookmark here');

		$this->alice->post('/api/v1/statuses/' . $nid($second) . '/bookmark');
		[$status, $listed] = $app->query('app.bsky.bookmark.getBookmarks', ['limit' => 20]);
		$this->assertSame(200, $status, json_encode($listed));
		$uris = array_map(static fn (array $b): string => (string)($b['subject']['uri'] ?? ''), $listed['bookmarks'] ?? []);
		$this->assertContains($second['uri'], $uris, 'the bookmark made here is in the app\'s list');
		$this->assertContains($first['uri'], $uris);
		$this->assertNotNull($this->network->await(function () use ($app, $second): ?bool {
			[, $posts] = $app->query('app.bsky.feed.getPosts', ['uris' => $second['uri']]);

			return ($posts['posts'][0]['viewer']['bookmarked'] ?? false) === true ? true : null;
		}), 'the app shows it bookmarked');
	}
}
