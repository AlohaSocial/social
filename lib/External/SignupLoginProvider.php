<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\External;

use OCA\Social\Service\ExternalUserService;
use OCP\Authentication\IAlternativeLoginProvider;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Offers registration on the login page, unless external users are off, the
 * instance is full, or registration is by invitation only (the invitation
 * link is the way in then).
 */
class SignupLoginProvider implements IAlternativeLoginProvider {
	public function __construct(
		private ExternalUserService $externalUserService,
		private IURLGenerator $urlGenerator,
		private IL10N $l10n,
	) {
	}

	#[\Override]
	public function getAlternativeLogins(): array {
		if (!$this->externalUserService->hasRoom()
			|| $this->externalUserService->mode() === ExternalUserService::MODE_INVITE) {
			return [];
		}

		return [new SignupLogin(
			$this->l10n->t('Create an account'),
			$this->urlGenerator->linkToRoute('social.Signup.page')
		)];
	}
}
