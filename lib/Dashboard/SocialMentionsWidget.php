<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Dashboard;

use OCA\Social\Model\Client\Options\ProbeOptions;

/**
 * The posts that talk to the reader. Mentions are the one notification kind
 * that asks for an answer, so they get a tile of their own rather than being
 * mixed in with follows and favourites.
 */
class SocialMentionsWidget extends TimelineWidget {
	#[\Override]
	public function getId(): string {
		return 'social_mentions';
	}

	#[\Override]
	public function getTitle(): string {
		return $this->l10n->t('Aloha Social mentions');
	}

	#[\Override]
	public function getOrder(): int {
		return 12;
	}

	#[\Override]
	protected function getProbe(): string {
		return ProbeOptions::NOTIFICATIONS;
	}

	#[\Override]
	protected function configureProbe(ProbeOptions $options): void {
		$options->setTypes(['mention']);
	}

	#[\Override]
	protected function getTimelinePath(): string {
		return 'notifications';
	}

	#[\Override]
	protected function getButtonText(): string {
		return $this->l10n->t('Open notifications');
	}

	#[\Override]
	protected function getEmptyMessage(): string {
		return $this->l10n->t('Nobody has mentioned you yet');
	}

	#[\Override]
	protected function getHalfEmptyMessage(): string {
		return $this->l10n->t('No recent mentions');
	}
}
