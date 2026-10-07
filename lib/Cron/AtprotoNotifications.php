<?php
declare(strict_types=1);

namespace OCA\Social\Cron;

use OCA\Social\Atproto\Sync\AtprotoNotifications;
use OCP\ILogger;

class AtprotoNotificationsJob {
	public function __construct(
		private readonly AtprotoNotifications $notifications,
		private readonly ILogger $logger
	) {}
	
	public function run(): void {
		try {
			$this->notifications->run();
		} catch (\Throwable $e) {
			$this->logger->error('AtprotoNotifications cron job failed', ['error' => $e->getMessage()]);
		}
	}
}