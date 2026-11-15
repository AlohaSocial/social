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
use PHPUnit\Framework\Attributes\DataProvider;
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

	/**
	 * A document with bytes JSON cannot encode, as a fuzzed one had: it is
	 * not verified, where the encoder's `false` used to reach json_decode()
	 * and come out of verify() as a TypeError.
	 */
	public function testADocumentJsonCannotEncodeIsNotVerified(): void {
		$signature = new LinkedDataSignature();
		$signature->import(['object' => "\xff", 'signature' => ['signatureValue' => '=']]);
		$signature->setPublicKey(self::$publicKey);

		$this->assertFalse($signature->verify());
	}

	/**
	 * JSON-LD the normaliser fails on with an Error rather than an exception,
	 * as fuzzed documents had it: not verified, where the Error used to come
	 * out of verify(). A context that is a NUL arrives in valid JSON; an
	 * empty object a body decoded to arrays cannot carry, the model can.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function unreadableDocuments(): array {
		return [
			'a context that is a NUL' => [['@context' => "\0", 'type' => 'Note']],
			'an empty object for a type' => [['@context' => ['https://www.w3.org/ns/activitystreams'], 'object' => ['type' => new \stdClass()]]],
		];
	}

	#[DataProvider('unreadableDocuments')]
	public function testADocumentTheNormaliserCannotReadIsNotVerified(array $document): void {
		$signature = new LinkedDataSignature();
		$signature->import($document + [
			'signature' => ['created' => '2026-10-11T12:00:00Z', 'signatureValue' => base64_encode('x')],
		]);
		$signature->setPublicKey(self::$publicKey);

		$this->assertFalse($signature->verify());
	}

	public function testImportRequiresASignatureBlock(): void {
		$this->expectException(LinkedDataSignatureMissingException::class);

		(new LinkedDataSignature())->import($this->note());
	}

	private function signedCreate(): array {
		$create = [
			'@context' => [ACore::CONTEXT_ACTIVITYSTREAMS],
			'id' => 'https://cloud.example.org/apps/social/@alice/1/activity',
			'type' => 'Create',
			'actor' => 'https://cloud.example.org/apps/social/@alice',
			'to' => [ACore::CONTEXT_PUBLIC],
			'object' => [
				'id' => 'https://cloud.example.org/apps/social/@alice/1',
				'type' => 'Note',
				'attributedTo' => 'https://cloud.example.org/apps/social/@alice',
				'content' => '<p>Signed hello</p>',
			],
		];
		$signature = new LinkedDataSignature();
		$signature->setType('RsaSignature2017')
			->setCreator('https://cloud.example.org/apps/social/@alice#main-key')
			->setCreated('2024-05-01T12:00:00Z')
			->setPrivateKey(self::$privateKey)
			->setObject($create);
		$signature->sign();
		$create['signature'] = $signature->jsonSerialize();

		return $create;
	}

	/**
	 * A signed Create, re-keyed with an inline context so that its raw keys
	 * read as a Delete of the actor while it still expands to the signed
	 * triples.
	 */
	private function aliasedAsDelete(array $create): array {
		return [
			'@context' => [ACore::CONTEXT_ACTIVITYSTREAMS, [
				'type' => null,
				'id' => null,
				'object' => null,
				'xtype' => '@type',
				'xid' => '@id',
				'xobject' => ['@id' => 'as:object', '@type' => '@id'],
			]],
			'xid' => $create['id'],
			'xtype' => $create['type'],
			'actor' => $create['actor'],
			'to' => $create['to'],
			'xobject' => [
				'xid' => $create['object']['id'],
				'xtype' => $create['object']['type'],
				'attributedTo' => $create['object']['attributedTo'],
				'content' => $create['object']['content'],
			],
			'id' => 'https://evil.example/forged',
			'type' => 'Delete',
			'object' => $create['actor'],
			'signature' => $create['signature'],
		];
	}

	public function testAnInlineContextCannotReKeyASignedDocument(): void {
		$create = $this->signedCreate();
		$genuine = new LinkedDataSignature();
		$genuine->import($create);
		$genuine->setPublicKey(self::$publicKey);
		$this->assertTrue($genuine->verify());

		$forged = new LinkedDataSignature();
		$forged->import($this->aliasedAsDelete($create));
		$forged->setPublicKey(self::$publicKey);

		$this->assertFalse($forged->verify());
	}

	/**
	 * The extension terms this app and Mastodon declare inline only add
	 * definitions, so a document using them is still verified.
	 */
	public function testTheKnownExtensionContextIsStillVerified(): void {
		$note = $this->note();
		$note['@context'] = [ACore::CONTEXT_ACTIVITYSTREAMS, ACore::CONTEXT_SECURITY, ACore::CONTEXT_EXTENSIONS];
		$note['sensitive'] = false;
		$signature = new LinkedDataSignature();
		$signature->setType('RsaSignature2017')
			->setCreator('https://cloud.example.org/apps/social/@alice#main-key')
			->setCreated('2024-05-01T12:00:00Z')
			->setPrivateKey(self::$privateKey)
			->setObject($note);
		$signature->sign();
		$note['signature'] = $signature->jsonSerialize();

		$received = new LinkedDataSignature();
		$received->import($note);
		$received->setPublicKey(self::$publicKey);

		$this->assertTrue($received->verify());
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function refusedContexts(): array {
		return [
			'a term redefined to null' => [[ACore::CONTEXT_ACTIVITYSTREAMS, ['type' => null]]],
			'a keyword alias' => [[ACore::CONTEXT_ACTIVITYSTREAMS, ['kind' => '@type']]],
			'a known term with another definition' => [[ACore::CONTEXT_ACTIVITYSTREAMS, ['sensitive' => 'as:content']]],
			'the as prefix redefined' => [[ACore::CONTEXT_ACTIVITYSTREAMS, ['as' => 'https://evil.example/ns#']]],
			'a context that remaps name and url' => [[ACore::CONTEXT_ACTIVITYSTREAMS, 'https://w3id.org/identity/v1']],
			'a vocabulary' => [[ACore::CONTEXT_ACTIVITYSTREAMS, ['@vocab' => 'https://evil.example/ns#']]],
		];
	}

	#[DataProvider('refusedContexts')]
	public function testADocumentWithAnUnknownContextIsNotVerified(mixed $context): void {
		$this->assertFalse(LinkedDataSignature::usesOnlyKnownContexts(['@context' => $context, 'type' => 'Note']));
	}

	public function testANestedContextIsCheckedToo(): void {
		$this->assertFalse(LinkedDataSignature::usesOnlyKnownContexts([
			'@context' => ACore::CONTEXT_ACTIVITYSTREAMS,
			'type' => 'Create',
			'object' => ['@context' => ['type' => null], 'type' => 'Note'],
		]));
	}

	public function testAKeywordKeyOtherThanTheContextIsRefused(): void {
		$this->assertFalse(LinkedDataSignature::usesOnlyKnownContexts([
			'@context' => ACore::CONTEXT_ACTIVITYSTREAMS,
			'@type' => 'Create',
			'type' => 'Delete',
		]));
	}

	/**
	 * The same signature bytes in another base64 spelling are not a different
	 * signature: only the canonical, padded form is accepted.
	 */
	public function testANonCanonicalSpellingOfTheSignatureIsNotVerified(): void {
		$signed = $this->signedNote();
		$value = $signed->getSignatureValue();
		foreach ([rtrim($value, '='), $value . "\n", ' ' . $value] as $variant) {
			$document = $this->note();
			$document['signature'] = ['signatureValue' => $variant] + $signed->jsonSerialize();
			$received = new LinkedDataSignature();
			$received->import($document);
			$received->setPublicKey(self::$publicKey);

			$this->assertFalse($received->verify(), json_encode($variant));
		}
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
