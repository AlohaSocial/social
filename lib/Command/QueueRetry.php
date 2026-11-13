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
 * Retries or drops queued deliveries.
 *
 * `occ social:queue:status` could tell an administrator that N deliveries to a
 * host keep failing and nothing could be done about it: a delivery that fails
 * for a reason since fixed sat there until its fifteenth attempt deleted it,
 * and one that will never succeed (a peer that is gone, an activity a peer
 * refuses) kept a worker busy until then. This is the other half: put them
 * back in the queue, or take them out of it.
 *
 * `--instance` is the form that answers the report: `social:queue:status` names
 * the hosts this instance has given up on, and the reason it gave up is
 * usually one thing that has since been fixed — a certificate, a firewall, a
 * peer that was down for three days. Those deliveries are still in the table
 * for a week after they were abandoned, and this is what puts them back.
 *
 * The selecting and resetting is `QueueRetryService`, which the Retry button
 * on the admin page's Deliveries card uses as well.
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
