<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests;

use OCP\AppFramework\Http\Attribute\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionAttribute;
use ReflectionClass;

/**
 * Keeps `docs/` mechanically in sync with the code.
 *
 * Most of this is parsed out of the source files with regular expressions,
 * because the classes involved (commands, in particular) extend
 * server-internal base classes that are not autoloadable in the standalone
 * test suite. The routes are the exception: they are `#[FrontpageRoute]`
 * attributes on controller methods, and controllers only extend
 * `OCP\AppFramework\Controller`, which the OCP stubs provide — so they are
 * read by reflection, the same way the server reads them.
 *
 * Only contracts that a reader of the docs would act on are asserted: occ
 * command names, HTTP route URLs, supported version ranges and the app
 * version. Counts of internal classes are deliberately not asserted.
 */
class DocumentationTest extends TestCase {
	/** Endpoints the app serves outside its route table, so the doc may name them. */
	private const NON_ROUTE_ENDPOINTS = [
		// Registered as an IHandler in lib/WellKnown/WebfingerHandler.php, not as a route.
		'/.well-known/webfinger',
	];

	/** The hand-written documents whose claims are checked here. */
	private const DOCUMENTATION_FILES = [
		'README.md',
		'docs/API.md',
		'docs/Architecture.md',
		'docs/OCC-Commands.md',
		'docs/User-Guide.md',
	];

	/**
	 * Options every command has without declaring one, so a section may name
	 * them with no `addOption()` to match: `--output` comes from the app's own
	 * `SocialCommand`, the rest from Symfony's default definition.
	 */
	private const INHERITED_OPTIONS = [
		'output',
		'help',
		'quiet',
		'verbose',
		'version',
		'ansi',
		'no-ansi',
		'no-interaction',
	];

	public function testInfoXmlRegistersEveryCommandClass(): void {
		$onDisk = $this->commandClassesOnDisk();
		$registered = $this->registeredCommandClasses();

		$this->assertSame(
			[],
			array_values(array_diff($onDisk, $registered)),
			'These command classes exist in lib/Command/ but are not registered:'
			. ' add a <command> entry for each in appinfo/info.xml.'
		);
		$this->assertSame(
			[],
			array_values(array_diff($registered, $onDisk)),
			'These <command> entries in appinfo/info.xml have no class in lib/Command/:'
			. ' remove them from appinfo/info.xml or add the missing class.'
		);
	}

	public function testEveryRegisteredCommandIsDocumented(): void {
		$registered = $this->registeredCommandNames();
		$documented = $this->documentedCommandNames();

		$this->assertSame(
			[],
			array_values(array_diff($registered, $documented)),
			'These occ commands are registered but undocumented:'
			. ' document them in docs/OCC-Commands.md.'
		);
		$this->assertSame(
			[],
			array_values(array_diff($documented, $registered)),
			'These occ commands are documented in docs/OCC-Commands.md but not registered:'
			. ' remove them from docs/OCC-Commands.md, or register them in appinfo/info.xml.'
		);
	}

	public function testEveryRouteIsDocumented(): void {
		$routes = $this->routeUrls();
		$documented = $this->documentedPaths();

		$this->assertSame(
			[],
			array_values(array_diff($routes, $documented)),
			'These routes of the app are undocumented:'
			. ' document these routes in docs/API.md (keep the {placeholder} names verbatim).'
		);
	}

	public function testDocumentedPathsAreRealRoutes(): void {
		$routes = $this->routeUrls();
		$documented = $this->documentedPaths();

		$this->assertSame(
			[],
			array_values(array_diff($documented, $routes)),
			'These paths in docs/API.md are not routes:'
			. ' remove them from docs/API.md, or fix the path (a wrong {placeholder} name'
			. ' counts as a wrong path) so it matches the url of a route.'
		);
	}

	/**
	 * The method beside a route in `docs/API.md` is what a client will send.
	 *
	 * A path that exists is not enough: `GET` where the route is a `POST` is a
	 * 405 for whoever believed the document, and the existing check compares
	 * only paths. It has happened — a route changed verb and the table did
	 * not.
	 *
	 * Only rows that name exactly one path are compared: a row like
	 * "`/api/v1/statuses/{nid}/favourite`, `…/unfavourite`" is prose about two
	 * routes, and reading a verb off it would be guessing.
	 */
	public function testDocumentedMethodsMatchTheRoutes(): void {
		$verbs = [];
		foreach ($this->attributeRoutes() as $route) {
			$path = $this->normalisePath((string)$route['url']);
			$verbs[$path][] = strtoupper((string)($route['verb'] ?? 'GET'));
		}
		foreach ($this->arrayRoutes() as $route) {
			$path = $this->normalisePath((string)$route['url']);
			$verbs[$path][] = strtoupper((string)($route['verb'] ?? 'GET'));
		}

		$wrong = [];
		foreach (explode("\n", $this->read('docs/API.md')) as $line) {
			$line = trim($line);
			if (!str_starts_with($line, '|')) {
				continue;
			}

			$cells = array_map('trim', explode('|', trim($line, '|')));
			if (count($cells) < 2) {
				continue;
			}

			$method = strtoupper(trim($cells[0], ' `*'));
			if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
				continue;
			}

			preg_match_all('/`(\/[^`]*)`/', $cells[1], $matches);
			if (count($matches[1]) !== 1) {
				continue;
			}

			$path = $this->normalisePath($matches[1][0]);
			if (in_array($matches[1][0], self::NON_ROUTE_ENDPOINTS, true) || !isset($verbs[$path])) {
				// a path that is not a route at all is the other test's to report
				continue;
			}

			if (!in_array($method, $verbs[$path], true)) {
				$wrong[] = $method . ' ' . $path . ' (the route answers '
					. implode('/', array_unique($verbs[$path])) . ')';
			}
		}

