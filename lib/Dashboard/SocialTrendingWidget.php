<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Dashboard;

use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Service\HashtagService;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IReloadableWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * What the instance is talking about. Unlike the timelines this needs no
 * viewer, so it works the same for a reader who follows nobody yet.
 */
class SocialTrendingWidget implements IAPIWidgetV2, IIconWidget, IButtonWidget, IReloadableWidget {
	private const MAX_ITEMS = 10;

	/** Long enough to be a trend, short enough to still be news. */
	private const PERIOD = '1d';

	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
		private HashtagService $hashtagService,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function getId(): string {
		return 'social_trending';
	}

	#[\Override]
	public function getTitle(): string {
		return $this->l10n->t('Aloha Social trending hashtags');
	}

	#[\Override]
	public function getOrder(): int {
		return 16;
	}

	#[\Override]
	public function getIconClass(): string {
		return 'icon-social';
	}

	#[\Override]
	public function getIconUrl(): string {
		return $this->urlGenerator->imagePath(Application::APP_ID, 'social-dark.svg');
	}

	#[\Override]
	public function getUrl(): ?string {
		return $this->urlGenerator->linkToRoute('social.Navigation.timeline', ['path' => 'timeline']);
	}

	#[\Override]
	public function load(): void {
		Util::addStyle(Application::APP_ID, 'dashboard');
	}

	#[\Override]
	public function getWidgetButtons(string $userId): array {
		return [
			new WidgetButton(
				WidgetButton::TYPE_MORE,
				(string)$this->getUrl(),
				$this->l10n->t('Open timeline')
			),
		];
	}

	/** Trends move in hours, not seconds. */
	#[\Override]
	public function getReloadInterval(): int {
		return 900;
	}

	#[\Override]
	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		try {
			$trending = $this->hashtagService->getTrending(
				max(1, min($limit, self::MAX_ITEMS)),
				self::PERIOD
			);

			$items = [];
			foreach ($trending as $entry) {
				$hashtag = (string)($entry['hashtag'] ?? '');
				if ($hashtag === '') {
					continue;
				}

				$posts = (int)($entry['trend'][self::PERIOD] ?? 0);
				$items[] = new WidgetItem(
					'#' . $hashtag,
					$this->l10n->n('%n post', '%n posts', $posts),
					$this->urlGenerator->linkToRoute(
						'social.Navigation.timeline',
						['path' => 'tags/' . $hashtag]
					)
				);
			}

			// the dashboard prints the half-empty message *above* the rows, so
			// sending one with rows present put "Nothing is trending" directly
			// on top of the hashtags this widget had just listed
			return new WidgetItems(
				$items,
				$this->l10n->t('Nothing is trending yet'),
				$items === [] ? $this->l10n->t('Nothing is trending') : '',
			);
		} catch (Exception $e) {
			$this->logger->warning('could not build the social_trending dashboard widget', [
				'exception' => $e,
			]);
			$failed = $this->l10n->t('Could not load trending hashtags');

			return new WidgetItems([], $failed, $failed);
		}
	}
}
