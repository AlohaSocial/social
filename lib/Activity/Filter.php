<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Activity;

use OCA\Social\AppInfo\Application;
use OCP\Activity\IFilter;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * "Social" in the Activity app's sidebar: the stream narrowed to this app.
 */
class Filter implements IFilter {
	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
	) {
	}

	#[\Override]
	public function getIdentifier(): string {
		return Application::APP_ID;
	}

	#[\Override]
	public function getName(): string {
		return $this->l10n->t('Aloha Social');
	}

	#[\Override]
	public function getPriority(): int {
		return 55;
	}

	#[\Override]
	public function getIcon(): string {
		return $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'social-dark.svg'));
	}

	/**
	 * @param string[] $types
	 * @return string[]
	 */
	#[\Override]
	public function filterTypes(array $types): array {
		return $types;
	}

	/** @return string[] */
	#[\Override]
	public function allowedApps(): array {
		return [Application::APP_ID];
	}
}
