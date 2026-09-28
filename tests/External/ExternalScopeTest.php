<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\External;

use OCA\Social\External\ExternalScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExternalScopeTest extends TestCase {
	public static function allowed(): array {
		return [
			'Social itself' => ['OCA\\Social\\Controller\\NavigationController', 'navigate'],
			'the Mastodon API' => ['OCA\\Social\\Controller\\StatusApiController', 'statusNew'],
			'logging in and out' => ['OC\\Core\\Controller\\LoginController', 'logout'],
			'the two-factor challenge' => ['OC\\Core\\Controller\\TwoFactorChallengeController', 'showChallenge'],
			'a two-factor provider' => ['OCA\\TwoFactorTOTP\\Controller\\SettingsController', 'enable'],
			'password reset' => ['OC\\Core\\Controller\\LostController', 'email'],
			'dismissing the one-time Nextcloud welcome wizard' => ['OCA\\FirstRunWizard\\Controller\\WizardController', 'disable'],
			'avatars' => ['OC\\Core\\Controller\\AvatarController', 'getAvatar'],
			'theming' => ['OCA\\Theming\\Controller\\ThemingController', 'getImage'],
			'notifications' => ['OCA\\Notifications\\Controller\\EndpointController', 'listNotifications'],
			'capabilities' => ['OC\\Core\\Controller\\OCSController', 'getCapabilities'],
			'their own user' => ['OCA\\Provisioning_API\\Controller\\UsersController', 'getCurrentUser'],
			'editing their own fields' => ['OCA\\Provisioning_API\\Controller\\UsersController', 'editUser'],
			'changing the password' => ['OCA\\Settings\\Controller\\ChangePasswordController', 'changePersonalPassword'],
			'sessions and app passwords' => ['OCA\\Settings\\Controller\\AuthSettingsController', 'create'],
		];
	}

	#[DataProvider('allowed')]
	public function testWhatAnExternalUserNeedsIsAllowed(string $controller, string $method): void {
		$this->assertSame(ExternalScope::ALLOW, ExternalScope::verdict($controller, $method));
	}

	public static function refused(): array {
		return [
			'Files' => ['OCA\\Files\\Controller\\ViewController', 'index'],
			'Talk' => ['OCA\\Talk\\Controller\\RoomController', 'getRooms'],
			'the dashboard' => ['OCA\\Dashboard\\Controller\\DashboardController', 'index'],
			'unified search' => ['OC\\Core\\Controller\\UnifiedSearchController', 'search'],
			'the sharee autocomplete' => ['OC\\Core\\Controller\\AutoCompleteController', 'get'],
			'the contacts menu' => ['OC\\Core\\Controller\\ContactsMenuController', 'index'],
			'the navigation API' => ['OC\\Core\\Controller\\NavigationController', 'getAppsNavigation'],
			'client login flow' => ['OC\\Core\\Controller\\ClientFlowLoginV2Controller', 'init'],
			'file previews' => ['OC\\Core\\Controller\\PreviewController', 'getPreviewByFileId'],
			'display names of others' => ['OC\\Core\\Controller\\UserController', 'getDisplayNames'],
			'searching users by phone' => ['OCA\\Provisioning_API\\Controller\\UsersController', 'searchByPhoneNumbers'],
			'listing users' => ['OCA\\Provisioning_API\\Controller\\UsersController', 'getUsers'],
			'identity proofs' => ['OC\\Core\\Controller\\OCSController', 'getIdentityProof'],
			'the status of others' => ['OCA\\UserStatus\\Controller\\StatusesController', 'findAll'],
			'help' => ['OCA\\Settings\\Controller\\HelpController', 'help'],
			'a Guests app controller' => ['OCA\\Guests\\Controller\\PageController', 'index'],
			'other first-run wizard actions' => ['OCA\\FirstRunWizard\\Controller\\WizardController', 'anythingElse'],
			'an app installed next year' => ['OCA\\Whatever\\Controller\\PageController', 'index'],
			'a look-alike namespace' => ['OCA\\SocialNetwork\\Controller\\PageController', 'index'],
		];
	}

	#[DataProvider('refused')]
	public function testEverythingElseIsRefused(string $controller, string $method): void {
		$this->assertSame(ExternalScope::DENY, ExternalScope::verdict($controller, $method));
	}

	public function testPersonalSettingsAreAllowedSectionBySection(): void {
		$this->assertSame(ExternalScope::SETTINGS, ExternalScope::verdict(ExternalScope::SETTINGS_CONTROLLER, 'index'));

		foreach (['security', 'personal-info', 'profile-contact', 'language-locale', 'theming', 'notifications'] as $section) {
			$this->assertTrue(ExternalScope::allowsSettingsSection($section), $section);
		}
		foreach (['sharing', 'availability', 'calendar', 'sync-clients', 'workflow', 'additional', ''] as $section) {
			$this->assertFalse(ExternalScope::allowsSettingsSection($section), $section);
		}
		$this->assertTrue(ExternalScope::allowsSettingsSection(ExternalScope::SETTINGS_FALLBACK));
	}

	public function testTheProfilePageIsRecognisedWhereverItLives(): void {
		$this->assertTrue(ExternalScope::isProfilePage('OC\\Core\\Controller\\ProfilePageController'));
		$this->assertTrue(ExternalScope::isProfilePage('\\OCA\\Profile\\Controller\\ProfilePageController'));
		$this->assertFalse(ExternalScope::isProfilePage('OCA\\Profile\\Controller\\ProfileApiController'));
	}
}
