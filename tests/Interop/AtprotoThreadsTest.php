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
use OCA\Social\Atproto\Reader\DeletionSweep;
use OCA\Social\Atproto\Reader\FeedPoller;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Db\AtprotoWatchRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\Thread\ThreadService;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Phase 3a against Bluesky's own software: a thread spans both networks.
 * A local reply to a Bluesky post is a reply in the Bluesky thread, a
 * local quote of it is a record embed of it, and a post its author deletes
 * on Bluesky goes away here.
 */
class AtprotoThreadsTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $alice;

	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null) {
			$this->markTestSkipped('no Bluesky development network (ATPROTO_NETWORK_FILE)');
		}
		$this->network = $network;
		$this->alice = LocalAccount::create('th');
	}

	public function testAThreadSpansBothNetworks(): void {
		$bobDid = $this->network->createUser('thread' . bin2hex(random_bytes(3)));
		$identity = Server::get(IdentityService::class)->forActor($this->alice->actor);
		$this->assertNotNull($identity);
		$bobId = $this->network->await(function (): ?string {
			try {
				return $this->alice->resolve($this->network->userHandle());
			} catch (\Throwable) {
				return null;
			}
		});
		$this->assertNotNull($bobId);
		$this->alice->follow($bobId);

		$words = 'Thread start ' . bin2hex(random_bytes(4));
		$bobs = $this->network->postText($words);
		$status = $this->network->await(function () use ($words, $bobDid): ?array {
			Server::get(FeedPoller::class)->pollWatch(Server::get(AtprotoWatchRequest::class)->getByDid($bobDid));
			foreach ($this->alice->get('/api/v1/timelines/home', ['limit' => '40']) as $s) {
				if (is_array($s) && str_contains((string)($s['content'] ?? ''), $words)) {
					return $s;
				}
			}

			return null;
		});
		$this->assertNotNull($status, 'the post was read');

		// a reply from here is a reply in the Bluesky thread
		$replyWords = 'Answered from Aloha ' . bin2hex(random_bytes(4));
		$this->alice->postStatus($replyWords, (string)$status['id']);
		Server::get(Publisher::class)->reconcile();
		$reply = $this->network->await(function () use ($bobs, $replyWords): ?array {
			foreach ($this->network->replies($bobs['uri']) as $post) {
				if (str_contains((string)($post['record']['text'] ?? ''), $replyWords)) {
					return $post;
				}
			}

			return null;
		});
		$this->assertNotNull($reply, 'the reply is in the thread on Bluesky');
		$this->assertSame($identity->did, $reply['author']['did']);
		$this->assertSame($bobs['uri'], $reply['record']['reply']['parent']['uri']);
		$this->assertSame($bobs['uri'], $reply['record']['reply']['root']['uri']);

		// a quote from here embeds the quoted post
		$quoteWords = 'Worth reading ' . bin2hex(random_bytes(4));
		$this->alice->postStatus($quoteWords, null, ['quote_id' => (string)$status['id']]);
		Server::get(Publisher::class)->reconcile();
		$embedded = $this->network->await(function () use ($identity, $quoteWords): ?array {
			foreach ($this->network->authorFeed($identity->did) as $item) {
				if (str_contains((string)($item['post']['record']['text'] ?? ''), $quoteWords)) {
					return $item['post'];
				}
			}

			return null;
		});
		$this->assertNotNull($embedded, 'the quote reached the AppView');
		$this->assertSame($bobs['uri'], $embedded['record']['embed']['record']['uri'] ?? '', 'a record embed, not a link');

		// the author deletes it on Bluesky; the check notices
		$this->network->deletePost($bobs['uri']);
		$gone = $this->network->await(function () use ($status): ?bool {
			Server::get(ConfigService::class)->setAppValue(ConfigService::ATPROTO_DELETE_CURSOR, '0');
			Server::get(DeletionSweep::class)->run();

			return $this->alice->status((string)$status['id']) === null ? true : null;
		});
		$this->assertTrue($gone, 'the post deleted on Bluesky is gone here');
	}

	/**
	 * A reply by a Bluesky account nobody here follows is not read by any
	 * poller; opening the conversation reads it from the AppView, so the
	 * thread here is the thread there.
	 */
	public function testOpeningAConversationBringsInTheRepliesOfStrangers(): void {
		$this->network->createUser('root' . bin2hex(random_bytes(3)));
		$root = $this->network->postText('Who wants to answer ' . bin2hex(random_bytes(4)));
		$this->assertNotNull($this->network->await(fn (): ?bool => Server::get(PostStore::class)->storeByUri($root['uri']) ? true : null), 'the post is stored here');
		$this->network->createUser('stranger' . bin2hex(random_bytes(3)));
		$words = 'A stranger answers ' . bin2hex(random_bytes(4));
		$reply = $this->network->postText($words, ['parent' => $root]);
		$this->assertNotNull($this->network->await(fn (): ?bool => in_array($reply['uri'], array_column($this->network->replies($root['uri']), 'uri'), true) ? true : null), 'the AppView has the reply');
		$nid = (string)Server::get(StreamRequest::class)->getStreamById(BlueskyIds::postIdOfUri($root['uri']))->getNid();

		$this->alice->get('/api/v1/statuses/' . $nid . '/context');
		Server::get(ThreadService::class)->fill(BlueskyIds::postIdOfUri($root['uri']));

		$descendants = $this->alice->get('/api/v1/statuses/' . $nid . '/context')['descendants'] ?? [];
		$this->assertNotEmpty(array_filter($descendants, static fn (array $s): bool => str_contains((string)($s['content'] ?? ''), $words)), 'the stranger\'s reply is not in the conversation here');
	}
}
