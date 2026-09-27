<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\AppInfo;

use OCA\DAV\Events\CardCreatedEvent;
use OCA\DAV\Events\CardUpdatedEvent;
use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Social\Dashboard\SocialBookmarksWidget;
use OCA\Social\Dashboard\SocialDirectWidget;
use OCA\Social\Dashboard\SocialFederationHealthWidget;
use OCA\Social\Dashboard\SocialFollowRequestsWidget;
use OCA\Social\Dashboard\SocialMentionsWidget;
use OCA\Social\Dashboard\SocialReportsWidget;
use OCA\Social\Dashboard\SocialTimelineWidget;
use OCA\Social\Dashboard\SocialTrendingWidget;
use OCA\Social\Dashboard\SocialWidget;
use OCA\Social\External\ExternalDavGuard;
use OCA\Social\External\ExternalGroupBackend;
use OCA\Social\External\ExternalUserBackend;
use OCA\Social\External\SignupLoginProvider;
use OCA\Social\Listeners\ExternalAddressBookListener;
use OCA\Social\Listeners\ExternalDavListener;
use OCA\Social\Listeners\ExternalFirstLoginListener;
use OCA\Social\Listeners\ExternalNavigationListener;
use OCA\Social\Listeners\ExternalPageListener;
use OCA\Social\Listeners\ExternalUserStatusListener;
use OCA\Social\Listeners\FilesScriptsListener;
use OCA\Social\Listeners\GroupListListener;
use OCA\Social\Listeners\ProfileSectionListener;
use OCA\Social\Listeners\UserAccountListener;
use OCA\Social\Listeners\UserDeletedListener;
use OCA\Social\Middleware\AccessBlockMiddleware;
use OCA\Social\Middleware\ApiRateLimitMiddleware;
use OCA\Social\Middleware\ExternalScopeMiddleware;
use OCA\Social\Middleware\RateLimitHeadersMiddleware;
use OCA\Social\Notification\Notifier;
use OCA\Social\Reference\PostReferenceProvider;
use OCA\Social\Search\UnifiedSearchProvider;
use OCA\Social\SetupChecks\ClientApiAtRoot;
use OCA\Social\SetupChecks\CloudAddressMatches;
use OCA\Social\SetupChecks\CronRanRecently;
use OCA\Social\SetupChecks\MemcacheConfigured;
use OCA\Social\SetupChecks\OutboundQueueNotStuck;
use OCA\Social\SetupChecks\ProxyForwardsTheScheme;
use OCA\Social\SetupChecks\ReachableByStrictPeers;
use OCA\Social\SetupChecks\UploadLimitsAgree;
use OCA\Social\SetupChecks\WebFingerReachable;
use OCA\Social\UserMigration\SocialMigrator;
use OCA\Social\WellKnown\WebfingerHandler;
use OCP\Accounts\UserUpdatedEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent as PageRenderedEvent;
use OCP\Group\Events\GroupChangedEvent;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Profile\BeforeTemplateRenderedEvent;
use OCP\User\Events\UserDeletedEvent;
use OCP\User\Events\UserEnumerationFilterEvent;
use OCP\User\Events\UserFirstTimeLoggedInEvent;

require_once __DIR__ . '/../../vendor/autoload.php';

class Application extends App implements IBootstrap {
	public const APP_ID = 'social';
	public const APP_NAME = 'Social';
	public const APP_SUBJECT = 'http://nextcloud.com/';
	public const APP_REL = 'https://apps.nextcloud.com/apps/social';

	public function __construct(array $params = []) {
		parent::__construct(self::APP_ID, $params);
	}

