<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Model;

use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Protocol\Syntax;

/**
 * A live record of a repository, as stored: its path, CID and bytes, and
 * the Social object it was made from.
 */
final class StoredRecord {
	public function __construct(
		public readonly string $did,
		public readonly string $collection,
		public readonly string $rkey,
		public readonly Cid $cid,
		public readonly string $bytes,
		public readonly string $localId,
		public readonly int $creation,
	) {
	}

	public function path(): string {
		return $this->collection . '/' . $this->rkey;
	}

	public function uri(): string {
		return Syntax::atUri($this->did, $this->collection, $this->rkey);
	}

	/** the record as a value, for the XRPC answers */
	public function value(): array {
		$value = DagCbor::decode($this->bytes);

		return is_array($value) ? $value : [];
	}
}
