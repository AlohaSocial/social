<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\Route;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use PHPUnit\Framework\TestCase;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

/**
 * The rate-limit attributes on every route, read the way the server reads them.
 */
class RateLimitAttributesTest extends TestCase {
	/**
	 * A route that limits only sessioned callers limits almost nobody here.
	 *
	 * Nextcloud applies `UserRateLimit` to a caller with a Nextcloud session
	 * and `AnonRateLimit` to everyone else, and a client holding one of this
	 * app's OAuth tokens has no session: Nextcloud counts it as anonymous. A
	 * `PublicPage` route carrying only `UserRateLimit` therefore has no limit
	 * at all for a token client, and the app's own default budget
	 * (`RateLimitService::appliesDefaultTo()`) skips it too, because it
	 * declares a limit of its own.
	 *
	 * A route without `PublicPage` cannot be reached that way: Nextcloud's
	 * `SecurityMiddleware` refuses a caller who is not signed in before its
	 * `RateLimitingMiddleware` runs, so every caller that route sees is one
	 * `UserRateLimit` applies to. Those are left out of the rule rather than
	 * listed, so a route that becomes public is held to it at once.
	 */
	public function testEveryPublicRateLimitedRouteAlsoLimitsSessionlessCallers(): void {
		$bare = [];
		$checked = 0;

		foreach (self::routeMethods() as $method) {
			if ($method->getAttributes(PublicPage::class) === []
				|| $method->getAttributes(UserRateLimit::class) === []) {
				continue;
			}

			$checked++;
			if ($method->getAttributes(AnonRateLimit::class) === []) {
				$bare[] = $method->getDeclaringClass()->getShortName() . '::' . $method->getName();
			}
		}

		// guards against a scan that silently found nothing to check
		$this->assertGreaterThan(50, $checked);
		$this->assertSame([], $bare, 'these routes are unthrottled for a bearer-token client');
	}

	/**
	 * Every method of a controller in `lib/Controller` that declares a route,
	 * found the way `OC\Route\Router::getAttributeRoutes()` finds them.
	 *
	 * @return list<ReflectionMethod>
	 */
	private static function routeMethods(): array {
		$methods = [];
		foreach (glob(__DIR__ . '/../../lib/Controller/*Controller.php') ?: [] as $file) {
			$class = 'OCA\\Social\\Controller\\' . basename($file, '.php');
			foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				if ($method->getDeclaringClass()->getName() === $class
					&& $method->getAttributes(Route::class, ReflectionAttribute::IS_INSTANCEOF) !== []) {
					$methods[] = $method;
				}
			}
		}

		return $methods;
	}
}
