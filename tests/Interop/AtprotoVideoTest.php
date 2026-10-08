<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\VideoUpload;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Publisher\VideoUploadService;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Db\AtprotoVideoRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Tests\Interop\Bluesky\AppClient;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Video both ways (D11), against the development network.
 *
 * A video posted here goes through a stand-in for Bluesky's video service,
 * which does what that service does: it stores the video in the account's
 * repository here with the token the account signed for it, and answers the
 * job with the blob. The post then goes to Bluesky naming that blob, and the
 * AppView shows it as a video. A video posted on Bluesky is read here as a
 * video streamed from its playlist, through this server's proxy.
 */
class AtprotoVideoTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $alice;
	private string $video;

	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null) {
			$this->markTestSkipped('no Bluesky development network (ATPROTO_NETWORK_FILE)');
		}
		$this->video = (string)getenv('ATPROTO_VIDEO');
		if ($this->video === '' || !is_file($this->video)) {
			$this->markTestSkipped('no test video (ATPROTO_VIDEO)');
		}
		$this->network = $network;
		$this->alice = LocalAccount::create('vd');
	}

	public function testAVideoPostedHereIsAVideoOnBluesky(): void {
		$identity = Server::get(IdentityService::class)->forActor($this->alice->actor);
		$this->assertNotNull($identity);
		$words = 'A video from here ' . bin2hex(random_bytes(4));
		$status = $this->alice->postFile($this->video, 'video/mp4', $words);
		$postId = (string)$status['uri'];

		// published, the post waits for its video
		Server::get(Publisher::class)->reconcile();
		$queued = Server::get(AtprotoVideoRequest::class)->get($postId);
		$this->assertNotNull($queued, 'the video is queued for the video service');
		$this->assertSame(VideoUpload::QUEUED, $queued->state);

		// the video job: sent, stored here by the service, the post published
		$ended = Server::get(VideoUploadService::class)->advance();
		$row = Server::get(AtprotoVideoRequest::class)->get($postId);
		$this->assertContains($postId, $ended, 'the job ended: ' . json_encode($row));
		$this->assertSame(VideoUpload::DONE, $row?->state, (string)$row?->error);
		Server::get(Publisher::class)->publishPost(Server::get(StreamRequest::class)->getStreamById($postId));

		// the service stored the video here, and it is served under its CID
		$served = AppClient::at((string)getenv('NEXTCLOUD_URL') ?: 'https://nextcloud.test')->blob($identity->did, $row->blobCid);
		$this->assertSame($row->blobCid, Cid::forRaw($served)->toString());
		$this->assertSame(hash_file('sha256', $this->video), hash('sha256', $served), 'the video as it was posted');

		$onAppView = $this->network->await(function () use ($identity, $words): ?array {
			foreach ($this->network->authorFeed($identity->did) as $item) {
				if (str_contains((string)($item['post']['record']['text'] ?? ''), $words)) {
					return $item['post'];
				}
			}

			return null;
		});
		$this->assertNotNull($onAppView, 'the post reached the AppView');
		$this->assertSame('app.bsky.embed.video#view', $onAppView['embed']['$type'] ?? null, 'as a video');
		$this->assertSame($row->blobCid, $onAppView['embed']['cid'] ?? null);
		$this->assertSame($words, $onAppView['record']['text'], 'with no link: the video is there');
	}

	public function testABlueskyVideoIsStreamedHere(): void {
		$this->network->createUser('vidbob' . bin2hex(random_bytes(3)));
		$words = 'A video from Bluesky ' . bin2hex(random_bytes(4));
		$post = $this->network->postVideo($words, $this->video);
		$view = $this->network->await(fn () => ($this->network->postView($post['uri'])['embed']['$type'] ?? '') === 'app.bsky.embed.video#view' ? $this->network->postView($post['uri']) : null);
		$this->assertNotNull($view, 'the AppView shows the video');
		$this->assertStringStartsWith($this->network->videoHost . '/watch/', (string)$view['embed']['playlist']);

		$this->assertTrue(Server::get(PostStore::class)->storeByUri($post['uri']), 'read here');
		$stream = Server::get(StreamRequest::class)->getStreamById(BlueskyIds::postIdOfUri($post['uri']));

		$this->assertSame('Video', $stream->getSubType(), 'a video, as a federated one is');
		$this->assertStringContainsString($words, $stream->getContent());
		$this->assertCount(1, $stream->getAttachments());
		$video = $stream->getAttachments()[0];
		$this->assertSame('application/x-mpegURL', $video->getMediaType());
		$this->assertStringContainsString('/media/playlist/', (string)$video->asLocal()['url'], 'streamed through the playlist proxy, never copied');
		$this->assertSame($view['embed']['playlist'], $video->asLocal()['remote_url']);
	}
}
