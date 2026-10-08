<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Protocol;

use InvalidArgumentException;

/**
 * The leaves of a repository's Merkle Search Tree, read from its blocks:
 * every record path with the CID of its record, in key order. What a CAR
 * file of another PDS holds, when a repository moves here.
 */
final class MstReader {
	private const MAX_DEPTH = 64;

	/**
	 * @param array<string, string> $blocks CID => bytes
	 * @return array<string, Cid> path => record CID
	 * @throws InvalidArgumentException when a node is missing or malformed
	 */
	public static function leaves(Cid $root, array $blocks): array {
		$leaves = [];
		self::walk($root, $blocks, $leaves, 0);

		return $leaves;
	}

	/**
	 * @param array<string, string> $blocks
	 * @param array<string, Cid> $leaves
	 */
	private static function walk(Cid $cid, array $blocks, array &$leaves, int $depth): void {
		if ($depth > self::MAX_DEPTH) {
			throw new InvalidArgumentException('The tree is deeper than a repository can be');
		}
		$bytes = $blocks[$cid->toString()] ?? null;
		if ($bytes === null) {
			throw new InvalidArgumentException('Tree node ' . $cid->toString() . ' is missing');
		}
		$node = DagCbor::decode($bytes);
		if (!is_array($node) || !array_key_exists('e', $node) || !is_array($node['e'])) {
			throw new InvalidArgumentException('Not a tree node: ' . $cid->toString());
		}
		if (($node['l'] ?? null) instanceof Cid) {
			self::walk($node['l'], $blocks, $leaves, $depth + 1);
		}
		$previous = '';
		foreach ($node['e'] as $entry) {
			$prefix = is_array($entry) ? ($entry['p'] ?? null) : null;
			$suffix = is_array($entry) ? ($entry['k'] ?? null) : null;
			$value = is_array($entry) ? ($entry['v'] ?? null) : null;
			if (!is_int($prefix) || $prefix < 0 || $prefix > strlen($previous) || !$suffix instanceof Bytes || !$value instanceof Cid) {
				throw new InvalidArgumentException('Not a tree entry in ' . $cid->toString());
			}
			$key = substr($previous, 0, $prefix) . $suffix->value;
			if (!Mst::isValidKey($key)) {
				throw new InvalidArgumentException('Not a record path: ' . $key);
			}
			$leaves[$key] = $value;
			$previous = $key;
			if (($entry['t'] ?? null) instanceof Cid) {
				self::walk($entry['t'], $blocks, $leaves, $depth + 1);
			}
		}
	}
}
