<?php
declare(strict_types=1);

namespace OCA\Social\Cron;

use OCA\Social\Atproto\Sync\AtprotoSync;
use OCP\ILogger;

class AtprotoSyncJob {
	public function __construct(
		private readonly AtprotoSync $sync,
		private readonly ILogger $logger
	) {}
	
	public function run(): void {
		try {
			$this->sync->run();
		} catch (\Throwable $e) {
			$this->logger->error('AtprotoSync cron job failed', ['error' => $e->getMessage()]);
		}
	}
}