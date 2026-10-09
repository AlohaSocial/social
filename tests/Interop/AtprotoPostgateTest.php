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
 * A Bluesky post its author closed to quotes (`postgate` with a
 * `disableRule`): a quote from here is refused with the reason instead of
 * being made where every AppView would show it detached. A post without a
 * gate is quoted as before.
 */
class AtprotoPostgateTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $alice;

	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null) {
			$this->markTestSkipped('no Bluesky development network (ATPROTO_NETWORK_FILE)');
		}
		$this->network = $network;
		$this->alice = LocalAccount::create('pg');
	}

	/** The post's status id here, once the AppView has it and it is stored. */
	private function stored(string $uri): string {
		$this->assertNotNull($this->network->await(fn (): ?bool => Server::get(PostStore::class)->storeByUri($uri) ? true : null), 'the AppView has ' . $uri);

		return (string)Server::get(StreamRequest::class)->getStreamById(BlueskyIds::postIdOfUri($uri))->getNid();
	}

	public function testAPostClosedToQuotesTakesNoQuoteFromHere(): void {
		$this->assertNotNull(Server::get(IdentityService::class)->forActor($this->alice->actor));
		$did = $this->network->createUser('gatedq' . bin2hex(random_bytes(3)));
		$closed = $this->network->postText('Do not quote me ' . bin2hex(random_bytes(3)));
		$open = $this->network->postText('Quote me ' . bin2hex(random_bytes(3)));
		$rkey = substr($closed['uri'], (int)strrpos($closed['uri'], '/') + 1);
		[$status, $answer] = $this->network->asUser('POST', 'com.atproto.repo.createRecord', [
			'repo' => $did, 'collection' => 'app.bsky.feed.postgate', 'rkey' => $rkey,
			'record' => ['$type' => 'app.bsky.feed.postgate', 'post' => $closed['uri'], 'embeddingRules' => [['$type' => 'app.bsky.feed.postgate#disableRule']], 'createdAt' => gmdate('Y-m-d\TH:i:s.000\Z')],
		]);
		$this->assertSame(200, $status, json_encode($answer));

		$closedHere = $this->stored($closed['uri']);
		$openHere = $this->stored($open['uri']);
		try {
			$this->alice->postStatus('Quoting anyway', null, ['quote_id' => $closedHere]);
			$this->fail('the quote went out');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('does not allow quotes', $e->getMessage());
		}

		$quote = $this->alice->postStatus('Quoting this one', null, ['quote_id' => $openHere]);
		$this->assertNotEmpty($quote['quote'] ?? null, 'a post without a gate is quoted');
	}
}
