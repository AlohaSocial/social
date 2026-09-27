<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\AppInfo;

use OCP\AppFramework\Http\Attribute\Route;
use PHPUnit\Framework\TestCase;
use ReflectionAttribute;
use ReflectionClass;

/**
 * Every route name the app builds a URL from is a route the app declares.
 *
 * A route's name is derived from its controller and method, so moving a route
 * to another controller renames it. `linkToRoute()` with a name that is gone
 * does not fail: the router logs it at info level and answers an empty string,
 * which then goes out as the picture in a published post or the button in a
 * notification.
 */
class RouteNamesTest extends TestCase {
	/** @return array<string, true> every route name, lower-cased as the router compares them */
	private static function declaredNames(): array {
		$names = [];
		foreach (glob(__DIR__ . '/../../lib/Controller/*Controller.php') as $file) {
			$short = basename($file, 'Controller.php');
			$class = 'OCA\\Social\\Controller\\' . $short . 'Controller';
			if (!class_exists($class) || (new ReflectionClass($class))->isAbstract()) {
				continue;
			}
			foreach ((new ReflectionClass($class))->getMethods() as $method) {
				foreach ($method->getAttributes(Route::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
					/** @var Route $route */
					$route = $attribute->newInstance();
					$names[strtolower('social.' . $short . '.' . $method->getName() . ($route->getPostfix() ?? ''))] = true;
				}
			}
		}

		$file = include __DIR__ . '/../../appinfo/routes.php';
		foreach ($file['routes'] ?? [] as $route) {
			$names[strtolower('social.' . str_replace('#', '.', $route['name']))] = true;
		}

		return $names;
	}

	/** @return array<string, string> route name => the first file that names it */
	private static function usedNames(): array {
		$controllers = [];
		foreach (glob(__DIR__ . '/../../lib/Controller/*Controller.php') as $file) {
			$controllers[strtolower(basename($file, 'Controller.php'))] = true;
		}

		$used = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../lib'));
		foreach ($files as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}
			// a cache is named the same way (`createDistributed('social.interests.feed')`)
			preg_match_all(
				'/(create\w*\(\s*)?[\'"](social\.(\w+)\.\w+)[\'"]/',
				(string)file_get_contents($file->getPathname()), $matches, PREG_SET_ORDER
			);
			foreach ($matches as [, $cache, $name, $controller]) {
				// only a string naming a controller can be a route name
				if ($cache === '' && isset($controllers[strtolower($controller)])) {
					$used[$name] ??= substr($file->getPathname(), strlen(__DIR__ . '/../../'));
				}
			}
		}

		return $used;
	}

	public function testEveryRouteNameTheAppLinksToIsDeclared(): void {
		$declared = self::declaredNames();
		$used = self::usedNames();
		$this->assertNotSame([], $used, 'found no route names at all, so the check checks nothing');

		$missing = [];
		foreach ($used as $name => $file) {
			if (!isset($declared[strtolower($name)])) {
				$missing[] = $name . ' (' . $file . ')';
			}
		}

		$this->assertSame([], $missing);
	}
}
