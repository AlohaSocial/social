<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\AppInfo;

use OCA\DAV\Events\CardCreatedEvent;
use OCA\DAV\Events\CardUpdatedEvent;
use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Social\AppInfo\Application;
use OCA\Social\Dashboard\SocialBookmarksWidget;
use OCA\Social\Dashboard\SocialDirectWidget;
use OCA\Social\Dashboard\SocialFederationHealthWidget;
use OCA\Social\Dashboard\SocialFollowRequestsWidget;
use OCA\Social\Dashboard\SocialMentionsWidget;
use OCA\Social\Dashboard\SocialReportsWidget;
use OCA\Social\Dashboard\SocialTimelineWidget;
use OCA\Social\Dashboard\SocialTrendingWidget;
use OCA\Social\Dashboard\SocialWidget;
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
use OCA\Social\Middleware\HideCountsMiddleware;
use OCA\Social\Middleware\RateLimitHeadersMiddleware;
use OCA\Social\Notification\Notifier;
use OCA\Social\Search\UnifiedSearchProvider;
use OCA\Social\UserMigration\SocialMigrator;
use OCA\Social\WellKnown\WebfingerHandler;
use OCP\Accounts\UserUpdatedEvent;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent as PageRenderedEvent;
use OCP\Group\Events\GroupChangedEvent;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCP\Profile\BeforeTemplateRenderedEvent;
use OCP\User\Events\UserDeletedEvent;
use OCP\User\Events\UserEnumerationFilterEvent;
use OCP\User\Events\UserFirstTimeLoggedInEvent;
use PHPUnit\Framework\TestCase;

