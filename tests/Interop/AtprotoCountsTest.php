<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Service\Counts\CountService;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The counts of a post that lives on Bluesky stay current here: a post
 * stored with no likes is liked there, and once its counts are asked for
 * again the status here has the like.
 */
class AtprotoCountsTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $alice;

	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null) {
			$this->markTestSkipped('no Bluesky development network (ATPROTO_NETWORK_FILE)');
		}
		$this->network = $network;
		$this->alice = LocalAccount::create('ct');
	}

	public function testALikeWhereThePostLivesReachesItsCountHere(): void {
		$this->network->createUser('counted' . bin2hex(random_bytes(3)));
		$post = $this->network->postText('Count me ' . bin2hex(random_bytes(4)));
		$this->assertNotNull($this->network->await(fn (): ?bool => Server::get(PostStore::class)->storeByUri($post['uri']) ? true : null), 'the post is stored here');
		$postId = BlueskyIds::postIdOfUri($post['uri']);
		$nid = (string)Server::get(StreamRequest::class)->getStreamById($postId)->getNid();
		$this->assertSame(0, (int)($this->alice->status($nid)['favourites_count'] ?? -1), 'stored with no likes');

		$likerDid = $this->network->createUser('liker' . bin2hex(random_bytes(3)));
		$this->network->like($post['uri'], $post['cid']);
		$this->assertNotNull($this->network->await(fn (): ?bool => in_array($likerDid, $this->network->likers($post['uri']), true) ? true : null), 'the AppView has the like');

		$counts = Server::get(CountService::class);
		$status = [];
		$counted = $this->network->await(function () use ($counts, $postId, $nid, &$status): ?bool {
			// what the background read does once the post is due, asked now
			$counts->refreshPosts([Server::get(StreamRequest::class)->getStreamById($postId)]);
			$status = $this->alice->status($nid) ?? [];

			return (int)($status['favourites_count'] ?? 0) === 1 ? true : null;
		});
		$this->assertTrue($counted ?? false, 'the like is counted here, the status says ' . json_encode(array_intersect_key($status, array_flip(['favourites_count', 'reblogs_count', 'replies_count']))));

		// and a quote of it, by somebody nobody here follows: counted all the same
		[$code, $answer] = $this->network->asUser('POST', 'com.atproto.repo.createRecord', [
			'repo' => $likerDid, 'collection' => 'app.bsky.feed.post',
			'record' => ['$type' => 'app.bsky.feed.post', 'text' => 'Quoting it', 'createdAt' => gmdate('Y-m-d\TH:i:s.000\Z'), 'embed' => ['$type' => 'app.bsky.embed.record', 'record' => $post]],
		]);
		$this->assertSame(200, $code, json_encode($answer));
		$this->assertNotNull($this->network->await(fn (): ?bool => (int)($this->network->postView($post['uri'])['quoteCount'] ?? 0) === 1 ? true : null), 'the AppView counts the quote');
		$quoted = $this->network->await(function () use ($counts, $postId, $nid, &$status): ?bool {
			$counts->refreshPosts([Server::get(StreamRequest::class)->getStreamById($postId)]);
			$status = $this->alice->status($nid) ?? [];

			return (int)($status['quotes_count'] ?? 0) === 1 ? true : null;
		});
		$this->assertTrue($quoted ?? false, 'the quote is counted here, the status says quotes_count ' . json_encode($status['quotes_count'] ?? null));
	}
}
