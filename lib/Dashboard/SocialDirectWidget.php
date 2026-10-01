<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Dashboard;

use OCA\Social\Model\Client\Options\ProbeOptions;

/**
 * The posts addressed to the reader alone.
 */
class SocialDirectWidget extends TimelineWidget {
	#[\Override]
	public function getId(): string {
		return 'social_direct';
	}

	#[\Override]
	public function getTitle(): string {
		return $this->l10n->t('Aloha Social direct messages');
	}

	#[\Override]
	public function getOrder(): int {
		return 13;
	}

	#[\Override]
	protected function getProbe(): string {
		return ProbeOptions::DIRECT;
	}

	#[\Override]
	protected function getTimelinePath(): string {
		return 'direct';
	}

	#[\Override]
	protected function getButtonText(): string {
		return $this->l10n->t('Open direct messages');
	}

	#[\Override]
	protected function getEmptyMessage(): string {
		return $this->l10n->t('No direct messages');
	}

	#[\Override]
	protected function getHalfEmptyMessage(): string {
		return $this->l10n->t('No recent direct messages');
	}
}
