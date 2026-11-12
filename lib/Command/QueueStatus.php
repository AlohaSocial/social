<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use Exception;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FederationHealthService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\RequestQueueService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class QueueStatus extends SocialCommand {
	public function __construct(
		private RequestQueueService $requestQueueService,
		private ConfigService $configService,
		private MiscService $miscService,
		private FederationHealthService $federationHealthService,
	) {
		parent::__construct();
	}

	/**
	 *
	 */
	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:queue:status')
			->addOption(
				'token', 't', InputOption::VALUE_OPTIONAL, 'token of a request'
			)
			->setDescription('Show what the outbound delivery queue holds and what it gave up on, or the deliveries of one --token');
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @throws Exception
	 */
	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$token = $input->getOption('token');

		if ($token === null) {
			// an administrator asking after "the queue" wants the state of the
			// queue, not an argument about which request they meant
			$this->reportHealth($output);

			return 0;
		}

		$requests = $this->requestQueueService->getRequestFromToken($token);

		foreach ($requests as $request) {
			$output->writeLn(json_encode($request));
		}

		return 0;
	}

	private function reportHealth(OutputInterface $output): void {
		$summary = $this->federationHealthService->summary();

		$output->writeln($summary['waiting'] . ' deliveries waiting, ' . $summary['running'] . ' being sent');

		if ($summary['failing'] === 0) {
			$output->writeln('<info>nothing is failing to deliver</info>');
		} else {
			$output->writeln(
				'<comment>' . $summary['failing'] . ($summary['truncated'] ? '+' : '')
				. ' have failed at least once, ' . $summary['atRisk']
				. ' are close to being given up on (abandoned after '
				. $summary['maxTries'] . ' attempts)</comment>'
			);
			$output->writeln('');

			foreach ($summary['instances'] as $instance) {
				$output->writeln(
					sprintf(
						'  %-40s %4d waiting   %2d/%d attempts   last %s',
						$instance['host'],
						$instance['requests'],
						$instance['tries'],
						$summary['maxTries'],
						$instance['last'] > 0 ? gmdate('Y-m-d H:i', $instance['last']) : 'never'
					)
				);
			}
		}

		$this->reportGivenUp($output, $summary);
	}

	/**
	 * What this instance has stopped trying to deliver.
	 *
	 * Printed even when nothing is currently failing, and printed separately:
	 * an empty "failing" section on an instance that gave up on a peer
	 * yesterday is the reassuring half of a bad answer.
	 *
	 * @param array<string, mixed> $summary what FederationHealthService::summary() answers
	 */
	private function reportGivenUp(OutputInterface $output, array $summary): void {
		$abandoned = (int)$summary['abandoned'];
		$days = (int)$summary['retentionDays'];
		$maxTries = (int)$summary['maxTries'];

		if ($abandoned === 0) {
			$output->writeln('<info>nothing has been given up on in the last ' . $days . ' days</info>');

			return;
		}

		$output->writeln('');
		$output->writeln(
			'<error>' . $abandoned . ($summary['abandonedTruncated'] ? '+' : '')
			. ' deliveries were given up on in the last ' . $days
			. ' days: those servers never got them</error>'
		);
		$output->writeln('');

		/** @var list<array{host: string, requests: int, tries: int, last: int}> $givenUp */
		$givenUp = $summary['givenUp'];
		foreach ($givenUp as $instance) {
			$output->writeln(
				sprintf(
					'  %-40s %4d given up   %2d/%d attempts   last %s',
					$instance['host'],
					$instance['requests'],
					$instance['tries'],
					$maxTries,
					$instance['last'] > 0 ? gmdate('Y-m-d H:i', $instance['last']) : 'never'
				)
			);
		}

		$output->writeln('');
		$output->writeln(
			'Once the reason is fixed, "occ social:queue:retry --instance HOST" queues them again.'
		);
	}
}
