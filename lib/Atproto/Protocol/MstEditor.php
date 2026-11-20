<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Protocol;

use Closure;
use InvalidArgumentException;

/**
 * A stored tree edited in place: only the nodes on the path to each written key
 * are read and encoded again, so a commit costs the depth of the tree, not the
 * size of the repository. The result is node for node the tree `Mst::build()`
 * makes from the same records, which the tests pin.
 */
final class MstEditor {
	private const MAX_DEPTH = 64;

	private MstNode $root;
	/** @var array<string, true> the CIDs of the stored nodes read */
	private array $loaded = [];
	private bool $whole = false;

	/**
	 * @param Closure(string): ?string $read a stored node's bytes by CID string, null when there is none
	 */
	private function __construct(
		private readonly Closure $read,
	) {
		$this->root = new MstNode(0, null, []);
	}

	/**
	 * The tree at $root, read from the store as needed.
	 *
	 * @param callable(string): ?string $read a stored node's bytes by CID string, null when there is none
	 * @throws InvalidArgumentException when the root is missing or malformed
	 */
	public static function open(?Cid $root, callable $read): self {
		$editor = new self($read(...));
		if ($root !== null) {
			$editor->root = $editor->load($root, null, 0);
		}

		return $editor;
	}

	/**
	 * A tree built whole in memory, so that `close()` encodes every node:
	 * what a store whose nodes cannot be trusted is given instead.
	 */
	public static function whole(MstTree $tree): self {
		$blocks = $tree->blocks;
		$editor = self::open($tree->root, static fn (string $cid): ?string => $blocks[$cid] ?? null);
		$editor->whole = true;

		return $editor;
	}

	/**
	 * @throws InvalidArgumentException when a node on the way is missing or malformed
	 */
	public function get(string $key): ?Cid {
		$node = $this->root;
		for ($depth = 0; $depth <= self::MAX_DEPTH; $depth++) {
			$i = $node->indexOf($key);
			if (isset($node->entries[$i]) && $node->entries[$i]['key'] === $key) {
				return $node->entries[$i]['value'];
			}
			$below = $node->treeBefore($i);
			if ($below === null) {
				return null;
			}
			$node = $this->load($below, $node->layer - 1, $depth + 1);
		}

		throw new InvalidArgumentException('The tree is deeper than a repository can be');
	}

	/**
	 * Sets the key's value, adding the key when it is new.
	 *
	 * @throws InvalidArgumentException when a node on the way is missing or malformed
	 */
	public function put(string $key, Cid $value): void {
		if ($this->root->isEmpty()) {
			$this->root->layer = 0;
		}
		$this->root = $this->add($this->root, $key, $value, Mst::layer($key), 0);
	}

	/**
	 * @throws InvalidArgumentException when there is no such key, or a node on the way is missing or malformed
	 */
	public function delete(string $key): void {
		$root = $this->remove($this->root, $key, 0);
		while ($root->entries === [] && $root->left !== null) {
			$root = $this->load($root->left, $root->layer - 1, 0);
		}
		$this->root = $root;
	}

	/**
	 * Encodes what was changed.
	 *
	 * @return MstTree the root and the nodes encoded: those read and
	 *                 rewritten, plus the new ones; nodes never read are not among them
	 */
	public function close(): MstTree {
		$blocks = [];
		$root = $this->encode($this->root, $blocks);

		return new MstTree($root, $blocks);
	}

	/**
	 * The CIDs of the stored nodes that were read, so a caller can tell
	 * the nodes `close()` encoded again from the new ones, and the ones it
	 * dropped.
	 *
	 * @return array<string, true>
	 */
	public function loaded(): array {
		return $this->loaded;
	}

