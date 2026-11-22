<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command\Alias;

/**
 * `occ social:note:create`, the former name of `social:debug:note:create`,
 * kept for existing scripts and hidden from `occ list`. A subclass, since
 * Symfony's `setAliases()` would list it.
 */
class NoteCreate extends \OCA\Social\Command\NoteCreate {
	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:note:create')
			->setDescription('Former name of social:debug:note:create')
			->setHidden(true);
	}
}
