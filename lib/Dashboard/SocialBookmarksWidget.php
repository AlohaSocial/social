<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Dashboard;

use OCA\Social\Model\Client\Options\ProbeOptions;

/**
 * The reader's saved posts. Deliberate and slow-moving, which is what makes it
 * worth a dashboard tile where a firehose would not be.
 */
class SocialBookmarksWidget extends TimelineWidget {
	#[\Override]
	public function getId(): string {
		return 'social_bookmarks';
	}

	#[\Override]
	public function getTitle(): string {
		return $this->l10n->t('Aloha Social bookmarks');
	}

	#[\Override]
	public function getOrder(): int {
		return 14;
	}

	#[\Override]
	protected function getProbe(): string {
		return ProbeOptions::BOOKMARKS;
	}

	#[\Override]
	protected function getTimelinePath(): string {
		return 'bookmarks';
	}

	#[\Override]
	protected function getButtonText(): string {
		return $this->l10n->t('Open bookmarks');
	}

	#[\Override]
	protected function getEmptyMessage(): string {
		return $this->l10n->t('Bookmark a post to keep it here');
	}

	#[\Override]
	protected function getHalfEmptyMessage(): string {
		return $this->l10n->t('No bookmarks');
	}
}
