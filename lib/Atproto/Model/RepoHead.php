<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Model;

/**
 * Where a repository currently is: its latest commit and revision.
 */
final class RepoHead {
	public function __construct(
		public readonly string $did,
		public readonly string $commitCid,
		public readonly string $rev,
		public readonly int $recordCount,
		public readonly int $blobBytes,
		public readonly int $updated,
	) {
	}
}
