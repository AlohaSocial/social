<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Protocol;

use InvalidArgumentException;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Crypto\PublicKey;

/**
 * A signed repository commit: version 3, the DID, the MST root, a TID
 * revision, `prev` null, and a 64-byte signature over the DAG-CBOR of the
 * rest.
 */
final class Commit {
	private function __construct(
		public readonly string $did,
		public readonly Cid $data,
		public readonly string $rev,
		public readonly string $signature,
	) {
	}

	public static function sign(string $did, Cid $data, string $rev, PrivateKey $key): self {
		$signature = $key->sign(DagCbor::encode(self::unsigned($did, $data, $rev)));

		return new self($did, $data, $rev, $signature);
	}

	/**
	 * Reads a commit block, checking its shape; the signature is checked by
	 * verify(), which needs the key.
	 *
	 * @throws InvalidArgumentException
	 */
	public static function fromBytes(string $bytes): self {
		$value = DagCbor::decode($bytes);
		if (!is_array($value)
			|| ($value['version'] ?? null) !== 3
			|| !is_string($value['did'] ?? null)
			|| !($value['data'] ?? null) instanceof Cid
			|| !is_string($value['rev'] ?? null) || !Tid::isValid($value['rev'])
			|| !array_key_exists('prev', $value) || $value['prev'] !== null
			|| !($value['sig'] ?? null) instanceof Bytes || strlen($value['sig']->value) !== 64) {
			throw new InvalidArgumentException('Not a version 3 commit');
		}

		return new self($value['did'], $value['data'], $value['rev'], $value['sig']->value);
	}

	public function verify(PublicKey $key): bool {
		return $key->verify(DagCbor::encode(self::unsigned($this->did, $this->data, $this->rev)), $this->signature);
	}

	public function toBytes(): string {
		return DagCbor::encode(self::unsigned($this->did, $this->data, $this->rev) + ['sig' => new Bytes($this->signature)]);
	}

	public function cid(): Cid {
		return Cid::forDagCbor($this->toBytes());
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function unsigned(string $did, Cid $data, string $rev): array {
		return ['did' => $did, 'version' => 3, 'data' => $data, 'rev' => $rev, 'prev' => null];
	}
}
