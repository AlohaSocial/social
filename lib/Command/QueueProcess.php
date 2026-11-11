<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use OCA\Social\Service\ActivityService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\RequestQueueService;
use OCA\Social\Service\StreamQueueService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class QueueProcess extends SocialCommand {
	private RequestQueueService $requestQueueService;
	private ConfigService $configService;

	public function __construct(
		private ActivityService $activityService,
		RequestQueueService $requestQueueService,
		private StreamQueueService $streamQueueService,
		ConfigService $configService,
		private MiscService $miscService,
	) {
		parent::__construct();
		$this->requestQueueService = $requestQueueService;
		$this->configService = $configService;
	}

	/**
	 *
	 */
	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:queue:process')
			->setDescription('Send the queued deliveries and resolve the queued incoming posts once, now');
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 */
	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$output->writeLn('processing requests queue');
		$this->processRequestQueue($output);

		$output->writeLn('processing stream queue');
		$this->processStreamQueue($output);

		return 0;
	}

	/**
	 * @param OutputInterface $output
	 */
	private function processRequestQueue(OutputInterface $output) {
		$total = 0;
		$requests = $this->requestQueueService->getRequestStandby($total);

		$output->writeLn('- found a total of ' . $total . ' requests in the queue');
		if ($total === 0) {
			return;
		}

		$output->writeLn('- ' . sizeof($requests) . ' are processable at this time');
		if (sizeof($requests) === 0) {
			return;
		}

		$this->activityService->manageInit();
		foreach ($requests as $request) {
			$request->setTimeout(ActivityService::TIMEOUT_SERVICE);
			$output->write('.');
			try {
				$this->activityService->manageRequest($request);
			} catch (Throwable $e) {
				// the row was marked `running` before the attempt: left that
				// way it is never retried, never counted against MAX_TRIES and
				// only freed by the stale reaper an hour later
				$output->write('E');
				$this->miscService->log(
					'could not deliver ' . $request->getToken() . ': ' . get_class($e) . ' '
					. $e->getMessage(), 2
				);
				$this->requestQueueService->endRequest($request, false);
			}
		}

		$output->writeLn('done');
	}

	private function processStreamQueue(OutputInterface $output) {
		// an item whose drain died mid-resolution stays `running`, and nothing
		// but this ever looks at a running row again
		$this->streamQueueService->reapStaleRunning();

		$total = 0;
		$items = $this->streamQueueService->getRequestStandby($total);

		$output->writeLn('- found a total of ' . $total . ' not cached object in the queue');
		if ($total === 0) {
			return;
		}

		$output->writeLn('- ' . sizeof($items) . ' are processable at this time');
		if (sizeof($items) === 0) {
			return;
		}

		foreach ($items as $item) {
			$output->write('.');
			try {
				$this->streamQueueService->manageStreamQueue($item);
			} catch (Throwable $e) {
				$output->write('E');
				$this->miscService->log(
					'could not resolve ' . $item->getStreamId() . ': ' . get_class($e) . ' '
					. $e->getMessage(), 2
				);
			}
		}

		$output->writeLn('done');
	}
}
