<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Identity;

use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Crypto\PublicKey;
use OCA\Social\Atproto\Firehose\EventService;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\InstanceKeyService;
use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Atproto\Identity\PlcOperation;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoIdentityRequest;
use OCA\Social\Db\AtprotoPlcLogRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Security\PrivateKeyCipher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Making an identity: the handle, the genesis operation and the DID it
 * defines, what is stored sealed, what is logged before it is sent, and
 * what happens when the directory is down.
 */
#[AllowMockObjectsWithoutExpectations]
class IdentityServiceTest extends TestCase {
	/** @var AtprotoIdentityRequest&MockObject */
	private AtprotoIdentityRequest $identityRequest;
	/** @var AtprotoPlcLogRequest&MockObject */
	private AtprotoPlcLogRequest $plcLog;
	/** @var PlcClient&MockObject */
	private PlcClient $plc;
	/** @var EventService&MockObject */
	private EventService $events;
	/** @var RepositoryService&MockObject */
	private RepositoryService $repositories;
	/** @var AtprotoBlobRequest&MockObject */
	private AtprotoBlobRequest $blobs;
	private PrivateKey $rotation;
	private IdentityService $service;
	/** @var array<string, Identity> */
	private array $stored = [];
	/** @var array<int, array{did: string, cid: string, operation: array}> */
	private array $logged = [];

	protected function setUp(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$config->method('handleHost')->willReturn('social.test');
		$config->method('pdsEndpoint')->willReturn('https://social.test');
		$config->method('serviceDid')->willReturn('did:web:social.test');

		$this->identityRequest = $this->createMock(AtprotoIdentityRequest::class);
		$this->identityRequest->method('handleExists')->willReturnCallback(fn (string $handle): bool => $handle === 'taken.social.test');
		$this->identityRequest->method('create')->willReturnCallback(function (string $actorId, string $did, string $handle, string $sealed, string $public, string $recovery): int {
			$this->stored[$did] = new Identity(count($this->stored) + 1, $actorId, $did, $handle, $sealed, $public, $recovery, Identity::STATE_ACTIVE, '', 1700000000);

			return count($this->stored);
		});
		$this->identityRequest->method('getByDid')->willReturnCallback(fn (string $did): Identity => $this->stored[$did] ?? throw new AtprotoIdentityNotFoundException());
		$this->identityRequest->method('getByActorId')->willThrowException(new AtprotoIdentityNotFoundException());

		$this->plcLog = $this->createMock(AtprotoPlcLogRequest::class);
		$this->plcLog->method('record')->willReturnCallback(function (string $did, string $cid, array $operation): int {
			$this->logged[] = ['did' => $did, 'cid' => $cid, 'operation' => $operation];

			return count($this->logged);
		});
		$this->plcLog->method('latestCid')->willReturnCallback(function (string $did): string {
			foreach (array_reverse($this->logged) as $row) {
				if ($row['did'] === $did) {
					return $row['cid'];
				}
			}

			return '';
		});

		$this->rotation = PrivateKey::generate(Curve::K256);
		$instanceKeys = $this->createMock(InstanceKeyService::class);
		$instanceKeys->method('rotationKey')->willReturn($this->rotation);
		$instanceKeys->method('serviceKey')->willReturn(PrivateKey::generate(Curve::K256));

		$cipher = $this->createMock(PrivateKeyCipher::class);
		$cipher->method('seal')->willReturnCallback(static fn (string $secret): string => 'sealed:' . base64_encode($secret));
		$cipher->method('open')->willReturnCallback(static fn (string $stored): string => str_starts_with($stored, 'sealed:') ? (string)base64_decode(substr($stored, 7)) : '');

		$this->plc = $this->createMock(PlcClient::class);
		$this->events = $this->createMock(EventService::class);
		$this->repositories = $this->createMock(RepositoryService::class);
		$this->blobs = $this->createMock(AtprotoBlobRequest::class);

		$this->service = new IdentityService(
			$config, $this->identityRequest, $this->plcLog, $this->blobs, $instanceKeys, $this->plc, $this->events, $this->repositories, $cipher, new NullLogger(),
		);
	}

