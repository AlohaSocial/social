<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Command;

use OCA\Social\Command\SocialCommand;
use OCP\Defaults;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A command that does nothing but expose what the base class gives it.
 */
final class OutputProbeCommand extends SocialCommand {
	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:test:probe')
			->setDescription('probe');
	}

	public function writeArray(InputInterface $input, BufferedOutput $output, iterable $items, string $prefix = '  - '): string {
		$this->writeArrayInOutputFormat($input, $output, $items, $prefix);

		return $output->fetch();
	}

	public function writeTable(InputInterface $input, BufferedOutput $output, array $items): string {
		$this->writeTableInOutputFormat($input, $output, $items);

		return $output->fetch();
	}

	public function writeMixed(InputInterface $input, BufferedOutput $output, mixed $item): string {
		$this->writeMixedInOutputFormat($input, $output, $item);

		return $output->fetch();
	}
}

/**
 * The base class the occ commands extend instead of the server's private
 * `OC\Core\Command\Base`.
 *
 * `occ social:* --output=json` is a published interface, so the expectations
 * below are not "sensible JSON": they are the bytes core's `Base` wrote, taken
 * from the copy on a running server and asserted here literally — escaped
 * slashes and `\u00e9` included, because core passes no flags to `json_encode()`
 * beyond `JSON_PRETTY_PRINT`; a trailing newline on every format, because every
 * writer ends in `writeln()`; nothing at all for an empty array in plain format,
 * because the loop that would print it has nothing to iterate.
 */
class SocialCommandTest extends TestCase {
	private OutputProbeCommand $command;
	private BufferedOutput $output;

	protected function setUp(): void {
		parent::setUp();

		$this->command = new OutputProbeCommand();
		$this->output = new BufferedOutput();
	}

	private function input(?string $format): ArrayInput {
		return new ArrayInput(
			$format === null ? [] : ['--output' => $format],
			$this->command->getDefinition()
		);
	}

	/**
	 * The option core's `Base` added, to the letter: an operator's scripts and
	 * `occ social:… --help` both show it.
	 */
	public function testTheOutputOptionIsTheOneCoreDeclared(): void {
		$option = $this->command->getDefinition()->getOption('output');

		$this->assertNull($option->getShortcut());
		$this->assertTrue($option->isValueOptional());
		$this->assertFalse($option->isArray());
		$this->assertSame('plain', $option->getDefault());
		$this->assertSame(
			'Output format (plain, json or json_pretty, default is plain)',
			$option->getDescription()
		);
	}

	public function testTheDefaultFormatIsPlain(): void {
		$this->assertSame('plain', $this->input(null)->getOption('output'));
		$this->assertSame(
			SocialCommand::OUTPUT_FORMAT_PLAIN,
			$this->command->getDefinition()->getOption('output')->getDefault()
		);
	}

	public function testTheFormatConstantsKeepTheirValues(): void {
		$this->assertSame('plain', SocialCommand::OUTPUT_FORMAT_PLAIN);
		$this->assertSame('json', SocialCommand::OUTPUT_FORMAT_JSON);
		$this->assertSame('json_pretty', SocialCommand::OUTPUT_FORMAT_JSON_PRETTY);
	}

	#[DataProvider('provideArrays')]
	public function testWriteArrayInOutputFormat(string $format, array $items, string $expected): void {
		$this->assertSame(
			$expected,
			$this->command->writeArray($this->input($format), $this->output, $items)
		);
	}

