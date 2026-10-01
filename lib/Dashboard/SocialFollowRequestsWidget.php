<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Dashboard;

use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\FollowService;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IConditionalWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IOptionWidget;
use OCP\Dashboard\IReloadableWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\Dashboard\Model\WidgetOptions;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * The accounts waiting for the reader to let them follow. A queue with an
 * answer to give, so it earns a tile; only a locked account ever grows one,
 * which is why the widget offers itself conditionally.
 */
class SocialFollowRequestsWidget implements IAPIWidgetV2, IIconWidget, IButtonWidget, IReloadableWidget, IOptionWidget, IConditionalWidget {
	private const MAX_ITEMS = 20;

	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
		private IUserSession $userSession,
		private AccountService $accountService,
		private CacheActorService $cacheActorService,
		private FollowService $followService,
		private FollowsRequest $followsRequest,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function getId(): string {
		return 'social_follow_requests';
	}

	#[\Override]
	public function getTitle(): string {
		return $this->l10n->t('Aloha Social follow requests');
	}

	#[\Override]
	public function getOrder(): int {
		return 15;
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
		return $this->getRequestsUrl();
	}

	#[\Override]
	public function load(): void {
		Util::addStyle(Application::APP_ID, 'dashboard');
	}

	/**
	 * Offered to an account that can collect requests, and to one that opened
	 * itself up again while some were still waiting.
	 */
	#[\Override]
	public function isEnabled(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		try {
			$viewer = $this->getViewer($user->getUID());
		} catch (Exception $e) {
			// no Social account on this instance yet
			return false;
		}

		return $viewer->isLocked()
			|| $this->followsRequest->countPendingRequests($viewer->getId()) > 0;
	}

	#[\Override]
	public function getWidgetButtons(string $userId): array {
		return [
			new WidgetButton(
				WidgetButton::TYPE_MORE,
				$this->getRequestsUrl(),
				$this->l10n->t('Review follow requests')
			),
		];
	}

	#[\Override]
	public function getReloadInterval(): int {
		return 300;
	}

	#[\Override]
	public function getWidgetOptions(): WidgetOptions {
		return new WidgetOptions(true);
	}

	#[\Override]
	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		try {
			$viewer = $this->getViewer($userId);
			$this->followService->setViewer($viewer);

			$link = $this->getRequestsUrl();
			$pending = $this->followService->getPendingRequests(max(1, min($limit, self::MAX_ITEMS)));

			$items = [];
			foreach ($pending as $follower) {
				$items[] = new WidgetItem(
					$follower->getName() ?: $follower->getPreferredUsername(),
					$follower->getAccount(),
					$link,
					$follower->getAvatar()
				);
			}

			// the dashboard prints the half-empty message above the rows, so a
			// widget with rows must not send one
			return new WidgetItems(
				$items,
				$this->l10n->t('No follow requests'),
				$items === [] ? $this->l10n->t('No new follow requests') : '',
			);
		} catch (Exception $e) {
			$this->logger->warning('could not build the social_follow_requests dashboard widget', [
				'exception' => $e,
			]);
			$failed = $this->l10n->t('Could not load follow requests');

			return new WidgetItems([], $failed, $failed);
		}
	}

	private function getViewer(string $userId): Person {
		$account = $this->accountService->getActorFromUserId($userId, false);

		return $this->cacheActorService->getFromLocalAccount($account->getPreferredUsername());
	}

	/**
	 * The route name carries no separator before the postfix: routes are
	 * registered as strtolower($app.$controller.$action.$postfix).
	 */
	private function getRequestsUrl(): string {
		return $this->urlGenerator->linkToRoute('social.navigation.navigatefollowrequests');
	}
}
