<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Move;

use InvalidArgumentException;
use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Client\Preferences;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\InstanceKeyService;
use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Atproto\Model\BlobRef;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\Move;
use OCA\Social\Atproto\Model\StoredRecord;
use OCA\Social\Atproto\Move\MoveInService;
use OCA\Social\Atproto\Move\PdsClient;
use OCA\Social\Atproto\Protocol\Car;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\Commit;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Protocol\Mst;
use OCA\Social\Atproto\Protocol\Tid;
use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Cron\AtprotoMove;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoMoveRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\FollowService;
use OCP\BackgroundJob\IJobList;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class MoveInServiceTest extends TestCase {
	private const DID = 'did:plc:z72i7hdynmk6r22z27h6tvur';
	private const OLD = 'https://pds.example.com';
	private const HERE = 'https://social.test';
	private const FOLLOWED = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	/** @var list<array{method: string, json: ?array, token: string}> */
	private array $calls = [];
	/** @var array<string, list<array{status: int, body: array}>> */
	private array $answers = [];
	/** @var array<int, Move> */
	private array $rows = [];
	private array $queued = [];
	private PrivateKey $oldKey;
	private PrivateKey $rotation;
	private string $car;
	private string $blob = 'a picture, byte for byte';
	private Identity $current;
	/** @var IdentityService&MockObject */
	private IdentityService $identities;
	/** @var RepositoryService&MockObject */
	private RepositoryService $repositories;
	/** @var FollowService&MockObject */
	private FollowService $follows;
	/** @var AtprotoBlobRequest&MockObject */
	private AtprotoBlobRequest $blobs;
	private MoveInService $moves;

	protected function setUp(): void {
		$this->oldKey = PrivateKey::generate(Curve::K256);
		$this->rotation = PrivateKey::generate(Curve::K256);
		$this->current = new Identity(1, 'https://social.test/@alice', 'did:plc:qhuarct7u6lie3dhefl6c4ua', 'alice.social.test', '', '', '', Identity::STATE_ACTIVE, '', 0);
		$this->car = $this->oldRepository();

		$pds = $this->createMock(PdsClient::class);
		$pds->method('origin')->willReturnArgument(0);
		$pds->method('call')->willReturnCallback(function (string $origin, string $method, string $verb = 'GET', array $query = [], ?array $json = null, $bytes = null, string $type = '', string $token = ''): array {
			$this->calls[] = ['method' => $method, 'json' => $json, 'token' => $token];
			$this->answers[$method] ??= [];

			return array_shift($this->answers[$method]) ?? ['status' => 200, 'body' => []];
		});
		$pds->method('expect')->willReturnCallback(function (string $origin, string $method, string $verb = 'GET', array $query = []): array {
			$this->calls[] = ['method' => $method, 'json' => null, 'token' => ''];

			return $method === 'com.atproto.sync.listBlobs' ? ['cids' => [Cid::forRaw($this->blob)->toString(), Cid::forRaw('elsewhere')->toString()]] : [];
		});
		$pds->method('bytes')->willReturnCallback(fn (string $origin, string $method, array $query): array => match ($method) {
			'com.atproto.sync.getRepo' => ['status' => 200, 'bytes' => $this->car, 'type' => 'application/vnd.ipld.car'],
			'com.atproto.sync.getBlob' => $query['cid'] === Cid::forRaw($this->blob)->toString()
				? ['status' => 200, 'bytes' => $this->blob, 'type' => 'image/png']
				: ['status' => 200, 'bytes' => 'not what it is named', 'type' => 'image/png'],
		});
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('pdsEndpoint')->willReturn(self::HERE);
		$appView = $this->createMock(AppViewClient::class);
		$appView->method('query')->willReturnCallback(static fn (string $m, array $p): array => $p['handle'] === 'alice.bsky.social' ? ['did' => self::DID] : []);
		$plc = $this->createMock(PlcClient::class);
		$plc->method('data')->willReturnCallback(fn (): array => [
			'verificationMethods' => ['atproto' => $this->oldKey->didKey()],
			'services' => ['atproto_pds' => ['type' => 'AtprotoPersonalDataServer', 'endpoint' => self::OLD]],
		]);
		$this->identities = $this->createMock(IdentityService::class);
		$this->identities->method('getByDid')->willThrowException(new AtprotoIdentityNotFoundException());
		$this->identities->method('forActor')->willReturnCallback(fn (): Identity => $this->current);
		$keys = $this->createMock(InstanceKeyService::class);
		$keys->method('rotationKey')->willReturn($this->rotation);
		$this->repositories = $this->createMock(RepositoryService::class);
		$this->repositories->method('import')->willReturn(Cid::forRaw('the new commit'));
		$this->repositories->method('listRecords')->willReturn([
			new StoredRecord(self::DID, 'app.bsky.graph.follow', '3kfollow', Cid::forRaw('f'), DagCbor::encode(['$type' => 'app.bsky.graph.follow', 'subject' => self::FOLLOWED, 'createdAt' => '2026-01-01T00:00:00.000Z']), '', 0),
		]);
		$this->blobs = $this->createMock(AtprotoBlobRequest::class);
		$documents = $this->createMock(DocumentService::class);
		$documents->method('storeAsIs')->willReturnCallback(function (Person $actor, string $path): Document {
			$this->assertSame($this->blob, file_get_contents($path), 'stored as it came');
			$document = new Document();
			$document->setId('https://social.test/documents/local/9');
			$document->setMimeType('image/png');

			return $document;
		});
		$followed = new Person();
		$followed->setId('https://bsky.app/profile/' . self::FOLLOWED);
		$actors = $this->createMock(BlueskyActorService::class);
		$actors->method('resolve')->with(self::FOLLOWED)->willReturn($followed);
		$this->follows = $this->createMock(FollowService::class);
		$local = new Person();
		$local->setId('https://social.test/@alice');
		$actorsRequest = $this->createMock(ActorsRequest::class);
		$actorsRequest->method('getFromUserId')->willReturn($local);
		$request = $this->createMock(AtprotoMoveRequest::class);
		$request->method('add')->willReturnCallback(function (Move $move): int {
			$id = count($this->rows) + 1;
			$this->rows[$id] = new Move($id, $move->did, $move->userId, $move->direction, $move->pds, $move->pdsDid, $move->handle, $move->step, $move->state, $move->session);

			return $id;
		});
		$request->method('update')->willReturnCallback(function (Move $move): void {
			$this->rows[$move->id] = clone $move;
		});
		$request->method('get')->willReturnCallback(fn (int $id): ?Move => isset($this->rows[$id]) ? clone $this->rows[$id] : null);
		$request->method('latestOfUser')->willReturnCallback(fn (): ?Move => $this->rows === [] ? null : clone end($this->rows));
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(static fn (string $plain): string => 'sealed:' . base64_encode($plain));
		$crypto->method('decrypt')->willReturnCallback(static fn (string $sealed): string => (string)base64_decode(substr($sealed, 7)));
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('add')->willReturnCallback(function (string $job, mixed $argument): void {
			$this->queued[] = [$job, $argument];
		});
		$this->moves = new MoveInService(
			$pds, $config, $appView, $plc, $this->identities, $keys, $this->repositories, $this->createMock(AtprotoRepoRequest::class),
			$this->blobs, $documents, $this->createMock(Preferences::class), $actors, $this->follows, $actorsRequest, $request,
			$crypto, $jobs, new NullLogger(),
		);
		$this->answers['com.atproto.server.createSession'] = [['status' => 200, 'body' => ['did' => self::DID, 'handle' => 'alice.bsky.social', 'accessJwt' => 'access', 'refreshJwt' => 'refresh']]];
	}

	private function oldRepository(): string {
		$records = ['app.bsky.feed.post/' . Tid::next() => DagCbor::encode(['$type' => 'app.bsky.feed.post', 'text' => 'from Bluesky', 'createdAt' => '2026-01-01T00:00:00.000Z'])];
		$tree = (new Mst(array_map(static fn (string $b): Cid => Cid::forDagCbor($b), $records)))->build();
		$commit = Commit::sign(self::DID, $tree->root, Tid::next(), $this->oldKey);
		$blocks = [$commit->cid()->toString() => $commit->toBytes()] + $tree->blocks;
		foreach ($records as $bytes) {
			$blocks[Cid::forDagCbor($bytes)->toString()] = $bytes;
		}

		return Car::encode([$commit->cid()], $blocks);
	}

	private function methods(): array {
		return array_column($this->calls, 'method');
	}

	public function testTheMoveStartsBySigningInToTheOldServer(): void {
		$move = $this->moves->start('alice', '@Alice.bsky.social', 'the password');

		$this->assertSame(['identifier' => self::DID, 'password' => 'the password'], $this->calls[0]['json']);
		$this->assertSame(Move::IN, $move->direction);
		$this->assertSame(self::OLD, $move->pds);
		$this->assertStringNotContainsString('the password', base64_decode(substr($this->rows[1]->session, 7)), 'the password is not kept');
		$this->assertSame([[AtprotoMove::class, ['move' => 1]]], $this->queued);
	}

	public function testWhatStopsAMoveBeforeItStartsIsSaid(): void {
		$this->answers['com.atproto.server.createSession'] = [['status' => 401, 'body' => ['error' => 'AuthFactorTokenRequired']]];
		try {
			$this->moves->start('alice', 'alice.bsky.social', 'pw');
			$this->fail('started');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString('e-mailed you a sign-in code', $e->getMessage());
		}

		$this->expectException(InvalidArgumentException::class);
		$this->moves->start('alice', 'nobody.bsky.social', 'pw');
	}

	public function testTheCopyRunsUntilTheOldServerEmailsACode(): void {
		$this->moves->start('alice', 'alice.bsky.social', 'pw');
		$this->repositories->expects($this->once())->method('import')->with(self::DID, $this->isInstanceOf(PrivateKey::class), $this->callback(static fn (array $records): bool => count($records) === 1 && str_starts_with(array_key_first($records), 'app.bsky.feed.post/')))->willReturn(Cid::forRaw('the new commit'));
		$this->blobs->expects($this->once())->method('put')->with($this->callback(fn (BlobRef $blob): bool => $blob->cid->equals(Cid::forRaw($this->blob)) && $blob->documentId === 'https://social.test/documents/local/9'));
		$this->follows->expects($this->once())->method('adoptBlueskyFollow')->with($this->anything(), $this->anything(), self::DID, '3kfollow')->willReturn(true);

		$this->moves->run($this->rows[1]);

		$this->assertSame(Move::WAITING, $this->rows[1]->state);
		$this->assertSame(Move::STEP_CODE, $this->rows[1]->step);
		$this->assertContains('com.atproto.identity.requestPlcOperationSignature', $this->methods());
		$this->assertNotContains('com.atproto.identity.signPlcOperation', $this->methods(), 'nothing signed without the code');
	}

	public function testWithTheCodeTheDidMovesHere(): void {
		$this->moves->start('alice', 'alice.bsky.social', 'pw');
		$this->moves->run($this->rows[1]);
		$operation = [];
		$this->identities->expects($this->once())->method('adopt')->with($this->current, self::DID, $this->isInstanceOf(PrivateKey::class), self::OLD, $this->callback(static function (array $op) use (&$operation): bool {
			$operation = $op;

			return true;
		}))->willReturn($this->current);

		$this->moves->code('alice', ' 12345-ABCDE ');
		// the old server signs what it was asked: this PDS, the key made here
		$session = json_decode((string)base64_decode(substr($this->rows[1]->session, 7)), true);
		$key = new PrivateKey(Curve::K256, (string)hex2bin($session['key']));
		$this->answers['com.atproto.identity.signPlcOperation'] = [['status' => 200, 'body' => ['operation' => [
			'type' => 'plc_operation',
			'verificationMethods' => ['atproto' => $key->didKey()],
			'services' => ['atproto_pds' => ['type' => 'AtprotoPersonalDataServer', 'endpoint' => self::HERE]],
		]]]];
		$this->moves->run($this->rows[1]);

		$sign = array_values(array_filter($this->calls, static fn (array $c): bool => $c['method'] === 'com.atproto.identity.signPlcOperation'))[0]['json'];
		$this->assertSame('12345-ABCDE', $sign['token']);
		$this->assertSame([$this->rotation->didKey()], $sign['rotationKeys'], 'this server holds the DID now');
		$this->assertSame(['at://alice.social.test'], $sign['alsoKnownAs'], 'under the handle the account has here');
		$this->assertSame(self::HERE, $sign['services']['atproto_pds']['endpoint']);
		$this->assertSame('com.atproto.server.deactivateAccount', end($this->calls)['method'], 'and switched off there');
		$this->assertSame(Move::DONE, $this->rows[1]->state);
		$this->assertSame('', $this->rows[1]->session);
	}

	public function testAnOperationTheOldServerChangedIsNotTaken(): void {
		$this->moves->start('alice', 'alice.bsky.social', 'pw');
		$this->moves->run($this->rows[1]);
		$this->answers['com.atproto.identity.signPlcOperation'] = [['status' => 200, 'body' => ['operation' => ['services' => ['atproto_pds' => ['endpoint' => 'https://evil.example.org']]]]]];
		$this->identities->expects($this->never())->method('adopt');

		$this->moves->code('alice', '12345');
		$this->moves->run($this->rows[1]);
		$this->assertSame(Move::FAILED, $this->rows[1]->state);

		$this->moves->retry($this->rows[1]);
		$this->assertSame(Move::STEP_CODE, $this->rows[1]->step, 'a new code is asked for');
	}
}
