<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Move;
use OCA\Social\Atproto\Move\InboundMoveService;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\AtprotoMoveRequest;
use OCA\Social\Tests\Interop\Bluesky\AppClient;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * A Bluesky account moves here driven by the other side (§13.3), as Bridgy
 * Fed or a migration tool moves one: the person invites the DID here; the
 * test, as the tool, makes the account on this server with the code and a
 * token the development PDS signs, sends the repository and the blobs,
 * has the old PDS sign the operation this server recommends, submits it
 * here and activates the account, then switches it off on the old PDS.
 */
class AtprotoMoveByToolTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $alice;

	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null || $network->handleServer === '') {
			$this->markTestSkipped('no Bluesky development network with a handle server');
		}
		$this->network = $network;
		$this->alice = LocalAccount::create('mt');
	}

	public function testAMigrationToolMovesABlueskyAccountHere(): void {
		$name = 'tooled' . bin2hex(random_bytes(3));
		$did = $this->network->createUser($name);
		$words = 'Written on Bluesky before a tool moved me ' . bin2hex(random_bytes(4));
		$this->network->postText($words);
		$picture = self::picture();
		$pictureCid = $this->network->postPicture('With a picture', $picture, 'image/png');

		$userId = $this->alice->actor->getUserId();
		$before = Server::get(IdentityService::class)->forActor($this->alice->actor);
		$this->assertNotNull($before);
		$invited = Server::get(InboundMoveService::class)->invite($userId, $did);
		$this->assertFalse($invited['bridgy']);

		$here = AppClient::at((string)getenv('NEXTCLOUD_URL') ?: 'https://nextcloud.test');
		$token = $this->network->serviceAuth(Server::get(AtprotoConfig::class)->serviceDid(), 'com.atproto.server.createAccount');
		[$status, $made] = $here->procedure('com.atproto.server.createAccount', ['did' => $did, 'handle' => $invited['handle'], 'email' => $invited['email'], 'password' => $invited['code']], $token);
		$this->assertSame(200, $status, 'createAccount: ' . json_encode($made));
		$this->assertSame([$did, $before->handle], [$made['did'] ?? '', $made['handle'] ?? '']);
		$here->accessJwt = (string)$made['accessJwt'];
		[$status, $account] = $here->query('com.atproto.server.checkAccountStatus');
		$this->assertSame([200, false], [$status, $account['activated'] ?? null]);

		[$status, $answer] = $here->send('com.atproto.repo.importRepo', $this->network->pdsBytes('com.atproto.sync.getRepo', ['did' => $did]), 'application/vnd.ipld.car');
		$this->assertSame(200, $status, 'importRepo: ' . json_encode($answer));
		[, $missing] = $here->query('com.atproto.repo.listMissingBlobs');
		$this->assertSame([$pictureCid], array_column($missing['blobs'] ?? [], 'cid'));
		[$status, $uploaded] = $here->upload($this->network->pdsBytes('com.atproto.sync.getBlob', ['did' => $did, 'cid' => $pictureCid]), 'image/png');
		$this->assertSame([200, $pictureCid], [$status, $uploaded['blob']['ref']['$link'] ?? '']);

		[, $recommended] = $here->query('com.atproto.identity.getRecommendedDidCredentials');
		[$status] = $this->network->asUser('POST', 'com.atproto.identity.requestPlcOperationSignature');
		$this->assertSame(200, $status);
		[$status, $signed] = $this->network->asUser('POST', 'com.atproto.identity.signPlcOperation', ['token' => $this->network->plcToken($did)] + $recommended);
		$this->assertSame(200, $status, 'signPlcOperation: ' . json_encode($signed));
		[$status, $answer] = $here->procedure('com.atproto.identity.submitPlcOperation', ['operation' => $signed['operation']]);
		$this->assertSame(200, $status, 'submitPlcOperation: ' . json_encode($answer));
		[$status, $answer] = $here->procedure('com.atproto.server.activateAccount', []);
		$this->assertSame(200, $status, 'activateAccount: ' . json_encode($answer));
		[$status] = $this->network->asUser('POST', 'com.atproto.server.deactivateAccount', []);
		$this->assertSame(200, $status);

		Server::get(InboundMoveService::class)->run(Server::get(AtprotoMoveRequest::class)->latestOfUser($userId));
		$this->assertSame(Move::DONE, Server::get(AtprotoMoveRequest::class)->latestOfUser($userId)?->state);

		$now = Server::get(IdentityService::class)->forActor($this->alice->actor, false);
		$this->assertSame($did, $now?->did, 'the account is that Bluesky account now');
		$this->assertSame($before->handle, $now->handle, 'under its handle here');
		$document = $this->network->didDocument($did);
		$this->assertSame('at://' . $before->handle, $document['alsoKnownAs'][0] ?? null);
		$this->assertSame($here->pds, rtrim((string)($document['service'][0]['serviceEndpoint'] ?? ''), '/'), 'the DID names this server');
		$texts = array_map(static fn ($record): string => (string)(DagCbor::decode($record->bytes)['text'] ?? ''), Server::get(RepositoryService::class)->listRecords($did, 'app.bsky.feed.post', 50));
		$this->assertContains($words, $texts, 'the posts are here');
		$this->assertSame($picture, $here->blob($did, $pictureCid), 'the picture is served here, byte for byte');

		$this->assertNotNull($this->network->await(fn () => ($this->network->profile($did)['handle'] ?? '') === $before->handle ? true : null), 'the AppView follows the DID here');
	}

	/** A small PNG, made here so the test carries no binary file. */
	private static function picture(): string {
		$image = imagecreatetruecolor(64, 48);
		imagefill($image, 0, 0, (int)imagecolorallocate($image, 30, 120, 200));
		ob_start();
		imagepng($image);

		return (string)ob_get_clean();
	}
}
