<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Helper;

use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Stand-in for `\OC::$server` in unit tests.
 *
 * A few code paths reach the container statically (`\OCP\Server::get()`,
 * `\OC::$server->get()`) instead of taking their dependency through the
 * constructor. Tests that hit such a path register what it should resolve
 * to; anything unregistered is a NotFound exception naming the class, so a
 * hidden dependency shows up as a clear failure rather than a fatal.
 */
class TestContainer implements ContainerInterface {
	/** @var array<string, object> */
	private array $services = [];

	public function __construct() {
		$this->reset();
	}

	public function register(string $id, object $service): void {
		$this->services[$id] = $service;
	}

	/**
	 * Back to the defaults: a silent logger, a session with nobody in it, a
	 * URL generator whose addresses follow from what was asked, and a php.ini
	 * with no upload limits.
	 *
	 * The session is a default rather than something each test registers
	 * because `Response::getHeaders()` resolves one on every render from
	 * Nextcloud 35 onward. See {@see AnonymousUserSession}.
	 */
	public function reset(): void {
		$this->services = [
			LoggerInterface::class => new NullLogger(),
			IUserSession::class => new AnonymousUserSession(),
			IURLGenerator::class => new PredictableUrlGenerator(),
			PhpIni::SERVICE => new PhpIni(),
		];
	}

	public function get(string $id): object {
		if (!isset($this->services[$id])) {
			throw new class('Service ' . $id . ' is not registered in the test container') extends \RuntimeException implements NotFoundExceptionInterface {
			};
		}

		return $this->services[$id];
	}

	public function has(string $id): bool {
		return isset($this->services[$id]);
	}

	/** Same as get(), for code calling the legacy `query()` name. */
	public function query(string $id): object {
		return $this->get($id);
	}
}
