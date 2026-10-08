<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Crypto;

use InvalidArgumentException;
use OCA\Social\Atproto\Crypto\BigNum;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Crypto\PublicKey;
use OCA\Social\Atproto\Protocol\Encoding;
use OCA\Social\Tests\Atproto\Fixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Keys and signatures against the interop vectors: did:key derivation on
 * both curves, point decompression, and the low-S rule that makes a valid
 * ECDSA signature invalid in this protocol.
 */
class KeysTest extends TestCase {
	#[DataProvider('curves')]
	public function testADidKeyIsDerivedFromThePrivateBytes(Curve $curve, string $fixture): void {
		foreach (Fixtures::json($fixture) as $vector) {
			$key = new PrivateKey($curve, self::secretOf($vector));
			$this->assertSame($vector['publicDidKey'], $key->didKey());
		}
	}

	#[DataProvider('curves')]
	public function testACompressedPointIsRecoveredFromItsDidKey(Curve $curve, string $fixture): void {
		foreach (Fixtures::json($fixture) as $vector) {
			$expected = (new PrivateKey($curve, self::secretOf($vector)))->publicKey();
			$recovered = PublicKey::fromDidKey($vector['publicDidKey']);

			$this->assertSame($curve, $recovered->curve);
			$this->assertSame(bin2hex($expected->x), bin2hex($recovered->x));
			$this->assertSame(bin2hex($expected->y), bin2hex($recovered->y), 'y was decompressed with the right parity');
		}
	}

	/** the two fixture files write the secret differently */
	private static function secretOf(array $vector): string {
		return isset($vector['privateKeyBytesHex'])
			? (string)hex2bin($vector['privateKeyBytesHex'])
			: Encoding::base58Decode($vector['privateKeyBytesBase58']);
	}

	/**
	 * @return iterable<string, array{0: Curve, 1: string}>
	 */
	public static function curves(): iterable {
		yield 'k256' => [Curve::K256, 'w3c_didkey_K256.json'];
		yield 'p256' => [Curve::P256, 'w3c_didkey_P256.json'];
	}

	public function testSignatureFixtures(): void {
		foreach (Fixtures::json('signature-fixtures.json') as $vector) {
			$key = PublicKey::fromDidKey($vector['publicKeyDid']);
			// the fixture's multibase is the older form without the multicodec
			// prefix, as the 2019 verification suites wrote it
			$this->assertSame(bin2hex($key->compressed()), bin2hex(Encoding::base58Decode(substr($vector['publicKeyMultibase'], 1))), $vector['comment']);
			$message = (string)base64_decode($vector['messageBase64'], true);
			$signature = (string)base64_decode($vector['signatureBase64'], true);

			$this->assertSame($vector['validSignature'], $key->verify($message, $signature), $vector['comment']);
		}
	}

	#[DataProvider('curves')]
	public function testASignatureRoundTripsAndIsLowS(Curve $curve, string $unused): void {
		$key = PrivateKey::generate($curve);
		$message = random_bytes(100);
		for ($i = 0; $i < 8; $i++) {
			$signature = $key->sign($message);
			$this->assertSame(64, strlen($signature));
			$this->assertLessThanOrEqual(0, BigNum::fromBytes(substr($signature, 32))->compare($curve->halfOrder()));
			$this->assertTrue($key->publicKey()->verify($message, $signature));
			$this->assertFalse($key->publicKey()->verify($message . 'x', $signature));
		}
	}

	public function testAHighSSignatureIsRefusedEvenThoughTheMathHolds(): void {
		$key = PrivateKey::generate(Curve::K256);
		$signature = $key->sign('hello');
		$s = BigNum::fromBytes(substr($signature, 32));
		$high = substr($signature, 0, 32) . Curve::K256->order()->subtract($s)->toBytes(32);

		$this->assertFalse($key->publicKey()->verify('hello', $high));
	}

	public function testAKeyIsRebuiltFromItsSecret(): void {
		$key = PrivateKey::generate(Curve::K256);
		$again = new PrivateKey(Curve::K256, $key->secret());

		$this->assertSame($key->didKey(), $again->didKey());
		$this->assertTrue($key->publicKey()->verify('m', $again->sign('m')));
	}

	public function testAPointOffTheCurveIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		// x = 5 has no square root of x³ + 7 on secp256k1
		PublicKey::fromCompressed(Curve::K256, "\x02" . str_repeat("\0", 31) . "\x05");
	}

	public function testBigNumArithmetic(): void {
		$a = BigNum::fromHex('fffffffffffffffffffffffffffffffffffffffffffffffffffffffefffffc2f');
		$b = BigNum::fromHex('0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef');

		$this->assertSame('0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef', bin2hex($b->toBytes(32)));
		$this->assertSame(1, $a->compare($b));
		$this->assertSame(0, $a->add($b)->subtract($b)->compare($a));
		$this->assertSame(0, $a->subtract($b)->add($b)->compare($a));
		$this->assertSame('010000', bin2hex(BigNum::fromInt(0xffff)->add(BigNum::fromInt(1))->toBytes(3)));
		$this->assertSame('fffe0001', bin2hex(BigNum::fromInt(0xffff)->multiply(BigNum::fromInt(0xffff))->toBytes(4)));
		// (a * b) mod a == 0, and b mod a == b
		$this->assertTrue($a->modMultiply($b, $a)->isZero());
		$this->assertSame(0, $b->mod($a)->compare($b));
		// Fermat: b^(p-1) ≡ 1 (mod p) for prime p
		$this->assertSame('01', bin2hex(ltrim($b->modPow($a->subtract(BigNum::fromInt(1)), $a)->toBytes(32), "\0")));
		$this->assertSame(7, (int)hexdec(bin2hex(BigNum::fromInt(1000007)->mod(BigNum::fromInt(1000))->toBytes(1))));
	}

	public function testEncodings(): void {
		$this->assertSame('', Encoding::base58Encode(''));
		$this->assertSame('11', Encoding::base58Encode("\0\0"));
		$this->assertSame("\0\0", Encoding::base58Decode('11'));
		$bytes = random_bytes(35);
		$this->assertSame($bytes, Encoding::base58Decode(Encoding::base58Encode($bytes)));
		$this->assertSame($bytes, Encoding::base32Decode(Encoding::base32Encode($bytes)));
		$this->assertSame($bytes, Encoding::base64UrlDecode(Encoding::base64UrlEncode($bytes)));
		$this->assertStringNotContainsString('=', Encoding::base64UrlEncode("\xff"));
		$this->assertSame("\x80\x01", Encoding::varint(128));
		$offset = 0;
		$this->assertSame(300, Encoding::readVarint("\xac\x02rest", $offset));
		$this->assertSame(2, $offset);
	}
}
