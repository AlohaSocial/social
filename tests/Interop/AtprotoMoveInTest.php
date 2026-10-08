<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Move;
use OCA\Social\Atproto\Move\MoveInService;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Db\AtprotoMoveRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * A Bluesky account moves here (§13.1) from the development network's PDS,
 * driven from here: this server signs in to the old PDS with the account's
 * password, copies the repository and the follows, and with the code the
 * old PDS e-mails — read here from its inbox — has the DID pointed here.
 * The local account then is that Bluesky account, under its handle here,
 * and its posts are posts in its timeline here.
 */
class AtprotoMoveInTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $alice;

	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null || $network->handleServer === '') {
			$this->markTestSkipped('no Bluesky development network with a handle server');
		}
		$this->network = $network;
		$this->alice = LocalAccount::create('mi');
	}

	public function testABlueskyAccountMovesHereWithItsPostsAndFollows(): void {
		$followedDid = $this->network->createUser('followed' . bin2hex(random_bytes(3)));
		// the follow is taken over through the AppView, which must know the account by then
		$this->assertNotNull($this->network->await(fn () => $this->network->profile($followedDid)), 'the AppView knows the followed account');
		$name = 'arriving' . bin2hex(random_bytes(3));
		$did = $this->network->createUser($name);
		$this->network->follow($followedDid);
		$words = 'Written on Bluesky before moving ' . bin2hex(random_bytes(4));
		$this->network->postText($words);

		$userId = $this->alice->actor->getUserId();
		$before = Server::get(IdentityService::class)->forActor($this->alice->actor);
		$this->assertNotNull($before);
		$moves = Server::get(MoveInService::class);
		// by DID: the AppView may not have indexed a handle this new yet
		$moves->start($userId, $did, 'dev-pass-' . $name);
		$move = Server::get(AtprotoMoveRequest::class)->latestOfUser($userId);
		$moves->run($move);
		$waiting = Server::get(AtprotoMoveRequest::class)->latestOfUser($userId);
		$this->assertSame(Move::WAITING, $waiting?->state, 'waiting for the e-mailed code; stopped at ' . $waiting?->step . ': ' . $waiting?->error);

		$code = $this->network->plcToken($did);
		$this->assertNotSame('', $code, 'the old PDS sent a code');
		$moves->code($userId, $code);
		$moves->run(Server::get(AtprotoMoveRequest::class)->latestOfUser($userId));
		$done = Server::get(AtprotoMoveRequest::class)->latestOfUser($userId);
		$this->assertSame(Move::DONE, $done?->state, 'the move ended at ' . $done?->step . ': ' . $done?->error);

		$now = Server::get(IdentityService::class)->forActor($this->alice->actor, false);
		$this->assertSame($did, $now?->did, 'the account is that Bluesky account now');
		$this->assertSame($before->handle, $now->handle, 'under its handle here');
		$document = $this->network->didDocument($did);
		$this->assertSame('at://' . $before->handle, $document['alsoKnownAs'][0] ?? null);
		$this->assertSame((string)(getenv('NEXTCLOUD_URL') ?: 'https://nextcloud.test'), rtrim((string)($document['service'][0]['serviceEndpoint'] ?? ''), '/'), 'the DID names this server');

		$texts = array_map(static fn ($record): string => (string)(DagCbor::decode($record->bytes)['text'] ?? ''), Server::get(RepositoryService::class)->listRecords($did, 'app.bsky.feed.post', 50));
		$this->assertContains($words, $texts, 'the posts are here');
		$record = array_values(array_filter(Server::get(RepositoryService::class)->listRecords($did, 'app.bsky.feed.post', 50), static fn ($r): bool => str_contains((string)(DagCbor::decode($r->bytes)['text'] ?? ''), $words)))[0] ?? null;
		$this->assertNotSame('', $record?->localId ?? '', 'the post is tied to a post here');
		$post = Server::get(StreamRequest::class)->getStreamById((string)$record->localId);
		$this->assertSame([$this->alice->actor->getId(), true], [$post->getAttributedTo(), $post->isLocal()], 'as the account\'s own post');
		$this->assertStringContainsString($words, $post->getContent());
		Server::get(FollowsRequest::class)->getByPersons($this->alice->actor->getId(), 'https://bsky.app/profile/' . $followedDid);

		$this->assertNotNull($this->network->await(fn () => ($this->network->profile($did)['handle'] ?? '') === $before->handle ? true : null), 'the AppView follows the DID here');
	}
}