	private function add(MstNode $node, string $key, Cid $value, int $keyLayer, int $depth): MstNode {
		if ($keyLayer > $node->layer) {
			// above the root: the tree is split around the key and hung
			// under a new root, with an empty node for every layer skipped
			[$left, $right] = $this->splitAround($node, $key, $depth);
			for ($layer = $node->layer + 1; $layer < $keyLayer; $layer++) {
				$left = $left === null ? null : new MstNode($layer, $left, []);
				$right = $right === null ? null : new MstNode($layer, $right, []);
			}

			return new MstNode($keyLayer, $left, [['key' => $key, 'value' => $value, 'tree' => $right]]);
		}
		$i = $node->indexOf($key);
		if ($keyLayer === $node->layer) {
			if (isset($node->entries[$i]) && $node->entries[$i]['key'] === $key) {
				$node->entries[$i]['value'] = $value;

				return $node;
			}
			$below = $node->treeBefore($i);
			$right = null;
			if ($below !== null) {
				[$left, $right] = $this->splitAround($this->load($below, $node->layer - 1, $depth + 1), $key, $depth + 1);
				$node->setTreeBefore($i, $left);
			}
			array_splice($node->entries, $i, 0, [['key' => $key, 'value' => $value, 'tree' => $right]]);

			return $node;
		}
		$below = $node->treeBefore($i);
		$child = $below === null ? new MstNode($node->layer - 1, null, []) : $this->load($below, $node->layer - 1, $depth + 1);
		$node->setTreeBefore($i, $this->add($child, $key, $value, $keyLayer, $depth + 1));

		return $node;
	}

	/**
	 * The node cut in two at the key's place, the cut going on down the
	 * sub-tree that straddles it; a side with nothing in it is null.
	 *
	 * @return array{0: MstNode|null, 1: MstNode|null}
	 */
	private function splitAround(MstNode $node, string $key, int $depth): array {
		$i = $node->indexOf($key);
		$left = new MstNode($node->layer, $node->left, array_slice($node->entries, 0, $i));
		$right = new MstNode($node->layer, null, array_slice($node->entries, $i));
		$straddling = $node->treeBefore($i);
		if ($straddling !== null) {
			[$belowLeft, $belowRight] = $this->splitAround($this->load($straddling, $node->layer - 1, $depth + 1), $key, $depth + 1);
			$left->setTreeBefore($i, $belowLeft);
			$right->left = $belowRight;
		}

		return [$left->isEmpty() ? null : $left, $right->isEmpty() ? null : $right];
	}

	/**
	 * @throws InvalidArgumentException when there is no such key
	 */
	private function remove(MstNode $node, string $key, int $depth): MstNode {
		$i = $node->indexOf($key);
		if (isset($node->entries[$i]) && $node->entries[$i]['key'] === $key) {
			$before = $node->treeBefore($i);
			$after = $node->entries[$i]['tree'];
			array_splice($node->entries, $i, 1);
			if ($before !== null && $after !== null) {
				$node->setTreeBefore($i, $this->merge(
					$this->load($before, $node->layer - 1, $depth + 1),
					$this->load($after, $node->layer - 1, $depth + 1),
					$depth + 1,
				));
			} elseif ($after !== null) {
				$node->setTreeBefore($i, $after);
			}

			return $node;
		}
		$below = $node->treeBefore($i);
		if ($below === null) {
			throw new InvalidArgumentException('No key ' . $key . ' in the tree');
		}
		$child = $this->remove($this->load($below, $node->layer - 1, $depth + 1), $key, $depth + 1);
		$node->setTreeBefore($i, $child->isEmpty() ? null : $child);

		return $node;
	}

	/**
	 * Two nodes of one layer, every key of $a below every key of $b, as
	 * one: where $a's last sub-tree meets $b's first, those merge too.
	 */
	private function merge(MstNode $a, MstNode $b, int $depth): MstNode {
		$merged = new MstNode($a->layer, $a->left, [...$a->entries, ...$b->entries]);
		$seam = count($a->entries);
		$lastOfA = $a->treeBefore($seam);
		if ($lastOfA !== null && $b->left !== null) {
			$merged->setTreeBefore($seam, $this->merge(
				$this->load($lastOfA, $a->layer - 1, $depth + 1),
				$this->load($b->left, $a->layer - 1, $depth + 1),
				$depth + 1,
			));
		} elseif ($b->left !== null) {
			$merged->setTreeBefore($seam, $b->left);
		}

		return $merged;
	}

