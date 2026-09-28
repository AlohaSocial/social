<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\External;

/**
 * What an external user may reach outside the Social app.
 *
 * A list of what is allowed, not of what is refused: a controller nobody
 * thought of — an app installed next year — is refused. The allowed set is
 * Social itself, logging in and out with two-factor authentication, the
 * personal settings sections for the account's own details, password and
 * security, appearance and notifications, the notifications the Social
 * notifier sends, and the few assets every page loads.
 *
 * Controllers are named by class rather than by route, because the router
 * hands the middleware a controller and a method, and a class name cannot be
 * spelled two ways.
 */
final class ExternalScope {
	public const ALLOW = 'allow';
	public const DENY = 'deny';
	public const SETTINGS = 'settings';

	/** Whole namespaces: this app, theming, notifications, every two-factor provider. */
	private const NAMESPACES = [
		'OCA\\Social\\',
		'OCA\\Theming\\',
		'OCA\\Notifications\\',
		'OCA\\TwoFactor',
	];

	/** Whole controllers. */
	private const CONTROLLERS = [
		// logging in, out, two-factor, password reset and confirmation
		'OC\\Core\\Controller\\LoginController',
		'OC\\Core\\Controller\\TwoFactorChallengeController',
		'OC\\Core\\Controller\\LostController',
		'OC\\Core\\Controller\\WebAuthnController',
		'OC\\Core\\Controller\\CSRFTokenController',
		// what every page loads
		'OC\\Core\\Controller\\AvatarController',
		'OC\\Core\\Controller\\GuestAvatarController',
		'OC\\Core\\Controller\\CssController',
		'OC\\Core\\Controller\\JsController',
		'OC\\Core\\Controller\\OCJSController',
		'OC\\Core\\Controller\\WellKnownController',
		'OC\\Core\\Controller\\WalledGardenController',
		'OC\\Core\\Controller\\UnsupportedBrowserController',
		'OC\\Core\\Controller\\ErrorController',
		// the personal settings sections allowed below: password, sessions,
		// passwordless login, the account's own fields and profile visibility
		'OCA\\Settings\\Controller\\AuthSettingsController',
		'OCA\\Settings\\Controller\\ChangePasswordController',
		'OCA\\Settings\\Controller\\WebAuthnController',
		'OCA\\Settings\\Controller\\UsersController',
		'OCA\\Provisioning_API\\Controller\\PreferencesController',
		'OCA\\Provisioning_API\\Controller\\VerificationController',
		'OC\\Core\\Controller\\ProfileApiController',
		'OCA\\Profile\\Controller\\ProfileApiController',
		// one's own status in the account menu, not anybody else's
		'OCA\\UserStatus\\Controller\\UserStatusController',
		'OCA\\UserStatus\\Controller\\PredefinedStatusController',
		'OCA\\UserStatus\\Controller\\HeartbeatController',
	];

	/** Single methods of controllers that also serve things an external user may not see. */
	private const METHODS = [
		// The core welcome wizard records that its one-time introduction was
		// completed through DELETE /apps/firstrunwizard/wizard. Allow only this
		// method so external accounts do not see it again at every login.
		'OCA\\FirstRunWizard\\Controller\\WizardController' => ['disable'],
		'OC\\Core\\Controller\\OCSController' => ['getCapabilities', 'getConfig'],
		'OCA\\Provisioning_API\\Controller\\UsersController' => [
			'getCurrentUser', 'getUser', 'editUser', 'editUserMultiValue',
			'getEditableFields', 'getEditableFieldsForUser',
		],
	];

	public const SETTINGS_CONTROLLER = 'OCA\\Settings\\Controller\\PersonalSettingsController';

	/**
	 * The personal settings sections, by the ids of Nextcloud 34 to 36:
	 * `personal-info` became `profile-contact` and `language-locale`.
	 */
	public const SETTINGS_SECTIONS = [
		'personal-info', 'profile-contact', 'language-locale', 'security', 'theming', 'notifications',
	];

	public const SETTINGS_FALLBACK = 'security';

	/** Core's profile page, which moved from core into its own app in 36. */
	public const PROFILE_PAGES = [
		'OC\\Core\\Controller\\ProfilePageController',
		'OCA\\Profile\\Controller\\ProfilePageController',
	];

	/**
	 * Whether an external user may call this method.
	 *
	 * @return self::ALLOW|self::DENY|self::SETTINGS `settings` is the personal
	 *                                               settings page, allowed for the sections above only
	 */
	public static function verdict(string $controller, string $method): string {
		$controller = ltrim($controller, '\\');

		foreach (self::NAMESPACES as $namespace) {
			if (str_starts_with($controller, $namespace)) {
				return self::ALLOW;
			}
		}
		if (in_array($controller, self::CONTROLLERS, true)) {
			return self::ALLOW;
		}
		if (in_array($method, self::METHODS[$controller] ?? [], true)) {
			return self::ALLOW;
		}
		if ($controller === self::SETTINGS_CONTROLLER) {
			return self::SETTINGS;
		}

		return self::DENY;
	}

	public static function allowsSettingsSection(string $section): bool {
		return in_array($section, self::SETTINGS_SECTIONS, true);
	}

	public static function isProfilePage(string $controller): bool {
		return in_array(ltrim($controller, '\\'), self::PROFILE_PAGES, true);
	}

	/**
	 * The account menu and app menu entries an external user is shown, by
	 * navigation entry id: their settings, appearance and logging out.
	 */
	public const MENU_ENTRIES = ['settings_personal', 'accessibility_settings', 'logout'];
}
