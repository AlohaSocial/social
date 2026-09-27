<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Dashboard;

use OCA\Social\AppInfo\Application;
use OCP\Dashboard\IWidget;
use OCP\IL10N;
use OCP\IURLGenerator;

class SocialWidget implements IWidget {
	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
	) {
	}

	#[\Override]
	public function getId(): string {
		return 'social_notifications';
	}

	#[\Override]
	public function getTitle(): string {
		return $this->l10n->t('Social notifications');
	}

	#[\Override]
	public function getOrder(): int {
		return 10;
	}

	#[\Override]
	public function getIconClass(): string {
		return 'icon-social';
	}

	#[\Override]
	public function getUrl(): ?string {
		// the notifications page, not the JSON endpoint that feeds it
		return $this->urlGenerator->linkToRoute(
			'social.Navigation.timeline',
			['path' => 'notifications']
		);
	}

	#[\Override]
	public function load(): void {
		// the framework this entry was built without; see webpack.common.js
		\OCP\Util::addScript(Application::APP_ID, 'social-framework');
		\OCP\Util::addScript(Application::APP_ID, 'social-dashboard');
		\OCP\Util::addStyle(Application::APP_ID, 'dashboard');
	}
}