class ApplicationTest extends TestCase {
	/**
	 * App::__construct() needs the server container, so the bootstrap methods
	 * are exercised on an instance created without it.
	 */
	private function application(): Application {
		return (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
	}

	public function testIsABootstrappedAppNamedSocial(): void {
		$this->assertInstanceOf(IBootstrap::class, $this->application());
		$this->assertSame('social', Application::APP_ID);
		$this->assertSame('Social', Application::APP_NAME);
	}

	public function testRegisterWiresUpEveryIntegrationPoint(): void {
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects($this->once())->method('registerSearchProvider')->with(UnifiedSearchProvider::class);
		$context->expects($this->once())->method('registerWellKnownHandler')->with(WebfingerHandler::class);
		$context->expects($this->once())->method('registerNotifierService')->with(Notifier::class);
		// without this one an account export silently leaves out the whole app
		$context->expects($this->once())->method('registerUserMigrator')->with(SocialMigrator::class);

		// the button under the login form that leads to the registration page
		$context->expects($this->once())->method('registerAlternativeLoginProvider')->with(SignupLoginProvider::class);

		$listeners = [];
		$priorities = [];
		$context->expects($this->exactly(15))->method('registerEventListener')
			->willReturnCallback(function (string $event, string $listener, int $priority = 0) use (&$listeners, &$priorities): void {
				$listeners[$event] = $listener;
				$priorities[$event] = $priority;
			});
		$widgets = [];
		$context->expects($this->exactly(9))->method('registerDashboardWidget')
			->willReturnCallback(function (string $widget) use (&$widgets): void {
				$widgets[] = $widget;
			});

		$this->application()->register($context);

		$this->assertSame([
			BeforeTemplateRenderedEvent::class => ProfileSectionListener::class,
			UserUpdatedEvent::class => UserAccountListener::class,
			// without this one a deleted user keeps a live Fediverse account
			UserDeletedEvent::class => UserDeletedListener::class,
			// without this one Files has no "Share to Social"
			LoadAdditionalScriptsEvent::class => FilesScriptsListener::class,
			// the group lists follow the groups
			UserAddedEvent::class => GroupListListener::class,
			UserRemovedEvent::class => GroupListListener::class,
			GroupDeletedEvent::class => GroupListListener::class,
			GroupChangedEvent::class => GroupListListener::class,
			// self-registered external users stay out of the system address
			// book, the user lists, other apps' first-login setup, the page
			// chrome, the navigation and WebDAV
			CardCreatedEvent::class => ExternalAddressBookListener::class,
			CardUpdatedEvent::class => ExternalAddressBookListener::class,
			UserEnumerationFilterEvent::class => ExternalUserStatusListener::class,
			UserFirstTimeLoggedInEvent::class => ExternalFirstLoginListener::class,
			PageRenderedEvent::class => ExternalPageListener::class,
			ExternalNavigationListener::EVENT => ExternalNavigationListener::class,
			SabrePluginAddEvent::class => ExternalDavListener::class,
		], $listeners);
		// ahead of Files, which copies the skeleton on the same event
		$this->assertSame(ExternalFirstLoginListener::PRIORITY, $priorities[UserFirstTimeLoggedInEvent::class]);
		$this->assertGreaterThan(0, ExternalFirstLoginListener::PRIORITY);
		$this->assertSame([
			SocialWidget::class,
			SocialTimelineWidget::class,
			SocialMentionsWidget::class,
			SocialDirectWidget::class,
			SocialBookmarksWidget::class,
			SocialFollowRequestsWidget::class,
			SocialTrendingWidget::class,
			SocialReportsWidget::class,
			SocialFederationHealthWidget::class,
		], $widgets);
	}

	public function testRegisterDoesNotTouchOtherRegistrationApis(): void {
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects($this->never())->method('registerService');
		$context->expects($this->never())->method('registerCapability');

		$this->application()->register($context);
	}

	/**
	 * An IP block at `no_access` is a statement about the whole app, so it is
	 * enforced in the one place every request passes through. A block that
	 * held on the inbox but not on the API — or on last month's routes but not
	 * on the ones added since — is not what an admin switched on.
	 */
	public function testTheAccessBlockIsEnforcedForEveryRoute(): void {
		$registered = [];
		$context = $this->createStub(IRegistrationContext::class);
		$context->method('registerMiddleware')->willReturnCallback(
			static function (string $class) use (&$registered): void {
				$registered[] = $class;
			}
		);

		$this->application()->register($context);

		$this->assertContains(AccessBlockMiddleware::class, $registered);
	}

	/**
	 * The access check runs first: an address this instance refuses outright
	 * should never reach the counter, let alone spend budget in it. The
	 * limiter itself comes after the headers, for the same reason and in the
	 * same order every request meets them.
	 */
	public function testTheRateLimitersComeAfterTheAccessCheck(): void {
		$registered = [];
		$context = $this->createStub(IRegistrationContext::class);
		$context->method('registerMiddleware')->willReturnCallback(
			static function (string $class) use (&$registered): void {
				$registered[] = $class;
			}
		);

		$this->application()->register($context);

		$this->assertSame(
			[
				AccessBlockMiddleware::class,
				RateLimitHeadersMiddleware::class,
				ApiRateLimitMiddleware::class,
				HideCountsMiddleware::class,
				ExternalScopeMiddleware::class,
			],
			$registered
		);
	}

	/**
	 * The only middleware that runs for other apps' controllers: without
	 * `global` it would keep an external user inside Social only while they
	 * were already there.
	 */
	public function testTheExternalScopeIsEnforcedForEveryApp(): void {
		$global = [];
		$context = $this->createStub(IRegistrationContext::class);
		$context->method('registerMiddleware')->willReturnCallback(
			static function (string $class, bool $isGlobal = false) use (&$global): void {
				$global[$class] = $isGlobal;
			}
		);

		$this->application()->register($context);

		$this->assertSame([
			AccessBlockMiddleware::class => false,
			RateLimitHeadersMiddleware::class => false,
			ApiRateLimitMiddleware::class => false,
			HideCountsMiddleware::class => false,
			ExternalScopeMiddleware::class => true,
		], $global);
	}

	/**
	 * Boot registers the backends of the external users, through one
	 * injected function: nothing is resolved at registration time.
	 */
	public function testBootRegistersTheBackendsThroughOneInjectedFunction(): void {
		$context = $this->createMock(IBootContext::class);
		$context->expects($this->once())->method('injectFn')->with($this->isInstanceOf(\Closure::class));

		$this->application()->boot($context);
	}

	/** Loaded before login on every entry point, and still restrictable to groups. */
	public function testTheAppIsLoadedBeforeLogin(): void {
		$info = simplexml_load_file(__DIR__ . '/../../appinfo/info.xml');
		$this->assertNotFalse($info);

		$types = [];
		foreach ($info->types->children() as $type) {
			$types[] = $type->getName();
		}

		$this->assertSame(['extended_authentication'], $types);
	}

	/** The top bar has room for one short word; the full name stays everywhere else. */
	public function testTheTopBarEntryIsTheShortName(): void {
		$info = simplexml_load_file(__DIR__ . '/../../appinfo/info.xml');
		$this->assertNotFalse($info);

		$this->assertSame('Aloha Social', (string)$info->name);
		$this->assertSame('Aloha', (string)$info->navigations->navigation->name);
	}
}
