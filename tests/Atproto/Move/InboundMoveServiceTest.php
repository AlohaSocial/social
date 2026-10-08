<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Move;

use InvalidArgumentException;
use OCA\Social\Atproto\AppView\ServiceAuth;
use OCA\Social\Atproto\Client\AppPasswordService;
use OCA\Social\Atproto\Client\Preferences;
use OCA\Social\Atproto\Client\SessionService;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\InstanceKeyService;
use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Atproto\Model\BlobRef;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\Move;
use OCA\Social\Atproto\Move\BridgyTwin;
use OCA\Social\Atproto\Move\InboundMoveService;
use OCA\Social\Atproto\Move\MoveInService;
use OCA\Social\Atproto\Protocol\Car;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\Commit;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Protocol\Mst;
use OCA\Social\Atproto\Protocol\Tid;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Atproto\Xrpc\XrpcException;
use OCA\Social\Cron\AtprotoMove;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoClientRequest;
use OCA\Social\Db\AtprotoMoveRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Service\DocumentService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IUserManager;
use OCP\Security\Bruteforce\IThrottler;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class InboundMoveServiceTest extends TestCase {
	private const DID = 'did:plc:z72i7hdynmk6r22z27h6tvur';
	private const HERE = 'https://social.test';
	private const SERVICE = 'did:web:social.test';
	private const CODE = 'abcdef-ghijkl-mnopqr-stuvwx';

	private int $now = 1760000000;
	/** @var array<int, Move> */
	private array $rows = [];
	private array $queued = [];
	/** @var array<string, BlobRef> */
	private array $stored = [];
	private array $asked = [];
	private array $plcState;
	private PrivateKey $oldKey;
	private PrivateKey $rotation;
	private Identity $current;
	private string $picture = 'a picture, byte for byte';
	/** @var array<string, string> path => record bytes the repository holds */
	private array $records = [];
	/** @var IdentityService&MockObject */
	private IdentityService $identities;
	/** @var RepositoryService&MockObject */
	private RepositoryService $repositories;
	/** @var MoveInService&MockObject */
	private MoveInService $moveIn;
	private ServiceAuth $serviceAuth;
	private InboundMoveService $moves;

	protected function setUp(): void {
		$this->oldKey = PrivateKey::generate(Curve::K256);
		$this->rotation = PrivateKey::generate(Curve::K256);
		$this->current = new Identity(1, 'https://social.test/@alice', 'did:plc:qhuarct7u6lie3dhefl6c4ua', 'alice.social.test', '', '', '', Identity::STATE_ACTIVE, '', 0);
		$this->plcState = $this->stateAt('https://pds.example.com', $this->oldKey->didKey(), ['did:key:zOld']);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('pdsEndpoint')->willReturn(self::HERE);
		$config->method('serviceDid')->willReturn(self::SERVICE);
		$keys = $this->createMock(InstanceKeyService::class);
		$keys->method('rotationKey')->willReturn($this->rotation);
		$keys->method('serviceKey')->willReturn(PrivateKey::generate(Curve::P256));
		$sessions = new SessionService(
			$config, $this->createMock(IdentityService::class), $keys, $this->createMock(AppPasswordService::class),
			$this->createMock(AtprotoClientRequest::class), $this->createMock(ActorsRequest::class), $this->createMock(IUserManager::class),
			$this->createMock(IThrottler::class), $time,
		);
		$this->serviceAuth = new ServiceAuth($time);
		$plc = $this->createMock(PlcClient::class);
		$plc->method('data')->willReturnCallback(fn (): array => $this->plcState);
		$this->identities = $this->createMock(IdentityService::class);
		$this->identities->method('getByDid')->willThrowException(new AtprotoIdentityNotFoundException());
		$this->identities->method('forActor')->willReturnCallback(fn (): Identity => $this->current);
		$this->repositories = $this->createMock(RepositoryService::class);
		$this->repositories->method('import')->willReturnCallback(function (string $did, PrivateKey $key, array $records): Cid {
			$this->records = $records;

			return Cid::forRaw('a commit');
		});
		$this->repositories->method('getHead')->willReturnCallback(fn (): ?\OCA\Social\Atproto\Model\RepoHead => new \OCA\Social\Atproto\Model\RepoHead(self::DID, Cid::forRaw('a commit')->toString(), Tid::next(), count($this->records), 0, 0));
		$repoRequest = $this->createMock(AtprotoRepoRequest::class);
		$repoRequest->method('eachRecordBytes')->willReturnCallback(function (string $did, callable $each): void {
			foreach ($this->records as $path => $bytes) {
				$each(Cid::forDagCbor($bytes), $bytes, $path);
			}
		});
		$repoRequest->method('countRecords')->willReturnCallback(fn (): int => count($this->records));
		$repoRequest->method('getBlockCids')->willReturn([]);
		$blobs = $this->createMock(AtprotoBlobRequest::class);
		$blobs->method('get')->willReturnCallback(fn (string $did, string $cid): ?BlobRef => $this->stored[$cid] ?? null);
		$blobs->method('put')->willReturnCallback(function (BlobRef $blob): void {
			$this->stored[$blob->cid->toString()] = $blob;
		});
		$documents = $this->createMock(DocumentService::class);
		$documents->method('storeAsIs')->willReturnCallback(function (Person $actor, string $path): Document {
			$this->assertSame($this->picture, file_get_contents($path), 'stored as it came');
			$document = new Document();
			$document->setId('https://social.test/documents/local/9');

			return $document;
		});
		$this->moveIn = $this->createMock(MoveInService::class);
		$this->moveIn->method('didOf')->willReturn(self::DID);
		$bridgy = $this->createMock(BridgyTwin::class);
		$bridgy->method('ask')->willReturnCallback(function (Person $actor, string $command): void {
			$this->asked[] = $command;
		});
		$local = new Person();
		$local->setId('https://social.test/@alice');
		$local->setAccount('alice@social.test');
		$actors = $this->createMock(ActorsRequest::class);
		$actors->method('getFromUserId')->willReturn($local);
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
		$request->method('latestOfDid')->willReturnCallback(function (string $did, string $direction): ?Move {
			$of = array_filter($this->rows, static fn (Move $m): bool => $m->did === $did && $m->direction === $direction);

			return $of === [] ? null : clone end($of);
		});
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(static fn (string $plain): string => 'sealed:' . base64_encode($plain));
		$crypto->method('decrypt')->willReturnCallback(static fn (string $sealed): string => (string)base64_decode(substr($sealed, 7)));
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn(str_replace('-', '', self::CODE));
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('add')->willReturnCallback(function (string $job, mixed $argument): void {
			$this->queued[] = [$job, $argument];
		});

		$this->moves = new InboundMoveService(
			$config, $sessions, $this->serviceAuth, $plc, $this->identities, $keys, $this->repositories, $repoRequest, $blobs,
			$documents, $this->createMock(Preferences::class), $this->moveIn, $bridgy, $actors, $request, $crypto, $random,
			$this->createMock(IThrottler::class), $jobs, $time, new NullLogger(),
		);
	}

	private function stateAt(string $endpoint, string $signing, array $rotationKeys): array {
		return [
			'did' => self::DID,
			'rotationKeys' => $rotationKeys,
			'alsoKnownAs' => ['at://alice.bsky.social'],
			'verificationMethods' => ['atproto' => $signing],
			'services' => ['atproto_pds' => ['type' => 'AtprotoPersonalDataServer', 'endpoint' => $endpoint]],
		];
	}

	/** What the other side does first: the account made here, with the code and a token the DID signed. */
	private function made(): array {
		$this->moves->invite('alice', 'alice.bsky.social');
		$token = $this->serviceAuth->token($this->oldKey, self::DID, self::SERVICE, 'com.atproto.server.createAccount');

		return $this->moves->createAccount('Bearer ' . $token, ['did' => self::DID, 'handle' => 'whatever.test', 'email' => 'a@b.c', 'password' => self::CODE], '127.0.0.1');
	}

	private function oldRepository(PrivateKey $key): string {
		$picture = ['$type' => 'blob', 'ref' => Cid::forRaw($this->picture), 'mimeType' => 'image/png', 'size' => strlen($this->picture)];
		$records = ['app.bsky.feed.post/' . Tid::next() => DagCbor::encode(['$type' => 'app.bsky.feed.post', 'text' => 'from Bridgy', 'embed' => ['$type' => 'app.bsky.embed.images', 'images' => [['image' => $picture, 'alt' => '']]], 'createdAt' => '2026-01-01T00:00:00.000Z'])];
		$tree = (new Mst(array_map(static fn (string $b): Cid => Cid::forDagCbor($b), $records)))->build();
		$commit = Commit::sign(self::DID, $tree->root, Tid::next(), $key);
		$blocks = [$commit->cid()->toString() => $commit->toBytes()] + $tree->blocks;
		foreach ($records as $bytes) {
			$blocks[Cid::forDagCbor($bytes)->toString()] = $bytes;
		}

		return Car::encode([$commit->cid()], $blocks);
	}

	private function file(string $bytes): string {
		$path = (string)tempnam(sys_get_temp_dir(), 'social-test-');
		file_put_contents($path, $bytes);

		return $path;
	}

	private function refused(callable $call): XrpcException {
		try {
			$call();
		} catch (XrpcException $e) {
			return $e;
		}
		$this->fail('not refused');
	}

	public function testAToolGetsWhatItNeedsAndTheCodeIsNotKept(): void {
		$invited = $this->moves->invite('alice', 'alice.bsky.social');

		$this->assertFalse($invited['bridgy']);
		$this->assertSame(self::CODE, $invited['code']);
		$this->assertSame(['social.test', 'alice.social.test', 'alice@social.test'], [$invited['pds'], $invited['handle'], $invited['email']]);
		$this->assertSame([Move::INBOUND, Move::STEP_INVITED, Move::WAITING, 'alice.bsky.social'], [$invited['move']->direction, $invited['move']->step, $invited['move']->state, $invited['move']->handle]);
		$this->assertStringNotContainsString(self::CODE, base64_decode(substr($this->rows[1]->session, 7)), 'only a hash of the code is kept');
		$this->assertSame([], $this->asked);
	}

	public function testABridgyTwinIsAskedByDirectMessage(): void {
		$this->plcState = $this->stateAt('https://atproto.brid.gy', $this->oldKey->didKey(), ['did:key:zBridgy']);

		$invited = $this->moves->invite('alice', 'alice.social.test.ap.brid.gy');

		$this->assertTrue($invited['bridgy']);
		$this->assertSame('', $invited['code'], 'the code went to Bridgy, not back');
		$this->assertSame(['migrate-to social.test alice@social.test alice.social.test ' . self::CODE . ' ' . self::CODE], $this->asked);
	}

	public function testAnAccountIsMadeOnlyWithTheCodeAndATokenTheDidSigned(): void {
		$this->moves->invite('alice', 'alice.bsky.social');
		$good = $this->serviceAuth->token($this->oldKey, self::DID, self::SERVICE, 'com.atproto.server.createAccount');
		$body = ['did' => self::DID, 'handle' => 'x.test', 'email' => 'a@b.c'];

		$this->assertSame('InvalidInviteCode', $this->refused(fn () => $this->moves->createAccount('Bearer ' . $good, $body + ['password' => 'guessed'], 'ip'))->error);
		$this->assertSame('InvalidInviteCode', $this->refused(fn () => $this->moves->createAccount('Bearer ' . $good, ['did' => 'did:plc:someoneelse0000000000000'] + $body + ['password' => self::CODE], 'ip'))->error);
		$forged = $this->serviceAuth->token(PrivateKey::generate(Curve::K256), self::DID, self::SERVICE, 'com.atproto.server.createAccount');
		$this->assertSame('AuthenticationRequired', $this->refused(fn () => $this->moves->createAccount('Bearer ' . $forged, $body + ['password' => self::CODE], 'ip'))->error);
		$elsewhere = $this->serviceAuth->token($this->oldKey, self::DID, 'did:web:other.test', 'com.atproto.server.createAccount');
		$this->assertSame('AuthenticationRequired', $this->refused(fn () => $this->moves->createAccount('Bearer ' . $elsewhere, $body + ['password' => self::CODE], 'ip'))->error);

		$made = $this->moves->createAccount('Bearer ' . $good, $body + ['inviteCode' => self::CODE], 'ip');

		$this->assertSame([self::DID, 'alice.social.test', false], [$made['did'], $made['handle'], $made['active']], 'the handle is the one here');
		$this->assertSame(Move::STEP_REPO, $this->rows[1]->step);
		$this->assertTrue($this->moves->owns('Bearer ' . $made['accessJwt']));
		$this->assertSame('InvalidInviteCode', $this->refused(fn () => $this->moves->createAccount('Bearer ' . $good, $body + ['password' => self::CODE], 'ip'))->error, 'the code works once');
	}

	public function testAnInvitationRunsOut(): void {
		$this->moves->invite('alice', 'alice.bsky.social');
		$this->now += InboundMoveService::INVITATION_LIFETIME + 1;
		$this->repositories->expects($this->once())->method('delete')->with(self::DID);

		$move = $this->moves->latest('alice');

		$this->assertSame([Move::FAILED, 'The invitation expired', ''], [$move?->state, $move?->error, $this->rows[1]->session]);
	}

	public function testTheSessionOnlyMovesTheAccount(): void {
		$made = $this->made();
		$auth = 'Bearer ' . $made['accessJwt'];

		$this->assertSame('InsufficientScope', $this->refused(fn () => $this->moves->query('app.bsky.feed.getTimeline', [], $auth))->error);
		$this->assertSame('InsufficientScope', $this->refused(fn () => $this->moves->procedure('com.atproto.repo.createRecord', [], $auth))->error);
		$this->assertFalse($this->moves->owns('Bearer a.' . rtrim(strtr(base64_encode('{"sub":"did:plc:x"}'), '+/', '-_'), '=') . '.c'), 'an app\'s session is not a move\'s');

		$credentials = $this->moves->query('com.atproto.identity.getRecommendedDidCredentials', [], $auth);
		$this->assertSame([$this->rotation->didKey()], $credentials['rotationKeys']);
		$this->assertSame(['at://alice.social.test'], $credentials['alsoKnownAs']);
		$this->assertSame(self::HERE, $credentials['services']['atproto_pds']['endpoint']);
		$this->assertStringStartsWith('did:key:z', $credentials['verificationMethods']['atproto']);
	}

	public function testTheRepositoryIsTakenOnlyAsTheDidSignedIt(): void {
		$auth = 'Bearer ' . $this->made()['accessJwt'];

		$this->assertSame('InvalidRequest', $this->refused(fn () => $this->moves->importRepo($this->file($this->oldRepository(PrivateKey::generate(Curve::K256))), $auth))->error);
		$this->moves->importRepo($this->file($this->oldRepository($this->oldKey)), $auth);

		$this->assertCount(1, $this->records);
		$this->assertSame(Move::STEP_BLOBS, $this->rows[1]->step);
		$this->assertSame(['records' => 1, 'expectedBlobs' => 1], $this->rows[1]->progress);
	}

	public function testMissingBlobsAreListedAndKeptByteForByte(): void {
		$auth = 'Bearer ' . $this->made()['accessJwt'];
		$this->moves->importRepo($this->file($this->oldRepository($this->oldKey)), $auth);
		$cid = Cid::forRaw($this->picture)->toString();

		$missing = $this->moves->query('com.atproto.repo.listMissingBlobs', [], $auth);
		$this->assertSame($cid, $missing['blobs'][0]['cid']);
		$this->assertStringStartsWith('at://' . self::DID . '/app.bsky.feed.post/', $missing['blobs'][0]['recordUri']);

		$uploaded = $this->moves->upload($this->file($this->picture), $auth, 'image/png');

		$this->assertSame(['$type' => 'blob', 'ref' => ['$link' => $cid], 'mimeType' => 'image/png', 'size' => strlen($this->picture)], $uploaded['blob']);
		$this->assertSame([], $this->moves->query('com.atproto.repo.listMissingBlobs', [], $auth)['blobs']);
		$status = $this->moves->query('com.atproto.server.checkAccountStatus', [], $auth);
		$this->assertSame([false, false, 1, 1], [$status['activated'], $status['validDid'], $status['expectedBlobs'], $status['importedBlobs']]);
	}

	public function testTheAccountTakesTheDidOnlyOnceTheDirectoryPointsHere(): void {
		$auth = 'Bearer ' . $this->made()['accessJwt'];
		$this->moves->importRepo($this->file($this->oldRepository($this->oldKey)), $auth);
		$recommended = $this->moves->query('com.atproto.identity.getRecommendedDidCredentials', [], $auth);

		$this->assertSame('InvalidRequest', $this->refused(fn () => $this->moves->procedure('com.atproto.server.activateAccount', [], $auth))->error);

		$this->plcState = $this->stateAt(self::HERE, $recommended['verificationMethods']['atproto'], [$this->rotation->didKey()]);
		$this->identities->expects($this->once())->method('receive')
			->with($this->current, self::DID, $this->callback(fn (PrivateKey $key): bool => $key->didKey() === $recommended['verificationMethods']['atproto']), 'https://pds.example.com')
			->willReturn($this->current);
		$this->moves->procedure('com.atproto.server.activateAccount', [], $auth);

		$this->assertSame([Move::STEP_FOLLOWS, Move::RUNNING], [$this->rows[1]->step, $this->rows[1]->state]);
		$this->assertSame([[AtprotoMove::class, ['move' => 1]]], $this->queued);
		$this->assertTrue($this->moves->query('com.atproto.server.checkAccountStatus', [], $auth)['activated']);

		$this->moveIn->expects($this->once())->method('adoptFollows');
		$this->moveIn->expects($this->once())->method('importPosts')->with($this->callback(fn (Move $move): bool => $move->step === Move::STEP_POSTS));
		$this->moves->run($this->rows[1]);
		$this->assertSame(Move::DONE, $this->rows[1]->state);
		$this->assertTrue($this->moves->query('com.atproto.server.checkAccountStatus', [], $auth)['validDid'], 'still answered once the move is done');
		$this->moves->upload($this->file($this->picture), $auth, 'image/png');
	}

	public function testOnlyAnOperationPointingHereIsSent(): void {
		$auth = 'Bearer ' . $this->made()['accessJwt'];
		$recommended = $this->moves->query('com.atproto.identity.getRecommendedDidCredentials', [], $auth);
		$elsewhere = ['type' => 'plc_operation'] + $this->stateAt('https://evil.example.org', $recommended['verificationMethods']['atproto'], [$this->rotation->didKey()]);
		$here = ['type' => 'plc_operation'] + $this->stateAt(self::HERE, $recommended['verificationMethods']['atproto'], [$this->rotation->didKey()]);

		$this->identities->expects($this->once())->method('submit')->with(self::DID, $here);
		$this->assertSame('InvalidRequest', $this->refused(fn () => $this->moves->procedure('com.atproto.identity.submitPlcOperation', ['operation' => $elsewhere], $auth))->error);
		$this->moves->procedure('com.atproto.identity.submitPlcOperation', ['operation' => $here], $auth);
	}

	public function testARefreshEndsTheSessionBefore(): void {
		$made = $this->made();

		$refreshed = $this->moves->procedure('com.atproto.server.refreshSession', [], 'Bearer ' . $made['refreshJwt']);

		$this->assertSame('ExpiredToken', $this->refused(fn () => $this->moves->query('com.atproto.server.getSession', [], 'Bearer ' . $made['accessJwt']))->error);
		$this->assertSame('ExpiredToken', $this->refused(fn () => $this->moves->procedure('com.atproto.server.refreshSession', [], 'Bearer ' . $made['refreshJwt']))->error);
		$this->assertSame(self::DID, $this->moves->query('com.atproto.server.getSession', [], 'Bearer ' . $refreshed['accessJwt'])['did']);
	}

	public function testACalledOffMoveLeavesNothingAndItsSessionEnds(): void {
		$auth = 'Bearer ' . $this->made()['accessJwt'];
		$this->repositories->expects($this->atLeastOnce())->method('delete')->with(self::DID);

		$this->moves->cancel('alice');

		$this->assertSame(Move::FAILED, $this->rows[1]->state);
		$this->assertSame('ExpiredToken', $this->refused(fn () => $this->moves->query('com.atproto.server.getSession', [], $auth))->error);
	}

	public function testAnotherMoveUnderWayIsNotReplaced(): void {
		$this->rows[1] = new Move(1, self::DID, 'alice', Move::IN, 'https://pds.example.com', '', 'alice.bsky.social', Move::STEP_REPO, Move::RUNNING);

		$this->expectException(InvalidArgumentException::class);
		$this->moves->invite('alice', 'alice.bsky.social');
	}
}
