<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\SetupChecks;

use OCA\Social\Db\SearchTermsRequest;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\IndexService;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Whether the two index repairs got past every stored post: `Cron\Index`
 * (timeline and hashtag side rows of older posts) and `Cron\SearchIndex`
 * (content search). Both skip a post that keeps failing, which no timeline
 * or search will then find, so their skip counts are shown here.
 */
class IndexRepairsComplete implements ISetupCheck {
	public const DOC = Docs::ADMIN_GUIDE . '#posts-the-index-repairs-skipped';

	public function __construct(
		private IL10N $l10n,
		private ConfigService $configService,
	) {
	}

	#[\Override]
	public function getCategory(): string {
		return 'system';
	}

	#[\Override]
	public function getName(): string {
		return $this->l10n->t('Aloha Social: index repairs');
	}

	#[\Override]
	public function run(): SetupResult {
		$timelines = $this->configService->getAppValueInt(IndexService::SKIPPED_KEY);
		$search = $this->configService->getAppValueInt(SearchTermsRequest::SKIPPED);

		if ($timelines <= 0 && $search <= 0) {
			return SetupResult::success(
				$this->l10n->t('The index repairs have not had to skip any post.')
			);
		}

		$parts = [];
		if ($timelines > 0) {
			$parts[] = $this->l10n->n(
				'%n post could not be given its timeline and hashtag rows',
				'%n posts could not be given their timeline and hashtag rows',
				$timelines
			);
		}
		if ($search > 0) {
			$parts[] = $this->l10n->n(
				'%n post could not be added to the content search',
				'%n posts could not be added to the content search',
				$search
			);
		}

		return SetupResult::warning(
			implode('; ', $parts) . '. '
			. $this->l10n->t('The Nextcloud log names each one ("skipping stream", "skipping post") with the error that stopped it.'),
			self::DOC
		);
	}
}
