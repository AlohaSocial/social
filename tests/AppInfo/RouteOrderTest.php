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
 * No route of the app is unreachable because another one matches its paths
 * first.
 *
 * The server's matcher takes the first route that matches, in the order the
 * routes were registered: within one controller the attribute routes are read
 * in method-declaration order (a trait's methods after the class's own), the
 * controllers are walked in whatever order the filesystem returns, and
 * `appinfo/routes.php` comes after all of them. So a route with a placeholder
 * where another has a literal hides it when it is read first, and two such
 * routes in different controllers hide each other or not depending on the
 * machine.
 */
class RouteOrderTest extends TestCase {
	/**
	 * @return list<array{verb: string, url: string, regex: string, class: string, owner: string, last: bool}>
	 */
	private static function routes(): array {
		$routes = [];
		foreach (glob(__DIR__ . '/../../lib/Controller/*.php') as $file) {
			$class = 'OCA\\Social\\Controller\\' . basename($file, '.php');
			if (!class_exists($class)) {
				continue;
			}
			$reflection = new ReflectionClass($class);
			if ($reflection->isAbstract()) {
				continue;
			}
			foreach ($reflection->getMethods() as $method) {
				foreach ($method->getAttributes(Route::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
					/** @var Route $route */
					$route = $attribute->newInstance();
					$routes[] = self::route(
						$route->getType() . ' ' . ($route->getRoot() ?? '') . ' ' . $route->getVerb(), $route->getUrl(),
						$route->getRequirements() ?? [], $reflection->getShortName(), $method->getName(), false
					);
				}
			}
		}

		$file = include __DIR__ . '/../../appinfo/routes.php';
		foreach ($file['routes'] ?? [] as $route) {
			[$controller, $method] = explode('#', $route['name']);
			$routes[] = self::route(
				Route::TYPE_FRONTPAGE . '  ' . $route['verb'], $route['url'],
				$route['requirements'] ?? [], $controller . 'Controller', $method, true
			);
		}

		return $routes;
	}

	private static function route(string $verb, string $url, array $requirements, string $class, string $method, bool $last): array {
		$regex = preg_replace_callback(
			'/\\\\\{(\w+)\\\\\}/',
			static fn (array $m): string => '(' . ($requirements[$m[1]] ?? '[^/]+') . ')',
			preg_quote($url, '#')
		);

		return [
			'verb' => $verb,
			'url' => $url,
			'regex' => '#^' . $regex . '$#',
			'class' => $class,
			'owner' => $class . '::' . $method,
			'last' => $last,
		];
	}

	/** A path the route answers, with an ordinary value in every placeholder. */
	private static function probe(string $url): string {
		return (string)preg_replace('/\{\w+\}/', 'x1', $url);
	}

	public function testNoRouteIsHiddenByOneReadBeforeIt(): void {
		$routes = self::routes();
		$hidden = [];

		foreach ($routes as $i => $later) {
			$path = self::probe($later['url']);
			foreach ($routes as $j => $earlier) {
				if ($i === $j || $earlier['verb'] !== $later['verb'] || $earlier['last']
					|| !preg_match($earlier['regex'], $path)) {
					continue;
				}

				$sameClass = $earlier['class'] === $later['class'] && !$later['last'];
				if ($sameClass && $j > $i) {
					// read after it: it can only catch what the first let through
					continue;
				}

				$hidden[] = sprintf(
					'%s %s (%s) is caught by %s (%s)%s',
					$later['verb'], $later['url'], $later['owner'], $earlier['url'], $earlier['owner'],
					$sameClass ? ', declared before it' : ', in another controller or routes.php order'
				);
			}
		}

		$this->assertSame([], $hidden);
	}
}
