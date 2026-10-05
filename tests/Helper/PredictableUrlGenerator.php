<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Helper;

use OCP\IURLGenerator;

/**
 * The URL generator the test container answers with by default: every address
 * is predictable from what was asked, so a test that only needs *an* address
 * — the Account export building a placeholder picture, for one — gets one
 * without registering a double of its own. A test that cares about the exact
 * address still registers its own.
 */
class PredictableUrlGenerator implements IURLGenerator {
	public const BASE = 'https://cloud.example.org';

	public function linkToRoute(string $routeName, array $arguments = []): string {
		return '/' . $routeName . ($arguments === [] ? '' : '/' . implode('/', array_map('strval', $arguments)));
	}

	public function linkToRouteAbsolute(string $routeName, array $arguments = []): string {
		return self::BASE . $this->linkToRoute($routeName, $arguments);
	}

	public function linkToOCSRouteAbsolute(string $routeName, array $arguments = []): string {
		return self::BASE . '/ocs/v2.php' . $this->linkToRoute($routeName, $arguments);
	}

	public function linkTo(string $appName, string $file, array $args = []): string {
		return '/apps/' . $appName . '/' . $file . ($args === [] ? '' : '?' . http_build_query($args));
	}

	public function imagePath(string $appName, string $file): string {
		return '/apps/' . $appName . '/img/' . $file;
	}

	public function getAbsoluteURL(string $url): string {
		return str_starts_with($url, 'http') ? $url : self::BASE . $url;
	}

	public function linkToDocs(string $key): string {
		return 'https://docs.example.org/' . $key;
	}

	public function linkToDefaultPageUrl(): string {
		return self::BASE . '/apps/files/';
	}

	public function getBaseUrl(): string {
		return self::BASE;
	}

	public function getWebroot(): string {
		return '';
	}

	public function linkToRemote(string $service): string {
		return self::BASE . '/remote.php/' . $service;
	}

	public function getLogoutUrl(): string {
		return self::BASE . '/logout';
	}
}
