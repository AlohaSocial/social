<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A Bluesky thread its author closed to replies (`threadgate`, nobody
 * allowed): the post here says replies are not allowed, and a reply from
 * here is refused with the reason instead of being sent to where every
 * AppView would hide it.
 */
class AtprotoThreadgateTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $alice;

	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null) {
			$this->markTestSkipped('no Bluesky development network (ATPROTO_NETWORK_FILE)');
		}
		$this->network = $network;
		$this->alice = LocalAccount::create('tg');
	}

	public function testAThreadClosedToRepliesTakesNoReplyFromHere(): void {
		$this->assertNotNull(Server::get(IdentityService::class)->forActor($this->alice->actor));
		$did = $this->network->createUser('gated' . bin2hex(random_bytes(3)));
		$post = $this->network->postText('No replies, please ' . bin2hex(random_bytes(3)));
		$rkey = substr($post['uri'], (int)strrpos($post['uri'], '/') + 1);
		[$status, $answer] = $this->network->asUser('POST', 'com.atproto.repo.createRecord', [
			'repo' => $did, 'collection' => 'app.bsky.feed.threadgate', 'rkey' => $rkey,
			'record' => ['$type' => 'app.bsky.feed.threadgate', 'post' => $post['uri'], 'allow' => [], 'createdAt' => gmdate('Y-m-d\TH:i:s.000\Z')],
		]);
		$this->assertSame(200, $status, json_encode($answer));
		$this->assertNotNull($this->network->await(fn () => isset($this->network->postView($post['uri'])['threadgate']) ? true : null), 'the AppView has the gate');

		$this->assertTrue(Server::get(PostStore::class)->storeByUri($post['uri']));
		$nid = (string)Server::get(StreamRequest::class)->getStreamById(BlueskyIds::postIdOfUri($post['uri']))->getNid();
		$this->assertFalse($this->alice->status($nid)['interaction_policy']['reply'] ?? null, 'replies are not offered');

		try {
			$this->alice->postStatus('Replying anyway', $nid);
			$this->fail('the reply went out');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('allows no replies', $e->getMessage());
		}
	}
}
