<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use Exception;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\StreamDestRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Db\StreamTagsRequest;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CheckService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCA\Social\SetupChecks\ClientApiAtRoot;
use OCA\Social\SetupChecks\CloudAddressMatches;
use OCA\Social\SetupChecks\CronRanRecently;
use OCA\Social\SetupChecks\OutboundQueueNotStuck;
use OCA\Social\SetupChecks\WebFingerReachable;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\IDBConnection;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class CheckInstall extends SocialCommand {
	/** what the command says when nothing is wrong; the exit code is 0 */
	public const VERDICT_OK = '- all %d checks passed';
	/** and when something is; the exit code is 1 */
	public const VERDICT_ERRORS = '- %1$d of %2$d checks reported an error';
	/** what a run without --repair says about the entries it would remove */
	public const INVALID_FOUND = '%1$d follows and %2$d notes point to an account that is no longer cached';

	use TArrayTools;

	/**
	 * How many stream rows the index rebuild holds at once, so it cannot run
	 * out of memory on a large instance.
	 */
	private const INDEX_CHUNK = 500;

	/** how many individual failures the rebuild reports before it only counts them */
	private const INDEX_ERRORS_SHOWN = 10;

	private CacheActorService $cacheActorService;
	private StreamDestRequest $streamDestRequest;
	private ConfigService $configService;
	private IDBConnection $connection;

	public function __construct(
		private StreamRequest $streamRequest,
		StreamDestRequest $streamDestRequest,
		private StreamTagsRequest $streamTagsRequest,
		CacheActorService $cacheActorService,
		private CheckService $checkService,
		ConfigService $configService,
		private MiscService $miscService,
		IDBConnection $connection,
		private WebFingerReachable $webFingerReachable,
		private CloudAddressMatches $cloudAddressMatches,
		private CronRanRecently $cronRanRecently,
		private OutboundQueueNotStuck $outboundQueueNotStuck,
		private ClientApiAtRoot $clientApiAtRoot,
	) {
		parent::__construct();
		$this->streamDestRequest = $streamDestRequest;
		$this->cacheActorService = $cacheActorService;
		$this->configService = $configService;
		$this->connection = $connection;
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:check:install')
			->addOption(
				'repair', '', InputOption::VALUE_NONE,
				'remove the follows and notes whose account is no longer cached, and give every local'
				. ' account the self-follow its Home timeline needs; without it they are only counted'
			)
			->addOption('config', '', InputOption::VALUE_NONE, 'print the app configuration as JSON as well')
			->addOption('index', '', InputOption::VALUE_NONE, 'rebuild the timeline index from the posts instead of running the checks (asks first)')
			->addOption(
				'force', 'f', InputOption::VALUE_NONE,
				'skip the confirmation of --index (required with --no-interaction)'
			)
			->addOption(
				'offline', '', InputOption::VALUE_NONE,
				'skip the WebFinger and client API probes, the checks that go out on the network'
			)
			->setDescription('Run the setup checks without changing anything; --repair fixes what can be fixed, --index rebuilds the timeline index');
	}

	/**
	 * @throws Exception
	 */
	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$index = $this->regenerateIndexIfAsked($input, $output);
		if ($index !== null) {
			return $index;
		}

		$this->reportInvalidEntries($output, (bool)$input->getOption('repair'));

		$output->writeln('');
		$failed = $this->reportChecks($output, (bool)$input->getOption('offline'));

		if ($input->getOption('config')) {
			$output->writeln('');
			$output->writeln('- Your current configuration: ');
			$output->writeln((string)json_encode($this->configService->getConfig(), JSON_PRETTY_PRINT));
		}

		return $failed ? 1 : 0;
	}

	/**
	 * The follows and notes whose account is no longer cached: counted, and
	 * with --repair removed, together with the self-follow every local
	 * account needs. Without --repair nothing is written.
	 */
	private function reportInvalidEntries(OutputInterface $output, bool $repair): void {
		if ($repair) {
			$result = $this->checkService->checkInstallationStatus();
			$output->writeln('- ' . $this->getInt('invalidFollows', $result, 0) . ' invalid follows removed');
			$output->writeln('- ' . $this->getInt('invalidNotes', $result, 0) . ' invalid notes removed');
			$output->writeln('- every local account follows itself, as its Home timeline needs');

			return;
		}

		$follows = $this->checkService->removeInvalidFollows(true);
		$notes = $this->checkService->removeInvalidNotes(true);
		if ($follows === 0 && $notes === 0) {
			$output->writeln('- no follows or notes point to an account that is no longer cached');

			return;
		}

		$output->writeln('- <comment>' . sprintf(self::INVALID_FOUND, $follows, $notes) . '</comment>');
		$output->writeln('  occ social:check:install --repair removes them; nothing was changed');
	}

	/**
	 * Runs the same checks Administration → Overview shows, using the same
	 * classes so the two cannot drift, and prints each with its severity.
	 *
	 * @param bool $offline leave out the WebFinger probe, which is the one
	 *                      check that goes out on the network
	 *
	 * @return bool whether any check reported an error
	 */
	private function reportChecks(OutputInterface $output, bool $offline): bool {
		$checks = $this->checks($offline);
		if ($offline) {
			$output->writeln('- <comment>--offline: the WebFinger probe was not run</comment>');
		}

		$errors = 0;
		foreach ($checks as $check) {
			$result = $check->run();
			if ($result->getSeverity() === SetupResult::ERROR) {
				$errors++;
			}

			$output->writeln($this->line($check, $result));
			if ($result->getLinkToDoc() !== null && $result->getSeverity() !== SetupResult::SUCCESS) {
				$output->writeln('  see ' . $result->getLinkToDoc());
			}
		}

		// One-line verdict, matching the exit code a deployment script reads.
		$output->writeln($errors === 0
			? sprintf(self::VERDICT_OK, count($checks))
			: sprintf(self::VERDICT_ERRORS, $errors, count($checks)));

		return $errors > 0;
	}

	/**
	 * @return list<ISetupCheck>
	 */
	private function checks(bool $offline): array {
		$checks = [$this->cloudAddressMatches, $this->cronRanRecently, $this->outboundQueueNotStuck];
		if (!$offline) {
			// both probe over the network, so both are left out by --offline
			array_unshift($checks, $this->webFingerReachable, $this->clientApiAtRoot);
		}

		return $checks;
	}

	private function line(ISetupCheck $check, SetupResult $result): string {
		$description = (string)$result->getDescription();
		$text = $check->getName() . ': ' . $description;

		return match ($result->getSeverity()) {
			SetupResult::ERROR => '- <error>' . $text . '</error>',
			SetupResult::WARNING => '- <comment>' . $text . '</comment>',
			SetupResult::SUCCESS => '- <info>' . $text . '</info>',
			default => '- ' . $text,
		};
	}

	/**
	 * @return int|null the exit code, or null when --index was not asked for
	 */
	private function regenerateIndexIfAsked(InputInterface $input, OutputInterface $output): ?int {
		if (!$input->getOption('index')) {
			return null;
		}

		$output->writeln('<error>This command will regenerate the index of the Aloha Social App.</error>');
		$output->writeln(
			'<error>This operation can takes a while, and the Aloha Social App might not be stable during the process.</error>'
		);
		$output->writeln('');

		if (!$input->getOption('force')) {
			if (!$input->isInteractive()) {
				// A ConfirmationQuestion answers its default (false) when nobody is there.
				$output->writeln(
					'<error>Refusing to rebuild the index non-interactively without --force.</error>'
				);

				return 1;
			}

			$helper = $this->questionHelper();
			$question = new ConfirmationQuestion(
				'<info>Do you confirm this operation?</info> (y/N) ', false, '/^(y|Y)/i'
			);

			if (!$helper->ask($input, $output, $question)) {
				$output->writeln('cancelled, the index was left alone.');

				return 0;
			}
		}

		$this->streamDestRequest->emptyStreamDest();
		$this->streamTagsRequest->emptyStreamTags();

		return $this->regenerateIndex($output);
	}

	/**
	 * Rebuilds social_stream_dest and social_stream_tag from social_stream,
	 * paged on `nid` (not OFFSET) and one row at a time, so peak memory is one
	 * chunk.
	 */
	private function regenerateIndex(OutputInterface $output): int {
		$progressBar = new ProgressBar($output, $this->countStreams());
		$progressBar->start();

		$errors = [];
		$failed = 0;
		$lastNid = '0';

		while (true) {
			$chunk = $this->streamChunk($lastNid, self::INDEX_CHUNK);
			if ($chunk === []) {
				break;
			}

			foreach ($chunk as $row) {
				$lastNid = (string)$row['nid'];

				try {
					$stream = $this->streamRequest->getStream((string)$row['id_prim']);
					$this->streamDestRequest->generateStreamDest($stream);
					$this->streamTagsRequest->generateStreamTags($stream);
					// a repaired tag row changes what its hour's trend bucket counts
					$this->streamRequest->markTrendHour($stream->getPublishedTime());
				} catch (Exception $e) {
					$failed++;
					if (count($errors) < self::INDEX_ERRORS_SHOWN) {
						$errors[] = '  nid ' . $lastNid . ': ' . get_class($e) . ' - ' . $e->getMessage();
					}
				}

				$progressBar->advance();
			}
		}

		$progressBar->finish();
		$output->writeln('');

		if ($failed === 0) {
			return 0;
		}

		$output->writeln('<comment>' . $failed . ' stream(s) could not be indexed:</comment>');
		foreach ($errors as $error) {
			$output->writeln($error);
		}
		if ($failed > count($errors)) {
			$output->writeln('  … and ' . ($failed - count($errors)) . ' more');
		}

		return 1;
	}

	private function countStreams(): int {
		$qb = $this->connection->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))
			->from(CoreRequestBuilder::TABLE_STREAM);

		$cursor = $qb->executeQuery();
		$total = (int)$cursor->fetchOne();
		$cursor->closeCursor();

		return $total;
	}

	/**
	 * The next $limit stream rows after $afterNid, as (nid, id_prim) pairs; each
	 * full row is read back through StreamRequest, which parses it.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function streamChunk(int|string $afterNid, int $limit): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('nid', 'id_prim')
			->from(CoreRequestBuilder::TABLE_STREAM)
			->where($qb->expr()->gt('nid', $qb->createNamedParameter($afterNid)))
			->orderBy('nid', 'asc')
			->setMaxResults($limit);

		$cursor = $qb->executeQuery();
		$rows = $cursor->fetchAll();
		$cursor->closeCursor();

		return $rows;
	}
}
