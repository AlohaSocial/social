<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Service\Thread\ThreadService;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Replies the author of a conversation hid, the same on both sides: one
 * hidden here is in the post's threadgate on Bluesky, and one a Bluesky
 * author hid is hidden in the conversation here.
 */
class AtprotoHiddenRepliesTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $alice;

	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null) {
			$this->markTestSkipped('no Bluesky development network (ATPROTO_NETWORK_FILE)');
		}
		$this->network = $network;
		$this->alice = LocalAccount::create('hr');
	}

	public function testAReplyHiddenHereIsHiddenOnBluesky(): void {
		$identity = Server::get(IdentityService::class)->forActor($this->alice->actor);
		$this->assertNotNull($identity);
		$words = 'Answer me ' . bin2hex(random_bytes(4));
		$status = $this->alice->postStatus($words);
		Server::get(Publisher::class)->reconcile();
		$mine = $this->network->await(function () use ($identity, $words): ?array {
			foreach ($this->network->authorFeed($identity->did) as $item) {
				if (str_contains((string)($item['post']['record']['text'] ?? ''), $words)) {
					return ['uri' => (string)$item['post']['uri'], 'cid' => (string)$item['post']['cid']];
				}
			}

			return null;
		});
		$this->assertNotNull($mine, 'the AppView indexed the post');

		$this->network->createUser('rude' . bin2hex(random_bytes(3)));
		$reply = $this->network->postText('Something rude ' . bin2hex(random_bytes(4)), ['parent' => $mine]);
		$this->assertNotNull($this->network->await(fn (): ?bool => Server::get(PostStore::class)->storeByUri($reply['uri']) ? true : null), 'the reply is stored here');
		$replyNid = (string)Server::get(StreamRequest::class)->getStreamById(BlueskyIds::postIdOfUri($reply['uri']))->getNid();

		$this->alice->post('/api/v1/statuses/' . $replyNid . '/hide_reply');

		$this->assertNotContains($replyNid, array_column($this->alice->get('/api/v1/statuses/' . $status['id'] . '/context')['descendants'] ?? [], 'id'), 'left out of the conversation here');
		$hidden = $this->network->await(fn (): ?bool => in_array($reply['uri'], $this->network->postView($mine['uri'])['threadgate']['record']['hiddenReplies'] ?? [], true) ? true : null);
		$this->assertTrue($hidden ?? false, 'the threadgate on Bluesky lists it');
	}

	public function testAReplyHiddenOnBlueskyIsHiddenHere(): void {
		$host = 'host' . bin2hex(random_bytes(3));
		$authorDid = $this->network->createUser($host);
		$root = $this->network->postText('My thread ' . bin2hex(random_bytes(4)));
		$this->assertNotNull($this->network->await(fn (): ?bool => Server::get(PostStore::class)->storeByUri($root['uri']) ? true : null), 'the post is stored here');
		$this->network->createUser('guest' . bin2hex(random_bytes(3)));
		$reply = $this->network->postText('Off topic ' . bin2hex(random_bytes(4)), ['parent' => $root]);

		$this->network->switchTo($host);
		$rkey = substr($root['uri'], (int)strrpos($root['uri'], '/') + 1);
		[$code, $answer] = $this->network->asUser('POST', 'com.atproto.repo.createRecord', [
			'repo' => $authorDid, 'collection' => 'app.bsky.feed.threadgate', 'rkey' => $rkey,
			'record' => ['$type' => 'app.bsky.feed.threadgate', 'post' => $root['uri'], 'hiddenReplies' => [$reply['uri']], 'createdAt' => gmdate('Y-m-d\\TH:i:s.000\\Z')],
		]);
		$this->assertSame(200, $code, json_encode($answer));
		$this->assertNotNull($this->network->await(fn (): ?bool => isset($this->network->postView($root['uri'])['threadgate']) ? true : null), 'the AppView has the gate');

		$rootId = BlueskyIds::postIdOfUri($root['uri']);
		Server::get(ThreadService::class)->fill($rootId);
		$nid = (string)Server::get(StreamRequest::class)->getStreamById($rootId)->getNid();
		$replyId = (string)Server::get(StreamRequest::class)->getStreamById(BlueskyIds::postIdOfUri($reply['uri']))->getNid();

		$this->assertNotContains($replyId, array_column($this->alice->get('/api/v1/statuses/' . $nid . '/context')['descendants'] ?? [], 'id'), 'hidden here as on Bluesky');
		$this->assertContains($replyId, array_column($this->alice->get('/api/v1/statuses/' . $nid . '/context', ['with_hidden' => 'true'])['descendants'] ?? [], 'id'), 'and there when asked for');
	}
}
