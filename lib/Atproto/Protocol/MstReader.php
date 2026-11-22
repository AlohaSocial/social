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
 * every record path with its record's CID, in key order. The CAR comes from
 * another PDS and is untrusted, so keys that do not strictly ascend are
 * refused.
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
		$last = null;
		self::walk($root, $blocks, $leaves, $last, 0);

		return $leaves;
	}

	/**
	 * The nodes from the root down to the one that holds $key, or to where
	 * it would be: what proves a record is in the tree, without reading the
	 * rest of it.
	 *
	 * @param callable(Cid): ?string $block a node's bytes, null when it is missing
	 * @return array<string, string> CID => bytes, root first
	 * @throws InvalidArgumentException when a node is missing or malformed
	 */
	public static function path(Cid $root, string $key, callable $block): array {
		$nodes = [];
		$cid = $root;
		while ($cid !== null) {
			if (count($nodes) > self::MAX_DEPTH) {
				throw new InvalidArgumentException('The tree is deeper than a repository can be');
			}
			$bytes = $block($cid);
			if ($bytes === null) {
				throw new InvalidArgumentException('Tree node ' . $cid->toString() . ' is missing');
			}
			$nodes[$cid->toString()] = $bytes;
			$node = DagCbor::decode($bytes);
			if (!is_array($node) || !array_key_exists('e', $node) || !is_array($node['e'])) {
				throw new InvalidArgumentException('Not a tree node: ' . $cid->toString());
			}
			$next = ($node['l'] ?? null) instanceof Cid ? $node['l'] : null;
			$previous = '';
			foreach ($node['e'] as $entry) {
				$prefix = is_array($entry) ? ($entry['p'] ?? null) : null;
				$suffix = is_array($entry) ? ($entry['k'] ?? null) : null;
				if (!is_int($prefix) || $prefix < 0 || $prefix > strlen($previous) || !$suffix instanceof Bytes) {
					throw new InvalidArgumentException('Not a tree entry in ' . $cid->toString());
				}
				$previous = substr($previous, 0, $prefix) . $suffix->value;
				$order = strcmp($key, $previous);
				if ($order === 0) {
					return $nodes;
				}
				if ($order < 0) {
					break;
				}
				$next = ($entry['t'] ?? null) instanceof Cid ? $entry['t'] : null;
			}
			$cid = $next;
		}

		return $nodes;
	}

	/**
	 * @param array<string, string> $blocks
	 * @param array<string, Cid> $leaves
	 * @param string|null $last the key read before, null before the first
	 */
	private static function walk(Cid $cid, array $blocks, array &$leaves, ?string &$last, int $depth): void {
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
			self::walk($node['l'], $blocks, $leaves, $last, $depth + 1);
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
			if ($last !== null && strcmp($key, $last) <= 0) {
				throw new InvalidArgumentException('Tree keys out of order: ' . $key . ' after ' . $last);
			}
			$leaves[$key] = $value;
			$last = $key;
			$previous = $key;
			if (($entry['t'] ?? null) instanceof Cid) {
				self::walk($entry['t'], $blocks, $leaves, $last, $depth + 1);
			}
		}
	}
}
