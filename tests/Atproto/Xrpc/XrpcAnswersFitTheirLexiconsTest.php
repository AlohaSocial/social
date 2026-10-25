<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Xrpc;

use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Firehose\EventService;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\PlcOperation;
use OCA\Social\Atproto\Lexicon\Lexicon;
use OCA\Social\Atproto\Lexicon\LexiconException;
use OCA\Social\Atproto\Model\BlobRef;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Protocol\Car;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\Commit;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Protocol\MstReader;
use OCA\Social\Atproto\Publisher\PictureService;
use OCA\Social\Atproto\Publisher\VideoBlobService;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Repository\RepoWrite;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Atproto\Xrpc\XrpcBytes;
use OCA\Social\Atproto\Xrpc\XrpcException;
use OCA\Social\Atproto\Xrpc\XrpcService;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoEventRequest;
use OCA\Social\Db\AtprotoIdentityRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\ModerationService;
use OCA\Social\Tests\Atproto\Repository\InMemoryRepoRequest;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the public XRPC surface answers, checked against the `output`
 * schema of each method's lexicon as Bluesky publishes it
 * (tests/Atproto/lexicons, pinned by VendoredFilesTest): the shape a relay,
 * an AppView or another PDS parses. The repository behind the answers is a
 * real one, written through RepositoryService, so CIDs, revisions and
 * record values are the ones a commit makes, not placeholders.
 *
 * The errors are checked too: an error is either one of XRPC's generic
 * names or one the method's lexicon declares.
 */
#[AllowMockObjectsWithoutExpectations]
class XrpcAnswersFitTheirLexiconsTest extends TestCase {
	private const ALICE = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const BOB = 'did:plc:z72i7hdynmk6r22z27h6tvur';
	private const DAVE = 'did:plc:vwzwgnygau7ed7b7wt5ux7y2';
	private const GENERIC_ERRORS = ['InvalidRequest', 'AuthenticationRequired', 'MethodNotImplemented', 'InternalServerError', 'UpgradeRequired'];

	private static ?string $outputs = null;

	private InMemoryRepoRequest $store;
	private RepositoryService $repositories;
	private XrpcService $xrpc;
	private Lexicon $lexicon;
	/** @var array<string, Identity> */
	private array $identities = [];
	/** @var array<string, BlobRef> */
	private array $blobs = [];

	public static function setUpBeforeClass(): void {
		self::$outputs = self::outputDocuments();
	}

	public static function tearDownAfterClass(): void {
		if (self::$outputs !== null) {
			array_map('unlink', glob(self::$outputs . '/*.json') ?: []);
			@rmdir(self::$outputs);
		}
	}

	protected function setUp(): void {
		$this->lexicon = new Lexicon([(string)self::$outputs, __DIR__ . '/../lexicons', __DIR__ . '/../../../lib/Atproto/lexicons']);
		$this->store = new InMemoryRepoRequest();
		$events = $this->createMock(AtprotoEventRequest::class);
		$events->method('append')->willReturn(1);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1760000000);
		$this->repositories = new RepositoryService($this->store, new EventService($events, $time), new Lexicon(), $this->createMock(IDBConnection::class));

		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$config->method('serviceDid')->willReturn('did:web:social.example.com');
		$identities = $this->createMock(IdentityService::class);
		$identities->method('getByDid')->willReturnCallback(fn (string $did): Identity => $this->identities[$did] ?? throw new AtprotoIdentityNotFoundException());
		$identities->method('getByHandle')->willReturnCallback(function (string $handle): Identity {
			foreach ($this->identities as $identity) {
				if ($identity->handle === $handle) {
					return $identity;
				}
			}
			throw new AtprotoIdentityNotFoundException();
		});
		$identities->method('document')->willReturnCallback(static fn (Identity $identity): array => PlcOperation::document(
			$identity->did,
			PlcOperation::build([PrivateKey::generate(Curve::K256)->publicKey()->didKey()], $identity->signingPublic, $identity->handle, 'https://social.example.com', null),
		));
		$identityRequest = $this->createMock(AtprotoIdentityRequest::class);
		$identityRequest->method('getByDids')->willReturnCallback(fn (array $dids): array => array_intersect_key($this->identities, array_flip($dids)));
		$blobRequest = $this->createMock(AtprotoBlobRequest::class);
		$blobRequest->method('list')->willReturnCallback(fn (string $did, int $limit, string $cursor): array => array_slice(
			array_values(array_filter($this->blobs, static fn (BlobRef $blob): bool => $blob->did === $did && strcmp($blob->cid->toString(), $cursor) > 0)),
			0,
			$limit,
		));
		$blobRequest->method('get')->willReturnCallback(fn (string $did, string $cid): ?BlobRef => $this->blobs[$cid] ?? null);
		$pictures = $this->createMock(PictureService::class);
		$pictures->method('read')->willReturn('pixels');
		$configService = $this->createMock(ConfigService::class);
		$configService->method('getAppValue')->willReturn('0.26.180');
		$configService->method('getCloudUrl')->willReturn('https://social.example.com');

