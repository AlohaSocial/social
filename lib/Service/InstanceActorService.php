<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Exceptions\SignatureException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\ActivityPub\Actor\InstanceActor;
use OCA\Social\Security\PrivateKeyCipher;
use Psr\Log\LoggerInterface;

/**
 * The instance's own `Application` actor and its key pair, used to sign
 * outbound fetches so no person's account is the instance's public face and
 * an instance without accounts can still sign.
 *
 * The key pair lives in the app config rather than `oc_social_actor`: a row
 * there is a local account, listed, resolved by WebFinger and counted, which
 * the instance actor must not be.
 */
class InstanceActorService {
	/** The last path segment of the actor's id: `<social url>actor`, as Mastodon serves it. */
	public const PATH = 'actor';

	public const CONFIG_PUBLIC_KEY = 'instance_actor_public_key';
	public const CONFIG_PRIVATE_KEY = 'instance_actor_private_key';

	private ?InstanceActor $actor = null;

	public function __construct(
		private ConfigService $configService,
		private PrivateKeyCipher $keyCipher,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The actor id, which is also what a signature's `keyId` is built from and
	 * the URL a peer dereferences to check one.
	 *
	 * @throws SocialAppConfigException before the app knows its own URL
	 */
	public function getId(): string {
		return $this->root() . self::PATH;
	}

	/**
	 * The actor document, generating the key pair on first use.
	 *
	 * @throws SocialAppConfigException
	 * @throws SignatureException when no key pair can be produced
	 */
	public function getActor(): InstanceActor {
		if ($this->actor !== null) {
			return $this->actor;
		}

		[$publicKey, $privateKey] = $this->keyPair();

		$actor = new InstanceActor();
		$actor->setId($this->getId())
			->setUrl($this->getId())
			->setPreferredUsername($this->configService->getSocialAddress())
			->setName($this->configService->getSocialAddress())
			->setInbox($this->root() . 'inbox')
			->setSharedInbox($this->root() . 'inbox')
			->setPublicKey($publicKey)
			->setPrivateKey($privateKey)
			->setLocal(true);

		return $this->actor = $actor;
	}

	/**
	 * The actor to sign an outbound fetch as, or null when this instance cannot
	 * produce one (no URL yet, or OpenSSL refused). Null sends the fetch
	 * unsigned: a failure to sign must not become a failure to fetch.
	 */
	public function getSigningActor(): ?InstanceActor {
		try {
			return $this->getActor();
		} catch (SocialAppConfigException|SignatureException $e) {
			$this->logger->warning('no instance actor to sign with', ['exception' => $e]);

			return null;
		}
	}

	/**
	 * @return array{string, string} the public and private key, in that order
	 * @throws SignatureException
	 */
	private function keyPair(): array {
		$stored = $this->storedKeyPair();
		if ($stored !== null) {
			return $stored;
		}

		return $this->generateKeyPair();
	}

	/** @return ?array{string, string} */
	private function storedKeyPair(): ?array {
		$publicKey = (string)$this->configService->getAppValue(self::CONFIG_PUBLIC_KEY);
		$privateKey = $this->keyCipher->open(
			(string)$this->configService->getAppValue(self::CONFIG_PRIVATE_KEY)
		);

		if ($publicKey === '' || $privateKey === '') {
			return null;
		}

		return [$publicKey, $privateKey];
	}

	/**
	 * Generates and stores the pair: `SignatureService::generateKeys()` written
	 * out, since calling it would be a constructor cycle. The private key is
	 * sealed with the instance secret, as an actor's is, so a config dump alone
	 * cannot sign as this server.
	 *
	 * @return array{string, string}
	 * @throws SignatureException
	 */
	private function generateKeyPair(): array {
		$res = openssl_pkey_new(
			['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]
		);
		if ($res === false || !openssl_pkey_export($res, $privateKey)) {
			throw new SignatureException(
				'cannot generate the instance actor key pair: ' . openssl_error_string()
			);
		}

		$details = openssl_pkey_get_details($res);
		if ($details === false || ($details['key'] ?? '') === '') {
			throw new SignatureException(
				'cannot export the instance actor public key: ' . openssl_error_string()
			);
		}

		$this->configService->setAppValue(self::CONFIG_PUBLIC_KEY, $details['key']);
		$this->configService->setAppValue(
			self::CONFIG_PRIVATE_KEY, $this->keyCipher->seal($privateKey)
		);

		// Two requests may race here on a fresh instance; both sign with the pair
		// written last, which is the one the actor document publishes.
		return $this->storedKeyPair() ?? [$details['key'], $privateKey];
	}

	/**
	 * @throws SocialAppConfigException
	 */
	private function root(): string {
		return rtrim($this->configService->getSocialUrl(), '/') . '/';
	}
}
