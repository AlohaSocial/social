<?php

declare(strict_types=1);

namespace OCA\Social\Tests\Atproto;

use OCA\Social\Atproto\Identity\AtprotoDid;
use OCA\Social\Atproto\Identity\KeyManager;
use OCA\Social\Atproto\Identity\RecoveryPhrase;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Repository\Commit;
use PHPUnit\Framework\TestCase;

class IdentityProtocolTest extends TestCase {
	public function testNativeEndpointCanUseItsOwnHostname(): void {
		$config = $this->createStub(\OCA\Social\Service\ConfigService::class);
		$config->method('getAppValue')->willReturn('https://pds.example.org:8443/');
		$handles = new \OCA\Social\Atproto\Identity\HandleMapper($config);
		self::assertSame('alice.pds.example.org', $handles->mapUsernameToHandle('Alice'));
		self::assertSame('https://pds.example.org:8443', $handles->getPdsEndpoint());
	}
	public function testNativeEndpointRejectsCredentialsAndNonHttps(): void {
		$config = $this->createStub(\OCA\Social\Service\ConfigService::class);
		$config->method('getAppValue')->willReturn('http://user:secret@pds.example.org/');
		$this->expectException(\InvalidArgumentException::class);
		(new \OCA\Social\Atproto\Identity\HandleMapper($config))->getPdsEndpoint();
	}
	public function testGenesisIsSignedBeforeDidIsDerived(): void {
		$keys = new KeyManager(null, null);
		$key = $keys->generateSigningKey();
		$unsigned = AtprotoDid::operation($key['didKey'], [$key['didKey']], 'alice.example.org', 'https://example.org');
		$signed = AtprotoDid::signOperation($unsigned, $key['private'], $keys);
		self::assertTrue($keys->verify(DagCbor::encode($unsigned), base64_decode(strtr($signed['sig'], '-_', '+/')), $key['didKey']));
		$did = AtprotoDid::fromSignedGenesis($signed);
		self::assertNotNull(AtprotoDid::parse($did));
		self::assertSame('did:plc:' . substr(Cid::base32(hash('sha256', DagCbor::encode($signed), true)), 0, 24), $did);
	}
	public function testRecoveryEncodesTheActual256BitScalarWithChecksum(): void {
		$phrase = RecoveryPhrase::encode(str_repeat('0', 64));
		self::assertSame(24, count(explode(' ', $phrase)));
		self::assertSame(implode(' ', array_merge(array_fill(0, 23, 'abandon'), ['art'])), $phrase);
	}
	public function testCommitHasTypedLinksRawSignatureAndNullPrevious(): void {
		$keys = new KeyManager(null, null);
		$key = $keys->generateSigningKey();
		$commit = Commit::create('did:plc:abcdefghijklmnopqrstuvwx', Cid::hash('data'), '3aaaaaaaaaaaa', Cid::hash('old'), $key['private'], $keys);
		$decoded = DagCbor::decode($commit->toCbor());
		self::assertNull($decoded['prev']);
		self::assertSame(64, strlen($decoded['sig']->value));
		self::assertInstanceOf(Cid::class, $decoded['data']);
		$sig = $decoded['sig']->value;
		unset($decoded['sig']);
		self::assertTrue($keys->verify(DagCbor::encode($decoded), $sig, $key['didKey']));
	}
}
