<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Standalone unit-test bootstrap.
 *
 * The suite runs without a Nextcloud server: OCP interfaces come from the
 * `nextcloud/ocp` dev dependency, the app's own classes from the composer
 * autoloader. Anything that would need a booted server (database, config,
 * the `OC` container) is mocked in the tests themselves.
 */

// friendica/json-ld trips a compile-time deprecation on PHP 8.5; it is a
// dependency, not our code, so keep it out of the test output.
$previous = error_reporting(E_ALL & ~E_DEPRECATED);
require_once __DIR__ . '/../vendor/autoload.php';
error_reporting($previous);

/**
 * OCP interfaces, from the `nextcloud/ocp` dev dependency.
 *
 * Registered here rather than through composer's `autoload-dev`, deliberately:
 * the app's autoloader is loaded by the server at runtime, so a psr-4 entry for
 * `OCP\` there would shadow the server's real interfaces with these stubs on
 * any installation that has dev dependencies present, and a server class
 * carrying `#[\Override]` against a method the stub lacks then fails to load.
 */
spl_autoload_register(static function (string $class): void {
	if (!str_starts_with($class, 'OCP\\')) {
		return;
	}

	$file = __DIR__ . '/../vendor/nextcloud/ocp/' . str_replace('\\', '/', $class) . '.php';
	if (is_file($file)) {
		require_once $file;
	}
});

if (!defined('PHPUNIT_RUN')) {
	define('PHPUNIT_RUN', 1);
}

// Doctrine's parameter-type constants, which `IQueryBuilder`'s `PARAM_*` are
// defined in terms of: without them no repair step that binds a typed parameter
// can be reached from a test at all.
require_once __DIR__ . '/Helper/doctrine-parameter-types.php';

// `\OC` is the server's static root and is not part of the OCP stubs. A few code
// paths reach it for the container, so provide one that hands out registered test
// doubles (see TestContainer).
if (!class_exists('OC', false)) {
	class OC {
		public static ?\OCA\Social\Tests\Helper\TestContainer $server = null;
	}
}
OC::$server = new \OCA\Social\Tests\Helper\TestContainer();

// Exceptions the app throws that live in the server's private namespace and are
// therefore absent from the OCP stubs. Declared so the paths that raise them
// stay reachable in tests.
if (!class_exists('OC\\User\\NoUserException', false)) {
	class_alias(\OCA\Social\Tests\Helper\NoUserException::class, 'OC\\User\\NoUserException');
}

// `symfony/console` is a dev dependency rather than a `replace`d one: the app's
// own command base class extends Symfony's `Command` and every occ command
// extends that, so the real classes have to be loadable here. Nothing is
// aliased over them.

// `OCP\Files\IRootFolder` extends `OC\Hooks\Emitter`, which is server-private
// and absent from the OCP stubs: without this the interface cannot be loaded at
// all, so nothing that touches the user's files can even be mocked.
if (!interface_exists('OC\\Hooks\\Emitter', false)) {
	class_alias(\OCA\Social\Tests\Helper\HooksEmitter::class, 'OC\\Hooks\\Emitter');
}

// Util::addScript() constructs this one, so a settings page cannot be asked for
// its form without it.
if (!class_exists('OC\\AppScriptDependency', false)) {
	class_alias(\OCA\Social\Tests\Helper\AppScriptDependency::class, 'OC\\AppScriptDependency');
}

// dav, Sabre and a proposed server event, which the external-user code refers
// to and this suite has no copy of
require_once __DIR__ . '/Helper/external-stubs.php';

// The escaping helpers every server-side template is written against. Same
// behaviour as the server's: `p()` escapes, `print_unescaped()` does not.
if (!function_exists('p')) {
	function p($string): void {
		print htmlspecialchars((string)$string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
if (!function_exists('print_unescaped')) {
	function print_unescaped($string): void {
		print (string)$string;
	}
}
