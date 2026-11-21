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
 * It stands in for `OC\Core\Command\Base`, which is private to the server and
 * carries no stability promise, by reimplementing the parts the commands use
 * (the `--output` option, the three format constants and their writers) on
 * Symfony's public `Command`. Output is byte-for-byte what core's `Base`
 * produces, since `--output=json` is a published interface. Differences, none
 * observable from a `social:` command:
 *
 * - core's special case for `user:list` in the plain array writer is dropped.
 * - `valueToString()` casts explicitly, as `strict_types` requires; the string
 *   is the same for every scalar and `Stringable`.
 * - a pure enum is fatal here as in core, just with a different error.
 *
 * Not reimplemented, since nothing calls it: pcntl signal handling, the
 * streaming writers with `chunkIterator()`, and `CompletionAwareInterface`.
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
	 * Answered here on `--help` rather than in `configure()`, so constructing a
	 * command needs no server container; a command's own help text still wins.
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
	 * The helper behind every confirmation prompt in this app, checked once:
	 * `getHelper()` is typed as any helper.
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
	 * Under `--no-interaction` a question answers itself, so without `--force`
	 * this is refused rather than taken as a silent no.
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
