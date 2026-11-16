<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use OCP\Defaults;
use OCP\Server;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * The base class every `occ social:*` command extends.
 *
 * It stands in for `OC\Core\Command\Base`, which lives in the server's private
 * `core/` and carries no stability promise: a signature change there is a fatal
 * on somebody's `occ`, with no deprecation first. What the commands actually
 * took from it is small — the `--output` option, the three format constants and
 * the writers that honour them — so it is reimplemented here, against Symfony's
 * `Command`, which is a public dependency of the server.
 *
 * The output of the writers is byte-for-byte what core's `Base` produced:
 * `occ social:* --output=json` is a published interface and is not being
 * redesigned by a refactor. Three deliberate differences from core, none of
 * them observable from a `social:` command:
 *
 * - core's plain-format array writer prints an integer-keyed entry as
 *   `<prefix><key>: <value>` instead of `<prefix><value>` for exactly one class,
 *   its own `user:list`; that special case is dropped rather than reproduced.
 * - `valueToString()` casts to string explicitly. Core returns the value and
 *   lets PHP's weak mode coerce it against the `?string` return type, which
 *   this file, running under `strict_types`, cannot do. The result is the same
 *   string for every scalar and every `Stringable`.
 * - a backed enum is written as its value, as in core. A pure enum has no
 *   value: core reads `->value` off it anyway and dies with "undefined
 *   property", this dies casting an object to a string. Both are fatal, so
 *   there is nothing to preserve.
 *
 * Not reimplemented, because nothing in the app calls it: the pcntl signal
 * handling (`abortIfInterrupted()`, `cancelOperation()`), the streaming table
 * and JSON writers with `chunkIterator()`, and `CompletionAwareInterface`, whose
 * only effect is that a bash completion of `--output` no longer offers the three
 * format names.
 */
abstract class SocialCommand extends Command {
	public const OUTPUT_FORMAT_PLAIN = 'plain';
	public const OUTPUT_FORMAT_JSON = 'json';
	public const OUTPUT_FORMAT_JSON_PRETTY = 'json_pretty';

	protected string $defaultOutputFormat = self::OUTPUT_FORMAT_PLAIN;

	#[\Override]
	protected function configure() {
		$this->addOption(
			'output',
			null,
			InputOption::VALUE_OPTIONAL,
			'Output format (plain, json or json_pretty, default is plain)',
			$this->defaultOutputFormat
		);
	}

	/**
	 * The help line core's `Base` set on every command, kept word for word.
	 *
	 * Core sets it in `configure()`, which would mean asking the server
	 * container for the documentation address while the command is being
	 * constructed — and a command is constructed by anything that lists the
	 * commands, including a test suite with no server under it. It is answered
	 * here instead, when somebody actually asks for `--help`, and a command that
	 * sets its own help text still wins.
	 */
	#[\Override]
	public function getHelp(): string {
		$help = parent::getHelp();
		if ($help !== '') {
			return $help;
		}

		try {
			$docBaseUrl = Server::get(Defaults::class)->getDocBaseUrl();
		} catch (\Throwable) {
			return '';
		}

		return 'More extensive and thorough documentation may be found at ' . $docBaseUrl . PHP_EOL;
	}

	/**
	 * The helper behind every confirmation prompt in this app.
	 *
	 * `getHelper()` is typed as "some helper", so every caller that wanted to
	 * ask a question had to assume which one came back. Asked for here once,
	 * where the assumption can be checked: a command line with no question
	 * helper registered is a broken `occ`, not a prompt to answer itself.
	 */
	protected function questionHelper(): QuestionHelper {
		$helper = $this->getHelper('question');
		if (!$helper instanceof QuestionHelper) {
			throw new \LogicException('the console has no question helper');
		}

		return $helper;
	}

	/**
	 * Asks before something that cannot be undone, unless `--force` was given.
	 *
	 * Under `occ --no-interaction` a ConfirmationQuestion answers itself with
	 * its default, so without `--force` that is refused rather than taken as
	 * a no that a script could not tell from success.
	 *
	 * @return bool|null true to go ahead, false when the answer was no, null
	 *                   when there is nobody to ask and no `--force`
	 */
	protected function confirmDestructive(InputInterface $input, OutputInterface $output, string $question): ?bool {
		if ($input->hasOption('force') && $input->getOption('force')) {
			return true;
		}
		if (!$input->isInteractive()) {
			$output->writeln('<error>Refusing to run non-interactively without --force: there is nobody here to confirm.</error>');

			return null;
		}

		return (bool)$this->questionHelper()->ask(
			$input, $output, new ConfirmationQuestion($question . ' (y/N) ', false, '/^(y|Y)/i')
		);
	}

	protected function writeArrayInOutputFormat(InputInterface $input, OutputInterface $output, iterable $items, string $prefix = '  - '): void {
		switch ($input->getOption('output')) {
			case self::OUTPUT_FORMAT_JSON:
				$items = (is_array($items) ? $items : iterator_to_array($items));
				$output->writeln(json_encode($items));
				break;
			case self::OUTPUT_FORMAT_JSON_PRETTY:
				$items = (is_array($items) ? $items : iterator_to_array($items));
				$output->writeln(json_encode($items, JSON_PRETTY_PRINT));
				break;
			default:
				foreach ($items as $key => $item) {
					if (is_iterable($item)) {
						$output->writeln($prefix . $key . ':');
						$this->writeArrayInOutputFormat($input, $output, $item, '  ' . $prefix);
						continue;
					}
					if (!is_int($key)) {
						$value = $this->valueToString($item);
						if (!is_null($value)) {
							$output->writeln($prefix . $key . ': ' . $value);
						} else {
							$output->writeln($prefix . $key);
						}
					} else {
						$output->writeln($prefix . $this->valueToString($item));
					}
				}
				break;
		}
	}

	protected function writeTableInOutputFormat(InputInterface $input, OutputInterface $output, array $items): void {
		switch ($input->getOption('output')) {
			case self::OUTPUT_FORMAT_JSON:
				$output->writeln(json_encode($items));
				break;
			case self::OUTPUT_FORMAT_JSON_PRETTY:
				$output->writeln(json_encode($items, JSON_PRETTY_PRINT));
				break;
			default:
				$table = new Table($output);
				$table->setRows($items);
				if (!empty($items) && is_string(array_key_first(reset($items)))) {
					$table->setHeaders(array_keys(reset($items)));
				}
				$table->render();
				break;
		}
	}

	protected function writeMixedInOutputFormat(InputInterface $input, OutputInterface $output, mixed $item): void {
		if (is_array($item)) {
			$this->writeArrayInOutputFormat($input, $output, $item, '');

			return;
		}

		switch ($input->getOption('output')) {
			case self::OUTPUT_FORMAT_JSON:
				$output->writeln(json_encode($item));
				break;
			case self::OUTPUT_FORMAT_JSON_PRETTY:
				$output->writeln(json_encode($item, JSON_PRETTY_PRINT));
				break;
			default:
				$output->writeln((string)$this->valueToString($item, false));
				break;
		}
	}

	protected function valueToString(mixed $value, bool $returnNull = true): ?string {
		if ($value === false) {
			return 'false';
		} elseif ($value === true) {
			return 'true';
		} elseif ($value === null) {
			return $returnNull ? null : 'null';
		} elseif ($value instanceof \BackedEnum) {
			return (string)$value->value;
		} else {
			return (string)$value;
		}
	}
}
