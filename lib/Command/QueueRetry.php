<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Service\QueueRetryService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Retries or drops queued deliveries, such as those to hosts that
 * `social:queue:status` reports as given up on once the cause is fixed;
 * abandoned deliveries stay in the table for a week. The work is done by
 * `QueueRetryService`, shared with the Retry button on the admin page's
 * Deliveries card.
 */
class QueueRetry extends SocialCommand {
	public function __construct(
		private QueueRetryService $queueRetryService,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:queue:retry')
			->addOption(
				'token', 't', InputOption::VALUE_REQUIRED,
				'act on one delivery only, by its token', ''
			)
			->addOption(
				'min-tries', '', InputOption::VALUE_REQUIRED,
				'act on requests that have already failed at least this many times', '1'
			)
			->addOption(
				'instance', 'i', InputOption::VALUE_REQUIRED,
				'act on the deliveries for one host only, including the ones given up on', ''
			)
			->addOption(
				'limit', '', InputOption::VALUE_REQUIRED,
				'how many rows to touch at most', (string)QueueRetryService::DEFAULT_LIMIT
			)
			->addOption(
				'stream', '', InputOption::VALUE_NONE,
				'act on the inbound stream queue instead of the outbound delivery queue'
			)
			->addOption(
				'flush', '', InputOption::VALUE_NONE,
				'delete the matching rows instead of queueing them again'
			)
			->addOption(
				'force', 'f', InputOption::VALUE_NONE,
				'do not ask for confirmation (required with --no-interaction)'
			)
			->setDescription('Retry or drop queued deliveries that keep failing');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$table = $input->getOption('stream')
			? CoreRequestBuilder::TABLE_STREAM_QUEUE
			: CoreRequestBuilder::TABLE_REQUEST_QUEUE;
		$token = (string)$input->getOption('token');
		$minTries = max(0, (int)$input->getOption('min-tries'));
		$limit = max(1, (int)$input->getOption('limit'));
		$flush = (bool)$input->getOption('flush');
		$host = strtolower(trim((string)$input->getOption('instance')));

		if ($host !== '' && $input->getOption('stream')) {
			$output->writeln(
				'<error>--instance names the server a delivery was going to, and the stream'
				. ' queue holds what came in: the two cannot be combined.</error>'
			);

			return 1;
		}

		$matched = $this->queueRetryService->matching($table, $token, $minTries, $limit, $host);
		if ($matched === []) {
			$output->writeln('nothing in <info>' . $table . '</info> matches.');

			return 0;
		}

		$this->report($output, $table, $matched, $limit);

		if (!$this->confirm($input, $output, $flush, count($matched))) {
			return $input->isInteractive() ? 0 : 1;
		}

		$ids = array_map('intval', array_column($matched, 'id'));
		$touched = $flush
			? $this->queueRetryService->flush($table, $ids)
			: $this->queueRetryService->retry($table, $ids);

		$output->writeln(
			($flush ? 'dropped ' : 'queued again: ') . $touched . ' row(s).'
		);

		if (!$flush) {
			$output->writeln('They are sent on the next run of the queue cron, or by "occ social:queue:process".');
		}

		return 0;
	}

	/**
	 * @param list<array<string, mixed>> $matched
	 */
	private function report(OutputInterface $output, string $table, array $matched, int $limit): void {
		$tries = array_map('intval', array_column($matched, 'tries'));
		$tokens = array_unique(array_map('strval', array_column($matched, 'token')));

		$output->writeln(
			count($matched) . ' row(s) in <info>' . $table . '</info>, '
			. count($tokens) . ' distinct delivery token(s), '
			. 'between ' . min($tries) . ' and ' . max($tries) . ' failed attempts.'
		);

		if (count($matched) === $limit) {
			$output->writeln(
				'<comment>That is the --limit: run this again to work through the rest.</comment>'
			);
		}

		$output->writeln('');
	}

	private function confirm(InputInterface $input, OutputInterface $output, bool $flush, int $rows): bool {
		if ($input->getOption('force')) {
			return true;
		}

		if (!$input->isInteractive()) {
			$output->writeln('<error>Refusing to run non-interactively without --force.</error>');

			return false;
		}

		$question = new ConfirmationQuestion(
			$flush
				? '<error>Delete ' . $rows . ' queued row(s)? Those activities are never delivered.</error> (y/N) '
				: '<info>Queue ' . $rows . ' row(s) for delivery again?</info> (y/N) ',
			false,
			'/^(y|Y)/i'
		);

		if ((bool)$this->questionHelper()->ask($input, $output, $question)) {
			return true;
		}

		$output->writeln('cancelled, the queue was left alone.');

		return false;
	}
}
