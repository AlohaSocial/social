<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Security\PrivateKeyCipher;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\InstanceActorService;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The instance's own `Application` actor: the identity outbound fetches are
 * signed as, in place of the oldest local person whose key used to be borrowed.
 */
class InstanceActorServiceTest extends TestCase {
	private const SOCIAL_URL = 'https://cloud.example.com/apps/social/';
	private const ACTOR_ID = self::SOCIAL_URL . 'actor';

	/** @var array<string, string> the app config, in memory */
	private array $appConfig = [];

	/** @var ConfigService&Stub */
	private $configService;

	protected function setUp(): void {
		$this->appConfig = [];

		$this->configService = $this->createStub(ConfigService::class);
		$this->configService->method('getSocialUrl')->willReturn(self::SOCIAL_URL);
		$this->configService->method('getSocialAddress')->willReturn('cloud.example.com');
		$this->configService->method('getAppValue')
			->willReturnCallback(fn (string $key): string => $this->appConfig[$key] ?? '');
		$this->configService->method('setAppValue')
			->willReturnCallback(function (string $key, string $value): void {
				$this->appConfig[$key] = $value;
			});
	}

	private function service(): InstanceActorService {
		// a real cipher over a reversible stand-in for the instance secret, so
		// the test can tell a sealed value from a bare one
		$crypto = $this->createStub(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(static fn (string $v): string => 'sealed:' . $v);
		$crypto->method('decrypt')->willReturnCallback(
			static fn (string $v): string => str_starts_with($v, 'sealed:') ? substr($v, 7) : ''
		);

		return new InstanceActorService(
			$this->configService,
			new PrivateKeyCipher($crypto, new NullLogger()),
			new NullLogger()
		);
	}

	public function testTheActorIdIsTheOneMastodonServes(): void {
		$this->assertSame(self::ACTOR_ID, $this->service()->getId());
	}

	/**
	 * A peer checks a signature by dereferencing `keyId`, so the fragment the
	 * signature names and the `publicKey.id` in the document have to be one
	 * string.
	 */
	public function testTheDocumentPublishesTheKeyUnderTheKeyIdASignatureNames(): void {
		$actor = $this->service()->getActor();
		$document = $actor->exportAsActivityPub();

		$this->assertSame(self::ACTOR_ID . '#main-key', $actor->getKeyId());
		$this->assertSame(self::ACTOR_ID . '#main-key', $document['publicKey']['id']);
		$this->assertSame(self::ACTOR_ID, $document['publicKey']['owner']);
		$this->assertSame($actor->getPublicKey(), $document['publicKey']['publicKeyPem']);
		$this->assertStringContainsString('BEGIN PUBLIC KEY', $document['publicKey']['publicKeyPem']);
	}

	public function testTheDocumentIsAnApplicationAndNotAPerson(): void {
		$document = $this->service()->getActor()->exportAsActivityPub();

		$this->assertSame('Application', $document['type']);
		$this->assertSame(self::ACTOR_ID, $document['id']);
		$this->assertSame(self::ACTOR_ID, $document['url']);
		$this->assertSame('cloud.example.com', $document['preferredUsername']);
		$this->assertContains('https://www.w3.org/ns/activitystreams', $document['@context']);
		$this->assertContains('https://w3id.org/security/v1', $document['@context']);
	}

	/**
	 * An actor document naming a collection no route serves is worse than one
	 * that omits it: a peer that dereferences `outbox` gets a 404 and may
	 * conclude the actor is gone.
	 */
	public function testTheDocumentNamesNoCollectionThisInstanceDoesNotServe(): void {
		$document = $this->service()->getActor()->exportAsActivityPub();

		$this->assertArrayNotHasKey('outbox', $document);
		$this->assertArrayNotHasKey('followers', $document);
		$this->assertArrayNotHasKey('following', $document);
		$this->assertArrayNotHasKey('featured', $document);
	}

	/**
	 * Mastodon drops a fetched actor whose `inbox` is blank, and would then
	 * have no key to check our signature against.
	 */
	public function testTheActorHasAnInboxThatIsAlreadyRouted(): void {
		$document = $this->service()->getActor()->exportAsActivityPub();

		$this->assertSame(self::SOCIAL_URL . 'inbox', $document['inbox']);
		$this->assertSame(self::SOCIAL_URL . 'inbox', $document['endpoints']['sharedInbox']);
	}

	/** Nothing here accepts a follow, and nothing should offer a Follow button. */
	public function testTheActorIsNotOfferedAsSomebodyToFollowOrToIndex(): void {
		$document = $this->service()->getActor()->exportAsActivityPub();

		$this->assertTrue($document['manuallyApprovesFollowers']);
		$this->assertFalse($document['discoverable']);
		$this->assertFalse($document['indexable']);
	}

	/** It is not an account: nothing in the client API should ever return it. */
	public function testTheActorHasNoClientFacingForm(): void {
		$this->assertSame([], $this->service()->getActor()->exportAsLocal());
	}

	public function testTheKeyPairIsGeneratedOnFirstUseAndKeptAfterwards(): void {
		$first = $this->service()->getActor();
		$stored = $this->appConfig;

		// a second service, so nothing is answered from the in-process cache
		$second = $this->service()->getActor();

		$this->assertSame($first->getPublicKey(), $second->getPublicKey());
		$this->assertSame($first->getPrivateKey(), $second->getPrivateKey());
		$this->assertSame($stored, $this->appConfig, 'the stored pair was rewritten');
	}

	/**
	 * A config dump alone must not be enough to sign as this server, which is
	 * the same rule `oc_social_actor` private keys follow.
	 */
	public function testThePrivateKeyIsStoredSealed(): void {
		$actor = $this->service()->getActor();

		$raw = $this->appConfig[InstanceActorService::CONFIG_PRIVATE_KEY];
		// `-----BEGIN` is the very prefix PrivateKeyCipher reads as "stored
		// before encryption existed", so a value carrying it is a bare key
		$this->assertStringStartsNotWith('-----BEGIN', $raw);
		$this->assertSame('sealed:' . $actor->getPrivateKey(), $raw);
		$this->assertStringStartsWith(
			'-----BEGIN PUBLIC KEY',
			$this->appConfig[InstanceActorService::CONFIG_PUBLIC_KEY]
		);
	}

	/** The published key has to be the one a peer can verify a signature with. */
	public function testTheStoredPairSignsAndVerifiesAgainstThePublishedKey(): void {
		$actor = $this->service()->getSigningActor();

		$this->assertNotNull($actor);
		$this->assertTrue(openssl_sign('payload', $signed, $actor->getPrivateKey(), OPENSSL_ALGO_SHA256));
		$this->assertSame(
			1,
			openssl_verify('payload', $signed, $actor->getPublicKey(), OPENSSL_ALGO_SHA256)
		);
	}

	/**
	 * Before the app knows its own URL there is no id to build a keyId from.
	 * A failure to sign must not become a failure to fetch.
	 */
	public function testWithoutASocialUrlThereIsNobodyToSignAs(): void {
		$configService = $this->createStub(ConfigService::class);
		$configService->method('getSocialUrl')
			->willThrowException(new SocialAppConfigException());
		$crypto = $this->createStub(ICrypto::class);

		$service = new InstanceActorService(
			$configService, new PrivateKeyCipher($crypto, new NullLogger()), new NullLogger()
		);

		$this->assertNull($service->getSigningActor());
	}

	/**
	 * An undecryptable stored key — the instance secret changed — yields '' from
	 * the cipher. Signing with an empty key sends base64('') as the signature,
	 * which every peer rejects while looking like a working instance from here.
	 */
	public function testAnUnusableStoredKeyIsReplacedRatherThanSignedWith(): void {
		$this->appConfig = [
			InstanceActorService::CONFIG_PUBLIC_KEY => 'stale public key',
			InstanceActorService::CONFIG_PRIVATE_KEY => 'not decryptable',
		];

		$actor = $this->service()->getSigningActor();

		$this->assertNotNull($actor);
		$this->assertNotSame('', $actor->getPrivateKey());
		$this->assertStringContainsString('PRIVATE KEY', $actor->getPrivateKey());
		$this->assertNotSame('stale public key', $actor->getPublicKey());
	}
}
