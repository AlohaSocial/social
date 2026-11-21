<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\RequestQueueRequest;
use OCA\Social\Model\RequestQueue;

/**
 * Where a post got to: per server, whether its delivery was sent, is queued,
 * failing or given up on. Only as long as the rows are kept
 * (`RequestQueueService::RETENTION_SECONDS`); the answer carries `retention`
 * so a client can say how far back it reaches.
 */
class DeliveryService {
	/** What a row's status and tries add up to, as a client should read it. */
	public const STATE_DELIVERED = 'delivered';
	public const STATE_SENDING = 'sending';
	public const STATE_WAITING = 'waiting';
	public const STATE_FAILING = 'failing';
	public const STATE_ABANDONED = 'abandoned';

	public function __construct(
		private RequestQueueRequest $requestQueueRequest,
	) {
	}

	/**
	 * The deliveries of one object, counted and listed per server.
	 *
	 * @return array{
	 *   delivered: int, sending: int, waiting: int, failing: int, abandoned: int, total: int,
	 *   retention: int,
	 *   instances: list<array{host: string, state: string, tries: int, last: int}>
	 * }
	 */
	public function forObject(string $objectId): array {
		$counts = array_fill_keys([
			self::STATE_DELIVERED, self::STATE_SENDING, self::STATE_WAITING,
			self::STATE_FAILING, self::STATE_ABANDONED,
		], 0);
		$instances = [];

		foreach ($this->requestQueueRequest->getByObject(md5($objectId)) as $request) {
			$state = self::stateOf($request);
			$counts[$state]++;
			$instances[] = [
				'host' => $request->getInstance()?->getAddress() ?? '',
				'state' => $state,
				'tries' => $request->getTries(),
				'last' => $request->getLast(),
			];
		}

		// what needs the author's eye first: given up, then failing, then the
		// rest in the order they were queued
		usort($instances, static fn (array $a, array $b): int
			=> self::rank($a['state']) <=> self::rank($b['state']));

		return $counts + [
			'total' => count($instances),
			'retention' => RequestQueueService::RETENTION_SECONDS,
			'instances' => $instances,
		];
	}

	/** One word for a row, from the two columns that describe it. */
	public static function stateOf(RequestQueue $request): string {
		return match (true) {
			$request->getStatus() === RequestQueue::STATUS_SUCCESS => self::STATE_DELIVERED,
			$request->getStatus() === RequestQueue::STATUS_ABANDONED => self::STATE_ABANDONED,
			$request->getStatus() === RequestQueue::STATUS_RUNNING => self::STATE_SENDING,
			$request->getTries() > 0 => self::STATE_FAILING,
			default => self::STATE_WAITING,
		};
	}

	private static function rank(string $state): int {
		return [
			self::STATE_ABANDONED => 0,
			self::STATE_FAILING => 1,
			self::STATE_SENDING => 2,
			self::STATE_WAITING => 3,
			self::STATE_DELIVERED => 4,
		][$state] ?? 5;
	}
}
