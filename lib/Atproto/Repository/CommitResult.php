<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Repository;

use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\Syntax;

/**
 * What a commit produced: its CID and revision, the firehose sequence
 * number, and the CID of every record it wrote, by path.
 */
final class CommitResult {
	/**
	 * @param array<string, Cid> $written path => record CID
	 */
	public function __construct(
		public readonly string $did,
		public readonly Cid $commitCid,
		public readonly string $rev,
		public readonly int $seq,
		public readonly array $written,
	) {
	}

	/** the AT URI of a record this commit wrote */
	public function uriOf(string $path): string {
		[$collection, $rkey] = explode('/', $path, 2);

		return Syntax::atUri($this->did, $collection, $rkey);
	}

	public function cidOf(string $path): ?Cid {
		return $this->written[$path] ?? null;
	}
}
