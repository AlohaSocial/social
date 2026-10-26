<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Protocol;

/**
 * The Merkle search tree of a repository, built whole from the records.
 *
 * A key's layer is the number of leading zero bit-pairs of its SHA-256
 * (fanout 4). A node holds the entries of one layer, sorted, with the keys
 * of lower layers in sub-trees between them; keys are prefix-compressed
 * against the previous entry. The root is the node of the highest layer
 * present; an empty tree is one node with no entries.
 *
 * Built whole when a repository is imported or checked; a commit edits
 * the stored tree in place through MstEditor, which leaves the same nodes
 * this would build. Pinned by the interop vectors.
 */
final class Mst {
	/**
	 * @param array<string, Cid> $leaves record path => record CID
	 */
	public function __construct(
		private readonly array $leaves,
	) {
	}

	/**
	 * Builds the tree.
	 *
	 * @return MstTree the root and every node block, keyed by CID string
	 */
	public function build(): MstTree {
		$keys = array_map('strval', array_keys($this->leaves));
		sort($keys, SORT_STRING);

		$blocks = [];
		if ($keys === []) {
			$root = self::store($blocks, ['l' => null, 'e' => []]);

			return new MstTree($root, $blocks);
		}

		$layers = [];
		$top = 0;
		foreach ($keys as $key) {
			$layers[$key] = self::layer($key);
			$top = max($top, $layers[$key]);
		}
		$root = $this->node($keys, $layers, $top, $blocks);

		return new MstTree($root, $blocks);
	}

	/**
	 * The layer a key sits on.
	 */
	public static function layer(string $key): int {
		$hash = hash('sha256', $key, true);
		$zeros = 0;
		for ($i = 0, $n = strlen($hash); $i < $n; $i++) {
			$byte = ord($hash[$i]);
			if ($byte < 64) {
				$zeros++;
			}
			if ($byte < 16) {
				$zeros++;
			}
			if ($byte < 4) {
				$zeros++;
			}
			if ($byte !== 0) {
				break;
			}
			$zeros++;
		}

		return $zeros;
	}

	/**
	 * `<collection>/<rkey>`: an NSID, a slash, and a record key. ATProto allows
	 * record keys up to 512 ASCII characters. The tree itself takes any bytes
	 * (the interop vectors use short ones); the repository checks every path it
	 * writes against this.
	 */
	public static function isValidKey(string $key): bool {
		if (substr_count($key, '/') !== 1) {
			return false;
		}
		[$collection, $rkey] = explode('/', $key);

		return Syntax::isNsid($collection) && Syntax::isRecordKey($rkey);
	}

	/**
	 * @param string[] $keys sorted keys that belong under this node
	 * @param array<string, int> $layers every key's layer
	 * @param array<string, string> $blocks filled with the node bytes
	 */
	private function node(array $keys, array $layers, int $layer, array &$blocks): Cid {
		$entries = [];
		$left = null;
		$pending = [];
		$previous = '';
		foreach ($keys as $key) {
			if ($layers[$key] < $layer) {
				$pending[] = $key;
				continue;
			}
			$subtree = $pending === [] ? null : $this->node($pending, $layers, $layer - 1, $blocks);
			$pending = [];
			if ($entries === []) {
				$left = $subtree;
			} else {
				$entries[count($entries) - 1]['t'] = $subtree;
			}
			$shared = self::commonPrefixLength($previous, $key);
			$entries[] = [
				'p' => $shared,
				'k' => new Bytes(substr($key, $shared)),
				'v' => $this->leaves[$key],
				't' => null,
			];
			$previous = $key;
		}
		if ($pending !== []) {
			$subtree = $this->node($pending, $layers, $layer - 1, $blocks);
			if ($entries === []) {
				$left = $subtree;
			} else {
				$entries[count($entries) - 1]['t'] = $subtree;
			}
		}

		return self::store($blocks, ['l' => $left, 'e' => $entries]);
	}

	public static function commonPrefixLength(string $a, string $b): int {
		$max = min(strlen($a), strlen($b));
		for ($i = 0; $i < $max; $i++) {
			if ($a[$i] !== $b[$i]) {
				return $i;
			}
		}

		return $max;
	}

	/**
	 * @param array<string, string> $blocks
	 */
	private static function store(array &$blocks, array $node): Cid {
		$bytes = DagCbor::encode($node);
		$cid = Cid::forDagCbor($bytes);
		$blocks[$cid->toString()] = $bytes;

		return $cid;
	}
}
