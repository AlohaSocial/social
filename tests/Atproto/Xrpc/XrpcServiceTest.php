<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Xrpc;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\BlobRef;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\RepoHead;
use OCA\Social\Atproto\Model\StoredRecord;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Publisher\PictureService;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Atproto\Xrpc\XrpcBytes;
use OCA\Social\Atproto\Xrpc\XrpcException;
use OCA\Social\Atproto\Xrpc\XrpcService;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCA\Social\Service\ConfigService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The XRPC surface: what each method answers and refuses.
 */
#[AllowMockObjectsWithoutExpectations]
class XrpcServiceTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	/** @var AtprotoConfig&MockObject */
	private AtprotoConfig $config;
	/** @var IdentityService&MockObject */
	private IdentityService $identities;
	/** @var RepositoryService&MockObject */
	private RepositoryService $repositories;
	/** @var AtprotoRepoRequest&MockObject */
	private AtprotoRepoRequest $repoRequest;
	/** @var AtprotoBlobRequest&MockObject */
	private AtprotoBlobRequest $blobRequest;
	/** @var PictureService&MockObject */
	private PictureService $pictures;
	private XrpcService $xrpc;
	private Identity $alice;

	protected function setUp(): void {
		$this->config = $this->createMock(AtprotoConfig::class);
		$this->config->method('isEnabled')->willReturn(true);
		$this->config->method('serviceDid')->willReturn('did:web:social.test');
		$this->identities = $this->createMock(IdentityService::class);
		$this->repositories = $this->createMock(RepositoryService::class);
		$this->repoRequest = $this->createMock(AtprotoRepoRequest::class);
		$this->blobRequest = $this->createMock(AtprotoBlobRequest::class);
		$this->pictures = $this->createMock(PictureService::class);
		$configService = $this->createMock(ConfigService::class);
		$configService->method('getAppValue')->willReturn('1.0');
		$configService->method('getCloudUrl')->willReturn('https://social.test');
		$this->xrpc = new XrpcService($this->config, $this->identities, $this->repositories, $this->repoRequest, $this->blobRequest, $this->pictures, $configService);

		$this->alice = new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', '', 'did:key:z', '', Identity::STATE_ACTIVE, '', 0);
		$this->identities->method('getByDid')->willReturnCallback(fn (string $did): Identity => $did === self::DID ? $this->alice : throw new AtprotoIdentityNotFoundException());
		$this->identities->method('getByHandle')->willReturnCallback(fn (string $handle): Identity => $handle === 'alice.social.test' ? $this->alice : throw new AtprotoIdentityNotFoundException());
	}

	public function testResolveHandle(): void {
		$this->assertSame(['did' => self::DID], $this->xrpc->query('com.atproto.identity.resolveHandle', ['handle' => 'Alice.Social.Test']));
	}

	public function testAnUnknownHandleIsAnInvalidRequest(): void {
		try {
			$this->xrpc->query('com.atproto.identity.resolveHandle', ['handle' => 'nobody.social.test']);
			$this->fail();
		} catch (XrpcException $e) {
			$this->assertSame(400, $e->status);
			$this->assertSame(['error' => 'InvalidRequest', 'message' => 'Unable to resolve handle'], $e->toArray());
		}
	}

	public function testDescribeServer(): void {
		$answer = $this->xrpc->query('com.atproto.server.describeServer', []);
		$this->assertSame('did:web:social.test', $answer['did']);
		$this->assertTrue($answer['inviteCodeRequired'], 'nobody registers here');
	}

	public function testGetRepoIsTheCar(): void {
		$this->repositories->method('exportCar')->with(self::DID)->willReturn('CAR');
		$answer = $this->xrpc->query('com.atproto.sync.getRepo', ['did' => self::DID]);

		$this->assertInstanceOf(XrpcBytes::class, $answer);
		$this->assertSame('application/vnd.ipld.car', $answer->contentType);
		$this->assertSame('CAR', $answer->bytes);
	}

	public function testAnUnknownRepoIsRepoNotFound(): void {
		try {
			$this->xrpc->query('com.atproto.sync.getLatestCommit', ['did' => 'did:plc:aaaaaaaaaaaaaaaaaaaaaaaa']);
			$this->fail();
		} catch (XrpcException $e) {
			$this->assertSame(404, $e->status);
			$this->assertSame('RepoNotFound', $e->error);
		}
	}

	public function testGetLatestCommitAndRepoStatus(): void {
		$this->repositories->method('getHead')->willReturn(new RepoHead(self::DID, 'bafy', '3kznmn7xqxl22', 3, 0, 0));

		$this->assertSame(['cid' => 'bafy', 'rev' => '3kznmn7xqxl22'], $this->xrpc->query('com.atproto.sync.getLatestCommit', ['did' => self::DID]));
		$this->assertSame(['did' => self::DID, 'active' => true, 'rev' => '3kznmn7xqxl22'], $this->xrpc->query('com.atproto.sync.getRepoStatus', ['did' => self::DID]));
	}

	public function testGetRecordAnswersTheValueAsJson(): void {
		$value = ['$type' => 'app.bsky.feed.post', 'text' => 'hi', 'createdAt' => '2026-10-08T10:00:00.000Z', 'embed' => ['ref' => Cid::forRaw('x')]];
		$bytes = DagCbor::encode($value);
		$record = new StoredRecord(self::DID, 'app.bsky.feed.post', '3kznmn7xqxl22', Cid::forDagCbor($bytes), $bytes, 'https://social.test/@alice/1', 0);
		$this->repositories->method('getRecord')->with(self::DID, 'app.bsky.feed.post', '3kznmn7xqxl22')->willReturn($record);

		$answer = $this->xrpc->query('com.atproto.repo.getRecord', ['repo' => 'alice.social.test', 'collection' => 'app.bsky.feed.post', 'rkey' => '3kznmn7xqxl22']);

		$this->assertSame('at://' . self::DID . '/app.bsky.feed.post/3kznmn7xqxl22', $answer['uri']);
		$this->assertSame($record->cid->toString(), $answer['cid']);
		$this->assertSame(['$link' => Cid::forRaw('x')->toString()], $answer['value']['embed']['ref'], 'links are JSON links');
	}

	public function testListRecordsPages(): void {
		$records = [];
		foreach (['3kznmn7xqxl25', '3kznmn7xqxl24', '3kznmn7xqxl23'] as $rkey) {
			$bytes = DagCbor::encode(['$type' => 'app.bsky.feed.post', 'text' => $rkey]);
			$records[] = new StoredRecord(self::DID, 'app.bsky.feed.post', $rkey, Cid::forDagCbor($bytes), $bytes, '', 0);
		}
		$this->repositories->method('listRecords')->with(self::DID, 'app.bsky.feed.post', 3, '', false)->willReturn($records);

		$answer = $this->xrpc->query('com.atproto.repo.listRecords', ['repo' => self::DID, 'collection' => 'app.bsky.feed.post', 'limit' => '2']);

		$this->assertCount(2, $answer['records']);
		$this->assertSame('3kznmn7xqxl24', $answer['cursor']);
	}

	public function testGetBlob(): void {
		$blob = new BlobRef(self::DID, Cid::forRaw('pixels'), 'https://social.test/doc/1', 'image/jpeg', 6);
		$this->blobRequest->method('get')->with(self::DID, $blob->cid->toString())->willReturn($blob);
		$this->pictures->method('read')->with($blob)->willReturn('pixels');

		$answer = $this->xrpc->query('com.atproto.sync.getBlob', ['did' => self::DID, 'cid' => $blob->cid->toString()]);

		$this->assertInstanceOf(XrpcBytes::class, $answer);
		$this->assertSame('image/jpeg', $answer->contentType);
		$this->assertSame('pixels', $answer->bytes);
	}

	public function testListRepos(): void {
		$this->repoRequest->method('getHeads')->willReturn([new RepoHead(self::DID, 'bafy', '3kznmn7xqxl22', 1, 0, 0)]);

		$answer = $this->xrpc->query('com.atproto.sync.listRepos', []);

		$this->assertSame([['did' => self::DID, 'head' => 'bafy', 'rev' => '3kznmn7xqxl22', 'active' => true]], $answer['repos']);
		$this->assertArrayNotHasKey('cursor', $answer);
	}

	public function testSubscribeReposOverPlainHttpAsksForTheUpgrade(): void {
		try {
			$this->xrpc->query('com.atproto.sync.subscribeRepos', []);
			$this->fail();
		} catch (XrpcException $e) {
			$this->assertSame(426, $e->status);
		}
	}

	public function testWhatIsNotServedIsSaidSo(): void {
		try {
			$this->xrpc->query('app.bsky.actor.getProfile', ['actor' => 'x']);
			$this->fail();
		} catch (XrpcException $e) {
			$this->assertSame(501, $e->status);
			$this->assertSame('MethodNotImplemented', $e->error);
		}
		try {
			$this->xrpc->procedure('com.atproto.repo.createRecord', []);
			$this->fail();
		} catch (XrpcException $e) {
			$this->assertSame(401, $e->status);
		}
	}

	public function testAMissingParameterIsAnInvalidRequest(): void {
		try {
			$this->xrpc->query('com.atproto.sync.getRepo', []);
			$this->fail();
		} catch (XrpcException $e) {
			$this->assertSame(400, $e->status);
			$this->assertStringContainsString('"did"', $e->getMessage());
		}
	}

	public function testNothingIsServedWhenBlueskyIsOff(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(false);
		$xrpc = new XrpcService($config, $this->identities, $this->repositories, $this->repoRequest, $this->blobRequest, $this->pictures, $this->createMock(ConfigService::class));

		$this->expectException(XrpcException::class);
		$xrpc->query('com.atproto.server.describeServer', []);
	}
}
