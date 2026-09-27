<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\AppInfo;

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
use OCA\Social\Listeners\FilesScriptsListener;
use OCA\Social\Listeners\GroupListListener;
use OCA\Social\Listeners\ProfileSectionListener;
use OCA\Social\Listeners\UserAccountListener;
use OCA\Social\Listeners\UserDeletedListener;
use OCA\Social\Middleware\AccessBlockMiddleware;
use OCA\Social\Middleware\ApiRateLimitMiddleware;
use OCA\Social\Middleware\RateLimitHeadersMiddleware;
use OCA\Social\Notification\Notifier;
use OCA\Social\Search\UnifiedSearchProvider;
use OCA\Social\UserMigration\SocialMigrator;
use OCA\Social\WellKnown\WebfingerHandler;
use OCP\Accounts\UserUpdatedEvent;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Group\Events\GroupChangedEvent;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCP\Profile\BeforeTemplateRenderedEvent;
use OCP\User\Events\UserDeletedEvent;
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

		$listeners = [];
		$context->expects($this->exactly(8))->method('registerEventListener')
			->willReturnCallback(function (string $event, string $listener) use (&$listeners): void {
				$listeners[$event] = $listener;
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
		], $listeners);
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
			],
			$registered
		);
	}

	public function testBootDoesNothing(): void {
		$context = $this->createMock(IBootContext::class);
		$context->expects($this->never())->method($this->anything());

		$this->application()->boot($context);
	}
}