	#[\Override]
	public function register(IRegistrationContext $context): void {
		// before anything else it registers: an address at `no_access` is
		// refused whatever it was asking for
		$context->registerMiddleware(AccessBlockMiddleware::class);
		// after the access check, so a refused address is never counted
		$context->registerMiddleware(RateLimitHeadersMiddleware::class);
		// and the budget itself, for the routes that carry no limit of their own
		$context->registerMiddleware(ApiRateLimitMiddleware::class);
		// global: it keeps self-registered external users out of every other
		// app's controllers, not only this app's
		$context->registerMiddleware(ExternalScopeMiddleware::class, true);
		$context->registerSearchProvider(UnifiedSearchProvider::class);
		$context->registerReferenceProvider(PostReferenceProvider::class);
		$context->registerWellKnownHandler(WebfingerHandler::class);
		$context->registerEventListener(BeforeTemplateRenderedEvent::class, ProfileSectionListener::class);
		$context->registerEventListener(UserUpdatedEvent::class, UserAccountListener::class);
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
		// the class is the Files app's, not OCP's; the name is a string here and
		// the listener is only ever built when Files dispatches the event
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, FilesScriptsListener::class);
		// the group lists follow the groups the moment they change
		$context->registerEventListener(UserAddedEvent::class, GroupListListener::class);
		$context->registerEventListener(UserRemovedEvent::class, GroupListListener::class);
		$context->registerEventListener(GroupDeletedEvent::class, GroupListListener::class);
		$context->registerEventListener(GroupChangedEvent::class, GroupListListener::class);
		// self-registered external users: kept out of the system address
		// book, the user lists, other apps' first-login setup, the page
		// chrome, the navigation and WebDAV
		$context->registerEventListener(CardCreatedEvent::class, ExternalAddressBookListener::class);
		$context->registerEventListener(CardUpdatedEvent::class, ExternalAddressBookListener::class);
		$context->registerEventListener(UserEnumerationFilterEvent::class, ExternalUserStatusListener::class);
		$context->registerEventListener(UserFirstTimeLoggedInEvent::class, ExternalFirstLoginListener::class, ExternalFirstLoginListener::PRIORITY);
		$context->registerEventListener(PageRenderedEvent::class, ExternalPageListener::class);
		$context->registerEventListener(ExternalNavigationListener::EVENT, ExternalNavigationListener::class);
		$context->registerEventListener(SabrePluginAddEvent::class, ExternalDavListener::class);
		$context->registerAlternativeLoginProvider(SignupLoginProvider::class);
		$context->registerDashboardWidget(SocialWidget::class);
		$context->registerDashboardWidget(SocialTimelineWidget::class);
		$context->registerDashboardWidget(SocialMentionsWidget::class);
		$context->registerDashboardWidget(SocialDirectWidget::class);
		$context->registerDashboardWidget(SocialBookmarksWidget::class);
		$context->registerDashboardWidget(SocialFollowRequestsWidget::class);
		$context->registerDashboardWidget(SocialTrendingWidget::class);
		$context->registerDashboardWidget(SocialReportsWidget::class);
		$context->registerDashboardWidget(SocialFederationHealthWidget::class);
		$context->registerNotifierService(Notifier::class);
		$context->registerUserMigrator(SocialMigrator::class);
		// Administration → Overview: what breaks federation, or switches a
		// protection off, silently — in the place an administrator already looks
		$context->registerSetupCheck(WebFingerReachable::class);
		$context->registerSetupCheck(CloudAddressMatches::class);
		$context->registerSetupCheck(ReachableByStrictPeers::class);
		$context->registerSetupCheck(UploadLimitsAgree::class);
		$context->registerSetupCheck(CronRanRecently::class);
		$context->registerSetupCheck(OutboundQueueNotStuck::class);
		$context->registerSetupCheck(ClientApiAtRoot::class);
		$context->registerSetupCheck(ProxyForwardsTheScheme::class);
		$context->registerSetupCheck(MemcacheConfigured::class);
	}

	/**
	 * Registers the backends of self-registered external users.
	 *
	 * This app is of the `extended_authentication` type, so Nextcloud loads
	 * it before anybody logs in, on every entry point, and an external user
	 * can log in through the same form as everybody else. `remote.php` loads
	 * it right before handing the request to WebDAV, which is where an
	 * external user is turned away from it.
	 */
	#[\Override]
	public function boot(IBootContext $context): void {
		$context->injectFn(static function (
			IUserManager $userManager,
			IGroupManager $groupManager,
			ExternalUserBackend $userBackend,
			ExternalGroupBackend $groupBackend,
			ExternalDavGuard $davGuard,
		): void {
			$userManager->registerBackend($userBackend);
			$groupManager->addBackend($groupBackend);

			if ($davGuard->refuses()) {
				$davGuard->refuse();
			}
		});
	}
}