	public function testAnIdentityIsMadeRegisteredAndAnnounced(): void {
		$this->plc->expects($this->once())->method('submit')->with(
			$this->callback(static fn (string $did): bool => str_starts_with($did, 'did:plc:') && strlen($did) === 32),
			$this->callback(fn (array $operation): bool => $operation['type'] === 'plc_operation'
				&& $operation['rotationKeys'] === [$this->rotation->didKey()]
				&& $operation['alsoKnownAs'] === ['at://alice-smith.social.test']
				&& $operation['services']['atproto_pds']['endpoint'] === 'https://social.test'
				&& $operation['prev'] === null
				&& PlcOperation::verify($operation, $this->rotation->publicKey())),
		);
		$this->plcLog->expects($this->once())->method('markConfirmed');
		$this->events->expects($this->once())->method('identity')->with($this->anything(), 'alice-smith.social.test');

		$identity = $this->service->forActor(self::actor('Alice.Smith'));

		$this->assertNotNull($identity);
		$this->assertSame('alice-smith.social.test', $identity->handle);
		$this->assertSame($identity->did, PlcOperation::didOf($this->logged[0]['operation']), 'the DID is the hash of the logged genesis');
		$this->assertStringStartsWith('sealed:', $identity->sealedSigningKey);
		$this->assertSame($this->service->signingKey($identity)->didKey(), $identity->signingPublic, 'the sealed secret opens to the key the public half names');
		$this->assertSame('', $identity->recoveryPublic, 'no recovery key until the person asks for one');
	}

	public function testATakenHandleGetsANumber(): void {
		$identity = $this->service->forActor(self::actor('taken'));

		$this->assertSame('taken-2.social.test', $identity?->handle);
	}

	public function testADirectoryThatIsDownLeavesTheIdentityForRepair(): void {
		$this->plc->method('submit')->willThrowException(new AtprotoException('down'));
		$this->plcLog->expects($this->once())->method('markSent');
		$this->plcLog->expects($this->never())->method('markConfirmed');

		$identity = $this->service->forActor(self::actor('bob'));

		$this->assertNotNull($identity, 'the identity exists here whatever the directory said');
		$this->assertTrue($identity->isActive());
	}

	public function testRepairResendsWhatWasNotConfirmed(): void {
		$this->plcLog->method('getUnconfirmed')->willReturn([
			['id' => 7, 'did' => 'did:plc:x', 'cid' => 'bafy', 'operation' => ['type' => 'plc_operation'], 'creation' => 0, 'sent' => 0, 'confirmed' => 0],
		]);
		$this->plc->expects($this->once())->method('submit')->with('did:plc:x', ['type' => 'plc_operation']);
		$this->plcLog->expects($this->once())->method('markConfirmed')->with(7);

		$this->assertSame(1, $this->service->repair());
	}

	public function testARecoveryKeyIsAddedSecondAndThePhraseIsTheOnlyCopy(): void {
		$identity = $this->service->forActor(self::actor('carol'));
		$this->assertNotNull($identity);
		$submitted = [];
		$this->plc->method('submit')->willReturnCallback(static function (string $did, array $operation) use (&$submitted): void {
			$submitted[] = $operation;
		});
		$this->identityRequest->expects($this->once())->method('setRecoveryPublic')->with($identity->did, $this->stringStartsWith('did:key:zQ3sh'));

		$phrase = $this->service->issueRecoveryKey($identity);

		$this->assertCount(12, explode(' ', $phrase));
		$update = end($submitted);
		$this->assertSame($this->rotation->didKey(), $update['rotationKeys'][0], 'the instance key keeps the first place');
		$this->assertSame(\OCA\Social\Atproto\Identity\Mnemonic::keyFromPhrase($phrase)->didKey(), $update['rotationKeys'][1]);
		$this->assertSame($this->logged[0]['cid'], $update['prev'], 'the update follows the genesis');
		$this->assertTrue(PlcOperation::verify($update, $this->rotation->publicKey()));
	}