	/** @return array<string, array{string, array, string}> */
	public static function provideArrays(): array {
		$nested = ['actor' => ['account' => 'alice', 'tags' => ['a', 'b']], 'count' => 3, 'nothing' => null, 'yes' => true];

		return [
			'plain: nested' => ['plain', $nested, "  - actor:\n    - account: alice\n    - tags:\n      - a\n      - b\n  - count: 3\n  - nothing\n  - yes: true\n"],
			'json: nested' => ['json', $nested, "{\"actor\":{\"account\":\"alice\",\"tags\":[\"a\",\"b\"]},\"count\":3,\"nothing\":null,\"yes\":true}\n"],
			'json_pretty: nested' => ['json_pretty', $nested, "{\n    \"actor\": {\n        \"account\": \"alice\",\n        \"tags\": [\n            \"a\",\n            \"b\"\n        ]\n    },\n    \"count\": 3,\n    \"nothing\": null,\n    \"yes\": true\n}\n"],

			// An empty array prints nothing at all in plain format, and an
			// empty JSON *array* — not an object — in the other two.
			'plain: empty' => ['plain', [], ''],
			'json: empty' => ['json', [], "[]\n"],
			'json_pretty: empty' => ['json_pretty', [], "[]\n"],

			// An empty value nested inside a filled array is a key with a colon
			// and no rows under it, not a key with an empty value.
			'plain: empty nested' => ['plain', ['empty' => [], 'after' => 1], "  - empty:\n  - after: 1\n"],
			'json: empty nested' => ['json', ['empty' => [], 'after' => 1], "{\"empty\":[],\"after\":1}\n"],
			'json_pretty: empty nested' => ['json_pretty', ['empty' => [], 'after' => 1], "{\n    \"empty\": [],\n    \"after\": 1\n}\n"],

			// A list is printed without its keys; a null value prints as the
			// bare key, and false as the string "false".
			'plain: list' => ['plain', ['one', 'two'], "  - one\n  - two\n"],
			'json: list' => ['json', ['one', 'two'], "[\"one\",\"two\"]\n"],
			'json_pretty: list' => ['json_pretty', ['one', 'two'], "[\n    \"one\",\n    \"two\"\n]\n"],
			'plain: null and false' => ['plain', ['n' => null, 'f' => false, 'z' => 0, 's' => ''], "  - n\n  - f: false\n  - z: 0\n  - s: \n"],

			// json_encode() is called with no flags beyond JSON_PRETTY_PRINT,
			// so slashes are escaped and non-ASCII becomes \uXXXX.
			'json: escaping' => ['json', ['url' => 'https://example.org/a', 'text' => 'héllo "x"'], "{\"url\":\"https:\\/\\/example.org\\/a\",\"text\":\"h\\u00e9llo \\\"x\\\"\"}\n"],
			'json_pretty: escaping' => ['json_pretty', ['url' => 'https://example.org/a', 'text' => 'héllo "x"'], "{\n    \"url\": \"https:\\/\\/example.org\\/a\",\n    \"text\": \"h\\u00e9llo \\\"x\\\"\"\n}\n"],
			'plain: escaping' => ['plain', ['url' => 'https://example.org/a', 'text' => 'héllo "x"'], "  - url: https://example.org/a\n  - text: héllo \"x\"\n"],
		];
	}

	#[DataProvider('provideFormats')]
	public function testWriteArrayAcceptsAnyIterable(string $format, string $expected): void {
		$items = (static function () {
			yield 'k' => 'v';
			yield 'x' => ['y' => 'z'];
		})();

		$this->assertSame(
			$expected,
			$this->command->writeArray($this->input($format), $this->output, $items)
		);
	}

	/** @return array<string, array{string, string}> */
	public static function provideFormats(): array {
		return [
			'plain' => ['plain', "  - k: v\n  - x:\n    - y: z\n"],
			'json' => ['json', "{\"k\":\"v\",\"x\":{\"y\":\"z\"}}\n"],
			'json_pretty' => ['json_pretty', "{\n    \"k\": \"v\",\n    \"x\": {\n        \"y\": \"z\"\n    }\n}\n"],
		];
	}

	public function testWriteArrayTakesItsPrefixFromTheCaller(): void {
		// The nesting is two spaces on top of whatever prefix came in.
		$this->assertSame(
			"a:\n  b: c\n",
			$this->command->writeArray($this->input('plain'), $this->output, ['a' => ['b' => 'c']], '')
		);
	}

	#[DataProvider('provideTables')]
	public function testWriteTableInOutputFormat(string $format, array $rows, string $expected): void {
		$this->assertSame(
			$expected,
			$this->command->writeTable($this->input($format), $this->output, $rows)
		);
	}

	/** @return array<string, array{string, array, string}> */
	public static function provideTables(): array {
		$rows = [['host' => 'a.example', 'requests' => 3], ['host' => 'b.example', 'requests' => 12]];

		return [
			'plain: headers come from the string keys' => ['plain', $rows, "+-----------+----------+\n| host      | requests |\n+-----------+----------+\n| a.example | 3        |\n| b.example | 12       |\n+-----------+----------+\n"],
			'plain: a list of lists gets no header row' => ['plain', [['a', 1], ['b', 2]], "+---+---+\n| a | 1 |\n| b | 2 |\n+---+---+\n"],
			'plain: empty' => ['plain', [], ''],
			'json: rows' => ['json', $rows, "[{\"host\":\"a.example\",\"requests\":3},{\"host\":\"b.example\",\"requests\":12}]\n"],
			'json: empty' => ['json', [], "[]\n"],
			'json_pretty: rows' => ['json_pretty', $rows, "[\n    {\n        \"host\": \"a.example\",\n        \"requests\": 3\n    },\n    {\n        \"host\": \"b.example\",\n        \"requests\": 12\n    }\n]\n"],
			'json_pretty: empty' => ['json_pretty', [], "[]\n"],
		];
	}

