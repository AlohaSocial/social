<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use InvalidArgumentException;
use OCA\Social\Atproto\Client\AppPasswordService;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Reader\BlueskyFeeds;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Tests\Interop\Bluesky\AppClient;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Bluesky's custom feeds and lists, read here: a feed a Bluesky account
 * published, served by a feed generator, is kept by an account here and read
 * in the generator's order; a list a Bluesky app made through this server is
 * published, and read as its feed.
 */
class AtprotoFeedsTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $alice;

	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null || $network->feedGenDid === '') {
			$this->markTestSkipped('no Bluesky development network with a feed generator (ATPROTO_NETWORK_FILE)');
		}
		$this->network = $network;
		$this->alice = LocalAccount::create('fd');
	}

	/** @return string[] the post ids of a page */
	private static function ids(array $page): array {
		return array_map(static fn (Stream $post): string => $post->getId(), $page);
	}

	public function testACustomFeedIsKeptAndReadInItsOwnOrder(): void {
		$this->assertNotNull(Server::get(IdentityService::class)->forActor($this->alice->actor));
		$bob = $this->network->createUser('feeder' . bin2hex(random_bytes(3)));
		$first = $this->network->postText('First for the feed ' . bin2hex(random_bytes(3)))['uri'];
		$second = $this->network->postText('Second for the feed ' . bin2hex(random_bytes(3)))['uri'];
		[$status, $answer] = $this->network->asUser('POST', 'com.atproto.repo.createRecord', [
			'repo' => $bob, 'collection' => 'app.bsky.feed.generator', 'rkey' => 'interop',
			'record' => ['$type' => 'app.bsky.feed.generator', 'did' => $this->network->feedGenDid, 'displayName' => 'Interop picks', 'createdAt' => gmdate('Y-m-d\TH:i:s.000\Z')],
		]);
		$this->assertSame(200, $status, json_encode($answer));
		$feed = (string)$answer['uri'];
		$this->network->serveFeed([$first, $second]);

		$feeds = Server::get(BlueskyFeeds::class);
		$added = $this->network->await(function () use ($feeds, $bob): ?array {
			try {
				return $feeds->add($this->alice->actor, 'https://bsky.app/profile/' . $bob . '/feed/interop');
			} catch (InvalidArgumentException) {
				return null;
			}
		});
		$this->assertSame(['uri' => $feed, 'name' => 'Interop picks', 'pinned' => true], array_intersect_key((array)$added, ['uri' => 1, 'name' => 1, 'pinned' => 1]), 'kept, by its bsky.app address');
		$this->assertSame([$feed], array_column($feeds->saved($this->alice->actor), 'uri'));

		$this->assertSame(
			[BlueskyIds::postIdOfUri($first), BlueskyIds::postIdOfUri($second)],
			self::ids($feeds->page($this->alice->actor, $feed, '', 10)),
			'the generator\'s order, the older post first',
		);

		$feeds->remove($this->alice->actor, $feed);
		$this->assertSame([], $feeds->saved($this->alice->actor));
	}

	public function testAListAnAppMakesHereIsPublishedAndReadAsItsFeed(): void {
		$identity = Server::get(IdentityService::class)->forActor($this->alice->actor);
		$this->assertNotNull($identity);
		$bob = $this->network->createUser('listed' . bin2hex(random_bytes(3)));
		$post = $this->network->postText('Read through a list ' . bin2hex(random_bytes(3)))['uri'];
		$password = Server::get(AppPasswordService::class)->create($this->alice->userId, 'interop')['password'];
		$app = AppClient::at((string)getenv('NEXTCLOUD_URL') ?: 'https://nextcloud.test');
		$this->assertSame(200, $app->signIn($identity->handle, $password)[0]);

		$now = gmdate('Y-m-d\TH:i:s.000\Z');
		[$status, $list] = $app->procedure('com.atproto.repo.createRecord', [
			'repo' => $identity->did, 'collection' => 'app.bsky.graph.list',
			'record' => ['$type' => 'app.bsky.graph.list', 'purpose' => 'app.bsky.graph.defs#curatelist', 'name' => 'Interop friends', 'createdAt' => $now],
		]);
		$this->assertSame(200, $status, json_encode($list));
		[$status, $item] = $app->procedure('com.atproto.repo.createRecord', [
			'repo' => $identity->did, 'collection' => 'app.bsky.graph.listitem',
			'record' => ['$type' => 'app.bsky.graph.listitem', 'list' => $list['uri'], 'subject' => $bob, 'createdAt' => $now],
		]);
		$this->assertSame(200, $status, json_encode($item));
		[$status, $refused] = $app->procedure('com.atproto.repo.createRecord', [
			'repo' => $identity->did, 'collection' => 'app.bsky.graph.listblock',
			'record' => ['$type' => 'app.bsky.graph.listblock', 'subject' => $list['uri'], 'createdAt' => $now],
		]);
		$this->assertSame(400, $status, 'a list block is a block, and blocks stay here: ' . json_encode($refused));

		$feeds = Server::get(BlueskyFeeds::class);
		$page = $this->network->await(function () use ($feeds, $list): ?array {
			$page = $feeds->page($this->alice->actor, (string)$list['uri'], '', 10);

			return $page === [] ? null : $page;
		});
		$this->assertSame([BlueskyIds::postIdOfUri($post)], self::ids((array)$page), 'the AppView read the list from this server, and its member\'s post');
	}
}
