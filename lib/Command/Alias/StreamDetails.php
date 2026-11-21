<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command\Alias;

/**
 * `occ social:details`, an alias of `social:debug:details` kept for existing
 * scripts. A subclass rather than Symfony's `setAliases()`, so that `occ list`
 * does not show it.
 */
class StreamDetails extends \OCA\Social\Command\StreamDetails {
	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:details')
			->setDescription('Former name of social:debug:details')
			->setHidden(true);
	}
}
