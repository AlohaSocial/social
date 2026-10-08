<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Move;

use InvalidArgumentException;
use OCA\Social\Atproto\AppView\ServiceAuth;
use OCA\Social\Atproto\Client\Preferences;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\BlobRef;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\Move;
use OCA\Social\Atproto\Move\MoveAwayService;
use OCA\Social\Atproto\Move\PdsClient;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\Encoding;
use OCA\Social\Atproto\Publisher\PictureService;
use OCA\Social\Atproto\Publisher\VideoBlobService;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Cron\AtprotoMove;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoMoveRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class MoveAwayServiceTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const PDS = 'https://pds.example.com';
	private const PDS_DID = 'did:web:pds.example.com';

	/** @var list<array{method: string, verb: string, query: array, json: ?array, bytes: mixed, token: string}> */
	private array $calls = [];
	/** @var array<string, list<array{status: int, body: array}>> what the other PDS answers, by method, in turn */
	private array $answers = [];
	/** @var array<int, Move> */
	private array $rows = [];
	private array $queued = [];
	private PrivateKey $key;
	private Identity $alice;
	/** @var IdentityService&MockObject */
	private IdentityService $identities;
	private MoveAwayService $moves;

	protected function setUp(): void {
		$this->key = PrivateKey::generate(Curve::K256);
		$this->alice = new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', '', '', '', Identity::STATE_ACTIVE, '', 0);
		$pds = $this->createMock(PdsClient::class);
		$pds->method('origin')->willReturnCallback(static fn (string $typed): string => 'https://' . preg_replace('#^https?://#', '', rtrim($typed, '/')));
		$pds->method('call')->willReturnCallback(function (string $origin, string $method, string $verb = 'GET', array $query = [], ?array $json = null, $bytes = null, string $type = '', string $token = ''): array {
			$this->calls[] = ['method' => $method, 'verb' => $verb, 'query' => $query, 'json' => $json, 'bytes' => $bytes, 'token' => $token];

			return $this->answer($method);
		});
		$pds->method('expect')->willReturnCallback(function (string $origin, string $method, string $verb = 'GET', array $query = [], ?array $json = null, $bytes = null, string $type = '', string $token = ''): array {
			$this->calls[] = ['method' => $method, 'verb' => $verb, 'query' => $query, 'json' => $json, 'bytes' => $bytes, 'token' => $token];
			$answer = $this->answer($method);
			if ($answer['status'] !== 200) {
				throw new AtprotoException($method . ' answered ' . $answer['status']);
			}

			return $answer['body'];
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1760000000);
		$this->identities = $this->createMock(IdentityService::class);
		$this->identities->method('signingKey')->willReturnCallback(fn (): PrivateKey => $this->key);
		$this->identities->method('getByDid')->willReturnCallback(fn (): Identity => $this->alice);
		$repositories = $this->createMock(RepositoryService::class);
		$repositories->method('exportCar')->with(self::DID)->willReturn('the car');
		$cid = Cid::forRaw('pixels');
		$blobs = $this->createMock(AtprotoBlobRequest::class);
		$blobs->method('get')->willReturnCallback(static fn (string $did, string $c): ?BlobRef => $c === $cid->toString() ? new BlobRef(self::DID, $cid, 'doc', 'image/jpeg', 6) : null);
		$pictures = $this->createMock(PictureService::class);
		$pictures->method('read')->willReturn('pixels');
		$preferences = $this->createMock(Preferences::class);
		$preferences->method('get')->willReturn(['preferences' => [['$type' => 'app.bsky.actor.defs#adultContentPref', 'enabled' => false]]]);
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
		$crypto->method('decrypt')->willReturnCallback(static fn (string $sealed): string => base64_decode(substr($sealed, 7)));
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('add')->willReturnCallback(function (string $job, mixed $argument): void {
			$this->queued[] = [$job, $argument];
		});
		$this->moves = new MoveAwayService(
			$pds, new ServiceAuth($time), $this->identities, $repositories, $blobs, $pictures, $this->createMock(VideoBlobService::class),
			$preferences, $request, $crypto, $jobs, new NullLogger(),
		);
		$this->answers['com.atproto.server.describeServer'] = [['status' => 200, 'body' => ['did' => self::PDS_DID, 'availableUserDomains' => ['.pds.example.com'], 'inviteCodeRequired' => false]]];
		$this->answers['com.atproto.server.createAccount'] = [['status' => 200, 'body' => ['did' => self::DID, 'handle' => 'alice.pds.example.com', 'accessJwt' => 'access-1', 'refreshJwt' => 'refresh-1']]];
	}

	/** The other PDS's next answer to a method: what the test queued, else an empty 200. */
	private function answer(string $method): array {
		$this->answers[$method] ??= [];

		return array_shift($this->answers[$method]) ?? ['status' => 200, 'body' => []];
	}

	private function call(string $method): array {
		foreach ($this->calls as $call) {
			if ($call['method'] === $method) {
				return $call;
			}
		}
		$this->fail($method . ' was not called');
	}

	public function testTheAccountIsMadeThereWithATokenTheAccountSigned(): void {
		$move = $this->moves->start('alice', $this->alice, 'pds.example.com', '@alice.pds.example.com', 'alice@example.org', 'secret');

		$create = $this->call('com.atproto.server.createAccount');
		$this->assertSame(['did' => self::DID, 'handle' => 'alice.pds.example.com', 'email' => 'alice@example.org', 'password' => 'secret'], $create['json']);
		[$header, $payload, $signature] = explode('.', $create['token']);
		$claims = json_decode(Encoding::base64UrlDecode($payload), true);
		$this->assertSame(['iss' => self::DID, 'aud' => self::PDS_DID, 'lxm' => 'com.atproto.server.createAccount'], array_intersect_key($claims, ['iss' => 1, 'aud' => 1, 'lxm' => 1]));
		$this->assertTrue($this->key->publicKey()->verify($header . '.' . $payload, Encoding::base64UrlDecode($signature)));

		$this->assertSame(self::PDS, $move->pds);
		$this->assertSame(Move::STEP_REPO, $move->step);
		$this->assertStringNotContainsString('access-1', $this->rows[1]->session, 'the other server\'s session is sealed');
		$this->assertSame([[AtprotoMove::class, ['move' => 1]]], $this->queued);
	}

	public function testWhatTheOtherServerWillNotTakeIsSaidAtOnce(): void {
		foreach ([
			['alice.elsewhere.org', '', 'gives out handles ending in .pds.example.com'],
		] as [$handle, $invite, $message]) {
			try {
				$this->moves->start('alice', $this->alice, 'pds.example.com', $handle, 'a@example.org', 'pw', $invite);
				$this->fail('started');
			} catch (InvalidArgumentException $e) {
				$this->assertStringContainsString($message, $e->getMessage());
			}
		}

		$this->answers['com.atproto.server.describeServer'] = [['status' => 200, 'body' => ['did' => self::PDS_DID, 'inviteCodeRequired' => true]]];
		try {
			$this->moves->start('alice', $this->alice, 'pds.example.com', 'alice.pds.example.com', 'a@example.org', 'pw');
			$this->fail('started without an invite');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString('invite code', $e->getMessage());
		}

		$this->answers['com.atproto.server.describeServer'] = [['status' => 200, 'body' => ['did' => self::PDS_DID]]];
		$this->answers['com.atproto.server.createAccount'] = [['status' => 400, 'body' => ['error' => 'HandleNotAvailable', 'message' => 'Handle already taken']]];
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Handle already taken');
		$this->moves->start('alice', $this->alice, 'pds.example.com', 'alice.pds.example.com', 'a@example.org', 'pw');
	}

	public function testTheMoveCopiesHandsOverActivatesAndSwitchesOffHere(): void {
		$move = $this->moves->start('alice', $this->alice, 'pds.example.com', 'alice.pds.example.com', 'alice@example.org', 'secret');
		$missing = Cid::forRaw('pixels')->toString();
		$this->answers['com.atproto.repo.listMissingBlobs'] = [
			['status' => 200, 'body' => ['blobs' => [['cid' => $missing, 'recordUri' => 'at://x']], 'cursor' => 'c1']],
			['status' => 200, 'body' => ['blobs' => [['cid' => Cid::forRaw('never here')->toString()]]]],
		];
		$credentials = ['rotationKeys' => ['did:key:zTheirs'], 'verificationMethods' => ['atproto' => 'did:key:zSigning'], 'alsoKnownAs' => ['at://alice.pds.example.com'], 'services' => ['atproto_pds' => ['type' => 'AtprotoPersonalDataServer', 'endpoint' => self::PDS]]];
		$this->answers['com.atproto.identity.getRecommendedDidCredentials'] = [['status' => 200, 'body' => $credentials]];
		$this->identities->expects($this->once())->method('handOver')->with($this->alice, $credentials);
		$this->identities->expects($this->once())->method('markMovedAway')->with($this->alice);

		$this->moves->run($move->id);

		$this->assertSame(['com.atproto.server.describeServer', 'com.atproto.server.createAccount', 'com.atproto.repo.importRepo', 'com.atproto.repo.listMissingBlobs', 'com.atproto.repo.uploadBlob', 'com.atproto.repo.listMissingBlobs', 'app.bsky.actor.putPreferences', 'com.atproto.identity.getRecommendedDidCredentials', 'com.atproto.server.activateAccount'], array_column($this->calls, 'method'), 'in this order: the DID moves only once everything is there');
		$this->assertSame('the car', $this->call('com.atproto.repo.importRepo')['bytes']);
		$this->assertSame('pixels', $this->call('com.atproto.repo.uploadBlob')['bytes']);
		$this->assertSame(['limit' => 500, 'cursor' => 'c1'], $this->calls[5]['query']);
		$this->assertSame('access-1', $this->call('com.atproto.server.activateAccount')['token']);
		$this->assertNull($this->call('com.atproto.server.activateAccount')['json'], 'activateAccount takes no body');
		$this->assertNull($this->call('com.atproto.server.activateAccount')['bytes']);
		$this->assertSame(Move::DONE, $this->rows[1]->state);
		$this->assertSame('', $this->rows[1]->session, 'the other server\'s session is not kept');
	}

	public function testAFailedStepIsStartedAgainFromThere(): void {
		$move = $this->moves->start('alice', $this->alice, 'pds.example.com', 'alice.pds.example.com', 'alice@example.org', 'secret');
		$this->answers['com.atproto.repo.listMissingBlobs'] = [['status' => 502, 'body' => ['error' => 'UpstreamFailure']]];
		$this->moves->run($move->id);
		$this->assertNotContains('com.atproto.identity.getRecommendedDidCredentials', array_column($this->calls, 'method'), 'the DID did not move');
		$this->assertSame(Move::FAILED, $this->rows[1]->state);
		$this->assertSame(Move::STEP_BLOBS, $this->rows[1]->step);
		$this->assertStringContainsString('UpstreamFailure', $this->rows[1]->error);

		$this->calls = [];
		$this->moves->retry('alice');
		$this->moves->run($move->id);
		$this->assertNotContains('com.atproto.repo.importRepo', array_column($this->calls, 'method'), 'the repository is there already');
		$this->assertSame(Move::DONE, $this->rows[1]->state);
	}

	public function testAnExpiredSessionThereIsRefreshedOnce(): void {
		$move = $this->moves->start('alice', $this->alice, 'pds.example.com', 'alice.pds.example.com', 'alice@example.org', 'secret');
		$this->answers['com.atproto.repo.importRepo'] = [['status' => 400, 'body' => ['error' => 'ExpiredToken']], ['status' => 200, 'body' => []]];
		$this->answers['com.atproto.server.refreshSession'] = [['status' => 200, 'body' => ['accessJwt' => 'access-2', 'refreshJwt' => 'refresh-2']]];

		$this->moves->run($move->id);

		$this->assertSame('refresh-1', $this->call('com.atproto.server.refreshSession')['token']);
		$this->assertSame('access-2', $this->call('com.atproto.server.activateAccount')['token']);
	}

	public function testOneMoveAtATime(): void {
		$this->moves->start('alice', $this->alice, 'pds.example.com', 'alice.pds.example.com', 'alice@example.org', 'secret');

		$this->expectException(InvalidArgumentException::class);
		$this->moves->start('alice', $this->alice, 'pds.example.com', 'alice2.pds.example.com', 'alice@example.org', 'secret');
	}
}