		$this->xrpc = new XrpcService($config, $identities, $this->repositories, $this->store, $blobRequest, $pictures, $this->createMock(VideoBlobService::class), $configService, $this->createMock(ModerationService::class), $identityRequest);

		$this->identities[self::ALICE] = $this->identity(self::ALICE, 'alice.social.example.com', Identity::STATE_ACTIVE);
		$this->identities[self::BOB] = $this->identity(self::BOB, 'bob.social.example.com', Identity::STATE_DEACTIVATED);
		$this->identities[self::DAVE] = $this->identity(self::DAVE, 'dave.social.example.com', Identity::STATE_MOVED_AWAY);
		$key = PrivateKey::generate(Curve::K256);
		$this->repositories->write(self::ALICE, $key, [
			RepoWrite::create('app.bsky.actor.profile', ['$type' => 'app.bsky.actor.profile', 'displayName' => 'Alice', 'createdAt' => '2026-10-08T10:00:00.000Z'], '', 'self'),
			RepoWrite::create('app.bsky.feed.post', self::post('one'), 'https://social.example.com/@alice/1', '3kznmn7xqxl22'),
			RepoWrite::create('app.bsky.feed.post', self::post('two'), 'https://social.example.com/@alice/2', '3kznmn7xqxl23'),
			RepoWrite::create('app.bsky.feed.post', self::post('three'), 'https://social.example.com/@alice/3', '3kznmn7xqxl24'),
		]);
		$this->repositories->write(self::BOB, $key, [RepoWrite::create('app.bsky.feed.post', self::post('bob'), '', '3kznmn7xqxl25')]);
		foreach (['a', 'b', 'c'] as $bytes) {
			$cid = Cid::forRaw($bytes);
			$this->blobs[$cid->toString()] = new BlobRef(self::ALICE, $cid, 'https://social.example.com/doc/' . $bytes, 'image/png', 1);
		}
	}

	public static function answers(): array {
		$alice = ['did' => self::ALICE];
		$posts = ['repo' => self::ALICE, 'collection' => 'app.bsky.feed.post'];

		return [
			'describeServer' => ['com.atproto.server.describeServer', []],
			'resolveHandle' => ['com.atproto.identity.resolveHandle', ['handle' => 'alice.social.example.com']],
			'resolveDid' => ['com.atproto.identity.resolveDid', ['did' => self::ALICE]],
			'resolveIdentity' => ['com.atproto.identity.resolveIdentity', ['identifier' => self::ALICE]],
			'getLatestCommit' => ['com.atproto.sync.getLatestCommit', $alice],
			'getRepoStatus, active' => ['com.atproto.sync.getRepoStatus', $alice],
			'getRepoStatus, deactivated' => ['com.atproto.sync.getRepoStatus', ['did' => self::BOB]],
			'getRepoStatus, moved away' => ['com.atproto.sync.getRepoStatus', ['did' => self::DAVE]],
			'listBlobs' => ['com.atproto.sync.listBlobs', $alice],
			'listBlobs, a page' => ['com.atproto.sync.listBlobs', $alice + ['limit' => '2']],
			'listRepos' => ['com.atproto.sync.listRepos', []],
			'listRepos, a page' => ['com.atproto.sync.listRepos', ['limit' => '1']],
			'describeRepo' => ['com.atproto.repo.describeRepo', ['repo' => self::ALICE]],
			'describeRepo by handle' => ['com.atproto.repo.describeRepo', ['repo' => 'alice.social.example.com']],
			'getRecord' => ['com.atproto.repo.getRecord', $posts + ['rkey' => '3kznmn7xqxl22']],
			'getRecord, the profile' => ['com.atproto.repo.getRecord', ['repo' => self::ALICE, 'collection' => 'app.bsky.actor.profile', 'rkey' => 'self']],
			'listRecords' => ['com.atproto.repo.listRecords', $posts],
			'listRecords, a page' => ['com.atproto.repo.listRecords', $posts + ['limit' => '2']],
			'listRecords, reversed' => ['com.atproto.repo.listRecords', $posts + ['reverse' => 'true']],
			'listRecords, an empty collection' => ['com.atproto.repo.listRecords', ['repo' => self::ALICE, 'collection' => 'app.bsky.graph.follow']],
		];
	}

	/**
	 * @param array<string, string> $params
	 */
	#[DataProvider('answers')]
	public function testTheAnswerFitsTheOutputSchema(string $method, array $params): void {
		$answer = $this->xrpc->query($method, $params);
		$this->assertIsArray($answer);

		$this->assertFits($method, $answer);
	}

	public function testAPagedListingAnswersACursorThatContinuesIt(): void {
		$first = $this->xrpc->query('com.atproto.repo.listRecords', ['repo' => self::ALICE, 'collection' => 'app.bsky.feed.post', 'limit' => '2']);
		$second = $this->xrpc->query('com.atproto.repo.listRecords', ['repo' => self::ALICE, 'collection' => 'app.bsky.feed.post', 'limit' => '2', 'cursor' => $first['cursor']]);

		$uris = array_column([...$first['records'], ...$second['records']], 'uri');
		$this->assertCount(3, $uris);
		$this->assertCount(3, array_unique($uris), 'no record twice across the pages');
		$this->assertArrayNotHasKey('cursor', $second, 'the last page says there is no more');

		$repos = $this->xrpc->query('com.atproto.sync.listRepos', ['limit' => '1']);
		$rest = $this->xrpc->query('com.atproto.sync.listRepos', ['limit' => '1', 'cursor' => $repos['cursor']]);
		$this->assertNotSame($repos['repos'][0]['did'], $rest['repos'][0]['did']);
	}

	/**
	 * `getLatestCommit` names the commit the CAR of `getRepo` has as its
	 * root, and the revision in it; the CAR holds a signed v3 commit whose
	 * tree has every record `listRecords` lists.
	 */
	public function testGetRepoIsTheCarOfTheCommitGetLatestCommitNames(): void {
		$latest = $this->xrpc->query('com.atproto.sync.getLatestCommit', ['did' => self::ALICE]);
		$car = $this->xrpc->query('com.atproto.sync.getRepo', ['did' => self::ALICE]);
		$this->assertInstanceOf(XrpcBytes::class, $car);
		$this->assertSame('application/vnd.ipld.car', $car->contentType);

		$decoded = Car::decode(implode('', iterator_to_array($car->pieces ?? [], false)));
		$this->assertCount(1, $decoded['roots']);
		$this->assertSame($latest['cid'], $decoded['roots'][0]->toString());
		$commit = Commit::fromBytes($decoded['blocks'][$latest['cid']]);
		$this->assertSame($latest['rev'], $commit->rev);
		$this->assertSame(self::ALICE, $commit->did);

		$leaves = MstReader::leaves($commit->data, $decoded['blocks']);
		$records = $this->xrpc->query('com.atproto.repo.listRecords', ['repo' => self::ALICE, 'collection' => 'app.bsky.feed.post']);
		foreach ($records['records'] as $record) {
			$path = substr($record['uri'], strlen('at://' . self::ALICE . '/'));
			$this->assertSame($record['cid'], $leaves[$path]->toString());
			$this->assertSame($record['cid'], Cid::forDagCbor($decoded['blocks'][$record['cid']])->toString(), 'the record block is in the CAR');
		}
	}

	/**
	 * `sync.getRecord` is a proof: from the commit at its root, through the
	 * tree's nodes it carries, to the record's CID, and the record's block.
	 */
	public function testSyncGetRecordIsAProofFromTheCommitToTheRecord(): void {
		$car = $this->xrpc->query('com.atproto.sync.getRecord', ['did' => self::ALICE, 'collection' => 'app.bsky.feed.post', 'rkey' => '3kznmn7xqxl23']);
		$this->assertInstanceOf(XrpcBytes::class, $car);

		$decoded = Car::decode($car->bytes);
		$commit = Commit::fromBytes($decoded['blocks'][$decoded['roots'][0]->toString()]);
		$leaves = MstReader::leaves($commit->data, $decoded['blocks']);
		$cid = $leaves['app.bsky.feed.post/3kznmn7xqxl23']->toString();
		$this->assertEquals(self::post('two'), DagCbor::decode($decoded['blocks'][$cid]), 'the same fields, in DAG-CBOR key order');
	}

	/**
	 * `getBlocks` is the blocks asked for and nothing else — a tree node, the
	 * commit, a record — as a CAR with no roots.
	 */
	public function testGetBlocksIsTheBlocksAskedForWithNoRoots(): void {
		$head = $this->repositories->getHead(self::ALICE);
		$this->assertNotNull($head);
		$record = $this->repositories->getRecord(self::ALICE, 'app.bsky.feed.post', '3kznmn7xqxl23');
		$this->assertNotNull($record);
		$node = $this->store->getBlockCids(self::ALICE, AtprotoRepoRequest::BLOCK_MST)[0];

		$car = $this->xrpc->query('com.atproto.sync.getBlocks', ['did' => self::ALICE, 'cids' => [$node, $head->commitCid, $record->cid->toString()]]);
		$this->assertInstanceOf(XrpcBytes::class, $car);
		$this->assertSame('application/vnd.ipld.car', $car->contentType);

		$decoded = Car::decode($car->bytes);
		$this->assertSame([], $decoded['roots']);
		$this->assertEqualsCanonicalizing([$node, $head->commitCid, $record->cid->toString()], array_keys($decoded['blocks']));
		$this->assertSame($record->bytes, $decoded['blocks'][$record->cid->toString()]);
		$this->assertSame($head->rev, Commit::fromBytes($decoded['blocks'][$head->commitCid])->rev);

		$one = $this->xrpc->query('com.atproto.sync.getBlocks', ['did' => self::ALICE, 'cids' => $node]);
		$this->assertInstanceOf(XrpcBytes::class, $one);
		$this->assertSame([$node], array_keys(Car::decode($one->bytes)['blocks']), 'one CID, given as a string');
	}

	/**
	 * A record of another application with an empty object in it, as an
	 * account moving here brings it: served with the object an object,
	 * not turned into a list, and with the CID of the bytes as they were.
	 */
	public function testAnEmptyObjectInARecordIsServedAsAnObject(): void {
		$carol = 'did:plc:oc6vwdlmk2kqyida5i74d3p5';
		$this->identities[$carol] = $this->identity($carol, 'carol.social.example.com', Identity::STATE_ACTIVE);
		$bytes = DagCbor::encode(['$type' => 'com.example.thing', 'settings' => new \stdClass(), 'tags' => []]);
		$this->repositories->import($carol, PrivateKey::generate(Curve::K256), ['com.example.thing/3kznmn7xqxl22' => $bytes]);

		$answer = $this->xrpc->query('com.atproto.repo.getRecord', ['repo' => $carol, 'collection' => 'com.example.thing', 'rkey' => '3kznmn7xqxl22']);

		$this->assertSame('{"tags":[],"$type":"com.example.thing","settings":{}}', json_encode($answer['value']));
		$this->assertSame(Cid::forDagCbor($bytes)->toString(), $answer['cid']);
		$this->assertFits('com.atproto.repo.getRecord', $answer);
		$this->lexicon->validate(new \stdClass(), 'app.bsky.feed.threadgate#followerRule');
	}

	public static function refusals(): array {
		return [
			'getLatestCommit of an unknown DID' => ['com.atproto.sync.getLatestCommit', ['did' => 'did:plc:aaaaaaaaaaaaaaaaaaaaaaaa'], 404],
			'getRepo of an unknown DID' => ['com.atproto.sync.getRepo', ['did' => 'did:plc:aaaaaaaaaaaaaaaaaaaaaaaa'], 404],
			'getRepoStatus of an unknown DID' => ['com.atproto.sync.getRepoStatus', ['did' => 'did:plc:aaaaaaaaaaaaaaaaaaaaaaaa'], 404],
			'listBlobs of an unknown DID' => ['com.atproto.sync.listBlobs', ['did' => 'did:plc:aaaaaaaaaaaaaaaaaaaaaaaa'], 404],
			'getBlob that is not there' => ['com.atproto.sync.getBlob', ['did' => self::ALICE, 'cid' => 'bafkreibme22gw2h7y2h7tg2fhqotaqjucnbc24deqo72b6mkl2egezxhvy'], 404],
			'getBlob of something not a CID' => ['com.atproto.sync.getBlob', ['did' => self::ALICE, 'cid' => 'nope'], 400],
			'getBlocks with one the repository does not have' => ['com.atproto.sync.getBlocks', ['did' => self::ALICE, 'cids' => ['bafyreie5737gdxlw5i64vzichcalba3z2v5n6icifvx5xytvske7mr3hpm']], 404],
			'getBlocks of something not a CID' => ['com.atproto.sync.getBlocks', ['did' => self::ALICE, 'cids' => ['nope']], 400],
			'getBlocks without cids' => ['com.atproto.sync.getBlocks', ['did' => self::ALICE], 400],
			'getBlocks of a deactivated account' => ['com.atproto.sync.getBlocks', ['did' => self::BOB, 'cids' => ['bafyreie5737gdxlw5i64vzichcalba3z2v5n6icifvx5xytvske7mr3hpm']], 400],
			'getRecord that is not there' => ['com.atproto.repo.getRecord', ['repo' => self::ALICE, 'collection' => 'app.bsky.feed.post', 'rkey' => '3kznmn7xqxl2z'], 404],
			'sync.getRecord that is not there' => ['com.atproto.sync.getRecord', ['did' => self::ALICE, 'collection' => 'app.bsky.feed.post', 'rkey' => '3kznmn7xqxl2z'], 404],
			'getRecord of a path that is not one' => ['com.atproto.repo.getRecord', ['repo' => self::ALICE, 'collection' => 'app.bsky.feed.post', 'rkey' => '../..'], 400],
			'listRecords of something not a collection' => ['com.atproto.repo.listRecords', ['repo' => self::ALICE, 'collection' => 'not a collection'], 400],
			'resolveHandle without a handle' => ['com.atproto.identity.resolveHandle', [], 400],
			'resolveHandle of a handle not here' => ['com.atproto.identity.resolveHandle', ['handle' => 'carol.social.example.com'], 400],
			'getRepo of a deactivated account' => ['com.atproto.sync.getRepo', ['did' => self::BOB], 400],
			'getLatestCommit of a deactivated account' => ['com.atproto.sync.getLatestCommit', ['did' => self::BOB], 400],
			'sync.getRecord of a deactivated account' => ['com.atproto.sync.getRecord', ['did' => self::BOB, 'collection' => 'app.bsky.feed.post', 'rkey' => '3kznmn7xqxl25'], 400],
			'getBlob of an account that moved away' => ['com.atproto.sync.getBlob', ['did' => self::DAVE, 'cid' => 'bafkreibme22gw2h7y2h7tg2fhqotaqjucnbc24deqo72b6mkl2egezxhvy'], 400],
			'listBlobs of an account that moved away' => ['com.atproto.sync.listBlobs', ['did' => self::DAVE], 400],
			'resolveHandle of a deactivated account' => ['com.atproto.identity.resolveHandle', ['handle' => 'bob.social.example.com'], 400],
		];
	}

	/**
	 * @param array<string, string> $params
	 */
	#[DataProvider('refusals')]
	public function testARefusalIsAnErrorTheMethodMayAnswer(string $method, array $params, int $status): void {
		try {
			$this->xrpc->query($method, $params);
			$this->fail($method . ' answered');
		} catch (XrpcException $e) {
			$this->assertSame($status, $e->status);
			$declared = array_column(self::document($method)['defs']['main']['errors'] ?? [], 'name');
			$this->assertContains($e->error, [...self::GENERIC_ERRORS, ...$declared]);
			$this->assertSame(['error', 'message'], array_keys($e->toArray()));
		}
	}

	/**
	 * The validator these checks rest on refuses what the schemas refuse,
	 * so an answer passing above is a fit and not a validator that lets
	 * everything through.
	 */
	public function testAnAnswerMissingARequiredFieldOrWithAWrongFormatDoesNotFit(): void {
		$latest = $this->xrpc->query('com.atproto.sync.getLatestCommit', ['did' => self::ALICE]);
		$this->assertFits('com.atproto.sync.getLatestCommit', $latest);

		foreach ([
			'no rev' => ['cid' => $latest['cid']],
			'a placeholder CID' => ['cid' => 'bafy', 'rev' => $latest['rev']],
			'a revision that is not a TID' => ['cid' => $latest['cid'], 'rev' => '1'],
		] as $why => $answer) {
			try {
				$this->assertFits('com.atproto.sync.getLatestCommit', $answer);
				$this->fail($why . ' fitted');
			} catch (LexiconException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	private function assertFits(string $method, array $answer): void {
		$this->lexicon->validate(DagCbor::fromLexJson($answer), $method . '#output');
		$this->addToAssertionCount(1);
	}

	private function identity(string $did, string $handle, string $state): Identity {
		return new Identity(1, 'https://social.example.com/users/' . explode('.', $handle)[0], $did, $handle, '', PrivateKey::generate(Curve::K256)->publicKey()->didKey(), '', $state, '', 1760000000);
	}

	private static function post(string $text): array {
		return ['$type' => 'app.bsky.feed.post', 'text' => $text, 'createdAt' => '2026-10-08T10:00:00.000Z'];
	}

	private static function document(string $nsid): array {
		return json_decode((string)file_get_contents(__DIR__ . '/../lexicons/' . $nsid . '.json'), true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * Each query lexicon again, with its output schema as a definition of
	 * its own (`<nsid>#output`), so the record validator can check an
	 * answer the way it checks a record; relative references keep working
	 * because the definition stays in its own document.
	 */
	private static function outputDocuments(): string {
		$directory = sys_get_temp_dir() . '/social-xrpc-outputs-' . bin2hex(random_bytes(4));
		mkdir($directory);
		foreach (glob(__DIR__ . '/../lexicons/*.json') ?: [] as $path) {
			$document = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
			$schema = $document['defs']['main']['output']['schema'] ?? null;
			if (is_array($schema)) {
				$document['defs']['output'] = $schema;
				file_put_contents($directory . '/' . basename($path), json_encode($document));
			}
		}

		return $directory;
	}
}
