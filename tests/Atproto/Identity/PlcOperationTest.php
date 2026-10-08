<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Identity;

use InvalidArgumentException;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\HandleMapper;
use OCA\Social\Atproto\Identity\Mnemonic;
use OCA\Social\Atproto\Identity\PlcOperation;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Protocol\Syntax;
use PHPUnit\Framework\TestCase;

class PlcOperationTest extends TestCase {
	public function testAGenesisOperationDefinesADid(): void {
		$rotation = PrivateKey::generate(Curve::K256);
		$signing = PrivateKey::generate(Curve::K256);
		$operation = PlcOperation::sign(
			PlcOperation::build([$rotation->didKey()], $signing->didKey(), 'alice.social.test', 'https://social.test', null),
			$rotation,
		);

		$this->assertSame(['type', 'rotationKeys', 'verificationMethods', 'alsoKnownAs', 'services', 'prev', 'sig'], array_keys($operation));
		$this->assertNull($operation['prev']);
		$this->assertTrue(PlcOperation::verify($operation, $rotation->publicKey()));
		$this->assertFalse(PlcOperation::verify($operation, $signing->publicKey()));

		$did = PlcOperation::didOf($operation);
		$this->assertTrue(Syntax::isDidPlc($did), $did);
		$this->assertSame($did, PlcOperation::didOf($operation), 'deterministic');
		$this->assertStringStartsWith('bafyrei', PlcOperation::cid($operation)->toString());
	}

	public function testTheSignatureIsOverTheOperationWithoutSig(): void {
		$rotation = PrivateKey::generate(Curve::K256);
		$operation = PlcOperation::build([$rotation->didKey()], $rotation->didKey(), 'a.b.test', 'https://b.test', null);
		$signed = PlcOperation::sign($operation, $rotation);
		$unsigned = $signed;
		unset($unsigned['sig']);

		$this->assertTrue($rotation->publicKey()->verify(DagCbor::encode($unsigned), \OCA\Social\Atproto\Protocol\Encoding::base64UrlDecode($signed['sig'])));
		$this->assertStringNotContainsString('=', $signed['sig']);
	}

	public function testATombstoneNamesWhatItEnds(): void {
		$rotation = PrivateKey::generate(Curve::K256);
		$tombstone = PlcOperation::sign(PlcOperation::tombstone('bafyreie5cvv4h45feadgeuwhbcutmh6t2ceseocckahdoe6uat64zmz454'), $rotation);

		$this->assertSame(PlcOperation::TYPE_TOMBSTONE, $tombstone['type']);
		$this->assertTrue(PlcOperation::verify($tombstone, $rotation->publicKey()));
	}

	public function testTheDocumentNamesTheKeyTheHandleAndThePds(): void {
		$signing = PrivateKey::generate(Curve::K256);
		$operation = PlcOperation::build(['did:key:zRotation'], $signing->didKey(), 'alice.social.test', 'https://social.test', null);
		$document = PlcOperation::document('did:plc:abc', $operation);

		$this->assertSame('did:plc:abc', $document['id']);
		$this->assertSame(['at://alice.social.test'], $document['alsoKnownAs']);
		$this->assertSame('did:plc:abc#atproto', $document['verificationMethod'][0]['id']);
		$this->assertSame('Multikey', $document['verificationMethod'][0]['type']);
		$this->assertSame($signing->publicKey()->multibase(), $document['verificationMethod'][0]['publicKeyMultibase']);
		$this->assertSame(['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => 'https://social.test'], $document['service'][0]);
	}

	public function testMoreThanFiveRotationKeysIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		PlcOperation::build(array_fill(0, 6, 'did:key:z'), 'did:key:z', 'a.b.test', 'https://b.test', null);
	}

	public function testHandlesAreMappedFromUsernames(): void {
		$this->assertSame('alice', HandleMapper::label('alice'));
		$this->assertSame('alice-smith', HandleMapper::label('Alice.Smith'));
		$this->assertSame('a-b', HandleMapper::label('a___b'));
		$this->assertSame('user', HandleMapper::label('___'));
		$this->assertSame('u-123', HandleMapper::label('123'));
		$this->assertSame(63, strlen(HandleMapper::label(str_repeat('a', 80))));
		$this->assertTrue(Syntax::isHandle(HandleMapper::label('Frank_Karlitschek') . '.social.example.com'));
	}

	public function testACollisionGetsANumber(): void {
		$taken = ['alice-smith.social.test', 'alice-smith-2.social.test'];
		$handle = HandleMapper::handle('Alice.Smith', 'Social.Test', static fn (string $h): bool => in_array($h, $taken, true));

		$this->assertSame('alice-smith-3.social.test', $handle);
		$this->assertSame('bob.social.test', HandleMapper::handle('bob', 'social.test', static fn (): bool => false));
	}

	public function testARecoveryPhraseRoundTrips(): void {
		[$phrase, $key] = Mnemonic::generate();

		$this->assertCount(12, explode(' ', $phrase));
		$this->assertSame($key->didKey(), Mnemonic::keyFromPhrase($phrase)->didKey());
		$this->assertSame($key->didKey(), Mnemonic::keyFromPhrase(strtoupper($phrase) . ' ')->didKey(), 'case and spacing do not matter');
	}

	public function testAKnownPhrase(): void {
		// the BIP-39 test vector for sixteen zero bytes
		$this->assertSame('abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about', Mnemonic::encode(str_repeat("\0", 16)));
		$this->assertSame(str_repeat("\0", 16), Mnemonic::decode('abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about'));
	}

	public function testAWrongPhraseIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		Mnemonic::decode('abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon');
	}
}
