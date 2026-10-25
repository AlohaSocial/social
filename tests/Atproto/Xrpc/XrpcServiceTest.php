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
use OCA\Social\Atproto\Publisher\VideoBlobService;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Atproto\Xrpc\XrpcBytes;
use OCA\Social\Atproto\Xrpc\XrpcException;
use OCA\Social\Atproto\Xrpc\XrpcService;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoIdentityRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\ModerationService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
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
	/** @var VideoBlobService&MockObject */
	private VideoBlobService $videos;
	private XrpcService $xrpc;
	private Identity $alice;
	private bool $suspended = false;
	/** @var ModerationService&MockObject */
	private ModerationService $moderation;
	/** @var AtprotoIdentityRequest&MockObject */
	private AtprotoIdentityRequest $identityRequest;

	protected function setUp(): void {
		$this->config = $this->createMock(AtprotoConfig::class);
		$this->config->method('isEnabled')->willReturn(true);
		$this->config->method('serviceDid')->willReturn('did:web:social.test');
		$this->config->method('handleHost')->willReturn('social.test');
		$this->identities = $this->createMock(IdentityService::class);
		$this->repositories = $this->createMock(RepositoryService::class);
		$this->repoRequest = $this->createMock(AtprotoRepoRequest::class);
		$this->blobRequest = $this->createMock(AtprotoBlobRequest::class);
		$this->pictures = $this->createMock(PictureService::class);
		$configService = $this->createMock(ConfigService::class);
		$configService->method('getAppValue')->willReturn('1.0');
		$configService->method('getCloudUrl')->willReturn('https://social.test');
		$this->videos = $this->createMock(VideoBlobService::class);
		$this->moderation = $this->createMock(ModerationService::class);
		$this->moderation->method('isSuspended')->willReturnCallback(fn (string $actorId): bool => $this->suspended && $actorId === 'https://social.test/@alice');
		$this->moderation->method('suspendedAmong')->willReturnCallback(fn (array $actorIds): array => $this->suspended && in_array('https://social.test/@alice', $actorIds, true) ? ['https://social.test/@alice' => true] : []);
		$this->identityRequest = $this->createMock(AtprotoIdentityRequest::class);
		$this->identityRequest->method('getByDids')->willReturnCallback(fn (array $dids): array => in_array(self::DID, $dids, true) ? [self::DID => $this->alice] : []);
		$this->xrpc = new XrpcService($this->config, $this->identities, $this->repositories, $this->repoRequest, $this->blobRequest, $this->pictures, $this->videos, $configService, $this->moderation, $this->identityRequest);

		$this->alice = new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', '', 'did:key:z', '', Identity::STATE_ACTIVE, '', 0);
		$this->identities->method('getByDid')->willReturnCallback(fn (string $did): Identity => $did === self::DID ? $this->alice : throw new AtprotoIdentityNotFoundException());
		$this->identities->method('getByHandle')->willReturnCallback(fn (string $handle): Identity => $handle === 'alice.social.test' ? $this->alice : throw new AtprotoIdentityNotFoundException());
	}

	public function testResolveHandle(): void {
		$this->assertSame(['did' => self::DID], $this->xrpc->query('com.atproto.identity.resolveHandle', ['handle' => 'Alice.Social.Test']));
	}

	public function testResolveDidReturnsTheManagedDidDocument(): void {
		$this->identities->expects($this->once())->method('document')->with($this->alice)->willReturn(['id' => self::DID]);
		$this->assertSame(['didDoc' => ['id' => self::DID]], $this->xrpc->query('com.atproto.identity.resolveDid', ['did' => self::DID]));
	}

	public function testResolveIdentityReturnsTheManagedIdentity(): void {
		$this->identities->expects($this->once())->method('document')->with($this->alice)->willReturn(['id' => self::DID]);
		$this->assertSame([
			'did' => self::DID,
			'handle' => 'alice.social.test',
			'didDoc' => ['id' => self::DID],
		], $this->xrpc->query('com.atproto.identity.resolveIdentity', ['identifier' => self::DID]));
	}

	public function testResolveDidRejectsMalformedIdentifiers(): void {
		try {
			$this->xrpc->query('com.atproto.identity.resolveDid', ['did' => 'not-a-did']);
			$this->fail();
		} catch (XrpcException $e) {
			$this->assertSame(400, $e->status);
			$this->assertSame('InvalidRequest', $e->error);
		}
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
		$this->assertSame([
			'did' => 'did:web:social.test',
			'availableUserDomains' => ['.social.test'],
			'inviteCodeRequired' => true,
			'blobUploadLimit' => 100000000,
		], $answer);
		$this->assertTrue($answer['inviteCodeRequired'], 'nobody registers here');
	}

	public function testGetRepoIsTheCarInPieces(): void {
		$this->repositories->method('streamCar')->with(self::DID)->willReturnCallback(static function (): \Generator {
			yield 'CA';
			yield 'R';
		});
		$answer = $this->xrpc->query('com.atproto.sync.getRepo', ['did' => self::DID]);

		$this->assertInstanceOf(XrpcBytes::class, $answer);
		$this->assertSame('application/vnd.ipld.car', $answer->contentType);
		$this->assertSame('', $answer->bytes);
		$this->assertSame('CAR', implode('', iterator_to_array($answer->pieces ?? [], false)));
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

	/**
	 * @return array<string, array{0: string, 1: bool, 2: string, 3: string}> state, suspended, error, status
	 */
	public static function inactiveRepositories(): array {
		return [
			'switched off by its owner' => [Identity::STATE_DEACTIVATED, false, 'RepoDeactivated', 'deactivated'],
			'moved away' => [Identity::STATE_MOVED_AWAY, false, 'RepoDeactivated', 'deactivated'],
			'suspended by a moderator' => [Identity::STATE_ACTIVE, true, 'RepoTakendown', 'takendown'],
			'suspended while switched off' => [Identity::STATE_DEACTIVATED, true, 'RepoTakendown', 'takendown'],
			'deleted' => [Identity::STATE_TOMBSTONED, false, 'RepoNotFound', 'deleted'],
		];
	}

	/**
	 * A repository that is switched off, moved away, taken down or deleted
	 * is served to nobody, by DID or by handle: every read of it answers
	 * the error its lexicon names for that state, and the handle does not
	 * resolve.
	 */
	#[DataProvider('inactiveRepositories')]
	public function testAnInactiveRepositoryIsNotServed(string $state, bool $suspended, string $error, string $status): void {
		$this->alice = new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', '', 'did:key:z', '', $state, '', 0);
		$this->suspended = $suspended;
		$this->repositories->method('getHead')->willReturn(new RepoHead(self::DID, 'bafy', '3kznmn7xqxl22', 3, 0, 0));
		$this->repositories->expects($this->never())->method('streamCar');
		$this->repositories->expects($this->never())->method('getRecord');
		$this->repositories->expects($this->never())->method('listRecords');
		$this->blobRequest->expects($this->never())->method('get');
		$this->blobRequest->expects($this->never())->method('list');
		$this->repoRequest->expects($this->never())->method('getLeaves');
		$cid = Cid::forRaw('pixels')->toString();

		$refused = [];
		foreach ([
			['com.atproto.sync.getRepo', ['did' => self::DID]],
			['com.atproto.sync.getLatestCommit', ['did' => self::DID]],
			['com.atproto.sync.getRecord', ['did' => self::DID, 'collection' => 'app.bsky.actor.profile', 'rkey' => 'self']],
			['com.atproto.sync.getBlob', ['did' => self::DID, 'cid' => $cid]],
			['com.atproto.sync.listBlobs', ['did' => self::DID]],
			['com.atproto.repo.describeRepo', ['repo' => self::DID]],
			['com.atproto.repo.getRecord', ['repo' => 'alice.social.test', 'collection' => 'app.bsky.actor.profile', 'rkey' => 'self']],
			['com.atproto.repo.listRecords', ['repo' => 'alice.social.test', 'collection' => 'app.bsky.feed.post']],
		] as [$method, $params]) {
			try {
				$this->xrpc->query($method, $params);
				$refused[$method] = '';
			} catch (XrpcException $e) {
				$refused[$method] = $e->error;
			}
		}

		$this->assertSame(array_fill_keys(array_keys($refused), $error), $refused);
		if (!$suspended) {
			$this->expectException(XrpcException::class);
			$this->xrpc->query('com.atproto.identity.resolveHandle', ['handle' => 'alice.social.test']);
		}
	}

	/**
	 * `getRepoStatus` and `listRepos` say why an inactive repository is not
	 * served, and `getRepoStatus` names a revision only for an active one.
	 */
	#[DataProvider('inactiveRepositories')]
	public function testAnInactiveRepositorySaysWhy(string $state, bool $suspended, string $error, string $status): void {
		$this->alice = new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', '', 'did:key:z', '', $state, '', 0);
		$this->suspended = $suspended;
		$this->repositories->method('getHead')->willReturn(new RepoHead(self::DID, 'bafy', '3kznmn7xqxl22', 3, 0, 0));
		$this->repoRequest->method('getHeads')->willReturn([new RepoHead(self::DID, 'bafy', '3kznmn7xqxl22', 1, 0, 0)]);

		$this->assertSame(['did' => self::DID, 'active' => false, 'status' => $status], $this->xrpc->query('com.atproto.sync.getRepoStatus', ['did' => self::DID]));
		$this->assertSame(
			[['did' => self::DID, 'head' => 'bafy', 'rev' => '3kznmn7xqxl22', 'active' => false, 'status' => $status]],
			$this->xrpc->query('com.atproto.sync.listRepos', [])['repos'],
		);
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

	public function testAVideoBlobIsAStream(): void {
		$blob = new BlobRef(self::DID, Cid::forRaw('film'), 'https://social.test/doc/2', 'video/mp4', 4);
		$this->blobRequest->method('get')->willReturn($blob);
		$stream = fopen('php://memory', 'rb');
		$this->videos->method('open')->with($blob)->willReturn(['stream' => $stream, 'size' => 4]);
		$this->pictures->expects($this->never())->method('read');

		$answer = $this->xrpc->query('com.atproto.sync.getBlob', ['did' => self::DID, 'cid' => $blob->cid->toString()]);

		$this->assertSame($stream, $answer->stream);
		$this->assertSame(4, $answer->length);
		$this->assertSame('video/mp4', $answer->contentType);
	}

	public function testListRepos(): void {
		$this->repoRequest->method('getHeads')->willReturn([new RepoHead(self::DID, 'bafy', '3kznmn7xqxl22', 1, 0, 0)]);

		$answer = $this->xrpc->query('com.atproto.sync.listRepos', []);

		$this->assertSame([['did' => self::DID, 'head' => 'bafy', 'rev' => '3kznmn7xqxl22', 'active' => true]], $answer['repos']);
		$this->assertArrayNotHasKey('cursor', $answer);
	}

	/**
	 * A page of `listRepos` asks for its identities and for which of them
	 * are suspended once each, however many repositories it lists.
	 */
	public function testAPageOfListReposLooksItsAccountsUpOnce(): void {
		$dids = [self::DID, 'did:plc:z72i7hdynmk6r22z27h6tvur', 'did:plc:vwzwgnygau7ed7b7wt5ux7y2'];
		$this->repoRequest->method('getHeads')->willReturn(array_map(static fn (string $did): RepoHead => new RepoHead($did, 'bafy', '3kznmn7xqxl22', 1, 0, 0), $dids));
		$this->identityRequest = $this->createMock(AtprotoIdentityRequest::class);
		$this->identityRequest->expects($this->once())->method('getByDids')->with($dids)->willReturn(array_combine($dids, array_map(
			static fn (string $did): Identity => new Identity(1, 'https://social.test/@' . substr($did, -4), $did, substr($did, -4) . '.social.test', '', 'did:key:z', '', Identity::STATE_ACTIVE, '', 0),
			$dids,
		)));
		$this->moderation = $this->createMock(ModerationService::class);
		$this->moderation->expects($this->once())->method('suspendedAmong')->willReturn(['https://social.test/@x7y2' => true]);
		$this->moderation->expects($this->never())->method('isSuspended');
		$this->identities->expects($this->never())->method('getByDid');
		$configService = $this->createMock(ConfigService::class);
		$xrpc = new XrpcService($this->config, $this->identities, $this->repositories, $this->repoRequest, $this->blobRequest, $this->pictures, $this->videos, $configService, $this->moderation, $this->identityRequest);

		$repos = $xrpc->query('com.atproto.sync.listRepos', [])['repos'];

		$this->assertSame([true, true, false], array_column($repos, 'active'));
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
		$xrpc = new XrpcService($config, $this->identities, $this->repositories, $this->repoRequest, $this->blobRequest, $this->pictures, $this->createMock(VideoBlobService::class), $this->createMock(ConfigService::class), $this->createMock(ModerationService::class), $this->createMock(AtprotoIdentityRequest::class));

		$this->expectException(XrpcException::class);
		$xrpc->query('com.atproto.server.describeServer', []);
	}
}
