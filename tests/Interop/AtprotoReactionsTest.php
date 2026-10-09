<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Atproto\Reader\BlueskyInteractionSource;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Service\Interaction\InteractionService;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Who liked and who quoted a post where it lives, here: a Bluesky account
 * nobody here follows likes and quotes a Bluesky post, and the post's list
 * of likes and of quotes here has them, as any other.
 */
class AtprotoReactionsTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $alice;

	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null) {
			$this->markTestSkipped('no Bluesky development network (ATPROTO_NETWORK_FILE)');
		}
		$this->network = $network;
		$this->alice = LocalAccount::create('rx');
	}

	public function testTheLikesAndQuotesWhereThePostLivesAreInItsLists(): void {
		$this->network->createUser('liked' . bin2hex(random_bytes(3)));
		$post = $this->network->postText('Like me ' . bin2hex(random_bytes(4)));
		$this->assertNotNull($this->network->await(fn (): ?bool => Server::get(PostStore::class)->storeByUri($post['uri']) ? true : null), 'the post is stored here');
		$postId = BlueskyIds::postIdOfUri($post['uri']);
		$nid = (string)Server::get(StreamRequest::class)->getStreamById($postId)->getNid();

		$likerDid = $this->network->createUser('liker' . bin2hex(random_bytes(3)));
		$liker = $this->network->userHandle();
		// until the AppView has verified a new handle it lists the account as
		// `handle.invalid`
		$this->assertNotNull($this->network->await(fn (): ?bool => $this->network->resolveHandle($liker) === $likerDid ? true : null), 'the AppView knows the liker\'s handle');
		$this->network->like($post['uri'], $post['cid']);
		$words = 'Quoting it ' . bin2hex(random_bytes(4));
		[$code, $answer] = $this->network->asUser('POST', 'com.atproto.repo.createRecord', [
			'repo' => $likerDid, 'collection' => 'app.bsky.feed.post',
			'record' => ['$type' => 'app.bsky.feed.post', 'text' => $words, 'createdAt' => gmdate('Y-m-d\TH:i:s.000\Z'), 'embed' => ['$type' => 'app.bsky.embed.record', 'record' => $post]],
		]);
		$this->assertSame(200, $code, json_encode($answer));
		$this->assertNotNull($this->network->await(fn (): ?bool => in_array($likerDid, $this->network->likers($post['uri']), true) ? true : null), 'the AppView has the like');

		$stored = Server::get(StreamRequest::class)->getStreamById($postId);
		$source = Server::get(BlueskyInteractionSource::class);
		$this->assertTrue($source->supports($stored), 'the post is one Bluesky is asked about');
		$this->assertNotNull($this->network->await(fn (): ?bool => in_array('https://bsky.app/profile/' . $likerDid, $source->actors($stored, 'Like', 80), true) ? true : null), 'Bluesky names the liker');

		$interactions = Server::get(InteractionService::class);
		$listed = [];
		$liked = $this->network->await(function () use ($interactions, $postId, $nid, $liker, &$listed): ?bool {
			$interactions->fill($postId, 'Like');
			$listed = array_column($this->alice->get('/api/v1/statuses/' . $nid . '/favourited_by'), 'acct');

			return in_array($liker, $listed, true) ? true : null;
		});
		$this->assertTrue($liked ?? false, 'the account that liked it on Bluesky is in its likes here, which has ' . json_encode($listed));

		$quoted = $this->network->await(function () use ($interactions, $postId, $nid, $words): ?bool {
			$interactions->fill($postId, InteractionService::QUOTES);
			foreach ($this->alice->get('/api/v1/statuses/' . $nid . '/quotes') as $quote) {
				if (str_contains((string)($quote['content'] ?? ''), $words)) {
					return true;
				}
			}

			return null;
		});
		$this->assertTrue($quoted ?? false, 'the post quoting it on Bluesky is in its quotes here');
	}
}
