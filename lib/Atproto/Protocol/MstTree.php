<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Protocol;

/**
 * A built tree: its root and the DAG-CBOR bytes of every node.
 */
final class MstTree {
	/**
	 * @param array<string, string> $blocks node CID string => node bytes
	 */
	public function __construct(
		public readonly Cid $root,
		public readonly array $blocks,
	) {
	}

	/**
	 * The nodes this tree has that $previous does not: what a commit adds to
	 * the block store and carries in its firehose frame.
	 *
	 * @return array<string, string>
	 */
	public function blocksNotIn(?MstTree $previous): array {
		return $previous === null ? $this->blocks : array_diff_key($this->blocks, $previous->blocks);
	}

	/**
	 * The CIDs of nodes $previous had that this tree no longer has.
	 *
	 * @return string[]
	 */
	public function cidsDroppedFrom(?MstTree $previous): array {
		return $previous === null ? [] : array_keys(array_diff_key($previous->blocks, $this->blocks));
	}
}
