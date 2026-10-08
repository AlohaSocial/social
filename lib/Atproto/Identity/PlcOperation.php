<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Identity;

use InvalidArgumentException;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Crypto\PublicKey;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Protocol\Encoding;

/**
 * The operations a `did:plc` is made of: a signed object naming the
 * rotation keys, the signing key, the handle and the PDS, each pointing at
 * the previous one by CID. The DID itself is the hash of the first.
 */
final class PlcOperation {
	public const TYPE_OPERATION = 'plc_operation';
	public const TYPE_TOMBSTONE = 'plc_tombstone';
	public const PDS_SERVICE_TYPE = 'AtprotoPersonalDataServer';

	/**
	 * The unsigned operation.
	 *
	 * @param string[] $rotationKeys did:keys, highest authority first, at most five
	 * @param string $signingKey the repository signing key as a did:key
	 * @param string $handle the handle, without `at://`
	 * @param string $pdsEndpoint `https://host`
	 * @param string|null $prev the previous operation's CID, null for the genesis
	 */
	public static function build(array $rotationKeys, string $signingKey, string $handle, string $pdsEndpoint, ?string $prev): array {
		if ($rotationKeys === [] || count($rotationKeys) > 5) {
			throw new InvalidArgumentException('One to five rotation keys');
		}

		return [
			'type' => self::TYPE_OPERATION,
			'rotationKeys' => array_values($rotationKeys),
			'verificationMethods' => ['atproto' => $signingKey],
			'alsoKnownAs' => ['at://' . $handle],
			'services' => ['atproto_pds' => ['type' => self::PDS_SERVICE_TYPE, 'endpoint' => $pdsEndpoint]],
			'prev' => $prev,
		];
	}

	public static function tombstone(string $prev): array {
		return ['type' => self::TYPE_TOMBSTONE, 'prev' => $prev];
	}

	/**
	 * Signs with a rotation key: the signature is over the DAG-CBOR of the
	 * operation without `sig`, written base64url without padding.
	 */
	public static function sign(array $operation, PrivateKey $rotationKey): array {
		unset($operation['sig']);
		$operation['sig'] = Encoding::base64UrlEncode($rotationKey->sign(DagCbor::encode($operation)));

		return $operation;
	}

	/**
	 * Whether the operation's signature is by $key.
	 */
	public static function verify(array $operation, PublicKey $key): bool {
		if (!is_string($operation['sig'] ?? null)) {
			return false;
		}
		try {
			$signature = Encoding::base64UrlDecode($operation['sig']);
		} catch (InvalidArgumentException) {
			return false;
		}
		unset($operation['sig']);

		return $key->verify(DagCbor::encode($operation), $signature);
	}

	/** the CID of a signed operation, which the next one names as `prev` */
	public static function cid(array $signedOperation): Cid {
		return Cid::forDagCbor(DagCbor::encode($signedOperation));
	}

	/**
	 * The DID a genesis operation defines: the first 24 characters of the
	 * base32 SHA-256 of its DAG-CBOR.
	 */
	public static function didOf(array $signedGenesis): string {
		return 'did:plc:' . substr(Encoding::base32Encode(hash('sha256', DagCbor::encode($signedGenesis), true)), 0, 24);
	}

	/**
	 * The DID document the directory derives from an operation's state, as
	 * this app shows it and as the interop checks compare it.
	 */
	public static function document(string $did, array $operation): array {
		$document = [
			'@context' => ['https://www.w3.org/ns/did/v1', 'https://w3id.org/security/multikey/v1', 'https://w3id.org/security/suites/secp256k1-2019/v1'],
			'id' => $did,
			'alsoKnownAs' => $operation['alsoKnownAs'] ?? [],
			'verificationMethod' => [],
			'service' => [],
		];
		foreach ($operation['verificationMethods'] ?? [] as $name => $didKey) {
			$document['verificationMethod'][] = [
				'id' => $did . '#' . $name,
				'type' => 'Multikey',
				'controller' => $did,
				'publicKeyMultibase' => substr((string)$didKey, strlen('did:key:')),
			];
		}
		foreach ($operation['services'] ?? [] as $name => $service) {
			$document['service'][] = [
				'id' => '#' . $name,
				'type' => $service['type'] ?? '',
				'serviceEndpoint' => $service['endpoint'] ?? '',
			];
		}

		return $document;
	}
}