	/**
	 * @param int|null $layer the layer the node is known to be on; null for a root, which says itself
	 * @throws InvalidArgumentException when the node is missing or malformed
	 */
	private function load(MstNode|Cid $node, ?int $layer, int $depth): MstNode {
		if ($node instanceof MstNode) {
			return $node;
		}
		if ($depth > self::MAX_DEPTH) {
			throw new InvalidArgumentException('The tree is deeper than a repository can be');
		}
		$cid = $node->toString();
		$bytes = ($this->read)($cid);
		if ($bytes === null) {
			throw new InvalidArgumentException('Tree node ' . $cid . ' is missing');
		}
		$decoded = DagCbor::decode($bytes);
		if (!is_array($decoded) || !array_key_exists('e', $decoded) || !is_array($decoded['e'])
			|| !(($decoded['l'] ?? null) instanceof Cid || ($decoded['l'] ?? null) === null)) {
			throw new InvalidArgumentException('Not a tree node: ' . $cid);
		}
		$entries = [];
		$previous = '';
		foreach ($decoded['e'] as $entry) {
			$prefix = is_array($entry) ? ($entry['p'] ?? null) : null;
			$suffix = is_array($entry) ? ($entry['k'] ?? null) : null;
			$value = is_array($entry) ? ($entry['v'] ?? null) : null;
			$tree = is_array($entry) ? ($entry['t'] ?? null) : null;
			if (!is_int($prefix) || $prefix < 0 || $prefix > strlen($previous) || !$suffix instanceof Bytes || !$value instanceof Cid
				|| !($tree instanceof Cid || $tree === null)) {
				throw new InvalidArgumentException('Not a tree entry in ' . $cid);
			}
			$key = substr($previous, 0, $prefix) . $suffix->value;
			if (strcmp($key, $previous) <= 0 && $entries !== []) {
				throw new InvalidArgumentException('Tree keys out of order in ' . $cid);
			}
			$entries[] = ['key' => $key, 'value' => $value, 'tree' => $tree];
			$previous = $key;
		}
		$this->loaded[$cid] = true;
		if ($entries !== []) {
			$layer = Mst::layer($entries[0]['key']);
		} elseif ($layer === null) {
			// a root without entries: as deep as what hangs under it, plus one
			$layer = $decoded['l'] === null ? 0 : $this->load($decoded['l'], null, $depth + 1)->layer + 1;
		}

		return new MstNode($layer, $decoded['l'], $entries);
	}

	/**
	 * @param array<string, string> $blocks filled with the node bytes
	 */
	private function encode(MstNode $node, array &$blocks): Cid {
		$left = $this->encodeBelow($node->left, $node->layer - 1, $blocks);
		$entries = [];
		$previous = '';
		foreach ($node->entries as $entry) {
			$shared = Mst::commonPrefixLength($previous, $entry['key']);
			$entries[] = [
				'p' => $shared,
				'k' => new Bytes(substr($entry['key'], $shared)),
				'v' => $entry['value'],
				't' => $this->encodeBelow($entry['tree'], $node->layer - 1, $blocks),
			];
			$previous = $entry['key'];
		}
		$bytes = DagCbor::encode(['l' => $left, 'e' => $entries]);
		$cid = Cid::forDagCbor($bytes);
		$blocks[$cid->toString()] = $bytes;

		return $cid;
	}

	/**
	 * @param array<string, string> $blocks
	 */
	private function encodeBelow(MstNode|Cid|null $tree, int $layer, array &$blocks): ?Cid {
		if ($tree instanceof Cid && $this->whole) {
			$tree = $this->load($tree, $layer, 0);
		}

		return $tree instanceof MstNode ? $this->encode($tree, $blocks) : $tree;
	}
}