		$this->assertSame(
			[],
			$wrong,
			'docs/API.md states a method the route does not answer; a client that'
			. " believes the document gets a 405:\n" . implode("\n", $wrong)
		);
	}

	public function testSupportedNextcloudVersionsAreDocumented(): void {
		$doc = $this->read('docs/Architecture.md');

		$this->assertSame(
			$this->dependencyVersion('nextcloud', 'min'),
			$this->documentedVersion($doc, 'nextcloud', 'min'),
			'docs/Architecture.md states the wrong minimum Nextcloud version:'
			. ' state the <nextcloud min-version> from appinfo/info.xml.'
		);
		$this->assertSame(
			$this->dependencyVersion('nextcloud', 'max'),
			$this->documentedVersion($doc, 'nextcloud', 'max'),
			'docs/Architecture.md states the wrong maximum Nextcloud version:'
			. ' state the <nextcloud max-version> from appinfo/info.xml.'
		);
	}

	public function testSupportedPhpVersionsAreDocumented(): void {
		$doc = $this->read('docs/Architecture.md');

		$this->assertSame(
			$this->dependencyVersion('php', 'min'),
			$this->documentedVersion($doc, 'php', 'min'),
			'docs/Architecture.md states the wrong minimum PHP version:'
			. ' state the <php min-version> from appinfo/info.xml.'
		);
		$this->assertSame(
			$this->dependencyVersion('php', 'max'),
			$this->documentedVersion($doc, 'php', 'max'),
			'docs/Architecture.md states the wrong maximum PHP version:'
			. ' state the <php max-version> from appinfo/info.xml.'
		);
	}

	public function testAppVersionMatchesComposerVersion(): void {
		$composer = json_decode($this->read('composer.json'), true, 512, JSON_THROW_ON_ERROR);
		// Not an early return: this check used to disable itself when the key
		// was absent, so deleting "version" silenced it instead of failing it.
		$this->assertArrayHasKey(
			'version',
			$composer,
			'composer.json declares no version, so nothing here compares it with'
			. ' appinfo/info.xml: add "version" to composer.json.'
		);

		$this->assertSame(
			$this->appVersion(),
			(string)$composer['version'],
			'The version in composer.json disagrees with <version> in appinfo/info.xml:'
			. ' bump "version" in composer.json to the appinfo/info.xml version'
			. ' (appinfo/info.xml is the release version of the app).'
		);
	}

	/**
	 * Phrasings that assert a feature works.
	 *
	 * Each requires the verb and its complement to be adjacent, which is what
	 * keeps a denial out: "is not supported", "are never implemented" and
	 * "does not work correctly" all put a word in between and match none of
	 * these. Nothing broader belongs here — a check that fires on ordinary
	 * prose is a check somebody deletes.
	 */
	private const WORKS_PATTERNS = [
		'/\b(?:is|are)\s+(?:fully\s+)?supported\b/i',
		'/\b(?:is|are)\s+(?:fully\s+)?implemented\b/i',
		'/\bworks?\s+(?:fine|correctly|as expected|as documented)\b/i',
	];

	/**
	 * A bullet under "Not implemented yet" may not say the feature works.
	 *
	 * That section's own intro says "These are absent from the code today, not
	 * merely rough edges", and nine of its ten bullets used to say the
	 * opposite — blocking, muting, reporting, locked accounts, profile fields
	 * and polls all described as supported, because as each one landed its
	 * entry was rewritten in place instead of being moved up to the feature
	 * list. Nothing mechanical caught it. This does.
	 *
	 * Only phrasing, though: a bullet that describes a working feature in
	 * words this list does not know still passes. See
	 * testNoDocumentedDenialOfARouteIsFalse() for the same mistake caught by
	 * fact rather than by wording.
	 */
	public function testNothingUnderNotImplementedIsDescribedAsWorking(): void {
		$offenders = [];
		foreach ($this->bulletsOfNotImplementedSection() as $bullet) {
			foreach (self::WORKS_PATTERNS as $pattern) {
				if (preg_match($pattern, $bullet) === 1) {
					$offenders[] = $bullet;
					break;
				}
			}
		}

		$this->assertSame(
			[],
			$offenders,
			'These bullets sit under a "Not implemented" heading in README.md and say the'
			. ' feature is supported: move them into the Features list, or describe what is'
			. ' actually missing.'
		);
	}

	public function testTheNotImplementedSectionIsStillThereToCheck(): void {
		$this->assertNotEmpty(
			$this->bulletsOfNotImplementedSection(),
			'No bullets found under a "Not implemented" heading in README.md:'
			. ' testNothingUnderNotImplementedIsDescribedAsWorking has stopped checking anything.'
		);
	}

	/**
	 * Every table the code declares is in the schema table of the doc.
	 *
	 * `social_report`, `social_moderation` and `social_stream_card` were all
	 * missing, the last appearing nowhere in the document at all.
	 */
	public function testEveryDeclaredTableIsInTheSchemaTable(): void {
		$declared = $this->declaredTables();
		$documented = $this->documentedTables();

		$this->assertNotEmpty($declared, 'No TABLE_* constants found in lib/Db/CoreRequestBuilder.php.');
		$this->assertSame(
			[],
			array_values(array_diff($declared, $documented)),
			'These tables are declared in CoreRequestBuilder but missing from the schema'
			. ' table in docs/Architecture.md: add a row for each.'
		);
		$this->assertSame(
			[],
			array_values(array_diff($documented, $declared)),
			'These tables have a row in the schema table of docs/Architecture.md but are'
			. ' not declared in CoreRequestBuilder: remove the row, or fix the name.'
		);
	}

	/**
	 * Every option a command declares has a row in that command's section, and
	 * every option documented there exists.
	 *
	 * Until this existed, only command *names* were checked, so a documented
	 * flag was worth nothing: the doc claimed for months that key rotation was
	 * not part of `social:cache:refresh` while `--rotate-keys` sat in its
	 * `configure()`, and an operator who read that never rotated a key.
	 *
	 * Options every command inherits from `SocialCommand` and Symfony are not
	 * declared per command and are not required to have a row.
	 */
	public function testDocumentedCommandOptionsMatchTheCode(): void {
		$sections = $this->documentedCommandSections();
		$problems = [];

		foreach ($this->commandFiles() as $code) {
			$name = $this->commandNameOf($code);
			if ($name === null) {
				continue;
			}

			$declared = $this->configuredNames($code, 'addOption');
			$documented = $this->documentedOptionNames($sections[$name] ?? '');

			foreach (array_diff($declared, $documented) as $option) {
				$problems[] = $name . ': --' . $option . ' is declared in lib/Command/'
					. ' but has no row in docs/OCC-Commands.md';
			}
			foreach (array_diff($documented, $declared, self::INHERITED_OPTIONS) as $option) {
				$problems[] = $name . ': --' . $option . ' is documented in'
					. ' docs/OCC-Commands.md but the command declares no such option';
			}
		}

		$this->assertSame([], $problems, implode("\n", $problems));
	}

	/**
	 * The same for arguments, which are positional: documenting one the
	 * command does not take, or leaving one out, makes the synopsis wrong in a
	 * way a reader cannot see.
	 */
	public function testDocumentedCommandArgumentsMatchTheCode(): void {
		$sections = $this->documentedCommandSections();
		$problems = [];

		foreach ($this->commandFiles() as $code) {
			$name = $this->commandNameOf($code);
			if ($name === null) {
				continue;
			}

			$declared = $this->configuredNames($code, 'addArgument');
			$documented = $this->documentedArgumentNames($sections[$name] ?? '');

			foreach (array_diff($declared, $documented) as $argument) {
				$problems[] = $name . ': the ' . $argument . ' argument has no row'
					. ' in the argument table of docs/OCC-Commands.md';
			}
			foreach (array_diff($documented, $declared) as $argument) {
				$problems[] = $name . ': docs/OCC-Commands.md documents an argument '
					. $argument . ' the command does not declare';
			}
		}

		$this->assertSame([], $problems, implode("\n", $problems));
	}

	/**
	 * Every repair step registered in appinfo/info.xml has a row in the
	 * integration table of docs/Architecture.md, and every row is registered.
	 *
	 * `CacheFeaturedCollections` ran on every upgrade for a release without
	 * appearing in the document at all, which is the same shape of miss the
	 * `<command>` check above was written for.
	 */
	public function testEveryRepairStepIsDocumented(): void {
		$registered = $this->registeredRepairSteps();
		$documented = $this->documentedRepairSteps();

		$this->assertNotEmpty($registered, 'appinfo/info.xml registers no repair steps.');
		$this->assertSame(
			[],
			array_values(array_diff($registered, $documented)),
			'These repair steps are registered in appinfo/info.xml but missing from the'
			. ' integration table in docs/Architecture.md: add a row for each.'
		);
		$this->assertSame(
			[],
			array_values(array_diff($documented, $registered)),
			'These repair steps have a row in docs/Architecture.md but are not registered'
			. ' in appinfo/info.xml: remove the row, or register the step.'
		);
	}

	public function testArchitectureStatesTheAppVersion(): void {
		$found = preg_match(
			'/\*\*App version:\*\*\s*`?([0-9][0-9.]*)`?/',
			$this->read('docs/Architecture.md'),
			$match
		);
		$this->assertSame(
			1,
			$found,
			'docs/Architecture.md no longer states an "**App version:**", so nothing'
			. ' compares it with appinfo/info.xml.'
		);

		$this->assertSame(
			$this->appVersion(),
			$match[1],
			'docs/Architecture.md states the wrong app version: state the <version>'
			. ' from appinfo/info.xml.'
		);
	}

	/**
	 * A sentence that denies a route exists has to be right.
	 *
	 * The route tables of docs/API.md are checked both ways already, but prose
	 * saying "there is no `/api/v1/lists` route" was checked by nothing — and a
	 * documented absence that is in fact present is the direction the rest of
	 * this file is blind in. Only an explicit denial is examined, so naming a
	 * real route in ordinary prose is not an offence.
	 */
	public function testNoDocumentedDenialOfARouteIsFalse(): void {
		$routes = $this->routeUrls();
		$offenders = [];

		foreach ($this->documentationFiles() as $file => $content) {
			preg_match_all('/\bno\s+`(\/[^`]+)`\s+route\b/i', $content, $matches);
			foreach ($matches[1] as $path) {
				if (in_array($this->normalisePath($path), $routes, true)) {
					$offenders[] = $file . ' says there is no ' . $path . ' route, and there is';
				}
			}
		}

		$this->assertSame([], $offenders, implode("\n", $offenders));
	}

	/**
	 * A symbol the documentation says is commented out may not be called by
	 * live code.
	 *
	 * docs/OCC-Commands.md told operators that key-pair rotation was
	 * unavailable because "the `blindKeyRotation()` call is commented out",
	 * six lines after documenting the flag that calls it. Nothing could catch
	 * that: every check here asks whether a documented feature exists, and
	 * this was the opposite — a feature that exists, documented as absent.
	 */
	public function testNothingDocumentedAsCommentedOutIsLive(): void {
		$offenders = [];

		foreach ($this->documentationFiles() as $file => $content) {
			preg_match_all(
				'/`([A-Za-z_][A-Za-z0-9_]*)\(\)`(?:(?!`[A-Za-z_]).){0,160}?\bcommented out\b/is',
				$content,
				$matches
			);

			foreach (array_unique($matches[1]) as $symbol) {
				$callers = $this->liveCallersOf($symbol);
				if ($callers !== []) {
					$offenders[] = $file . ' says ' . $symbol . '() is commented out, but '
						. implode(', ', $callers) . ' is not commented';
				}
			}
		}

		$this->assertSame([], $offenders, implode("\n", $offenders));
	}

	public function testAppVersionMatchesPackageVersion(): void {
		$package = json_decode($this->read('package.json'), true, 512, JSON_THROW_ON_ERROR);
		$this->assertArrayHasKey('version', $package, 'package.json declares no version.');

		$this->assertSame(
			$this->appVersion(),
			(string)$package['version'],
			'The version in package.json disagrees with <version> in appinfo/info.xml:'
			. ' bump "version" in package.json to the appinfo/info.xml version'
			. ' (appinfo/info.xml is the release version of the app).'
		);
	}

	/**
	 * The list items under README.md's "Not implemented yet" heading, up to
	 * the next heading.
	 *
	 * @return list<string>
	 */
	private function bulletsOfNotImplementedSection(): array {
		$lines = explode("\n", $this->read('README.md'));
		$bullets = [];
		$inside = false;

		foreach ($lines as $line) {
			if (preg_match('/^#{2,4}\s.*not implemented/i', $line) === 1) {
				$inside = true;
				continue;
			}

			if ($inside && str_starts_with($line, '#')) {
				break;
			}

			if ($inside && preg_match('/^\s*[-*]\s+(.*)$/', $line, $match) === 1) {
				$bullets[] = $match[1];
			}
		}

		return $bullets;
	}

	/**
	 * Table names from the `TABLE_*` constants of CoreRequestBuilder, sorted.
	 *
	 * @return list<string>
	 */
	private function declaredTables(): array {
		preg_match_all(
			'/const\s+TABLE_[A-Z_]+\s*=\s*[\'"]([a-z0-9_]+)[\'"]/',
			$this->read('lib/Db/CoreRequestBuilder.php'),
			$matches
		);

		return $this->normalise($matches[1]);
	}

	/**
	 * Table names in the first column of the schema table of
	 * docs/Architecture.md, sorted.
	 *
	 * @return list<string>
	 */
	private function documentedTables(): array {
		$tables = [];
		foreach (explode("\n", $this->read('docs/Architecture.md')) as $line) {
			if (preg_match('/^\|\s*`(social_[a-z0-9_]+)`\s*\|/', $line, $match) === 1) {
				$tables[] = $match[1];
			}
		}

		return $this->normalise($tables);
	}

	/**
	 * The body of each command's own section in docs/OCC-Commands.md, from its
	 * heading up to the next heading of any level.
	 *
	 * @return array<string, string> command name => section body
	 */
	private function documentedCommandSections(): array {
		$sections = [];
		$current = null;

		foreach (explode("\n", $this->read('docs/OCC-Commands.md')) as $line) {
			if (preg_match('/^#{2,4}[^\S\n]+`(social:[a-z0-9:_-]+)`/', $line, $match) === 1) {
				$current = $match[1];
				$sections[$current] = '';
				continue;
			}

			if (str_starts_with($line, '#')) {
				$current = null;
				continue;
			}

			if ($current !== null) {
				$sections[$current] .= $line . "\n";
			}
		}

		return $sections;
	}

	/**
	 * The names passed to `addOption()` / `addArgument()` in one command's
	 * source, sorted.
	 *
	 * @param string $method `addOption` or `addArgument`
	 * @return string[]
	 */
	private function configuredNames(string $code, string $method): array {
		preg_match_all(
			'/->' . $method . '\(\s*[\'"]([a-zA-Z0-9][a-zA-Z0-9_-]*)[\'"]/',
			$code,
			$matches
		);

		return $this->normalise($matches[1]);
	}

	/**
	 * Long option names written as `--name` anywhere in one command's section,
	 * sorted. A short alias (`-f`) is documented alongside its long form, so
	 * only the long form is compared.
	 *
	 * @return string[]
	 */
	private function documentedOptionNames(string $section): array {
		preg_match_all('/`--([a-zA-Z0-9][a-zA-Z0-9_-]*)`/', $section, $matches);

		return $this->normalise($matches[1]);
	}

	/**
	 * Argument names from the argument table of one command's section, sorted.
	 *
	 * A row of that table is `| \`name\` | Yes | …`; the Required column is
	 * what tells it apart from the option table, whose second column is the
	 * option's value.
	 *
	 * @return string[]
	 */
	private function documentedArgumentNames(string $section): array {
		preg_match_all(
			'/^\|\s*`([a-zA-Z0-9_]+)`\s*\|\s*(?:Yes|No)\s*\|/m',
			$section,
			$matches
		);

		return $this->normalise($matches[1]);
	}

	/** Repair-step classes registered in appinfo/info.xml, short names, sorted. */
	private function registeredRepairSteps(): array {
		preg_match_all(
			'/<step>\s*OCA\\\\Social\\\\Migration\\\\([A-Za-z0-9_]+)\s*<\/step>/',
			$this->read('appinfo/info.xml'),
			$matches
		);

		return $this->normalise($matches[1]);
	}

	/**
	 * Repair-step classes named in the integration table of
	 * docs/Architecture.md, short names, sorted.
	 */
	private function documentedRepairSteps(): array {
		$steps = [];
		foreach (explode("\n", $this->read('docs/Architecture.md')) as $line) {
			if (!str_starts_with(trim($line), '| Repair step')) {
				continue;
			}

			if (preg_match('/`Migration\\\\([A-Za-z0-9_]+)`/', $line, $match) === 1) {
				$steps[] = $match[1];
			}
		}

		return $this->normalise($steps);
	}

	/**
	 * Files in lib/ that call `$symbol(` on a line that is not commented out.
	 *
	 * Line-based and deliberately crude: a block of code commented out with
	 * leading `//` is what the documentation means by "commented out", and a
	 * call inside a `/* … *​/` block still begins its line with `*`.
	 *
	 * @return string[] `path:line`, at most a handful
	 */
	private function liveCallersOf(string $symbol): array {
		$found = [];
		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator(__DIR__ . '/../lib', \FilesystemIterator::SKIP_DOTS)
		);

		foreach ($files as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}

			$lines = explode("\n", (string)file_get_contents($file->getPathname()));
			foreach ($lines as $number => $line) {
				$trimmed = ltrim($line);
				if ($trimmed === ''
					|| str_starts_with($trimmed, '//')
					|| str_starts_with($trimmed, '#')
					|| str_starts_with($trimmed, '*')
					|| str_starts_with($trimmed, '/*')) {
					continue;
				}

				if (preg_match('/\b' . preg_quote($symbol, '/') . '\s*\(/', $line) === 1) {
					$path = str_replace('\\', '/', $file->getPathname());
					$cut = strrpos($path, '/lib/');
					$found[] = ($cut === false ? $path : substr($path, $cut + 1))
						. ':' . ($number + 1);
				}
			}
		}

		return $found;
	}

	/**
	 * Every hand-written document this test guards.
	 *
	 * @return array<string, string> relative path => contents
	 */
	private function documentationFiles(): array {
		$files = [];
		foreach (self::DOCUMENTATION_FILES as $path) {
			$files[$path] = $this->read($path);
		}

		return $files;
	}

	private function read(string $relativePath): string {
		$path = __DIR__ . '/../' . $relativePath;
		$content = file_get_contents($path);
		$this->assertIsString($content, $relativePath . ' is missing or unreadable.');

		return $content;
	}

	/** Fully qualified command classes listed as `<command>` in appinfo/info.xml, sorted. */
	private function registeredCommandClasses(): array {
		preg_match_all(
			'/<command>\s*([^<\s]+)\s*<\/command>/',
			$this->read('appinfo/info.xml'),
			$matches
		);

		return $this->normalise($matches[1]);
	}

	/**
	 * Fully qualified command classes found in lib/Command/, sorted.
	 *
	 * Files that register no command name (the shared ExtendedBase) are not
	 * commands and are skipped.
	 */
	private function commandClassesOnDisk(): array {
		$classes = [];
		foreach ($this->commandFiles() as $file => $code) {
			if ($this->commandNameOf($code) === null) {
				continue;
			}

			$classes[] = 'OCA\\Social\\Command\\' . basename($file, '.php');
		}

		return $this->normalise($classes);
	}

	/** Command names passed to setName() in lib/Command/, sorted. */
	private function registeredCommandNames(): array {
		$names = [];
		foreach ($this->commandFiles() as $code) {
			$name = $this->commandNameOf($code);
			if ($name !== null) {
				$names[] = $name;
			}
		}

		return $this->normalise($names);
	}

	/**
	 * Command names that have their own section in docs/OCC-Commands.md, sorted.
	 *
	 * A heading is what documents a command; a name in prose may well be a
	 * counter-example ("the command is `social:details`, not `social:stream:details`").
	 */
	private function documentedCommandNames(): array {
		preg_match_all(
			'/^#{2,4}[^\S\n]+`(social:[a-z0-9:_-]+)`/m',
			$this->read('docs/OCC-Commands.md'),
			$matches
		);

		return $this->normalise($matches[1]);
	}

	/** @return array<string, string> file path => file contents */
	private function commandFiles(): array {
		$files = glob(__DIR__ . '/../lib/Command/*.php');
		$this->assertNotEmpty($files, 'No command classes found in lib/Command/.');

		$contents = [];
		foreach ($files as $file) {
			$contents[$file] = (string)file_get_contents($file);
		}

		return $contents;
	}

	private function commandNameOf(string $code): ?string {
		if (preg_match('/setName\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $code, $match) !== 1) {
			return null;
		}

		return $match[1];
	}

	/**
	 * Two route attributes on one method need two names.
	 *
	 * A route is keyed by controller, method and postfix, so a second
	 * registration without a postfix replaces the first rather than joining
	 * it. That is how GET `/@{username}/outbox` disappeared behind its own
	 * POST: both were named `ActivityPub#outbox`, first in the array table and
	 * then in the attributes that replaced it, and a remote server fetching an
	 * outbox got nothing.
	 */
	/**
	 * The greedy post route is declared in `appinfo/routes.php`, and the pages
	 * it would otherwise swallow are not.
	 *
	 * `/@{username}/{token}` matches any single segment, so it also matches
	 * `/@{username}/portfolio` and `/@{username}/collections` — which belong to
	 * `SocialPubController`. The first route offered to the matcher wins, and
	 * attribute routes of two *different* controllers are contributed in
	 * whatever order the filesystem hands the directory back. On an install
	 * where `ActivityPubController` came back first, both pages were answered
	 * by `displayPost()`, found no post of that name, and served "Post not
	 * found" to every reader without a session (#2284). Whether an instance
	 * had the bug came down to the order of two files on disk.
	 *
	 * `appinfo/routes.php` is offered after every attribute route, which is the
	 * one place in the app where "last" is a guarantee rather than an
	 * accident. Moving the greedy route there fixes both pages and every
	 * reserved segment added later.
	 */
	public function testTheGreedyPostRouteCannotSwallowTheReservedPages(): void {
		$greedy = '/@{username}/{token}';

		$attributes = array_map(
			fn (array $route): string => $this->normalisePath((string)$route['url']),
			$this->attributeRoutes()
		);
		$this->assertNotContains(
			$this->normalisePath($greedy),
			$attributes,
			$greedy . ' is a controller attribute again. It matches /@{username}/portfolio and'
			. ' /@{username}/collections, and nothing decides which of two controllers is offered'
			. ' first — put it back in appinfo/routes.php, which is always offered last.'
		);

		$arrays = array_map(
			fn (array $route): string => $this->normalisePath((string)$route['url']),
			$this->arrayRoutes()
		);
		$this->assertContains(
			$this->normalisePath($greedy),
			$arrays,
			$greedy . ' is declared nowhere: a post page would be a 404.'
		);

		// and the pages it would swallow stay attributes, so they are offered
		// before it whatever order their own file came back in
		foreach (['/@{username}/portfolio', '/@{username}/collections'] as $reserved) {
			$this->assertContains(
				$this->normalisePath($reserved),
				$attributes,
				$reserved . ' is no longer an attribute route, so it is no longer'
				. ' guaranteed to be offered before ' . $greedy . '.'
			);
		}
	}

	public function testRoutesOnTheSameMethodHaveDistinctNames(): void {
		$collisions = [];

		foreach (glob(__DIR__ . '/../lib/Controller/*.php') as $file) {
			$source = (string)file_get_contents($file);
			preg_match_all(
				'/((?:\t#\[(?:Frontpage|Api)Route\([^\]]*\)\]\n)+)\tpublic function (\w+)/',
				$source,
				$matches,
				PREG_SET_ORDER
			);

			foreach ($matches as $match) {
				preg_match_all('/#\[(?:Frontpage|Api)Route\((.*?)\)\]/', $match[1], $routes);
				if (count($routes[1]) < 2) {
					continue;
				}

				$names = array_map(
					static fn (string $route): string
						=> preg_match("/postfix:\s*'([^']*)'/", $route, $postfix) ? $postfix[1] : '',
					$routes[1]
				);

				if (count(array_unique($names)) !== count($names)) {
					$collisions[] = basename($file) . '::' . $match[2];
				}
			}
		}

		$this->assertSame(
			[],
			$collisions,
			'These methods carry route attributes that register under the same name,'
			. ' so all but the last are silently dropped: give each a distinct postfix.'
		);
	}

	/**
	 * Every route url the app registers, normalised and sorted.
	 *
	 * Both places a route can be declared are read, because the server reads
	 * both: the `#[FrontpageRoute]` attributes on the controller methods, and
	 * whatever is left in `appinfo/routes.php` (see the comment in that file
	 * for the one route that has to stay there).
	 */
	private function routeUrls(): array {
		$urls = [];
		foreach ($this->attributeRoutes() as $route) {
			$this->assertArrayHasKey('url', $route, 'A route attribute has no url.');
			$urls[] = $this->normalisePath((string)$route['url']);
		}
		foreach ($this->arrayRoutes() as $route) {
			$this->assertArrayHasKey('url', $route, 'A route in appinfo/routes.php has no url.');
			$urls[] = $this->normalisePath((string)$route['url']);
		}

		$this->assertNotEmpty(
			$urls,
			'No routes found at all: the app declares none, or this test stopped'
			. ' finding them — either way docs/API.md would be checked against nothing.'
		);

		return $this->normalise($urls);
	}

	/**
	 * The route attributes on the controllers, read the way the server reads
	 * them.
	 *
	 * `OC\Route\Router::getAttributeRoutes()` walks `lib/Controller`, reflects
	 * over every `*Controller.php` in it, and takes every method attribute that
	 * is an `OCP\AppFramework\Http\Attribute\Route` — which `FrontpageRoute`
	 * and `ApiRoute` both are. This does the same, so a route this test cannot
	 * see is a route the server cannot see either.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function attributeRoutes(): array {
		$files = glob(__DIR__ . '/../lib/Controller/*Controller.php');
		$this->assertNotEmpty($files, 'No controllers found in lib/Controller/.');

		$routes = [];
		foreach ($files as $file) {
			$class = 'OCA\\Social\\Controller\\' . basename($file, '.php');
			$this->assertTrue(
				class_exists($class),
				$file . ' declares no ' . $class . ':'
				. ' the server reflects over that class name, so its routes would be lost.'
			);

			foreach ((new ReflectionClass($class))->getMethods() as $method) {
				$attributes = $method->getAttributes(Route::class, ReflectionAttribute::IS_INSTANCEOF);
				foreach ($attributes as $attribute) {
					$routes[] = $attribute->newInstance()->toArray();
				}
			}
		}

		return $routes;
	}

	/**
	 * What is still declared the old way, in the `routes` key of
	 * `appinfo/routes.php`. May legitimately be empty.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function arrayRoutes(): array {
		$definition = require __DIR__ . '/../appinfo/routes.php';
		$this->assertIsArray($definition, 'appinfo/routes.php returns no array.');

		return array_values($definition['routes'] ?? []);
	}

	/**
	 * Paths documented in docs/API.md, normalised and sorted.
	 *
	 * Only code spans inside the endpoint tables count: paths in prose are
	 * illustrations (a base URL, a `/api/v1/` prefix, a full https:// example)
	 * rather than claims about a route.
	 */
	private function documentedPaths(): array {
		$paths = [];
		foreach (explode("\n", $this->read('docs/API.md')) as $line) {
			if (!str_starts_with(trim($line), '|')) {
				continue;
			}

			preg_match_all('/`(\/[^`]*)`/', $line, $matches);
			foreach ($matches[1] as $path) {
				if (in_array($path, self::NON_ROUTE_ENDPOINTS, true)) {
					continue;
				}

				$paths[] = $this->normalisePath($path);
			}
		}

		return $this->normalise($paths);
	}

	/** Drops a trailing slash, so `/local/` and `/local` compare equal; keeps the root. */
	private function normalisePath(string $path): string {
		$trimmed = rtrim(trim($path), '/');

		return $trimmed === '' ? '/' : $trimmed;
	}

	private function appVersion(): string {
		$found = preg_match(
			'/<version>\s*([^<\s]+)\s*<\/version>/',
			$this->read('appinfo/info.xml'),
			$match
		);
		$this->assertSame(1, $found, 'appinfo/info.xml declares no <version>.');

		return $match[1];
	}

	/**
	 * A `min-version`/`max-version` from the `<dependencies>` of appinfo/info.xml.
	 *
	 * @param string $subject `nextcloud` or `php`
	 * @param string $bound `min` or `max`
	 */
	private function dependencyVersion(string $subject, string $bound): string {
		$found = preg_match(
			'/<' . $subject . '\b[^>]*\b' . $bound . '-version="([^"]+)"/',
			$this->read('appinfo/info.xml'),
			$match
		);
		$this->assertSame(
			1,
			$found,
			'appinfo/info.xml declares no ' . $subject . ' ' . $bound . '-version.'
		);

		return $match[1];
	}

	/**
	 * The version a doc states for one bound of one dependency.
	 *
	 * Accepts the labelled forms ("Minimum NC version: 28", "PHP max version: 8.5")
	 * in either word order, and a range ("Nextcloud: 28 - 35").
	 *
	 * @param string $subject `nextcloud` or `php`
	 * @param string $bound `min` or `max`
	 */
	private function documentedVersion(string $doc, string $subject, string $bound): string {
		$name = $subject === 'nextcloud' ? '(?:nextcloud|nc)' : 'php';
		$limit = $bound === 'min' ? '(?:minimum|min)\.?' : '(?:maximum|max)\.?';
		$version = '`?([0-9]+(?:\.[0-9]+)*)`?';
		$gap = '[\s*_:]+';

		$patterns = [
			'/' . $limit . $gap . $name . $gap . '(?:version[s]?' . $gap . ')?' . $version . '/i',
			'/' . $name . $gap . $limit . $gap . '(?:version[s]?' . $gap . ')?' . $version . '/i',
		];
		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $doc, $match) === 1) {
				return $match[1];
			}
		}

		$range = '/' . $name . $gap . '(?:version[s]?' . $gap . ')?' . $version
			. '\s*(?:-|--|–|—|\.\.\.?|to)\s*' . $version . '/i';
		if (preg_match($range, $doc, $match) === 1) {
			return $bound === 'min' ? $match[1] : $match[2];
		}

		return 'not stated';
	}

	/**
	 * The two surveys stay in the repository, and stay reachable.
	 *
	 * `docs/Technical-Debt.md` was written once before and lived only in a
	 * working tree, so it was lost without anything noticing. Three of these
	 * began as reports written outside the repository; the user guide is here
	 * because it is the one document a reader who is not a developer is sent
	 * to, and the same three ways of disappearing apply to it. Their *contents*
	 * are deliberately not asserted — a test over "45% of lib is older than
	 * 2023" would either be brittle or be the fix — but a file that is deleted,
	 * renamed, or quietly orphaned from the README is something a test can
	 * catch, and those are the ways a document like this actually disappears.
	 *
	 * @return iterable<string, array{string, string}>
	 */
	public static function surveyDocuments(): iterable {
		yield 'technical debt' => ['docs/Technical-Debt.md', 'Technical debt and legacy code'];
		yield 'performance' => ['docs/Performance.md', 'Performance and scalability'];
		yield 'mastodon compatibility' => ['docs/Mastodon-Compatibility.md', 'Mastodon compatibility'];
		yield 'atproto specification' => ['docs/Atproto-Compatibility.md', 'Bluesky and AT Protocol compatibility'];
		yield 'user guide' => ['docs/User-Guide.md', 'User guide'];
	}

	#[DataProvider('surveyDocuments')]
	public function testTheSurveysAreStillHereAndLinkedFromTheReadme(string $path, string $title): void {
		$document = $this->read($path);
		$this->assertStringContainsString(
			'# ' . $title,
			$document,
			$path . ' has lost its title, so it is probably not the document it was.'
		);
		$this->assertStringContainsString(
			'**Verified against:**',
			$document,
			$path . ' must say which version it was checked against: nothing else tells a'
			. ' reader how far it has drifted.'
		);
		$this->assertStringContainsString(
			$path,
			$this->read('README.md'),
			$path . ' is not linked from README.md, where somebody looking for it would start.'
		);
	}

	/**
	 * The web-server rules exist in three places: the file an administrator
	 * includes, the section of the admin guide that quotes it, and the warning
	 * the app itself shows with the rules to paste. Two of those are copies,
	 * and a copy of a rule that answers 404 instead of serving the API is
	 * worse than no rule at all, so they are pinned to the file.
	 */
	public static function quotedRuleSources(): iterable {
		yield 'admin guide' => ['docs/Admin.md'];
		yield 'in-app warning' => ['src/components/SetupChecks.vue'];
	}

	#[DataProvider('quotedRuleSources')]
	public function testQuotedApacheRulesMatchTheShippedFile(string $path): void {
		$shipped = $this->apacheDirectives($this->read('contrib/webserver/apache-social-root.conf'));
		$quoted = $this->apacheDirectives($this->read($path));

		$this->assertNotEmpty(
			$quoted,
			$path . ' quotes none of the web-server rules any more. Either it stopped'
			. ' telling an administrator what to paste, or the rules changed shape and'
			. ' this test can no longer find them.'
		);

		foreach ($quoted as $directive) {
			$this->assertContains(
				$directive,
				$shipped,
				$path . ' quotes a rule that contrib/webserver/apache-social-root.conf does'
				. ' not contain: ' . $directive
			);
		}
	}

	/**
	 * The `RewriteRule` and `ProxyPreserveHost` lines of a document, with runs
	 * of whitespace collapsed so that the alignment of the shipped file does
	 * not have to be reproduced, and with JavaScript's doubled backslashes
	 * undone so that the Vue string reads as what it renders.
	 *
	 * @return string[]
	 */
	private function apacheDirectives(string $content): array {
		preg_match_all(
			'/^[\t \x27"]*((?:RewriteRule|ProxyPreserveHost)[^\n\x27"]+)/m',
			str_replace('\\\\', '\\', $content),
			$matches
		);

		$directives = [];
		foreach ($matches[1] as $directive) {
			// the environment rule is an implementation detail of the file and
			// deliberately not quoted anywhere; nothing else is filtered
			$directives[] = trim((string)preg_replace('/\s+/', ' ', $directive));
		}

		return array_values(array_filter(
			$directives,
			static fn (string $directive): bool => !str_contains($directive, 'HTTP_AUTHORIZATION')
		));
	}

	/**
	 * The places somebody deciding whether to install the app reads first.
	 *
	 * @return iterable<string, array{string}>
	 */
	public static function firstReadDocuments(): iterable {
		yield 'app store' => ['appinfo/info.xml'];
		yield 'readme' => ['README.md'];
		yield 'user guide' => ['docs/User-Guide.md'];
	}

	/**
	 * All three said Mastodon apps *cannot* connect, long after the web-server
	 * rules that let them shipped: a person on the app store left believing
	 * their phone would never work, and the answer was reachable from none of
	 * them.
	 */
	#[DataProvider('firstReadDocuments')]
	public function testTheWayMastodonAppsConnectIsNamedWhereAReaderLooksFirst(string $path): void {
		$document = $this->read($path);

		$this->assertStringContainsString('contrib/webserver', $document, $path . ' does not say where the rules for Mastodon apps are');
		$this->assertDoesNotMatchRegularExpression(
			'/(clients?|apps?) cannot (reach|connect)/i',
			$document,
			$path . ' says Mastodon apps cannot connect; they can, once the shipped web-server rules are in place'
		);
	}

	/**
	 * A group is a list only when an administrator has chosen it, and none is
	 * by default. Promising every group as a list left people looking for lists
	 * that were never going to appear.
	 */
	#[DataProvider('firstReadDocuments')]
	public function testGroupListsAreDescribedAsTheAdministratorsChoice(string $path): void {
		$this->assertDoesNotMatchRegularExpression(
			'/every\s+Nextcloud\s+group\s+you\s+(are\s+in|belong\s+to)/i',
			$this->read($path),
			$path . ' promises every group as a list; only the groups an administrator chose are'
		);
	}

	/**
	 * How many setup checks, commands or background jobs there are went wrong
	 * in every document that said, within weeks. The lists themselves are
	 * the authority, so the prose does not count them.
	 */
	public function testTheProseDoesNotCountWhatTheCodeLists(): void {
		$number = '(?:\d+|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|twenty[- ]?\w*|thirty[- ]?\w*)';
		$paths = ['README.md', 'appinfo/info.xml', ...array_map(
			fn (string $file): string => 'docs/' . basename($file),
			glob(dirname(__DIR__) . '/docs/*.md') ?: []
		)];

		foreach ($paths as $path) {
			$this->assertDoesNotMatchRegularExpression(
				'/\b' . $number . '\s+(?:setup\s+checks|`?occ`?\s+commands|commands\s+the\s+app\s+registers|`TimedJob`s|dashboard\s+widgets)\b/i',
				$this->read($path),
				$path . ' counts something the code lists; name the list instead'
			);
		}
	}

	/**
	 * @param string[] $values
	 * @return string[]
	 */
	private function normalise(array $values): array {
		$unique = array_unique($values);
		sort($unique);

		return array_values($unique);
	}
}
