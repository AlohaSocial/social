<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Object;

use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Interfaces\Activity\AbstractActivityPubInterface;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Flag;
use OCA\Social\Service\ReportService;
use Psr\Log\LoggerInterface;

/**
 * Incoming federated reports: a remote instance flags one of our accounts
 * (and, usually, some of its statuses). The report is stored for the local
 * admins; nothing is federated back.
 */
class FlagInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	public function __construct(
		private ReportService $reportService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @throws InvalidOriginException
	 */
	#[\Override]
	public function processIncomingRequest(ACore $item): void {
		/** @var Flag $flag */
		$flag = $item;
		$flag->checkOrigin($flag->getActorId());

		if ($flag->getObjectIds() === []) {
			$this->logger->notice('incoming Flag without objects, ignored', ['actor' => $flag->getActorId()]);

			return;
		}

		$report = $this->reportService->reportFromFlag($flag);
		if ($report === null) {
			// nothing of this instance is named; the service has said so
			return;
		}

		$this->logger->info(
			'incoming report stored',
			['report' => $report->getId(), 'from' => $flag->getActorId()]
		);
	}
}
