<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Dashboard;

use OCA\Social\Model\Client\Options\ProbeOptions;

/**
 * The posts of the accounts the reader follows.
 */
class SocialTimelineWidget extends TimelineWidget {
	#[\Override]
	public function getId(): string {
		return 'social_timeline';
	}

	#[\Override]
	public function getTitle(): string {
		return $this->l10n->t('Aloha Social timeline');
	}

	#[\Override]
	public function getOrder(): int {
		return 11;
	}

	#[\Override]
	protected function getProbe(): string {
		return ProbeOptions::HOME;
	}

	#[\Override]
	protected function getTimelinePath(): string {
		return 'home';
	}

	#[\Override]
	protected function getEmptyMessage(): string {
		return $this->l10n->t('Follow some accounts to see their posts here');
	}

	#[\Override]
	protected function getHalfEmptyMessage(): string {
		return $this->l10n->t('No recent posts');
	}
}
