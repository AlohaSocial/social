<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Service\GifPackService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fetches the first screenful of the animated emoji into appdata ahead of
 * time, so the first person to open the picker does not wait. Only that
 * screenful: the rest, about 440 MiB, is fetched when somebody picks one. A
 * few per run, as each is an HTTP request.
 */
class GifPack extends TimedJob {
	/** How much of the pack is worth having before it is asked for. */
	private const WARM = 60;

	/** How many to fetch in one run. */
	private const PER_RUN = 10;

	public function __construct(
		ITimeFactory $time,
		private ?GifPackService $gifPackService = null,
		private ?LoggerInterface $logger = null,
	) {
		parent::__construct($time);

		$this->setInterval(15 * 60);
	}

	/**
	 * @param mixed $argument
	 */
	#[\Override]
	protected function run($argument) {
		if ($this->gifPackService === null || !$this->gifPackService->enabled()) {
			return;
		}

		$fetched = 0;
		foreach (array_slice($this->gifPackService->all(), 0, self::WARM) as $gif) {
			if ($fetched >= self::PER_RUN) {
				return;
			}

			if ($this->gifPackService->isCached($gif->getSlug())) {
				continue;
			}

			try {
				$this->gifPackService->file($gif->getSlug());
				$fetched++;
			} catch (Throwable $e) {
				// the source is unreachable, or this instance has no way out:
				// say so once and stop, rather than spending the run failing
				$this->logger?->notice('could not warm the animated emoji pack', [
					'slug' => $gif->getSlug(), 'exception' => $e,
				]);

				return;
			}
		}
	}
}
