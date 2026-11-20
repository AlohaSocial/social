<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command\Alias;

/**
 * `occ social:note:like`, an alias of `social:debug:note:like` hidden from
 * `occ list`. A subclass rather than `setAliases()`, which `occ list` prints.
 */
class NoteLike extends \OCA\Social\Command\NoteLike {
	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:note:like')
			->setDescription('Former name of social:debug:note:like')
			->setHidden(true);
	}
}
