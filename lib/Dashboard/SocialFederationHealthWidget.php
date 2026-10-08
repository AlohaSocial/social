<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Dashboard;

use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Service\FederationHealthService;
use OCA\Social\Service\ModeratorService;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IConditionalWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IReloadableWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * Which instances this one is failing to reach. Deliveries are dropped once
 * they run out of tries, and until now that happened without anyone being
 * told; this puts it where an admin already looks.
 */
class SocialFederationHealthWidget implements IAPIWidgetV2, IIconWidget, IButtonWidget, IReloadableWidget, IConditionalWidget {
	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
		private IUserSession $userSession,
		private ModeratorService $moderatorService,
		private IDateTimeFormatter $dateTimeFormatter,
		private FederationHealthService $federationHealthService,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function getId(): string {
		return 'social_federation_health';
	}

	#[\Override]
	public function getTitle(): string {
		return $this->l10n->t('Aloha Social federation health');
	}

	#[\Override]
	public function getOrder(): int {
		return 21;
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

	/** Only someone who can act on a stuck queue is offered it. */
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
				$this->l10n->t('Open Aloha Social administration')
			),
		];
	}

	#[\Override]
	public function getReloadInterval(): int {
		return 600;
	}

	/**
	 * Asked of the user the API names, as `isEnabled()` is of the one looking:
	 * a widget that checked only what the dashboard offers would tell any
	 * client which instances this one cannot reach.
	 */
	#[\Override]
	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		if (!$this->moderatorService->isModerator($userId)) {
			return new WidgetItems();
		}

		try {
			$summary = $this->federationHealthService->summary();
			$failing = (int)($summary['failing'] ?? 0);
			$link = $this->getSettingsUrl();

			$items = [];
			if ($failing > 0) {
				// the totals lead, so a queue in trouble is visible even when
				// none of the failures could be pinned on a named host
				$items[] = new WidgetItem(
					$this->l10n->n(
						'%n delivery is failing',
						'%n deliveries are failing',
						$failing
					),
					$this->l10n->t(
						'%1$s at risk of being dropped after %2$s tries',
						[(string)(int)($summary['atRisk'] ?? 0), (string)(int)($summary['maxTries'] ?? 0)]
					),
					$link
				);
			}

			foreach ($summary['instances'] ?? [] as $instance) {
				if (count($items) >= max(1, $limit)) {
					break;
				}

				$items[] = new WidgetItem(
					(string)($instance['host'] ?? ''),
					$this->describe((int)($instance['requests'] ?? 0), (int)($instance['tries'] ?? 0), (int)($instance['last'] ?? 0)),
					$link
				);
			}

			// the dashboard prints the half-empty message above the rows, so a
			// widget with rows must not send one
			return new WidgetItems(
				$items,
				$this->l10n->t('Everything is being delivered'),
				$items === [] ? $this->l10n->t('Everything is being delivered') : '',
			);
		} catch (Exception $e) {
			$this->logger->warning('could not build the social_federation_health dashboard widget', [
				'exception' => $e,
			]);
			$failed = $this->l10n->t('Could not load federation health');

			return new WidgetItems([], $failed, $failed);
		}
	}

	/** How much is stuck for one host, how hard we tried, and how long ago. */
	private function describe(int $requests, int $tries, int $last): string {
		$stuck = $this->l10n->n('%n delivery', '%n deliveries', $requests);
		$attempts = $this->l10n->n('%n try', '%n tries', $tries);
		if ($last <= 0) {
			return $stuck . ', ' . $attempts;
		}

		return $stuck . ', ' . $attempts . ', ' . $this->l10n->t('last tried %s', [
			$this->dateTimeFormatter->formatTimeSpan($last),
		]);
	}

	private function getSettingsUrl(): string {
		return $this->urlGenerator->linkToRoute('settings.AdminSettings.index', ['section' => 'social']) . '#federation';
	}
}
