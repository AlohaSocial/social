<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use OCA\Social\Atproto\Firehose\EventStore;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/** Fresh app installs migrate schema only and skip data migration callbacks. */
class InitializeAtprotoClock implements IRepairStep {
	public function __construct(
		private readonly EventStore $events,
	) {
	}
	#[\Override]
	public function getName(): string {
		return 'Initialize the native PDS firehose sequence allocator';
	}
	#[\Override]
	public function run(IOutput $output): void {
		$this->events->initialize();
	}
}