	#[DataProvider('provideMixed')]
	public function testWriteMixedInOutputFormat(string $format, mixed $item, string $expected): void {
		$this->assertSame(
			$expected,
			$this->command->writeMixed($this->input($format), $this->output, $item)
		);
	}

	/** @return array<string, array{string, mixed, string}> */
	public static function provideMixed(): array {
		return [
			'plain: int' => ['plain', 42, "42\n"],
			'json: int' => ['json', 42, "42\n"],
			'json_pretty: int' => ['json_pretty', 42, "42\n"],

			// null is written as the word, not as an empty line: with
			// $returnNull false there is no "no value" to fall back to.
			'plain: null' => ['plain', null, "null\n"],
			'json: null' => ['json', null, "null\n"],
			'plain: true' => ['plain', true, "true\n"],
			'json: true' => ['json', true, "true\n"],
			'plain: string' => ['plain', 'a string', "a string\n"],
			'json: string' => ['json', 'a string', "\"a string\"\n"],

			// An array is handed to the array writer with an empty prefix,
			// which is why these have no leading "  - ".
			'plain: array' => ['plain', ['a' => 1, 'b' => ['c' => 2]], "a: 1\nb:\n  c: 2\n"],
			'plain: empty array' => ['plain', [], ''],
			'json: array' => ['json', ['a' => 1, 'b' => ['c' => 2]], "{\"a\":1,\"b\":{\"c\":2}}\n"],
			'json_pretty: array' => ['json_pretty', ['a' => 1, 'b' => ['c' => 2]], "{\n    \"a\": 1,\n    \"b\": {\n        \"c\": 2\n    }\n}\n"],
		];
	}

	public function testAnUnknownFormatFallsBackToPlain(): void {
		// Core's writers switch on the option with a `default:` arm rather than
		// validating it, so `--output=yaml` prints the plain rendering.
		$this->assertSame(
			"  - a: 1\n",
			$this->command->writeArray($this->input('yaml'), $this->output, ['a' => 1])
		);
	}

	/**
	 * The help line core's `Base` put on every command, still there.
	 */
	public function testHelpNamesTheDocumentation(): void {
		$defaults = $this->createStub(Defaults::class);
		$defaults->method('getDocBaseUrl')->willReturn('https://docs.nextcloud.com/server/35');
		\OC::$server->register(Defaults::class, $defaults);

		try {
			$this->assertSame(
				'More extensive and thorough documentation may be found at https://docs.nextcloud.com/server/35' . PHP_EOL,
				$this->command->getHelp()
			);
		} finally {
			\OC::$server->reset();
		}
	}

	public function testACommandsOwnHelpWins(): void {
		$this->command->setHelp('how to probe');

		$this->assertSame('how to probe', $this->command->getHelp());
	}

	/**
	 * Asking the container for the documentation address is not worth a fatal.
	 *
	 * Core sets the help text while the command is being constructed, which
	 * means a container lookup for every command on every `occ` run. This asks
	 * only when somebody wants `--help`, and says nothing rather than dying if
	 * there is no container to ask — which is also what lets a unit test build
	 * a command at all.
	 */
	public function testHelpIsEmptyWithoutAServer(): void {
		$this->assertSame('', $this->command->getHelp());
	}

	/**
	 * The reason this class exists.
	 *
	 * `OC\Core\Command\Base` was the last name in lib/ from outside the public
	 * API. Nothing in lib/ may name a server-private class again: they carry no
	 * stability promise, so a signature change upstream is a fatal here with no
	 * deprecation first.
	 */
	public function testNothingInLibNamesAPrivateServerClass(): void {
		$offenders = [];
		$directory = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator(__DIR__ . '/../../lib', \FilesystemIterator::SKIP_DOTS)
		);

		/** @var \SplFileInfo $file */
		foreach ($directory as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}

			$code = (string)file_get_contents($file->getPathname());
			if (preg_match_all('/^use (OC\\\\[^;]+);$/m', $code, $matches) > 0) {
				foreach ($matches[1] as $name) {
					$offenders[] = basename($file->getPathname()) . ': ' . $name;
				}
			}
		}

		$this->assertSame(
			[],
			$offenders,
			'lib/ imports classes from the server\'s private namespace: ' . implode(', ', $offenders)
		);
	}
}
