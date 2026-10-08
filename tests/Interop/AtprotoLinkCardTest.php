<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Service\LinkPreviewService;
use OCA\Social\Tests\Interop\Bluesky\AppClient;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * A post with a link goes to Bluesky as a link card with the page's
 * picture (§8.3): the page is the development network's own, with an
 * `og:image`; the card is made here, the picture fetched here and published
 * as the card's thumbnail, and the AppView shows the card with it.
 */
class AtprotoLinkCardTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $alice;

	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null || $network->customHandle === '') {
			$this->markTestSkipped('no Bluesky development network with a page to link to');
		}
		$this->network = $network;
		$this->alice = LocalAccount::create('lc');
	}

	public function testALinkCardGoesToBlueskyWithThePagesPicture(): void {
		$identity = Server::get(IdentityService::class)->forActor($this->alice->actor);
		$this->assertNotNull($identity);
		$status = $this->alice->postStatus('Read this https://' . $this->network->customHandle . '/card');
		$post = Server::get(StreamRequest::class)->getStreamById((string)$status['uri']);
		$this->assertTrue(Server::get(LinkPreviewService::class)->generate($post), 'the card is made from the page');
		Server::get(Publisher::class)->reconcile();

		$record = array_values(array_filter(Server::get(RepositoryService::class)->getRecordsByLocalId($post->getId()), static fn ($r): bool => $r->collection === RecordMapper::POST))[0] ?? null;
		$this->assertNotNull($record, 'the post is on Bluesky');
		$external = DagCbor::decode($record->bytes)['embed']['external'] ?? [];
		$this->assertSame('A page with a picture', $external['title'] ?? null);
		$thumb = $external['thumb']['ref'] ?? null;
		$this->assertInstanceOf(Cid::class, $thumb, 'the card carries the page\'s picture');
		$picture = AppClient::at((string)getenv('NEXTCLOUD_URL') ?: 'https://nextcloud.test')->blob($identity->did, $thumb->toString());
		$this->assertNotSame('', $picture, 'and serves it');
		$this->assertSame(IMAGETYPE_PNG, getimagesizefromstring($picture)[2] ?? null);

		$uri = 'at://' . $identity->did . '/' . RecordMapper::POST . '/' . $record->rkey;
		$view = $this->network->await(fn () => $this->network->postView($uri));
		$this->assertNotNull($view, 'the AppView indexed the post');
		$this->assertSame('app.bsky.embed.external#view', $view['embed']['$type'] ?? null);
		$this->assertStringContainsString($thumb->toString(), (string)($view['embed']['external']['thumb'] ?? ''), 'and shows the card with its picture');
	}
}
