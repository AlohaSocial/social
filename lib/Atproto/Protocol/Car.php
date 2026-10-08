<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Protocol;

use InvalidArgumentException;

/**
 * CAR v1: a varint-prefixed DAG-CBOR header naming the roots, then blocks,
 * each a varint of its length followed by the CID and the bytes. What
 * `getRepo` answers and what a firehose commit carries.
 */
final class Car {
	/**
	 * @param Cid[] $roots
	 * @param array<string, string> $blocks CID string => bytes, in the order to write them
	 */
	public static function encode(array $roots, array $blocks): string {
		$header = DagCbor::encode(['version' => 1, 'roots' => array_values($roots)]);
		$out = Encoding::varint(strlen($header)) . $header;
		foreach ($blocks as $cid => $bytes) {
			$cidBytes = Cid::parse($cid)->bytes();
			$out .= Encoding::varint(strlen($cidBytes) + strlen($bytes)) . $cidBytes . $bytes;
		}

		return $out;
	}

	/**
	 * Reads a CAR, checking that every block's bytes hash to its CID.
	 *
	 * @param int $maxBlocks a bound on untrusted input
	 * @return array{roots: Cid[], blocks: array<string, string>}
	 * @throws InvalidArgumentException
	 */
	public static function decode(string $car, int $maxBlocks = 100000): array {
		$offset = 0;
		$headerLength = Encoding::readVarint($car, $offset);
		$header = DagCbor::decode(self::slice($car, $offset, $headerLength));
		if (!is_array($header) || ($header['version'] ?? null) !== 1 || !is_array($header['roots'] ?? null)) {
			throw new InvalidArgumentException('Not a CAR v1 header');
		}
		$roots = [];
		foreach ($header['roots'] as $root) {
			if (!$root instanceof Cid) {
				throw new InvalidArgumentException('CAR root is not a CID');
			}
			$roots[] = $root;
		}

		$blocks = [];
		$length = strlen($car);
		while ($offset < $length) {
			if (count($blocks) >= $maxBlocks) {
				throw new InvalidArgumentException('CAR has more blocks than allowed');
			}
			$blockLength = Encoding::readVarint($car, $offset);
			if ($blockLength <= 36) {
				throw new InvalidArgumentException('CAR block too short');
			}
			$cid = Cid::fromBytes(self::slice($car, $offset, 36));
			$bytes = self::slice($car, $offset, $blockLength - 36);
			$expected = $cid->codec() === Cid::CODEC_RAW ? Cid::forRaw($bytes) : Cid::forDagCbor($bytes);
			if (!$expected->equals($cid)) {
				throw new InvalidArgumentException('CAR block does not match its CID');
			}
			$blocks[$cid->toString()] = $bytes;
		}

		return ['roots' => $roots, 'blocks' => $blocks];
	}

	private static function slice(string $bytes, int &$offset, int $length): string {
		if ($length < 0 || $offset + $length > strlen($bytes)) {
			throw new InvalidArgumentException('CAR ends early');
		}
		$slice = substr($bytes, $offset, $length);
		$offset += $length;

		return $slice;
	}
}
