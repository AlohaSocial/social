<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
	) {
	}

	/** The section id, as the settings routes and the delegation use it. */
	public const SECTION_ID = 'social';

	#[\Override]
	public function getID(): string {
		return self::SECTION_ID;
	}

	#[\Override]
	public function getName(): string {
		return $this->l10n->t('Aloha Social');
	}

	#[\Override]
	public function getPriority(): int {
		return 75;
	}

	#[\Override]
	public function getIcon(): string {
		return $this->urlGenerator->imagePath('social', 'social-dark.svg');
	}
}
