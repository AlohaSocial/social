<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Client;

use OCA\Social\Atproto\Xrpc\XrpcException;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Service\ConfigService;

/**
 * How many records, and how many bytes of them, one account may keep in
 * collections Social has no role for (`WriteService::isOpaque()`): what an
 * app writes there is stored and published as it came, so without a cap any
 * app could fill the repository, and every relay that copies it.
 */
class OpaqueRecordQuota {
	public function __construct(
		private ConfigService $config,
		private AtprotoRepoRequest $repositories,
	) {
	}

	/**
	 * Refuses a write that would take the account past either limit. A write
	 * that frees room, or stays within it, passes.
	 *
	 * @param int $records the records the write adds, less those it removes
	 * @param int $bytes the bytes it adds, less those it removes
	 * @throws XrpcException
	 */
	public function assertRoom(string $did, int $records, int $bytes): void {
		$maxRecords = max(0, $this->config->getAppValueInt(ConfigService::ATPROTO_OPAQUE_RECORDS));
		$maxMb = max(0, $this->config->getAppValueInt(ConfigService::ATPROTO_OPAQUE_MB));
		$maxBytes = $maxMb * 1024 * 1024;
		if (($records <= 0 || $maxRecords === 0) && ($bytes <= 0 || $maxBytes === 0)) {
			return;
		}

		$usage = $this->repositories->usageOutside($did, WriteService::roleCollections());
		if (($records > 0 && $maxRecords > 0 && $maxRecords < $usage['records'] + $records)
			|| ($bytes > 0 && $maxBytes > 0 && $maxBytes < $usage['bytes'] + $bytes)) {
			throw XrpcException::invalidRequest(sprintf(
				'This account has reached the limit for records in collections this server does not use: %s records and %s MB. Delete some of them first.',
				$maxRecords === 0 ? 'unlimited' : number_format($maxRecords),
				$maxMb === 0 ? 'unlimited' : number_format($maxMb),
			));
		}
	}
}
