<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command\Alias;

/**
 * `occ social:note:create`, the name `social:debug:note:create` had before the debugging commands
 * moved under `social:debug:`, kept so existing scripts still run. Hidden
 * from `occ list`.
 *
 * A subclass rather than Symfony's `setAliases()`: `occ list` prints an alias
 * beside the command it belongs to, and this name is meant to stay out of it.
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
