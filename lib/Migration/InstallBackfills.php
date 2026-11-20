<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * The backfills a fresh install would otherwise never run: Nextcloud installs
 * an app by running its migrations schema-only, so `postSchemaChange()` does
 * not raise the flags the fast queries wait for (`dest_nid_filled`,
 * `stream_archived_filled`, `stream_media_kind_filled`) or queue the jobs that
 * raise `search_index_ready` and `stream_author_hosts_filled`. The tables are
 * empty, so the flags go up at once or on the jobs' first run.
 */
class InstallBackfills implements IRepairStep {
	public function __construct(
		private Version1000Date20260917000001 $recipientNids,
		private Version1000Date20261008000401 $searchIndex,
		private Version1000Date20261008000503 $authorHosts,
		private Version1000Date20261013100500 $postColumns,
	) {
	}

	#[\Override]
	public function getName(): string {
		return 'Run the backfills a fresh install skips';
	}

	#[\Override]
	public function run(IOutput $output): void {
		$this->recipientNids->fill($output);
		$this->postColumns->fill($output);
		$this->searchIndex->fill();
		$this->authorHosts->fill();
	}
}
