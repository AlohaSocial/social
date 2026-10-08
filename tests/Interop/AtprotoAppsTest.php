<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Atproto\Client\AppPasswordService;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Tests\Interop\Bluesky\AppClient;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\IAvatarManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Phase 3c: a Bluesky app signs in to this instance as its PDS with an app
 * password, reads its timeline through it from the development AppView, and
 * what it writes — a post, a like — happens here as the Social action it
 * stands for and reaches Bluesky as the record Social wrote.
 */
class AtprotoAppsTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $alice;

	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null) {
			$this->markTestSkipped('no Bluesky development network (ATPROTO_NETWORK_FILE)');
		}
		$this->network = $network;
		$this->alice = LocalAccount::create('ap');
	}

	public function testABlueskyAppSignsInReadsAndWritesThroughThisServer(): void {
		$identity = Server::get(IdentityService::class)->forActor($this->alice->actor);
		$this->assertNotNull($identity);
		$password = Server::get(AppPasswordService::class)->create($this->alice->userId, 'interop')['password'];
		$app = AppClient::at((string)getenv('NEXTCLOUD_URL') ?: 'https://nextcloud.test');

		[$status, $answer] = $app->signIn($identity->handle, 'wrong-pass-word-here');
		$this->assertSame(401, $status, 'a wrong password is refused');
		[$status, $answer] = $app->signIn($identity->handle, $password);
		$this->assertSame(200, $status, json_encode($answer));
		$this->assertSame($identity->did, $answer['did']);

		[$status, $session] = $app->query('com.atproto.server.getSession');
		$this->assertSame(200, $status);
		$this->assertSame($identity->handle, $session['handle']);

		// the AppView answers through this server, as alice
		[$status, $timeline] = $this->network->await(function () use ($app): ?array {
			$answer = $app->query('app.bsky.feed.getTimeline', ['limit' => 5]);

			return $answer[0] === 200 ? $answer : null;
		});
		$this->assertSame(200, $status, 'the proxied timeline: ' . json_encode($timeline));
		$this->assertArrayHasKey('feed', $timeline);

		// a picture is stored as any upload, and served under the CID the
		// answer names, which is the stored copy's
		[$status, $uploaded] = $app->upload(self::picture(), 'image/png');
		$this->assertSame(200, $status, json_encode($uploaded));
		$cid = (string)$uploaded['blob']['ref']['$link'];
		$served = $app->blob($identity->did, $cid);
		$this->assertNotSame('', $served, 'getBlob serves it');
		$this->assertSame($cid, Cid::forRaw($served)->toString(), 'under the CID of its bytes');

		// a post written by the app is a Social post, and reaches the AppView
		$words = 'Posted from a Bluesky app ' . bin2hex(random_bytes(4));
		[$status, $created] = $app->procedure('com.atproto.repo.createRecord', [
			'repo' => $identity->did,
			'collection' => 'app.bsky.feed.post',
			'record' => [
				'$type' => 'app.bsky.feed.post', 'text' => $words, 'createdAt' => gmdate('Y-m-d\TH:i:s.000\Z'),
				'embed' => ['$type' => 'app.bsky.embed.images', 'images' => [['alt' => 'A red square', 'image' => $uploaded['blob']]]],
			],
		]);
		$this->assertSame(200, $status, json_encode($created));
		$this->assertStringStartsWith('at://' . $identity->did . '/app.bsky.feed.post/', $created['uri']);
		$mine = array_values(array_filter($this->alice->statusesOf($this->alice->accountId()), static fn (array $s): bool => str_contains((string)($s['content'] ?? ''), $words)));
		$this->assertCount(1, $mine, 'the post is here, a Social post');
		$onAppView = $this->network->await(function () use ($identity, $created): ?array {
			foreach ($this->network->authorFeed($identity->did) as $item) {
				if (($item['post']['uri'] ?? '') === $created['uri']) {
					return $item['post'];
				}
			}

			return null;
		});
		$this->assertNotNull($onAppView, 'and on the AppView');
		$this->assertCount(1, $mine[0]['media_attachments'] ?? [], 'with its picture here');
		$this->assertSame('A red square', $mine[0]['media_attachments'][0]['description'] ?? null);
		$this->assertSame('app.bsky.embed.images#view', $onAppView['embed']['$type'] ?? null, 'and there');

		// a like of a dev user's post, not read here before
		$this->network->createUser('appsbob' . bin2hex(random_bytes(3)));
		$bobs = $this->network->postText('Like me ' . bin2hex(random_bytes(3)));
		$liked = $this->network->await(function () use ($app, $identity, $bobs): ?array {
			[$status, $answer] = $app->procedure('com.atproto.repo.createRecord', [
				'repo' => $identity->did,
				'collection' => 'app.bsky.feed.like',
				'record' => ['$type' => 'app.bsky.feed.like', 'subject' => $bobs, 'createdAt' => gmdate('Y-m-d\TH:i:s.000\Z')],
			]);

			return $status === 200 ? $answer : null;
		});
		$this->assertNotNull($liked, 'the like was made once the AppView knew the post');
		$this->assertNotNull($this->network->await(fn () => in_array($identity->did, $this->network->likers($bobs['uri']), true) ? true : null), 'and counted there');

		// a block is refused: blocks are never published
		[$status, $refused] = $app->procedure('com.atproto.repo.createRecord', ['repo' => $identity->did, 'collection' => 'app.bsky.graph.block', 'record' => ['$type' => 'app.bsky.graph.block', 'subject' => $this->network->userDid(), 'createdAt' => gmdate('Y-m-d\TH:i:s.000\Z')]]);
		$this->assertSame(400, $status);
		$this->assertStringContainsString('never published', (string)($refused['message'] ?? ''));

		// the app's profile editor: a new picture becomes the account's avatar
		$this->assertFalse(Server::get(IAvatarManager::class)->getAvatar($this->alice->userId)->isCustomAvatar());
		[$status, $saved] = $app->procedure('com.atproto.repo.putRecord', [
			'repo' => $identity->did, 'collection' => 'app.bsky.actor.profile', 'rkey' => 'self',
			'record' => ['$type' => 'app.bsky.actor.profile', 'displayName' => 'Alice from an app', 'avatar' => $uploaded['blob']],
		]);
		$this->assertSame(200, $status, json_encode($saved));
		$this->assertTrue(Server::get(IAvatarManager::class)->getAvatar($this->alice->userId)->isCustomAvatar(), 'the picture is the account\'s avatar');
		$this->assertNotNull($this->network->await(fn () => ($this->network->profile($identity->did)['avatar'] ?? '') !== '' ? true : null), 'and the AppView shows it');

		// deleting the post's record deletes the post here
		[$status] = $app->procedure('com.atproto.repo.deleteRecord', ['repo' => $identity->did, 'collection' => 'app.bsky.feed.post', 'rkey' => substr($created['uri'], (int)strrpos($created['uri'], '/') + 1)]);
		$this->assertSame(200, $status);
		$this->assertNull($this->alice->status((string)$mine[0]['id']), 'gone here');

		// signing out ends the session
		[$status] = $app->procedure('com.atproto.server.deleteSession', [], $app->refreshJwt);
		$this->assertSame(200, $status);
		[$status] = $app->query('com.atproto.server.getSession');
		$this->assertGreaterThanOrEqual(400, $status, 'the access token went with the session');
	}

	/** A small PNG, made here so the test carries no binary file. */
	private static function picture(): string {
		$image = imagecreatetruecolor(64, 48);
		imagefill($image, 0, 0, (int)imagecolorallocate($image, 200, 30, 40));
		ob_start();
		imagepng($image);

		return (string)ob_get_clean();
	}
}