	public function testSwitchingOffAnnouncesTheAccountInactiveAndOnAgainActive(): void {
		$identity = $this->service->forActor(self::actor('erin'));
		$this->assertNotNull($identity);
		$this->plc->expects($this->never())->method('submit');
		$this->repositories->expects($this->never())->method('delete');
		$states = [];
		$this->identityRequest->method('setState')->willReturnCallback(static function (string $did, string $state) use (&$states): void {
			$states[] = $state;
		});
		$announced = [];
		$this->events->method('account')->willReturnCallback(static function (string $did, bool $active, string $status = '') use (&$announced): int {
			$announced[] = [$active, $status];

			return 1;
		});

		$this->service->deactivate($identity);
		$this->service->deactivate($identity);
		$off = new Identity($identity->id, $identity->actorId, $identity->did, $identity->handle, $identity->sealedSigningKey, $identity->signingPublic, '', Identity::STATE_DEACTIVATED, '', $identity->creation);
		$this->service->deactivate($off);
		$this->service->activate($off);
		$this->service->activate($identity);

		$this->assertSame([Identity::STATE_DEACTIVATED, Identity::STATE_DEACTIVATED, Identity::STATE_ACTIVE], $states, 'the DID keeps its state row; only a change is written');
		$this->assertSame([[false, 'deactivated'], [false, 'deactivated'], [true, '']], $announced);
		$this->assertSame(['did' => $off->did, 'handle' => $off->handle, 'signing_key' => $off->signingPublic, 'state' => 'deactivated', 'active' => false, 'created_at' => gmdate('Y-m-d\TH:i:s\Z', $off->creation)], $off->toArray());
	}

	public function testATombstoneEndsEverything(): void {
		$identity = $this->service->forActor(self::actor('dave'));
		$this->assertNotNull($identity);
		$this->plc->expects($this->once())->method('submit')->with($identity->did, $this->callback(fn (array $op): bool => $op['type'] === 'plc_tombstone' && $op['prev'] === $this->logged[0]['cid'] && PlcOperation::verify($op, $this->rotation->publicKey())));
		$this->identityRequest->expects($this->once())->method('setState')->with($identity->did, Identity::STATE_TOMBSTONED);
		$this->repositories->expects($this->once())->method('delete')->with($identity->did);
		$this->blobs->expects($this->once())->method('deleteByDid')->with($identity->did);
		$this->events->expects($this->once())->method('account')->with($identity->did, false, 'deleted');

		$this->service->tombstone($identity);
	}

	public function testNothingIsMadeForARemoteOrMovedActor(): void {
		$remote = self::actor('eve');
		$remote->setLocal(false);
		$this->assertNull($this->service->forActor($remote));

		$moved = self::actor('fay');
		$moved->setMovedTo('https://elsewhere.example/@fay');
		$this->assertNull($this->service->forActor($moved));
	}

	public function testTheInstanceDocumentNamesTheServiceKeyAndThePds(): void {
		$document = $this->service->instanceDocument();

		$this->assertSame('did:web:social.test', $document['id']);
		$this->assertSame(['at://social.test'], $document['alsoKnownAs'], 'a handle, or the reference resolver will not take it');
		$this->assertSame('did:web:social.test#atproto', $document['verificationMethod'][0]['id']);
		$this->assertInstanceOf(PublicKey::class, PublicKey::fromDidKey('did:key:' . $document['verificationMethod'][0]['publicKeyMultibase']));
		$this->assertSame('https://social.test', $document['service'][0]['serviceEndpoint']);
	}

	private static function actor(string $username): Person {
		$actor = new Person();
		$actor->setId('https://social.test/@' . $username);
		$actor->setPreferredUsername($username);
		$actor->setLocal(true);

		return $actor;
	}
}
