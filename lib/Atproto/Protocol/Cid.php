<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Protocol;

use InvalidArgumentException;

/**
 * A content identifier as AT Protocol restricts it: CIDv1, codec dag-cbor
 * (0x71) for nodes and records or raw (0x55) for blobs, SHA-256 multihash,
 * base32 lower-case string form with the 'b' multibase prefix.
 */
final class Cid {
	public const CODEC_DAG_CBOR = 0x71;
	public const CODEC_RAW = 0x55;

	/** version 1, codec, sha2-256 (0x12), 32 bytes (0x20) */
	private const PREFIX_DAG_CBOR = "\x01\x71\x12\x20";
	private const PREFIX_RAW = "\x01\x55\x12\x20";
	private const LENGTH = 36;

	private function __construct(
		private readonly string $bytes,
	) {
	}

	public static function forDagCbor(string $encoded): self {
		return new self(self::PREFIX_DAG_CBOR . hash('sha256', $encoded, true));
	}

	public static function forRaw(string $blob): self {
		return new self(self::PREFIX_RAW . hash('sha256', $blob, true));
	}

	/**
	 * @param string $bytes the binary CID (36 bytes), e.g. from a CAR file or a CBOR link
	 * @throws InvalidArgumentException when it is not a CID this protocol allows
	 */
	public static function fromBytes(string $bytes): self {
		if (strlen($bytes) !== self::LENGTH
			|| (!str_starts_with($bytes, self::PREFIX_DAG_CBOR) && !str_starts_with($bytes, self::PREFIX_RAW))) {
			throw new InvalidArgumentException('Not a CIDv1 sha-256 of dag-cbor or raw');
		}

		return new self($bytes);
	}

	/**
	 * @throws InvalidArgumentException
	 */
	public static function parse(string $text): self {
		if (strlen($text) !== 59 || $text[0] !== 'b') {
			throw new InvalidArgumentException('Not a base32 CID: ' . $text);
		}

		return self::fromBytes(Encoding::base32Decode(substr($text, 1)));
	}

	public static function isValid(string $text): bool {
		try {
			self::parse($text);

			return true;
		} catch (InvalidArgumentException) {
			return false;
		}
	}

	public function toString(): string {
		return 'b' . Encoding::base32Encode($this->bytes);
	}

	public function __toString(): string {
		return $this->toString();
	}

	public function bytes(): string {
		return $this->bytes;
	}

	public function codec(): int {
		return ord($this->bytes[1]);
	}

	/** the 32-byte SHA-256 digest */
	public function digest(): string {
		return substr($this->bytes, 4);
	}

	public function equals(Cid $other): bool {
		return $this->bytes === $other->bytes;
	}
}
