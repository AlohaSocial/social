<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\SetupChecks;

use OCA\Social\Db\SearchTermsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Service\ConfigService;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Whether the post tables are growing with nothing to bound them. Retention
 * stays off by default (deleting what others sent is the admin's decision);
 * past a million posts the admin should hear about the setting.
 */
class RetentionBoundsTheTables implements ISetupCheck {
	public const DOC = Docs::ADMIN_GUIDE . '#the-post-tables-keep-growing';

	/** How many posts make the tables worth bounding. */
	public const POSTS = 1000000;

	/** How many indexed words do, about thirty a post at that size. */
	public const TERMS = 30000000;

	public function __construct(
		private IL10N $l10n,
		private ConfigService $configService,
		private StreamRequest $streamRequest,
		private SearchTermsRequest $searchTermsRequest,
	) {
	}

	#[\Override]
	public function getCategory(): string {
		return 'system';
	}

	#[\Override]
	public function getName(): string {
		return $this->l10n->t('Aloha Social: table growth');
	}

	#[\Override]
	public function run(): SetupResult {
		$quiet = $this->l10n->t('Retention bounds the post tables, or they are still small enough not to need it.');
		if ($this->configService->getAppValueInt(ConfigService::SOCIAL_RETENTION_DAYS) > 0) {
			return SetupResult::success($quiet);
		}

		$parts = [];
		if ($this->streamRequest->countPostsUpTo(self::POSTS + 1) > self::POSTS) {
			$parts[] = $this->l10n->t('more than %s posts are stored', [(string)self::POSTS]);
		}
		$terms = $this->searchTermsRequest->approximateCount();
		if ($terms > self::TERMS) {
			$parts[] = $this->l10n->t('about %s words are in the search index', [(string)$terms]);
		}
		if ($parts === []) {
			return SetupResult::success($quiet);
		}

		return SetupResult::warning(
			ucfirst(implode('; ', $parts)) . '. '
			. $this->l10n->t('Retention is off (retention_days is 0), so nothing removes the remote posts nobody here has interacted with, their recipient rows, their words or their cached media.'),
			self::DOC
		);
	}
}
