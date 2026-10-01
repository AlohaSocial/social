<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Dashboard;

use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Service\ModeratorService;
use OCA\Social\Service\ReportService;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IConditionalWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IReloadableWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * The reports nobody has dealt with yet. Moderation lives behind an admin
 * settings page that nobody visits on a schedule, so the open count has to
 * come and find the moderator instead.
 */
class SocialReportsWidget implements IAPIWidgetV2, IIconWidget, IButtonWidget, IReloadableWidget, IConditionalWidget {
	private const MAX_ITEMS = 20;

	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
		private IUserSession $userSession,
		private ModeratorService $moderatorService,
		private ReportService $reportService,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function getId(): string {
		return 'social_reports';
	}

	#[\Override]
	public function getTitle(): string {
		return $this->l10n->t('Aloha Social reports');
	}

	#[\Override]
	public function getOrder(): int {
		return 20;
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
		return $this->getSettingsUrl();
	}

	#[\Override]
	public function load(): void {
		Util::addStyle(Application::APP_ID, 'dashboard');
	}

	/** Only someone who can act on a report is offered it. */
	#[\Override]
	public function isEnabled(): bool {
		$user = $this->userSession->getUser();

		return $user !== null && $this->moderatorService->isModerator($user->getUID());
	}

	#[\Override]
	public function getWidgetButtons(string $userId): array {
		return [
			new WidgetButton(
				WidgetButton::TYPE_MORE,
				$this->getSettingsUrl(),
				$this->l10n->t('Review reports')
			),
		];
	}

	#[\Override]
	public function getReloadInterval(): int {
		return 600;
	}

	/**
	 * The rows are read for the user the API names, so the check is made here
	 * too: `isEnabled()` decides what the dashboard *offers*, and a widget
	 * that only answered that question would hand its rows to any client that
	 * asked for them by id. The reports name accounts somebody has complained
	 * about, which is not public.
	 */
	#[\Override]
	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		if (!$this->moderatorService->isModerator($userId)) {
			return new WidgetItems();
		}

		try {
			$open = array_slice(
				$this->reportService->getReports(),
				0,
				max(1, min($limit, self::MAX_ITEMS))
			);

			$link = $this->getSettingsUrl();
			$items = [];
			foreach ($open as $report) {
				$target = $report->getTargetAccount();
				$account = $target !== null && $target->getAccount() !== ''
					? $target->getAccount()
					: $report->getAccountId();

				$items[] = new WidgetItem(
					$account,
					$this->describe($report->getCategory(), $report->getComment()),
					$link,
					$target?->getAvatar() ?? '',
					(string)$report->getId()
				);
			}

			// the dashboard prints the half-empty message above the rows, so a
			// widget with rows must not send one
			return new WidgetItems(
				$items,
				$this->l10n->t('No reports to review'),
				$items === [] ? $this->l10n->t('No open reports') : '',
			);
		} catch (Exception $e) {
			$this->logger->warning('could not build the social_reports dashboard widget', [
				'exception' => $e,
			]);
			$failed = $this->l10n->t('Could not load reports');

			return new WidgetItems([], $failed, $failed);
		}
	}

	/** The category, and the reporter's own words when they left any. */
	private function describe(string $category, string $comment): string {
		$category = $category !== '' ? $category : $this->l10n->t('other');
		if ($comment === '') {
			return $category;
		}

		return $category . ' – ' . $comment;
	}

	private function getSettingsUrl(): string {
		return $this->urlGenerator->linkToRoute('settings.AdminSettings.index', ['section' => 'social']);
	}
}
