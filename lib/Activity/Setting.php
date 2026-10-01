<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Activity;

use OCP\Activity\ActivitySettings;
use OCP\IL10N;

/**
 * The line in Activity's settings that lets a person choose whether what
 * happens to them on Social shows in their activity stream and in the digest
 * mail. Activity's own notifications are off and stay off: this app has a bell
 * of its own (`Notification\Notifier`), and two bells for one follow is one too many.
 */
class Setting extends ActivitySettings {
	public const IDENTIFIER = 'social';

	public function __construct(
		private IL10N $l10n,
	) {
	}

	#[\Override]
	public function getIdentifier(): string {
		return self::IDENTIFIER;
	}

	#[\Override]
	public function getName(): string {
		return $this->l10n->t('Somebody <strong>followed</strong> you, <strong>mentioned</strong> you, or <strong>boosted</strong> or <strong>favourited</strong> a post of yours on Aloha Social');
	}

	#[\Override]
	public function getGroupIdentifier(): string {
		return 'social';
	}

	#[\Override]
	public function getGroupName(): string {
		return $this->l10n->t('Aloha Social');
	}

	#[\Override]
	public function getPriority(): int {
		return 60;
	}

	#[\Override]
	public function canChangeStream(): bool {
		return true;
	}

	#[\Override]
	public function isDefaultEnabledStream(): bool {
		return true;
	}

	#[\Override]
	public function canChangeMail(): bool {
		return true;
	}

	#[\Override]
	public function isDefaultEnabledMail(): bool {
		return false;
	}

	#[\Override]
	public function canChangeNotification(): bool {
		return false;
	}

	#[\Override]
	public function isDefaultEnabledNotification(): bool {
		return false;
	}
}
