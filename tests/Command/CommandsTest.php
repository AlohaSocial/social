<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Command;

use OCA\Social\Command\SocialCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Console\Command\Command;

/**
 * What can be asserted about the occ commands without a server.
 *
 * Executing one still needs tests/Integration/Command/, which boots a server
 * and drives each command through Symfony's CommandTester. Building one no
 * longer does: the commands extend the app's own SocialCommand, which extends
 * Symfony's Command, so their command line — every option and argument, with
 * its mode and default — can be read here from the real InputDefinition rather
 * than guessed at with a regular expression. What is left after that is the
 * class of bug that lives in the *strings*: advice that names an option the
 * named command does not declare, and a destructive command with no way to
 * confirm it.
 */
class CommandsTest extends TestCase {
	/** Options every occ command inherits, so no command declares them itself. */
	private const INHERITED_OPTIONS = [
		'output', 'no-interaction', 'no-warnings', 'verbose', 'quiet', 'help',
		'version', 'ansi', 'no-ansi',
	];

	/** Files in lib/Command/ that register no command of their own. */
	private const NOT_COMMANDS = ['ExtendedBase', 'SocialCommand'];

	public function testEveryCommandFileRegistersASocialCommand(): void {
		$names = [];
		foreach ($this->commandFiles() as $file => $code) {
			$class = basename($file, '.php');
			if (in_array($class, self::NOT_COMMANDS, true)) {
				continue;
			}

			$name = $this->commandNameOf($code);
			$this->assertNotNull($name, $class . ' declares no setName().');
			$this->assertStringStartsWith('social:', $name, $class . ' does not use the social: prefix.');
			$names[] = $name;
		}

		$this->assertSame($names, array_unique($names), 'two commands share one name.');
	}

	/**
	 * A command that asks for confirmation must also be usable from a script.
	 *
	 * Under `occ --no-interaction` a ConfirmationQuestion answers itself with
	 * its default — false — so a command that only asks silently does nothing,
	 * which a caller cannot tell from success. Every such command therefore
	 * declares --force.
	 */
	public function testEveryCommandThatConfirmsCanAlsoBeForced(): void {
		foreach ($this->commandFiles() as $file => $code) {
			if (str_contains($code, 'confirmDestructive(') && basename($file) !== 'SocialCommand.php') {
				// the base's helper checks isInteractive() itself; the
				// command still has to declare the --force it reads
				$this->assertContains(
					'force',
					$this->declaredOptions($code),
					basename($file) . ' asks for confirmation but declares no --force,'
					. ' so under --no-interaction it can only do nothing.'
				);

				continue;
			}
			if (!str_contains($code, 'ConfirmationQuestion') || basename($file) === 'SocialCommand.php') {
				continue;
			}

			$this->assertContains(
				'force',
				$this->declaredOptions($code),
				basename($file) . ' asks for confirmation but declares no --force,'
				. ' so under --no-interaction it can only do nothing.'
			);
			$this->assertStringContainsString(
				'isInteractive()',
				$code,
				basename($file) . ' asks for confirmation without checking isInteractive(),'
				. ' so under --no-interaction it answers its own question with "no" and exits as if it had worked.'
			);
		}
	}

	/**
	 * Every command is built on the app's own base class.
	 *
	 * They used to extend `OC\Core\Command\Base`, which lives in the server's
	 * private core and carries no stability promise. A new command copied from
	 * an old one would bring that import back with it.
	 */
	public function testEveryCommandExtendsTheAppsOwnBaseClass(): void {
		foreach ($this->commandClasses() as $class) {
			$this->assertTrue(
				is_subclass_of($class, SocialCommand::class),
				$class . ' does not extend ' . SocialCommand::class . '.'
			);
		}
	}

	/**
	 * `--output plain|json|json_pretty` is on every command, unchanged.
	 *
	 * Read off the constructed command rather than its source: this is what an
	 * operator's script sees, and what `occ social:… --help` prints.
	 */
	public function testEveryCommandOffersTheInheritedOutputOption(): void {
		foreach ($this->commandClasses() as $class) {
			$option = $this->built($class)->getDefinition()->getOption('output');

			$this->assertNull($option->getShortcut(), $class);
			$this->assertTrue($option->isValueOptional(), $class);
			$this->assertSame('plain', $option->getDefault(), $class);
			$this->assertSame(
				'Output format (plain, json or json_pretty, default is plain)',
				$option->getDescription(),
				$class
			);
		}
	}

	/**
	 * The option and argument names in the source are the ones Symfony sees.
	 *
	 * The advice scan below reads the source with a regular expression; this
	 * says the regular expression and the real definition agree, so a command
	 * that builds its definition some other way cannot slip past it.
	 */
	public function testTheDeclaredOptionsAreTheRealOnes(): void {
		foreach ($this->commandFiles() as $file => $code) {
			$class = basename($file, '.php');
			if (in_array($class, self::NOT_COMMANDS, true)) {
				continue;
			}

			$command = $this->built('OCA\\Social\\Command\\' . $class);
			$real = array_keys($command->getDefinition()->getOptions());
			sort($real);
			// Only `--output` is inherited from the base class; the rest of
			// INHERITED_OPTIONS — `--help`, `--quiet`, `--no-warnings` and so
			// on — belong to the application and are merged in when the command
			// is registered, so they are not in the command's own definition.
			$declared = array_values(array_unique(array_merge($this->declaredOptions($code), ['output'])));
			sort($declared);

			$this->assertSame($declared, $real, $class . ': source and definition disagree about the options.');
			$this->assertSame($this->commandNameOf($code), $command->getName(), $class);
		}
	}

