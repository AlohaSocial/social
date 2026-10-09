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
use OCA\Social\Atproto\Reader\DetachedQuoteSweep;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Service\ConfigService;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * A Bluesky account quotes a post written here, and its author detaches the
 * quote from here: the post's postgate lists it, so the AppView shows the
 * quote detached, and the quote reads as withdrawn here too.
 */
class AtprotoDetachQuoteTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $alice;

	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null) {
			$this->markTestSkipped('no Bluesky development network (ATPROTO_NETWORK_FILE)');
		}
		$this->network = $network;
		$this->alice = LocalAccount::create('dq');
	}

	public function testAQuoteDetachedHereIsDetachedOnBluesky(): void {
		$identity = Server::get(IdentityService::class)->forActor($this->alice->actor);
		$this->assertNotNull($identity);
		$words = 'Quote me if you must ' . bin2hex(random_bytes(4));
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

		$did = $this->network->createUser('quoter' . bin2hex(random_bytes(3)));
		[$code, $answer] = $this->network->asUser('POST', 'com.atproto.repo.createRecord', [
			'repo' => $did, 'collection' => 'app.bsky.feed.post',
			'record' => [
				'$type' => 'app.bsky.feed.post', 'text' => 'Look at this', 'createdAt' => gmdate('Y-m-d\TH:i:s.000\Z'),
				'embed' => ['$type' => 'app.bsky.embed.record', 'record' => $mine],
			],
		]);
		$this->assertSame(200, $code, json_encode($answer));
		$quoteUri = (string)$answer['uri'];
		$this->assertNotNull($this->network->await(fn (): ?bool => Server::get(PostStore::class)->storeByUri($quoteUri) ? true : null), 'the quote is stored here');
		$quoting = Server::get(StreamRequest::class)->getStreamById(BlueskyIds::postIdOfUri($quoteUri));
		$this->assertSame('accepted', $this->alice->status((string)$quoting->getNid())['quote']['state'] ?? null, 'a Bluesky quote stands unstamped');

		$this->alice->post('/api/v1/statuses/' . $status['id'] . '/quotes/' . $quoting->getNid() . '/revoke');

		$this->assertSame('revoked', $this->alice->status((string)$quoting->getNid())['quote']['state'] ?? null, 'withdrawn here');
		$this->assertNotNull(
			$this->network->await(fn (): ?bool => ($this->network->postView($quoteUri)['embed']['record']['$type'] ?? '') === 'app.bsky.embed.record#viewDetached' ? true : null),
			'the AppView shows the quote detached',
		);
	}

	/**
	 * The other way: a Bluesky author detaches a quote written here. Bluesky
	 * says so only in the author's postgate, which the maintenance sweep
	 * reads; the quote then reads as withdrawn here, as one taken back on
	 * the fediverse does.
	 */
	public function testAQuoteFromHereItsBlueskyAuthorDetachedReadsAsWithdrawn(): void {
		$identity = Server::get(IdentityService::class)->forActor($this->alice->actor);
		$this->assertNotNull($identity);
		$authorDid = $this->network->createUser('detacher' . bin2hex(random_bytes(3)));
		$theirs = $this->network->postText('Quote me if you dare ' . bin2hex(random_bytes(4)));
		$this->assertNotNull($this->network->await(fn (): ?bool => Server::get(PostStore::class)->storeByUri($theirs['uri']) ? true : null), 'their post is stored here');
		$theirsHere = (string)Server::get(StreamRequest::class)->getStreamById(BlueskyIds::postIdOfUri($theirs['uri']))->getNid();

		$words = 'Quoting you ' . bin2hex(random_bytes(4));
		$quote = $this->alice->postStatus($words, null, ['quote_id' => $theirsHere]);
		Server::get(Publisher::class)->reconcile();
		$mine = $this->network->await(function () use ($identity, $words): ?string {
			foreach ($this->network->authorFeed($identity->did) as $item) {
				if (str_contains((string)($item['post']['record']['text'] ?? ''), $words)) {
					return (string)$item['post']['uri'];
				}
			}

			return null;
		});
		$this->assertNotNull($mine, 'the quote reached the AppView');

		$rkey = substr($theirs['uri'], (int)strrpos($theirs['uri'], '/') + 1);
		[$code, $answer] = $this->network->asUser('POST', 'com.atproto.repo.createRecord', [
			'repo' => $authorDid, 'collection' => 'app.bsky.feed.postgate', 'rkey' => $rkey,
			'record' => ['$type' => 'app.bsky.feed.postgate', 'post' => $theirs['uri'], 'detachedEmbeddingUris' => [$mine], 'createdAt' => gmdate('Y-m-d\TH:i:s.000\Z')],
		]);
		$this->assertSame(200, $code, json_encode($answer));

		Server::get(ConfigService::class)->setAppValue(ConfigService::ATPROTO_DETACH_CURSOR, '0');
		while (Server::get(DetachedQuoteSweep::class)->run() === 0 && Server::get(ConfigService::class)->getAppValue(ConfigService::ATPROTO_DETACH_CURSOR) !== '0') {
		}

		$this->assertSame('revoked', $this->alice->status((string)$quote['id'])['quote']['state'] ?? null, 'the detached quote reads as withdrawn here');
	}
}
