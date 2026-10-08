<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\MediaApiController;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * What the routes of `MediaApiController` promise, read off the attributes themselves.
 *
 * Nothing here calls the controller. These are the guards that are easy to
 * leave off a new route and impossible to notice missing afterwards, which is
 * exactly what happened to `/media/{uuid}`.
 */
class MediaApiControllerTest extends TestCase {
	/**
	 * Every method of the controller with the routes declared on it.
	 *
	 * @return array<string, string[]> method name => the urls it answers
	 */
	private function routes(): array {
		$routes = [];

		foreach ((new ReflectionClass(MediaApiController::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			foreach ($method->getAttributes(FrontpageRoute::class) as $attribute) {
				$arguments = $attribute->getArguments();
				$url = (string)($arguments['url'] ?? $arguments[1] ?? '');
				if ($url !== '') {
					$routes[$method->getName()][] = $url;
				}
			}
		}

		return $routes;
	}

	private function has(string $method, string $attribute): bool {
		return (new ReflectionMethod(MediaApiController::class, $method))->getAttributes($attribute) !== [];
	}

	/**
	 * Every route that hands back stored bytes carries a ceiling.
	 *
	 * `/media/{uuid}` was the one that did not, while the six beside it — the
	 * stream, the playlists and the ladder — all did. A uuid is unguessable but
	 * not secret: it travels with the post it belongs to, so one harvested from
	 * a public timeline could be asked for in a loop, with a `Range` header
	 * each time, for as long as anybody cared to.
	 *
	 * Asserted over *every* `/media/` GET rather than over a list of names, so
	 * that the next one added is covered without anybody remembering to add it
	 * here.
	 */
	public function testEveryByteRouteCarriesARateLimit(): void {
		$bare = [];

		foreach ($this->routes() as $method => $urls) {
			foreach ($urls as $url) {
				if (!str_starts_with($url, '/media/')) {
					continue;
				}

				if (!$this->has($method, AnonRateLimit::class) || !$this->has($method, UserRateLimit::class)) {
					$bare[] = $method . ' (' . $url . ')';
				}
			}
		}

		$this->assertSame(
			[],
			$bare,
			'these routes serve stored bytes to anybody holding the address, with no ceiling'
		);
	}

	/**
	 * A write without a ceiling is a write anybody with a token can repeat.
	 *
	 * `mediaUpdate()` was one: its POST sibling has carried a limit since it
	 * was written, and the PUT beside it had none.
	 */
	public function testEveryWriteCarriesARateLimit(): void {
		$bare = [];

		foreach ($this->routes() as $method => $urls) {
			foreach ($urls as $url) {
				$verb = $this->verbOf($method, $url);
				if (!in_array($verb, ['POST', 'PUT', 'DELETE'], true)) {
					continue;
				}

				if (!$this->has($method, AnonRateLimit::class) || !$this->has($method, UserRateLimit::class)) {
					$bare[] = $verb . ' ' . $url;
				}
			}
		}

		$this->assertSame([], $bare, 'these writes have no ceiling');
	}

	/** The verb a route was declared with. */
	private function verbOf(string $method, string $url): string {
		foreach ((new ReflectionMethod(MediaApiController::class, $method))->getAttributes(FrontpageRoute::class) as $attribute) {
			$arguments = $attribute->getArguments();
			if ((string)($arguments['url'] ?? $arguments[1] ?? '') === $url) {
				return strtoupper((string)($arguments['verb'] ?? $arguments[0] ?? ''));
			}
		}

		return '';
	}
}
