<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Phase 1 of the Bluesky work, demonstrated against the official software:
 * a local account is a Bluesky account the development network can resolve,
 * its public post is indexed by the AppView, a Bluesky user follows it and
 * likes the post, an edit and a delete are followed through, and the relay
 * (when the job runs one) has verified the repository's commits.
 *
 * Nothing is stubbed: the PLC directory, the PDS the Bluesky user is on and
 * the AppView are the reference implementation; the firehose the AppView
 * reads is this app's daemon behind the job's web server.
 */
class AtprotoVisibleTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $alice;

	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null) {
			$this->markTestSkipped('no Bluesky development network (ATPROTO_NETWORK_FILE)');
		}
		$this->network = $network;
		$this->alice = LocalAccount::create('atp');
	}

	public function testALocalAccountIsVisibleOnBlueskyAndFollowedFromThere(): void {
		// the identity: made on first need, registered with the directory the
		// job runs, and resolvable from the Bluesky side both ways
		$identity = Server::get(IdentityService::class)->forActor($this->alice->actor);
		$this->assertNotNull($identity, 'the account got a Bluesky identity');
		$this->assertStringEndsWith('.nextcloud.test', $identity->handle);
		$document = $this->network->await(fn () => $this->network->didDocument($identity->did));
		$this->assertNotNull($document, 'the directory knows the DID');
		$this->assertSame(['at://' . $identity->handle], $document['alsoKnownAs']);
		$this->assertSame('https://nextcloud.test', $document['service'][0]['serviceEndpoint']);
		// the dev PDS answers from the AppView's index, which learns the
		// handle off this app's firehose, so it is asked until it knows
		$resolved = $this->network->await(fn () => $this->network->resolveHandle($identity->handle) === $identity->did ? $identity->did : null);
		$this->assertSame($identity->did, $resolved, 'the dev PDS resolves the handle through this app');

		// a public post goes to Bluesky as it is made; the AppView indexes it
		// off this app's firehose
		$words = 'Visible on Bluesky ' . bin2hex(random_bytes(4));
		$status = $this->alice->postStatus($words . ' https://nextcloud.com #interop');
		Server::get(Publisher::class)->reconcile();
		$feedItem = $this->network->await(function () use ($identity, $words): ?array {
			foreach ($this->network->authorFeed($identity->did) as $item) {
				if (str_contains((string)($item['post']['record']['text'] ?? ''), $words)) {
					return $item['post'];
				}
			}

			return null;
		});
		$this->assertNotNull($feedItem, 'the AppView indexed the post from the firehose');
		$this->assertSame($identity->handle, $feedItem['author']['handle']);
		$facets = $feedItem['record']['facets'] ?? [];
		$this->assertNotEmpty($facets, 'the link and the hashtag are facets');

		$profile = $this->network->await(fn () => $this->network->profile($identity->handle));
		$this->assertNotNull($profile, 'the profile is found by handle');
		$this->assertSame($identity->did, $profile['did']);

		// a Bluesky user follows and likes
		$bobDid = $this->network->createUser('bob' . bin2hex(random_bytes(2)));
		$this->network->follow($identity->did);
		$followers = $this->network->await(fn () => in_array($bobDid, $this->network->followers($identity->did), true) ? true : null);
		$this->assertTrue($followers, 'the AppView lists the Bluesky follower');
		$this->network->like((string)$feedItem['uri'], (string)$feedItem['cid']);
		$liked = $this->network->await(function () use ($identity, $feedItem): ?int {
			foreach ($this->network->authorFeed($identity->did) as $item) {
				if (($item['post']['uri'] ?? '') === $feedItem['uri'] && (int)($item['post']['likeCount'] ?? 0) > 0) {
					return (int)$item['post']['likeCount'];
				}
			}

			return null;
		});
		$this->assertSame(1, $liked, 'the like counted on the AppView');

		// the relay, when the job runs one, has verified the commits
		$relayStatus = $this->network->await(fn () => $this->network->relayRepoStatus($identity->did), 30);
		if ($this->network->relay !== '') {
			$this->assertNotNull($relayStatus, 'the relay has the repository');
			$this->assertTrue($relayStatus['active']);
			$this->assertSame(Server::get(RepositoryService::class)->getHead($identity->did)?->rev, $relayStatus['rev'], 'the relay is at the same revision');
		}

		// an edit within the grace period replaces the record; the AppView
		// shows the new text under a new URI
		$edited = $words . ' (edited)';
		$this->alice->editStatus((string)$status['id'], $edited);
		$editedItem = $this->network->await(function () use ($identity, $edited): ?array {
			foreach ($this->network->authorFeed($identity->did) as $item) {
				if (($item['post']['record']['text'] ?? '') === $edited || str_starts_with((string)($item['post']['record']['text'] ?? ''), $edited)) {
					return $item['post'];
				}
			}

			return null;
		});
		$this->assertNotNull($editedItem, 'the edit reached the AppView');
		$this->assertNotSame($feedItem['uri'], $editedItem['uri'], 'an edit is a new record');

		// a delete takes it off
		$this->alice->deleteStatus((string)$status['id']);
		$gone = $this->network->await(function () use ($identity, $editedItem): ?bool {
			foreach ($this->network->authorFeed($identity->did) as $item) {
				if (($item['post']['uri'] ?? '') === $editedItem['uri']) {
					return null;
				}
			}

			return true;
		});
		$this->assertTrue($gone, 'the deleted post left the AppView');
	}
}
