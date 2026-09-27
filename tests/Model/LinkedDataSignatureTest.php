<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model;

use OCA\Social\Exceptions\LinkedDataSignatureMissingException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\LinkedDataSignature;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use PHPUnit\Framework\TestCase;

/**
 * JSON-LD canonicalisation needs the remote @context documents; they are
 * served from the copies bundled in context/ through a mocked app-data folder,
 * so no network is touched.
 */
class LinkedDataSignatureTest extends TestCase {
	private static string $privateKey;
	private static string $publicKey;
	private static string $otherPublicKey;

	public static function setUpBeforeClass(): void {
		[self::$privateKey, self::$publicKey] = self::generateKeyPair();
		[, self::$otherPublicKey] = self::generateKeyPair();
	}

	/** @return array{0: string, 1: string} private and public PEM */
	private static function generateKeyPair(): array {
		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		openssl_pkey_export($key, $private);

		return [$private, openssl_pkey_get_details($key)['key']];
	}

	protected function setUp(): void {
		$folder = $this->createStub(ISimpleFolder::class);
		$folder->method('getFile')->willReturnCallback(function (string $name): ISimpleFile {
			$path = dirname(__DIR__, 2) . '/context/' . $name;
			if (!is_file($path)) {
				throw new \RuntimeException('no bundled context document for ' . $name);
			}
			$file = $this->createMock(ISimpleFile::class);
			$file->method('getMTime')->willReturn(time());
			$file->method('getContent')->willReturn(file_get_contents($path));

			return $file;
		});
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->with('context')->willReturn($folder);
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->with('social')->willReturn($appData);
		\OC::$server->register(IAppDataFactory::class, $factory);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	private function note(): array {
		return [
			'@context' => ACore::CONTEXT_ACTIVITYSTREAMS,
			'id' => 'https://cloud.example.org/apps/social/@alice/1',
			'type' => 'Note',
			'attributedTo' => 'https://cloud.example.org/apps/social/@alice',
			'to' => [ACore::CONTEXT_PUBLIC],
			'content' => '<p>Signed hello</p>',
			'published' => '2024-05-01T12:00:00Z',
		];
	}

	private function signedNote(): LinkedDataSignature {
		$signature = new LinkedDataSignature();
		$signature->setType('RsaSignature2017')
			->setCreator('https://cloud.example.org/apps/social/@alice#main-key')
			->setCreated('2024-05-01T12:00:00Z')
			->setPrivateKey(self::$privateKey)
			->setObject($this->note());
		$signature->sign();

		return $signature;
	}

	public function testSignThenVerifyWithTheMatchingPublicKey(): void {
		$signature = $this->signedNote();

		$this->assertNotSame('', $signature->getSignatureValue());
		$this->assertNotFalse(base64_decode($signature->getSignatureValue(), true));

		$signature->setPublicKey(self::$publicKey);
		$this->assertTrue($signature->verify());
	}

	public function testVerifyFailsWhenTheObjectWasTamperedWith(): void {
		$signature = $this->signedNote();
		$signature->setPublicKey(self::$publicKey);

		$tampered = $signature->getObject();
		$tampered['content'] = '<p>Something else</p>';
		$signature->setObject($tampered);

		$this->assertFalse($signature->verify());
	}

	public function testVerifyFailsWhenTheHeaderWasTamperedWith(): void {
		$signature = $this->signedNote();
		$signature->setPublicKey(self::$publicKey);

		$signature->setCreated('2024-05-02T12:00:00Z');

		$this->assertFalse($signature->verify());
	}

	public function testVerifyFailsWithAnotherKey(): void {
		$signature = $this->signedNote();

		$signature->setPublicKey(self::$otherPublicKey);

		$this->assertFalse($signature->verify());
	}

	public function testASignedDocumentCanBeImportedAndVerified(): void {
		$signed = $this->signedNote();
		$document = $this->note();
		$document['signature'] = $signed->jsonSerialize();

		$received = new LinkedDataSignature();
		$received->import($document);
		$received->setPublicKey(self::$publicKey);

		$this->assertSame('RsaSignature2017', $received->getType());
		$this->assertSame('https://cloud.example.org/apps/social/@alice#main-key', $received->getCreator());
		$this->assertSame('2024-05-01T12:00:00Z', $received->getCreated());
		$this->assertSame($signed->getSignatureValue(), $received->getSignatureValue());
		$this->assertSame($this->note(), $received->getObject(), 'the signature block is removed from the object');
		$this->assertTrue($received->verify());
	}

	public function testImportReadsTheNonce(): void {
		$signature = new LinkedDataSignature();

		$signature->import([
			'type' => 'Note',
			'signature' => ['type' => 'RsaSignature2017', 'creator' => 'https://a.example/#key', 'created' => '2024-05-01T12:00:00Z', 'nonce' => 'abc', 'signatureValue' => 'Zm9v'],
		]);

		$this->assertSame('abc', $signature->getNonce());
		$this->assertSame(['type' => 'Note'], $signature->getObject());
	}

	public function testImportRequiresASignatureBlock(): void {
		$this->expectException(LinkedDataSignatureMissingException::class);

		(new LinkedDataSignature())->import($this->note());
	}

	public function testJsonSerializeExposesTheSignatureBlock(): void {
		$signature = new LinkedDataSignature();
		$signature->setType('RsaSignature2017')
			->setCreator('https://a.example/#key')
			->setCreated('2024-05-01T12:00:00Z')
			->setSignatureValue('Zm9v');

		$this->assertSame([
			'type' => 'RsaSignature2017',
			'creator' => 'https://a.example/#key',
			'created' => '2024-05-01T12:00:00Z',
			'signatureValue' => 'Zm9v',
		], $signature->jsonSerialize());
	}
}
