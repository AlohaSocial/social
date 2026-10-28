<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Move;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Client\ClientXrpc;
use OCA\Social\Atproto\Client\Preferences;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\HandleVerifier;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\InstanceKeyService;
use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Atproto\Model\BlobRef;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\Move;
use OCA\Social\Atproto\Model\StoredRecord;
use OCA\Social\Atproto\Move\MovedBlobs;
use OCA\Social\Atproto\Move\MoveInService;
use OCA\Social\Atproto\Move\PdsClient;
use OCA\Social\Atproto\Move\PostHistory;
use OCA\Social\Atproto\Move\PostRepublisher;
use OCA\Social\Atproto\Move\RepoArchive;
use OCA\Social\Atproto\Protocol\Car;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\Commit;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Protocol\Mst;
use OCA\Social\Atproto\Protocol\Tid;
use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Atproto\Reader\BlueskyListImport;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Cron\AtprotoMove;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoIdentityRequest;
use OCA\Social\Db\AtprotoMoveRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCA\Social\Exceptions\MoveErrorException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\FollowService;
use OCP\BackgroundJob\IJobList;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
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
	/** what the stored document's bytes turned out to be */
	private string $sniffed = 'image/png';
	private Identity $current;
	/** whether the PLC directory knows the DID */
	private bool $known = true;
	/** @var array<string, int> the cap each download was given, by method */
	private array $downloaded = [];
	/** the revision of the old server's repository */
	private string $oldRev = '';
	/** whether the old server hands out the second blob as it is named */
	private bool $elsewhereArrives = false;
	/** @var array<string, string> path => bytes of the records copied here */
	private array $records = [];
	/** @var list<string> the DID's rotation keys before the move */
	private array $listedKeys = [];
	/** @var (callable(): void)|null what happens while the blobs are listed */
	private $duringCopy = null;
	/** @var (callable(): void)|null what happens right after the move was read */
	private $afterGet = null;
	/** @var (callable(): void)|null what the job does right after the person's move was read */
	private $afterRead = null;
	/** where the directory says the DID's PDS is */
	private string $pointsAt = self::OLD;
	/** the signing key the directory names for the DID, '' for the old server's */
	private string $signingKeyListed = '';
	/** the DID's handle before the move */
	private string $oldHandle = 'alice.bsky.social';
	/** @var list<string> the domains the old server gives handles under */
	private array $userDomains = ['.bsky.social'];
	/** @var list<string> the custom handles other accounts here have */
	private array $taken = [];
	/** @var list<string> the handles whose domain does not name the DID */
	private array $unverified = [];
	/** @var array<string, BlobRef> the blobs stored here, by CID */
	private array $stored = [];
	/** @var IdentityService&MockObject */
	private IdentityService $identities;
	/** @var RepositoryService&MockObject */
	private RepositoryService $repositories;
	/** @var FollowService&MockObject */
	private FollowService $follows;
	/** @var PostHistory&MockObject */
	private PostHistory $history;
	/** @var BlueskyListImport&MockObject */
	private BlueskyListImport $lists;
	/** @var AtprotoBlobRequest&MockObject */
	private AtprotoBlobRequest $blobs;
	/** @var AtprotoIdentityRequest&MockObject */
	private AtprotoIdentityRequest $identityRequest;
	/** @var PostRepublisher&MockObject */
	private PostRepublisher $republisher;
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
			$answer = array_shift($this->answers[$method]);
			if ($answer === null && $method === 'com.atproto.identity.signPlcOperation') {
				// the old server signs what it was asked
				return ['status' => 200, 'body' => ['operation' => ['type' => 'plc_operation'] + array_diff_key($json ?? [], ['token' => true])]];
			}

			if ($answer === null && $method === 'com.atproto.server.describeServer') {
				return ['status' => 200, 'body' => ['did' => 'did:web:pds.example.com', 'availableUserDomains' => $this->userDomains]];
			}

			return $answer ?? ['status' => 200, 'body' => []];
		});
		$pds->method('expect')->willReturnCallback(function (string $origin, string $method): array {
			$this->calls[] = ['method' => $method, 'json' => null, 'token' => ''];

			return [];
		});
		$pds->method('bytes')->willReturnCallback(function (string $origin, string $method, array $query, int $max): array {
			$this->downloaded[$method] = $max;
			$this->calls[] = ['method' => $method, 'json' => null, 'token' => ''];
			if ($method === 'com.atproto.sync.getBlob' && $this->duringCopy !== null) {
				($this->duringCopy)();
			}

			return self::downloaded(match ($method) {
				'com.atproto.sync.getRepo' => $this->car,
				'com.atproto.sync.getBlob' => match (true) {
					$query['cid'] === Cid::forRaw($this->blob)->toString() => $this->blob,
					$this->elsewhereArrives && $query['cid'] === Cid::forRaw('elsewhere')->toString() => 'elsewhere',
					default => 'not what it is named',
				},
			});
		});
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('pdsEndpoint')->willReturn(self::HERE);
		$appView = $this->createMock(AppViewClient::class);
		$appView->method('query')->willReturnCallback(static fn (string $m, array $p): array => $p['handle'] === 'alice.bsky.social' ? ['did' => self::DID] : []);
		$plc = $this->createMock(PlcClient::class);
		$plc->method('data')->willReturnCallback(fn (): ?array => $this->known ? [
			'rotationKeys' => $this->listedKeys,
			'alsoKnownAs' => ['at://' . $this->oldHandle],
			'verificationMethods' => ['atproto' => $this->signingKeyListed !== '' ? $this->signingKeyListed : $this->oldKey->didKey()],
			'services' => ['atproto_pds' => ['type' => 'AtprotoPersonalDataServer', 'endpoint' => $this->pointsAt]],
		] : null);
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
		$this->blobs->method('put')->willReturnCallback(function (BlobRef $blob): bool {
			$this->stored[$blob->cid->toString()] = $blob;
			return true;
		});
		$this->blobs->method('get')->willReturnCallback(fn (string $did, string $cid): ?BlobRef => $this->stored[$cid] ?? null);
		$this->identityRequest = $this->createMock(AtprotoIdentityRequest::class);
		$this->republisher = $this->createMock(PostRepublisher::class);
		$this->identityRequest->method('handleExists')->willReturnCallback(fn (string $handle): bool => in_array($handle, $this->taken, true));
		$verifier = $this->createMock(HandleVerifier::class);
		$verifier->method('refusal')->willReturnCallback(static fn (string $handle): string => str_ends_with($handle, '.social.test') || !str_contains($handle, '.') ? 'no' : '');
		$verifier->method('verify')->willReturnCallback(fn (string $handle, string $did): string => $did === self::DID && !in_array($handle, $this->unverified, true) ? 'dns' : '');
		$documents = $this->createMock(DocumentService::class);
		$documents->method('storeAsIs')->willReturnCallback(function (Person $actor, string $path): Document {
			$this->assertContains(file_get_contents($path), [$this->blob, 'elsewhere'], 'stored as it came');
			$document = new Document();
			$document->setId('https://social.test/documents/local/9');
			$document->setMimeType($this->sniffed);

			return $document;
		});
		$followed = new Person();
		$followed->setId('https://bsky.app/profile/' . self::FOLLOWED);
		$actors = $this->createMock(BlueskyActorService::class);
		$actors->method('resolve')->with(self::FOLLOWED)->willReturn($followed);
		$this->follows = $this->createMock(FollowService::class);
		$this->history = $this->createMock(PostHistory::class);
		$this->lists = $this->createMock(BlueskyListImport::class);
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
		$request->method('updateIfRunning')->willReturnCallback(function (Move $move): bool {
			if (($this->rows[$move->id] ?? null)?->state !== Move::RUNNING) {
				return false;
			}
			$this->rows[$move->id] = clone $move;

			return true;
		});
		$request->method('get')->willReturnCallback(function (int $id): ?Move {
			$move = isset($this->rows[$id]) ? clone $this->rows[$id] : null;
			if ($this->afterGet !== null) {
				($this->afterGet)();
			}

			return $move;
		});
		$request->method('latestOfUser')->willReturnCallback(function (): ?Move {
			$move = $this->rows === [] ? null : clone end($this->rows);
			if ($this->afterRead !== null) {
				($this->afterRead)();
			}

			return $move;
		});
		$request->method('updateIf')->willReturnCallback(function (Move $move, string $state, string $step): bool {
			$stored = $this->rows[$move->id] ?? null;
			if ($stored?->state !== $state || $stored->step !== $step) {
				return false;
			}
			$this->rows[$move->id] = clone $move;

			return true;
		});
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(static fn (string $plain): string => 'sealed:' . base64_encode($plain));
		$crypto->method('decrypt')->willReturnCallback(static fn (string $sealed): string => (string)base64_decode(substr($sealed, 7)));
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('add')->willReturnCallback(function (string $job, mixed $argument): void {
			$this->queued[] = [$job, $argument];
		});
		$repoRequest = $this->createMock(AtprotoRepoRequest::class);
		$repoRequest->method('eachRecordBytes')->willReturnCallback(function (string $did, callable $each): void {
			foreach ($this->records as $path => $bytes) {
				$each(Cid::forDagCbor($bytes), $bytes, $path);
			}
		});
		$repoRequest->method('getRecord')->willReturnCallback(function (string $did, string $collection, string $rkey): ?StoredRecord {
			$bytes = $this->records[$collection . '/' . $rkey] ?? null;

			return $bytes === null ? null : new StoredRecord($did, $collection, $rkey, Cid::forDagCbor($bytes), $bytes, '', 1);
		});
		$this->moves = new MoveInService(
			$pds, $config, $appView, $plc, $this->identities, $keys, $this->repositories, $repoRequest,
			$this->blobs, $this->createMock(Preferences::class), $actors, $this->follows, $this->history, $actorsRequest, $request,
			$crypto, $jobs, new NullLogger(), $this->lists, $this->identityRequest, $verifier,
			new MovedBlobs($pds, $repoRequest, $this->blobs, $documents, $actorsRequest, new NullLogger()), $this->republisher,
		);
		$this->answers['com.atproto.server.createSession'] = [['status' => 200, 'body' => ['did' => self::DID, 'handle' => 'alice.bsky.social', 'accessJwt' => 'access', 'refreshJwt' => 'refresh']]];
		$this->postNames(Cid::forRaw($this->blob));
	}

	/** The records copied here: a post with a picture. */
	private function postNames(Cid $picture): void {
		$this->records = ['app.bsky.feed.post/3kpost' => DagCbor::encode(['$type' => 'app.bsky.feed.post', 'text' => 'look', 'embed' => ['$type' => 'app.bsky.embed.images', 'images' => [['image' => ['$type' => 'blob', 'ref' => $picture, 'mimeType' => 'image/png', 'size' => 9], 'alt' => '']]], 'createdAt' => '2026-01-01T00:00:00.000Z'])];
	}

	/**
	 * @param list<string> $texts the posts in it
	 */
	private function oldRepository(array $texts = ['from Bluesky']): string {
		$records = [];
		foreach ($texts as $text) {
			$records['app.bsky.feed.post/' . Tid::next()] = DagCbor::encode(['$type' => 'app.bsky.feed.post', 'text' => $text, 'createdAt' => '2026-01-01T00:00:00.000Z']);
		}
		$tree = (new Mst(array_map(static fn (string $b): Cid => Cid::forDagCbor($b), $records)))->build();
		$this->oldRev = Tid::next();
		$commit = Commit::sign(self::DID, $tree->root, $this->oldRev, $this->oldKey);
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
		} catch (MoveErrorException $e) {
			$this->assertStringContainsString('e-mailed you a sign-in code', $e->getMessage());
		}

		$this->expectException(MoveErrorException::class);
		$this->moves->start('alice', 'nobody.bsky.social', 'pw');
	}

	/**
	 * @return string the code of what start() refused
	 */
	private function refusedStart(string $typed = 'alice.bsky.social'): string {
		try {
			$this->moves->start('alice', $typed, 'pw');
		} catch (MoveErrorException $e) {
			return $e->errorCode;
		}
		$this->fail('started');
	}

	/** What stops a move before it starts carries a code the page explains. */
	public function testARefusedStartSaysWhyAsACode(): void {
		$this->assertSame('handle-not-found', $this->refusedStart('nobody.bsky.social'));
		$this->assertSame('handle-invalid', $this->refusedStart('not a handle'));
		$this->assertSame('did-not-supported', $this->refusedStart('did:web:example.com'));

		$this->answers['com.atproto.server.createSession'] = [
			['status' => 401, 'body' => ['error' => 'AuthenticationRequired', 'message' => 'Invalid identifier or password']],
			['status' => 401, 'body' => ['error' => 'AuthFactorTokenRequired']],
			['status' => 401, 'body' => ['error' => 'InvalidToken']],
			['status' => 429, 'body' => ['error' => 'RateLimitExceeded']],
		];
		$this->assertSame(['wrong-password', 'auth-factor-required', 'sign-in-code-invalid', 'rate-limited'], [$this->refusedStart(), $this->refusedStart(), $this->refusedStart(), $this->refusedStart()]);

		$this->known = false;
		$this->assertSame('did-not-found', $this->refusedStart(), 'a DID the directory does not know is not "unreachable"');

		$this->known = true;
		$this->rows[1] = new Move(1, self::DID, 'alice', Move::IN, self::OLD, '', 'alice.bsky.social', Move::STEP_CODE, Move::WAITING);
		$this->assertSame('move-in-progress', $this->refusedStart());
	}

	/** A wrong or old e-mailed code fails the move with a code saying so. */
	public function testARefusedCodeFailsTheMoveWithItsCode(): void {
		$this->moves->start('alice', 'alice.bsky.social', 'pw');
		$this->moves->run($this->rows[1]);
		$this->moves->code('alice', '12345');
		$this->answers['com.atproto.identity.signPlcOperation'] = [
			['status' => 400, 'body' => ['error' => 'InvalidToken', 'message' => 'Token is invalid']],
			['status' => 400, 'body' => ['error' => 'InvalidToken', 'message' => 'Token is invalid']],
		];

		$this->moves->run($this->rows[1]);

		$this->assertSame(Move::FAILED, $this->rows[1]->state);
		$this->assertSame('code-invalid', $this->rows[1]->progress['code']);

		$this->moves->retry($this->rows[1]);
		$this->assertArrayNotHasKey('code', $this->rows[1]->progress, 'a move that goes on again has no failure');
	}

	public function testTheCopyRunsUntilTheOldServerEmailsACode(): void {
		$this->moves->start('alice', 'alice.bsky.social', 'pw');
		$this->repositories->expects($this->once())->method('import')->with(self::DID, $this->isInstanceOf(PrivateKey::class), $this->callback(static fn (array $records): bool => count($records) === 1 && str_starts_with(array_key_first($records), 'app.bsky.feed.post/')))->willReturn(Cid::forRaw('the new commit'));
		$this->blobs->expects($this->once())->method('put')->with($this->callback(fn (BlobRef $blob): bool => $blob->cid->equals(Cid::forRaw($this->blob)) && $blob->documentId === 'https://social.test/documents/local/9'));
		$this->follows->expects($this->never())->method('adoptBlueskyFollow');

		$this->moves->run($this->rows[1]);

		$this->assertSame(Move::WAITING, $this->rows[1]->state);
		$this->assertSame(Move::STEP_CODE, $this->rows[1]->step);
		$this->assertContains('com.atproto.identity.requestPlcOperationSignature', $this->methods());
		$this->assertNotContains('com.atproto.identity.signPlcOperation', $this->methods(), 'nothing signed without the code');
	}

	/**
	 * A blob copied from the old server keeps the type of its bytes when it
	 * is a picture, a video or a sound, and is opaque bytes otherwise.
	 */
	public function testABlobThatIsNotAMediaFileIsKeptAsOpaqueBytes(): void {
		$this->sniffed = 'text/plain';
		$this->moves->start('alice', 'alice.bsky.social', 'pw');
		$this->blobs->expects($this->once())->method('put')->with($this->callback(fn (BlobRef $blob): bool => $blob->mime === 'application/octet-stream'));

		$this->moves->run($this->rows[1]);
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
		$this->follows->expects($this->once())->method('adoptBlueskyFollow')->with($this->anything(), $this->anything(), self::DID, '3kfollow')->willReturnCallback(function (): bool {
			$this->calls[] = ['method' => 'follow', 'json' => null, 'token' => ''];

			return true;
		});
		$this->history->expects($this->once())->method('import')->with($this->isInstanceOf(Person::class), self::DID)->willReturn(3);
		$this->lists->expects($this->once())->method('adopt')->with($this->isInstanceOf(Person::class), self::DID)->willReturn(2);
		$this->moves->run($this->rows[1]);

		$sign = array_values(array_filter($this->calls, static fn (array $c): bool => $c['method'] === 'com.atproto.identity.signPlcOperation'))[0]['json'];
		$this->assertSame('12345-ABCDE', $sign['token']);
		$this->assertSame([$this->rotation->didKey()], $sign['rotationKeys'], 'this server holds the DID now');
		$this->assertSame(['at://alice.social.test'], $sign['alsoKnownAs'], 'under the handle the account has here');
		$this->assertSame(self::HERE, $sign['services']['atproto_pds']['endpoint']);
		$methods = $this->methods();
		$this->assertSame(['com.atproto.identity.signPlcOperation', 'com.atproto.server.deactivateAccount', 'follow'], array_values(array_intersect($methods, ['com.atproto.identity.signPlcOperation', 'com.atproto.server.deactivateAccount', 'follow'])), 'switched off there, and the follows taken over only once the DID is the account\'s');
		$this->assertSame(Move::DONE, $this->rows[1]->state);
		$this->assertSame('', $this->rows[1]->session);
		$this->assertSame(3, $this->rows[1]->progress['posts'], 'and its posts are posts here');
		$this->assertSame(2, $this->rows[1]->progress['lists'], 'and its lists lists here');
	}

	/**
	 * Runs a move to the signature and answers the rotation keys the old
	 * server was asked to sign.
	 *
	 * @param list<string> $oldServer what the old server names as its own keys
	 * @return list<string>
	 */
	private function signedRotationKeys(array $oldServer): array {
		$this->answers['com.atproto.identity.getRecommendedDidCredentials'] = [['status' => 200, 'body' => ['rotationKeys' => $oldServer]]];
		$this->identities->method('adopt')->willReturn($this->current);
		$this->moves->start('alice', 'alice.bsky.social', 'pw');
		$this->moves->run($this->rows[1]);
		$this->moves->code('alice', '12345');
		$this->moves->run($this->rows[1]);
		$this->assertSame(Move::DONE, $this->rows[1]->state, (string)$this->rows[1]->error);

		return array_values(array_filter($this->calls, static fn (array $c): bool => $c['method'] === 'com.atproto.identity.signPlcOperation'))[0]['json']['rotationKeys'];
	}

	/** The person's own rotation key stays theirs, ranked above this server's; the old server's is dropped. */
	public function testTheUsersRotationKeysAreKeptAndTheOldServersDropped(): void {
		$this->listedKeys = ['did:key:zUser', 'did:key:zOldPds'];

		$this->assertSame(['did:key:zUser', $this->rotation->didKey()], $this->signedRotationKeys(['did:key:zOldPds']));
	}

	/** A recovery key the person has here becomes one of the moved DID's, first. */
	public function testTheRecoveryKeyHereComesFirst(): void {
		$this->listedKeys = ['did:key:zUser', 'did:key:zOldPds'];
		$this->current = new Identity(1, 'https://social.test/@alice', 'did:plc:qhuarct7u6lie3dhefl6c4ua', 'alice.social.test', '', '', 'did:key:zRecovery', Identity::STATE_ACTIVE, '', 0);
		$this->identityRequest->expects($this->once())->method('setRecoveryPublic')->with(self::DID, 'did:key:zRecovery');

		$this->assertSame(['did:key:zRecovery', 'did:key:zUser', $this->rotation->didKey()], $this->signedRotationKeys(['did:key:zOldPds']));
	}

	/** An old server that does not say which keys are its own has none dropped; the directory's limit holds. */
	public function testEveryKeyIsKeptWhenTheOldServerDoesNotSayWithinTheLimit(): void {
		$this->listedKeys = ['did:key:z1', 'did:key:z2', 'did:key:z3', 'did:key:z4', 'did:key:z5'];
		$this->answers['com.atproto.identity.getRecommendedDidCredentials'] = [['status' => 400, 'body' => ['error' => 'InvalidRequest']]];

		$this->assertSame(['did:key:z1', 'did:key:z2', 'did:key:z3', 'did:key:z4', $this->rotation->didKey()], $this->signedRotationKeys([]));
	}

	public function testAnOperationWithOtherRotationKeysIsNotTaken(): void {
		$this->listedKeys = ['did:key:zUser', 'did:key:zOldPds'];
		$this->moves->start('alice', 'alice.bsky.social', 'pw');
		$this->moves->run($this->rows[1]);
		$this->moves->code('alice', '12345');
		$this->answers['com.atproto.identity.signPlcOperation'] = [['status' => 200, 'body' => ['operation' => [
			'rotationKeys' => [$this->rotation->didKey()],
			'verificationMethods' => ['atproto' => $this->keyHere()],
			'services' => ['atproto_pds' => ['endpoint' => self::HERE]],
		]]]];
		$this->identities->expects($this->never())->method('adopt');

		$this->moves->run($this->rows[1]);

		$this->assertSame('operation-mismatch', $this->rows[1]->progress['code'] ?? null);
	}

	/** A handle on the person's own domain stays theirs: the DID names it, and it is the custom handle here. */
	public function testAHandleOnTheirOwnDomainIsKept(): void {
		$this->oldHandle = 'alice.example.org';
		$this->identityRequest->expects($this->once())->method('setCustomHandle')->with($this->current->did, 'alice.example.org');
		$this->identities->method('adopt')->willReturn($this->current);
		$this->moves->start('alice', 'alice.bsky.social', 'pw');
		$this->moves->run($this->rows[1]);
		$this->assertSame('alice.example.org', $this->rows[1]->progress['handle_kept'], 'known while the move waits for the code');

		$this->moves->code('alice', '12345');
		$this->moves->run($this->rows[1]);

		$sign = array_values(array_filter($this->calls, static fn (array $c): bool => $c['method'] === 'com.atproto.identity.signPlcOperation'))[0]['json'];
		$this->assertSame(['at://alice.example.org'], $sign['alsoKnownAs']);
		$this->assertSame(Move::DONE, $this->rows[1]->state);
	}

	public function testAHandleTheOldServerGaveBecomesTheOneHere(): void {
		$this->identityRequest->expects($this->never())->method('setCustomHandle');
		$this->moves->start('alice', 'alice.bsky.social', 'pw');
		$this->moves->run($this->rows[1]);

		$this->assertSame('', $this->rows[1]->progress['handle_kept']);
	}

	/**
	 * @return array<string, array{0: string, 1: list<string>, 2: string}> the handle, the old server's domains, what is kept
	 */
	public static function handles(): array {
		return [
			'own domain' => ['alice.example.org', ['.pds.example.com'], 'alice.example.org'],
			'under a domain the old server gives' => ['alice.pds.example.com', ['.pds.example.com'], ''],
			'under one it gives without a dot' => ['alice.users.example', ['users.example'], ''],
			'Bluesky\'s, when the old server does not say' => ['alice.bsky.social', [], ''],
			'Bridgy\'s' => ['alice.mastodon.example.ap.brid.gy', [], ''],
			'this server\'s' => ['bob.social.test', [], ''],
			'another account\'s here' => ['taken.example.org', [], ''],
			'a domain that does not name the DID' => ['claimed.example.org', [], ''],
		];
	}

	#[DataProvider('handles')]
	public function testWhichHandleIsKept(string $handle, array $domains, string $kept): void {
		$this->userDomains = $domains;
		$this->taken = ['taken.example.org'];
		$this->unverified = ['claimed.example.org'];

		$this->assertSame($kept, $this->moves->keptHandle(self::DID, self::OLD, ['alsoKnownAs' => ['at://' . $handle]], 'alice.social.test'));
	}

	/**
	 * @return array<string, array{0: string, 1: string}> the step and state a move is called off in
	 */
	public static function beforeTheSwitch(): array {
		return [
			'copying the repository' => [Move::STEP_REPO, Move::RUNNING],
			'copying the blobs' => [Move::STEP_BLOBS, Move::RUNNING],
			'copying the preferences' => [Move::STEP_PREFERENCES, Move::RUNNING],
			'waiting for the code' => [Move::STEP_CODE, Move::WAITING],
			'failed with the code' => [Move::STEP_IDENTITY, Move::FAILED],
			'failed copying' => [Move::STEP_BLOBS, Move::FAILED],
		];
	}

	/** A move whose DID has not switched can be called off: the session ends and the copy is dropped. */
	#[DataProvider('beforeTheSwitch')]
	public function testAMoveIsCalledOffBeforeTheSwitch(string $step, string $state): void {
		$this->moves->start('alice', 'alice.bsky.social', 'pw');
		$this->rows[1]->step = $step;
		$this->rows[1]->state = $state;
		$this->repositories->expects($this->once())->method('delete')->with(self::DID);
		$this->blobs->expects($this->once())->method('deleteByDid')->with(self::DID);

		$move = $this->moves->cancel('alice');

		$this->assertSame([Move::CANCELLED, ''], [$move->state, $this->rows[1]->session]);
		$this->assertContains('com.atproto.server.deleteSession', $this->methods(), 'the session on the old server ends');
		$this->assertSame('refresh', end($this->calls)['token']);
		try {
			$this->moves->retry($this->rows[1]);
			$this->fail('started again');
		} catch (MoveErrorException $e) {
			$this->assertSame('no-failed-move', $e->errorCode, 'a called-off move is not started again');
		}
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}> the step, the state, where the directory points
	 */
	public static function pastTheSwitch(): array {
		return [
			'pointing the DID here' => [Move::STEP_IDENTITY, Move::RUNNING, self::OLD],
			'failed after the directory took it' => [Move::STEP_IDENTITY, Move::FAILED, self::HERE],
			'switching off the old account' => [Move::STEP_ACTIVATE, Move::RUNNING, self::HERE],
			'taking the follows over' => [Move::STEP_FOLLOWS, Move::FAILED, self::HERE],
			'done' => [Move::STEP_DONE, Move::DONE, self::HERE],
		];
	}

	#[DataProvider('pastTheSwitch')]
	public function testAMoveIsNotCalledOffOnceTheDidMayHaveSwitched(string $step, string $state, string $pointsAt): void {
		$this->moves->start('alice', 'alice.bsky.social', 'pw');
		$this->rows[1]->step = $step;
		$this->rows[1]->state = $state;
		$this->pointsAt = $pointsAt;
		$this->repositories->expects($this->never())->method('delete');

		try {
			$this->moves->cancel('alice');
			$this->fail('called off');
		} catch (MoveErrorException $e) {
			$this->assertSame($state === Move::DONE ? 'no-move-to-call-off' : 'move-past-switch', $e->errorCode);
		}
		$this->assertSame($state, $this->rows[1]->state);
	}

	/** A move called off while the job copies stops there, and what the job copied is dropped. */
	public function testTheJobStopsWhenTheMoveIsCalledOff(): void {
		$this->moves->start('alice', 'alice.bsky.social', 'pw');
		$this->duringCopy = function (): void {
			$this->duringCopy = null;
			$this->moves->cancel('alice');
		};
		$this->repositories->expects($this->exactly(2))->method('delete')->with(self::DID);

		$this->moves->run($this->rows[1]);

		$this->assertSame(Move::CANCELLED, $this->rows[1]->state);
		$this->assertNotContains('com.atproto.identity.requestPlcOperationSignature', $this->methods());
	}

	/**
	 * A move called off right after the job saw it running, while a step
	 * fails, stays called off, and what the job copied is dropped.
	 */
	public function testAMoveCalledOffWhileAStepFailsStaysCalledOff(): void {
		$this->moves->start('alice', 'alice.bsky.social', 'pw');
		$this->duringCopy = function (): void {
			$this->duringCopy = null;
			$this->afterGet = function (): void {
				$this->afterGet = null;
				$this->rows[1]->state = Move::CANCELLED;
			};
			throw new \RuntimeException('disk full');
		};
		$this->repositories->expects($this->once())->method('delete')->with(self::DID);

		$this->moves->run($this->rows[1]);

		$this->assertSame(Move::CANCELLED, $this->rows[1]->state);
	}

	/** A move the job takes on to the identity while the person calls it off is not called off: the DID may move. */
	public function testAMoveTheJobTakesToTheIdentityMeanwhileIsNotCalledOff(): void {
		$this->rows[1] = new Move(1, self::DID, 'alice', Move::IN, self::OLD, '', 'alice.bsky.social', Move::STEP_CODE, Move::RUNNING);
		$this->afterRead = function (): void {
			$this->afterRead = null;
			$this->rows[1]->step = Move::STEP_IDENTITY;
		};
		$this->repositories->expects($this->never())->method('delete');

		try {
			$this->moves->cancel('alice');
			$this->fail('called off');
		} catch (MoveErrorException $e) {
			$this->assertSame('move-past-switch', $e->errorCode);
		}
		$this->assertSame([Move::RUNNING, Move::STEP_IDENTITY], [$this->rows[1]->state, $this->rows[1]->step]);
	}

	/** A waiting move can get a new code, and says where the old server sent it. */
	public function testAWaitingMoveGetsANewCode(): void {
		$this->moves->start('alice', 'alice.bsky.social', 'pw');
		$this->answers['com.atproto.server.getSession'] = [
			['status' => 200, 'body' => ['did' => self::DID, 'email' => 'alice@example.org']],
			['status' => 200, 'body' => ['did' => self::DID]],
		];
		$this->moves->run($this->rows[1]);
		$this->assertSame('alice@example.org', $this->rows[1]->progress['code_sent_to']);

		$sent = $this->moves->newCode('alice');

		$this->assertSame(2, count(array_keys($this->methods(), 'com.atproto.identity.requestPlcOperationSignature', true)));
		$this->assertSame('', $sent['sent_to'], 'an old server that does not say');
		$this->assertSame([Move::WAITING, Move::STEP_CODE], [$sent['move']->state, $sent['move']->step]);

		$this->rows[1]->state = Move::RUNNING;
		$this->expectException(MoveErrorException::class);
		$this->moves->newCode('alice');
	}

	/** A download as PdsClient hands it over: in a temporary file. */
	private static function downloaded(string $bytes): array {
		$path = (string)tempnam(sys_get_temp_dir(), 'social-test-');
		file_put_contents($path, $bytes);

		return ['status' => 200, 'path' => $path, 'size' => strlen($bytes), 'type' => 'application/octet-stream'];
	}

	/** A repository and its blobs are downloaded with the caps the server keeps, and the files go afterwards. */
	public function testDownloadsAreCappedAndCleanedUp(): void {
		$before = glob(sys_get_temp_dir() . '/social-test-*') ?: [];
		$this->moves->start('alice', 'alice.bsky.social', 'pw');
		$this->moves->run($this->rows[1]);

		$this->assertSame(['com.atproto.sync.getRepo' => RepoArchive::MAX_BYTES, 'com.atproto.sync.getBlob' => ClientXrpc::MAX_UPLOAD], $this->downloaded);
		$this->assertSame(64 * 1024 * 1024, RepoArchive::MAX_BYTES);
		$this->assertSame($before, glob(sys_get_temp_dir() . '/social-test-*') ?: [], 'no download is left behind');
	}

	/** A post written on the old server while the move waited for the code comes along. */
	public function testPostsWrittenDuringTheMoveAreCopiedBeforeTheSignature(): void {
		$imported = [];
		$this->repositories->method('import')->willReturnCallback(function (string $did, PrivateKey $key, array $records) use (&$imported): Cid {
			$imported[] = count($records);

			return Cid::forRaw('a commit');
		});
		$this->identities->method('adopt')->willReturn($this->current);
		$this->moves->start('alice', 'alice.bsky.social', 'pw');
		$this->moves->run($this->rows[1]);
		$this->assertSame($this->oldRev, $this->rows[1]->progress['rev']);

		$this->car = $this->oldRepository(['from Bluesky', 'written while the move waited']);
		$this->answers['com.atproto.sync.getLatestCommit'] = [['status' => 200, 'body' => ['cid' => 'bafy', 'rev' => $this->oldRev]]];
		$this->repositories->expects($this->once())->method('delete')->with(self::DID);
		$this->moves->code('alice', '12345');
		$this->moves->run($this->rows[1]);

		$this->assertSame([1, 2], $imported, 'copied again, with the new post');
		$this->assertSame($this->oldRev, $this->rows[1]->progress['rev']);
		$methods = $this->methods();
		$this->assertLessThan(array_search('com.atproto.identity.signPlcOperation', $methods, true), array_search('com.atproto.sync.getLatestCommit', $methods, true), 'before the signature');
		$this->assertSame(Move::DONE, $this->rows[1]->state);
	}

	/** What the move takes off Bluesky is counted before the identity here is retired. */
	public function testThePostsOnBlueskyAreRememberedBeforeTheDidMoves(): void {
		$order = [];
		$this->republisher->expects($this->once())->method('remember')->with($this->anything(), $this->current)->willReturnCallback(function () use (&$order): void {
			$order[] = 'remember';
		});
		$this->identities->method('adopt')->willReturnCallback(function () use (&$order): Identity {
			$order[] = 'adopt';

			return $this->current;
		});
		$this->moves->start('alice', 'alice.bsky.social', 'pw');
		$this->moves->run($this->rows[1]);
		$this->moves->code('alice', '12345');
		$this->moves->run($this->rows[1]);

		$this->assertSame(['remember', 'adopt'], $order);
	}

	public function testAnUnchangedRepositoryIsNotCopiedAgain(): void {
		$this->identities->method('adopt')->willReturn($this->current);
		$this->moves->start('alice', 'alice.bsky.social', 'pw');
		$this->moves->run($this->rows[1]);
		$this->answers['com.atproto.sync.getLatestCommit'] = [['status' => 200, 'body' => ['cid' => 'bafy', 'rev' => $this->oldRev]]];
		$this->repositories->expects($this->never())->method('delete');

		$this->moves->code('alice', '12345');
		$this->moves->run($this->rows[1]);

		$this->assertSame(Move::DONE, $this->rows[1]->state);
	}

	/** A post here that names a picture which did not arrive. */
	private function aPostNamesAMissingPicture(): void {
		$this->postNames(Cid::forRaw('elsewhere'));
	}

	/** Before the code is asked for, a move with pictures missing waits for the person to decide. */
	public function testMissingPicturesStopTheMoveBeforeTheCode(): void {
		$this->aPostNamesAMissingPicture();
		$this->moves->start('alice', 'alice.bsky.social', 'pw');

		$this->moves->run($this->rows[1]);

		$this->assertSame([Move::NEEDS_DECISION, Move::STEP_CODE, 'blobs-missing'], [$this->rows[1]->state, $this->rows[1]->step, $this->rows[1]->progress['code']]);
		$this->assertSame(['count' => 1, 'cids' => [Cid::forRaw('elsewhere')->toString()]], $this->rows[1]->progress['missing']);
		$this->assertSame([[
			'collection' => 'app.bsky.feed.post',
			'rkey' => '3kpost',
			'created_at' => '2026-01-01T00:00:00.000Z',
			'text' => 'look',
			'url' => 'https://bsky.app/profile/' . self::DID . '/post/3kpost',
		]], $this->rows[1]->progress['missing_records'], 'the post that loses it is named');
		$this->assertNotContains('com.atproto.identity.requestPlcOperationSignature', $this->methods(), 'no code asked for');
		$this->assertTrue($this->rows[1]->isUnderWay(), 'and no other move starts meanwhile');
	}

	public function testThePersonGoesOnWithoutTheMissingPictures(): void {
		$this->aPostNamesAMissingPicture();
		$this->moves->start('alice', 'alice.bsky.social', 'pw');
		$this->moves->run($this->rows[1]);

		$this->moves->decide($this->rows[1], true);
		$this->moves->run($this->rows[1]);

		$this->assertSame([Move::WAITING, Move::STEP_CODE], [$this->rows[1]->state, $this->rows[1]->step]);
		$this->assertArrayNotHasKey('code', $this->rows[1]->progress);
		$this->assertContains('com.atproto.identity.requestPlcOperationSignature', $this->methods());
	}

	public function testThePersonHasTheMissingPicturesCopiedAgain(): void {
		$this->aPostNamesAMissingPicture();
		$this->moves->start('alice', 'alice.bsky.social', 'pw');
		$this->moves->run($this->rows[1]);
		$this->elsewhereArrives = true;

		$this->moves->decide($this->rows[1], false);
		$this->assertSame([Move::RUNNING, Move::STEP_BLOBS], [$this->rows[1]->state, $this->rows[1]->step]);
		$this->moves->run($this->rows[1]);

		$this->assertSame([Move::WAITING, Move::STEP_CODE], [$this->rows[1]->state, $this->rows[1]->step], $this->rows[1]->error);
		$this->assertArrayNotHasKey('missing', $this->rows[1]->progress);
		$this->assertArrayNotHasKey('missing_records', $this->rows[1]->progress);

		$this->expectException(MoveErrorException::class);
		$this->moves->decide($this->rows[1], true);
	}

	/** the did:key of the signing key the move made for the account here */
	private function keyHere(): string {
		$session = json_decode((string)base64_decode(substr($this->rows[1]->session, 7)), true);

		return (new PrivateKey(Curve::K256, (string)hex2bin($session['key'])))->didKey();
	}

	public function testTheFollowsComeAfterTheIdentity(): void {
		$this->assertSame(
			[Move::STEP_BLOBS, Move::STEP_PREFERENCES, Move::STEP_CODE, Move::STEP_IDENTITY, Move::STEP_ACTIVATE, Move::STEP_FOLLOWS, Move::STEP_POSTS, Move::STEP_DONE],
			array_map(Move::nextIn(...), [Move::STEP_REPO, Move::STEP_BLOBS, Move::STEP_PREFERENCES, Move::STEP_CODE, Move::STEP_IDENTITY, Move::STEP_ACTIVATE, Move::STEP_FOLLOWS, Move::STEP_POSTS]),
		);
	}

	/** A move that stopped at the follows when they came before the code asks for the code. */
	public function testAMoveStoppedAtTheFollowsBeforeTheCodeAsksForIt(): void {
		$this->records = [];
		$this->moves->start('alice', 'alice.bsky.social', 'pw');
		$this->rows[1]->step = Move::STEP_FOLLOWS;
		$this->follows->expects($this->never())->method('adoptBlueskyFollow');

		$this->moves->run($this->rows[1]);

		$this->assertSame([Move::STEP_CODE, Move::WAITING], [$this->rows[1]->step, $this->rows[1]->state]);
	}

	/** The posts are written to the move page by page, so a long import is not taken for one that stopped. */
	public function testThePostsAreWrittenAsTheyGo(): void {
		$this->rows[1] = new Move(1, self::DID, 'alice', Move::IN, self::OLD, '', 'alice.bsky.social', Move::STEP_POSTS, Move::RUNNING);
		$written = [];
		$page = function (?\Closure $onPage) use (&$written): void {
			$this->rows = [];
			$this->assertNotNull($onPage);
			$onPage();
			$written[] = isset($this->rows[1]);
		};
		$this->history->method('import')->willReturnCallback(function (Person $actor, string $did, ?\Closure $onPage = null) use ($page): int {
			$page($onPage);

			return 0;
		});
		$this->history->method('importActions')->willReturnCallback(function (Person $actor, string $did, ?\Closure $onPage = null) use ($page): array {
			$page($onPage);

			return ['likes' => 0, 'reposts' => 0];
		});

		$this->moves->importPosts(clone $this->rows[1]);

		$this->assertSame([true, true], $written);
	}

	public function testAMoveIsDoneEvenWhenItsPostsCannotBeImported(): void {
		$this->rows[1] = new Move(1, self::DID, 'alice', Move::IN, self::OLD, '', 'alice.bsky.social', Move::STEP_POSTS, Move::RUNNING);
		$this->history->method('import')->willThrowException(new \RuntimeException('no room'));
		$this->lists->expects($this->once())->method('adopt')->willThrowException(new \RuntimeException('no room'));

		$this->moves->run($this->rows[1]);

		$this->assertSame(Move::DONE, $this->rows[1]->state);
	}

	public function testAnOperationTheOldServerChangedIsNotTaken(): void {
		$this->moves->start('alice', 'alice.bsky.social', 'pw');
		$this->moves->run($this->rows[1]);
		$this->answers['com.atproto.identity.signPlcOperation'] = [['status' => 200, 'body' => ['operation' => ['services' => ['atproto_pds' => ['endpoint' => 'https://evil.example.org']]]]]];
		$this->identities->expects($this->never())->method('adopt');

		$this->moves->code('alice', '12345');
		$this->moves->run($this->rows[1]);
		$this->assertSame(Move::FAILED, $this->rows[1]->state);
		$this->assertSame('operation-mismatch', $this->rows[1]->progress['code']);

		$this->moves->retry($this->rows[1]);
		$this->assertSame(Move::STEP_CODE, $this->rows[1]->step, 'a new code is asked for');
	}

	/** Only the blobs the copied records name are asked for; the old server's list is not read. */
	public function testOnlyTheBlobsTheRecordsNameAreCopied(): void {
		$this->moves->start('alice', 'alice.bsky.social', 'pw');

		$this->moves->run($this->rows[1]);

		$this->assertNotContains('com.atproto.sync.listBlobs', $this->methods());
		$this->assertSame(1, count(array_keys($this->methods(), 'com.atproto.sync.getBlob', true)));
		$this->assertSame(strlen($this->blob), $this->rows[1]->progress['blob_bytes']);
		$this->assertSame(Move::WAITING, $this->rows[1]->state);
	}

	/** A move that downloaded its budget of blobs copies no more; the rest is missing, for the person to decide on. */
	public function testTheBlobsStopAtTheMovesBudget(): void {
		$this->moves->start('alice', 'alice.bsky.social', 'pw');
		$this->rows[1]->progress['blob_bytes'] = MovedBlobs::BUDGET;

		$this->moves->run($this->rows[1]);

		$this->assertNotContains('com.atproto.sync.getBlob', $this->methods());
		$this->assertSame([Move::NEEDS_DECISION, 'blobs-missing'], [$this->rows[1]->state, $this->rows[1]->progress['code']]);
	}

	/** The follows are written to the move page by page, so a long run is not taken for one that stopped. */
	public function testTheFollowsAreWrittenAsTheyGo(): void {
		$this->moves->start('alice', 'alice.bsky.social', 'pw');
		$this->follows->method('adoptBlueskyFollow')->willReturn(true);

		$this->moves->adoptFollows(clone $this->rows[1]);

		$this->assertSame(1, $this->rows[1]->progress['follows'] ?? null);
	}

	/**
	 * A move whose job stopped after the directory took the operation:
	 * the account takes the DID as the directory names it, and the old
	 * server is not asked to sign again.
	 */
	public function testADidTheDirectoryPointsHereAlreadyIsTaken(): void {
		$this->moves->start('alice', 'alice.bsky.social', 'pw');
		$this->moves->run($this->rows[1]);
		$this->moves->code('alice', '12345');
		$this->pointsAt = self::HERE;
		$this->signingKeyListed = $this->keyHere();
		$this->identities->expects($this->never())->method('adopt');
		$this->identities->expects($this->once())->method('receive')->with($this->current, self::DID, $this->isInstanceOf(PrivateKey::class), self::OLD)->willReturn($this->current);

		$this->moves->run($this->rows[1]);

		$this->assertNotContains('com.atproto.identity.signPlcOperation', $this->methods());
		$this->assertSame(Move::DONE, $this->rows[1]->state, $this->rows[1]->error);
	}

	/** "Try again" on a move that failed once the directory pointed the DID here goes on without a new code. */
	public function testARetryAfterTheSwitchAsksForNoNewCode(): void {
		$this->moves->start('alice', 'alice.bsky.social', 'pw');
		$this->rows[1]->step = Move::STEP_IDENTITY;
		$this->rows[1]->state = Move::FAILED;
		$this->pointsAt = self::HERE;
		$this->signingKeyListed = $this->keyHere();

		$this->moves->retry($this->rows[1]);

		$this->assertSame([Move::STEP_IDENTITY, Move::RUNNING], [$this->rows[1]->step, $this->rows[1]->state]);
	}
}
