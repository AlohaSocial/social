<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Service\BlockedBy\BlockedByService;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A Bluesky user's block of a local account, a record in their own
 * repository, honoured here: the local account's follow of them and reply
 * to them are refused with the reason, the block is the same relation a
 * Fediverse block is, and deleting the record lifts it.
 */
class AtprotoBlockedByTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $alice;

	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null) {
			$this->markTestSkipped('no Bluesky development network (ATPROTO_NETWORK_FILE)');
		}
		$this->network = $network;
		$this->alice = LocalAccount::create('bb');
	}

	public function testABlockOnBlueskyIsHonouredHereAndDeletingItLiftsIt(): void {
		// first: the block names alice's DID, and the AppView is asked as her
		$identities = Server::get(IdentityService::class);
		$identity = $identities->forActor($this->alice->actor);
		$this->assertNotNull($identity);
		$bob = $this->network->createUser('blocker' . bin2hex(random_bytes(3)));
		$post = $this->network->postText('Not for everybody ' . bin2hex(random_bytes(3)));
		$bobId = $this->network->await(function (): ?string {
			try {
				return $this->alice->resolve($this->network->userHandle());
			} catch (RuntimeException) {
				return null;
			}
		});
		$this->assertNotNull($bobId, 'the handle resolved');

		[$status, $block] = $this->network->asUser('POST', 'com.atproto.repo.createRecord', [
			'repo' => $bob,
			'collection' => 'app.bsky.graph.block',
			'record' => ['$type' => 'app.bsky.graph.block', 'subject' => $identity->did, 'createdAt' => gmdate('Y-m-d\TH:i:s.000\Z')],
		]);
		$this->assertSame(200, $status, json_encode($block));
		$blockedBy = function () use ($identities, $identity, $bob): bool {
			$profile = Server::get(AppViewClient::class)->queryAs($identity->did, $identities->signingKey($identity), 'app.bsky.actor.getProfile', ['actor' => $bob]);

			return ($profile['viewer']['blockedBy'] ?? false) === true;
		};
		$this->assertNotNull($this->network->await(fn (): ?bool => $blockedBy() ? true : null), 'the AppView has the block');

		try {
			$this->alice->follow($bobId);
			$this->fail('the follow went out');
		} catch (RuntimeException $e) {
			$this->assertSame(403, $e->getCode());
			$this->assertStringContainsString('This account has blocked you', $e->getMessage());
		}
		$relations = Server::get(ActorRelationRequest::class);
		$bobHere = BlueskyIds::actorId($bob);
		$this->assertTrue($relations->exists($this->alice->actor->getId(), $bobHere, ActorRelation::TYPE_BLOCKED_BY), 'the relation a Fediverse block is');
		$this->assertTrue($this->alice->relationship($bobId)['blocked_by'] ?? false);

		$this->assertTrue(Server::get(PostStore::class)->storeByUri($post['uri']));
		$nid = (string)Server::get(StreamRequest::class)->getStreamById(BlueskyIds::postIdOfUri($post['uri']))->getNid();
		try {
			$this->alice->postStatus('Replying anyway', $nid);
			$this->fail('the reply went out');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('This account has blocked you', $e->getMessage());
		}

		[$status, $answer] = $this->network->asUser('POST', 'com.atproto.repo.deleteRecord', [
			'repo' => $bob,
			'collection' => 'app.bsky.graph.block',
			'rkey' => substr($block['uri'], (int)strrpos($block['uri'], '/') + 1),
		]);
		$this->assertSame(200, $status, json_encode($answer));
		$this->assertNotNull($this->network->await(fn (): ?bool => $blockedBy() ? null : true), 'the AppView has the unblock');

		// what the listener does when it sees the record go, and what the next
		// question does once the answer kept from the last one runs out
		$this->assertSame([$bobHere => false], Server::get(BlockedByService::class)->blockedBy($this->alice->actor->getId(), [$bobHere], true));
		$this->assertFalse($relations->exists($this->alice->actor->getId(), $bobHere, ActorRelation::TYPE_BLOCKED_BY), 'the relation is gone');
		$this->alice->follow($bobId);
		$this->assertTrue($this->alice->relationship($bobId)['following'], 'and the follow goes out');
	}
}
