<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\SetupChecks;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Service\CheckService;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;
use Throwable;

/**
 * Whether `/.well-known/webfinger` answers for an account of this instance,
 * shown in Administration → Overview where administrators look. It asks
 * about the oldest live account, as the administrator may have none.
 */
class WebFingerReachable implements ISetupCheck {
	public const DOC = Docs::ADMIN_GUIDE . '#webfinger-does-not-answer';

	public function __construct(
		private IL10N $l10n,
		private CheckService $checkService,
		private ActorsRequest $actorsRequest,
	) {
	}

	#[\Override]
	public function getCategory(): string {
		return 'network';
	}

	#[\Override]
	public function getName(): string {
		return $this->l10n->t('Aloha Social: WebFinger');
	}

	#[\Override]
	public function run(): SetupResult {
		try {
			$actor = $this->actorsRequest->getAny();
		} catch (Throwable $e) {
			$actor = null;
		}

		if ($actor === null) {
			return SetupResult::info(
				$this->l10n->t('Aloha Social has no account yet, so its WebFinger answer cannot be probed. It will be once somebody sets one up.'),
				self::DOC
			);
		}

		if ($this->checkService->checkWellKnown($actor->getPreferredUsername())) {
			return SetupResult::success(
				$this->l10n->t('Other servers can look up the accounts of this instance.')
			);
		}

		return SetupResult::error(
			$this->l10n->t(
				'Nothing answers for %1$s at /.well-known/webfinger, so other servers cannot find the accounts of this instance. Either the .well-known redirects are missing, or Aloha Social is set up for a different address than the server now uses.',
				['@' . $actor->getPreferredUsername()]
			),
			self::DOC
		);
	}
}