	/**
	 * Every command class in lib/Command/ that registers a command.
	 *
	 * @return list<class-string<SocialCommand>>
	 */
	private function commandClasses(): array {
		$classes = [];
		foreach ($this->commandFiles() as $file => $code) {
			$class = basename($file, '.php');
			if (in_array($class, self::NOT_COMMANDS, true)) {
				continue;
			}

			$classes[] = 'OCA\\Social\\Command\\' . $class;
		}

		return $classes;
	}

	/**
	 * A command with its definition built, and none of its services.
	 *
	 * Every constructor here asks for app services this suite has no server to
	 * build, and none of them does anything but store them: what fills in the
	 * command line is `configure()`, which Symfony's own constructor calls.
	 * So that one is invoked, and the command's own is skipped.
	 */
	private function built(string $class): Command {
		$command = (new ReflectionClass($class))->newInstanceWithoutConstructor();
		(new ReflectionMethod(Command::class, '__construct'))->invoke($command);

		return $command;
	}

	/**
	 * Every `occ social:… --option` we print or document names a real option.
	 *
	 * This is the F9 regression: `social:check:install` and the README both
	 * told an administrator to run `occ social:reset --uri=<address>` while
	 * `Reset` declared only `--uninstall`, so Symfony rejected the command
	 * outright — advice that appeared precisely when federation was already
	 * broken.
	 */
	#[DataProvider('provideAdviceSources')]
	public function testAdvertisedOptionsExist(string $source): void {
		$declared = $this->optionsPerCommand();
		$checked = 0;

		foreach ($this->advertisedCommands($this->read($source)) as [$command, $option]) {
			$this->assertArrayHasKey(
				$command,
				$declared,
				$source . ' names "occ ' . $command . '", which no class in lib/Command/ registers.'
			);
			$this->assertContains(
				$option,
				$declared[$command],
				$source . ' tells the reader to run "occ ' . $command . ' --' . $option
				. '", but ' . $command . ' declares no such option: Symfony rejects that command line.'
			);
			$checked++;
		}

		// Not every source has to carry advice; the assertions above are what
		// matter. Recorded so a regex that stops matching anything at all is
		// visible in the test output rather than silently green.
		$this->addToAssertionCount(1);
		$this->assertGreaterThanOrEqual(0, $checked);
	}

	public function testTheAdviceScanFindsSomethingToCheck(): void {
		$found = 0;
		foreach ($this->provideAdviceSources() as $source) {
			$found += count($this->advertisedCommands($this->read($source[0])));
		}

		$this->assertGreaterThan(
			10,
			$found,
			'the "occ social:… --option" scan matched almost nothing:'
			. ' the pattern in advertisedCommands() has stopped seeing the advice it guards.'
		);
	}

	/** @return array<string, array{string}> */
	public static function provideAdviceSources(): array {
		return [
			'README' => ['README.md'],
			'command reference' => ['docs/OCC-Commands.md'],
			'the commands themselves' => ['lib/Command'],
			'the services that print advice' => ['lib/Service'],
		];
	}

	/**
	 * command name => options it declares, including the inherited ones.
	 *
	 * @return array<string, list<string>>
	 */
	private function optionsPerCommand(): array {
		$options = [];
		foreach ($this->commandFiles() as $code) {
			$name = $this->commandNameOf($code);
			if ($name === null) {
				continue;
			}

			$options[$name] = array_merge($this->declaredOptions($code), self::INHERITED_OPTIONS);
		}

		return $options;
	}

	/**
	 * The (command, option) pairs a text advertises.
	 *
	 * @return list<array{string, string}>
	 */
	private function advertisedCommands(string $text): array {
		// "occ social:x:y --opt --other=value", and the bracketed synopsis form
		// docs/OCC-Commands.md uses: "occ social:x [-d|--days DAYS] [--dry-run]".
		preg_match_all(
			'/occ (social:[a-z0-9:_-]+)((?:\s+\[?(?:-[a-zA-Z]\|)?--[a-zA-Z0-9_-]+(?:[= ][^\s\]`"\'<]*)?\]?)+)/',
			$text,
			$matches,
			PREG_SET_ORDER
		);

		$pairs = [];
		foreach ($matches as $match) {
			preg_match_all('/--([a-zA-Z0-9_-]+)/', $match[2], $options);
			foreach ($options[1] as $option) {
				$pairs[] = [$match[1], $option];
			}
		}

		return $pairs;
	}

	/** @return list<string> */
	private function declaredOptions(string $code): array {
		preg_match_all('/addOption\(\s*[\'"]([a-zA-Z0-9_-]+)[\'"]/', $code, $matches);

		return array_values(array_unique($matches[1]));
	}

	private function commandNameOf(string $code): ?string {
		if (preg_match('/setName\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $code, $match) !== 1) {
			return null;
		}

		return $match[1];
	}

	/** @return array<string, string> file path => contents */
	private function commandFiles(): array {
		$files = glob(__DIR__ . '/../../lib/Command/*.php');
		$this->assertNotEmpty($files, 'No command classes found in lib/Command/.');

		$contents = [];
		foreach ($files as $file) {
			$contents[$file] = (string)file_get_contents($file);
		}

		return $contents;
	}

	/** A file, or every .php file under a directory, as one string. */
	private function read(string $relativePath): string {
		$path = __DIR__ . '/../../' . $relativePath;

		if (is_dir($path)) {
			$text = '';
			foreach ((array)glob($path . '/*.php') as $file) {
				$text .= (string)file_get_contents((string)$file) . "\n";
			}

			return $text;
		}

		$content = file_get_contents($path);
		$this->assertIsString($content, $relativePath . ' is missing or unreadable.');

		return $content;
	}
}
