<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Helper;

use BadMethodCallException;
use LogicException;
use OCA\Social\Controller\ApiController;
use ReflectionMethod;

/**
 * The client API's controllers built from one set of test doubles, answering
 * each call on the controller that declares the route.
 *
 * Every constructor parameter is looked up by name in the dependencies it is
 * given, so one fixture serves every controller of the API, and a test calls
 * `statusNew()` or `instance()` without knowing which class answers it.
 */
final class ApiControllerRouter {
	/** @var list<class-string> the controllers that extend MastodonApiController */
	public const CONTROLLERS = [
		ApiController::class,
	];

	/** @var array<class-string, object> */
	private array $built = [];

	/** @param array<string, object> $dependencies by constructor parameter name */
	public function __construct(
		private array $dependencies,
	) {
	}

	/**
	 * @template T of object
	 * @param class-string<T> $class
	 * @return T
	 */
	public function controller(string $class): object {
		if (!isset($this->built[$class])) {
			$arguments = [];
			foreach ((new ReflectionMethod($class, '__construct'))->getParameters() as $parameter) {
				$arguments[] = $this->dependencies[$parameter->getName()]
					?? throw new LogicException('no test double named $' . $parameter->getName() . ' for ' . $class);
			}
			$this->built[$class] = new $class(...$arguments);
		}

		return $this->built[$class];
	}

	/** @return class-string the controller whose public method this is */
	public static function controllerOf(string $method): string {
		foreach (self::CONTROLLERS as $class) {
			if (method_exists($class, $method) && (new ReflectionMethod($class, $method))->isPublic()) {
				return $class;
			}
		}

		throw new BadMethodCallException('no client API controller declares ' . $method . '()');
	}

	public function __call(string $method, array $arguments): mixed {
		return $this->controller(self::controllerOf($method))->$method(...$arguments);
	}
}
