<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\SetupChecks;

use OCA\Social\Service\AboutService;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Whether a server that faces the public says who runs it and on what terms.
 *
 * A contact, rules, a privacy policy and a legal notice are what people
 * signing up, and peers receiving its posts, look for — and what a moderator
 * points at. A warning when people without a Nextcloud account may sign up,
 * information when the server only federates, and nothing on a server that
 * does neither.
 */
class PublicReady implements ISetupCheck {
	public const DOC = Docs::ADMIN_GUIDE . '#ready-to-be-public';

	public function __construct(
		private IL10N $l10n,
		private AboutService $aboutService,
	) {
	}

	#[\Override]
	public function getCategory(): string {
		return 'system';
	}

	#[\Override]
	public function getName(): string {
		return $this->l10n->t('Aloha Social: ready to be public');
	}

	#[\Override]
	public function run(): SetupResult {
		$signUp = $this->aboutService->isOpenForSignUp();
		if (!$signUp && !$this->aboutService->isFederating()) {
			return SetupResult::success($this->l10n->t('This server neither takes sign-ups nor federates.'));
		}

		$missing = $this->aboutService->missingBasics();
		if ($missing === []) {
			return SetupResult::success($this->l10n->t('The contact, the server rules, the privacy policy and the legal notice are all set.'));
		}

		// whole sentences, one per gap: a list of translated fragments joined
		// with commas cannot be put into every language's grammar
		$sentences = [$signUp
			? $this->l10n->t('People without a Nextcloud account can sign up here, so this server should say who runs it and on what terms.')
			: $this->l10n->t('This server federates with other servers, so it should say who runs it and on what terms.')];
		foreach ($missing as $basic) {
			$sentences[] = match ($basic) {
				AboutService::MISSING_CONTACT => $this->l10n->t('No contact address or account is set.'),
				AboutService::MISSING_RULES => $this->l10n->t('No server rules are written.'),
				AboutService::MISSING_PRIVACY => $this->l10n->t('No privacy policy link is set.'),
				default => $this->l10n->t('No legal notice link is set.'),
			};
		}
		if (array_intersect($missing, [AboutService::MISSING_CONTACT, AboutService::MISSING_RULES]) !== []) {
			$sentences[] = $this->l10n->t('Set the contact and the rules under Administration → Aloha Social → About this server.');
		}
		if (array_intersect($missing, [AboutService::MISSING_PRIVACY, AboutService::MISSING_LEGAL_NOTICE]) !== []) {
			$sentences[] = $this->l10n->t('Set the privacy policy and legal notice links under Administration → Theming.');
		}
		$text = implode(' ', $sentences);

		return $signUp ? SetupResult::warning($text, self::DOC) : SetupResult::info($text, self::DOC);
	}
}
