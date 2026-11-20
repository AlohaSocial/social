<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command\Alias;

/**
 * `occ social:benchmark`, an alias of `social:debug:benchmark` hidden from
 * `occ list`. A subclass rather than `setAliases()`, which `occ list` prints.
 */
class Benchmark extends \OCA\Social\Command\Benchmark {
	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:benchmark')
			->setDescription('Former name of social:debug:benchmark')
			->setHidden(true);
	}
}
