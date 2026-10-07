<?php
declare(strict_types=1);

namespace OCA\Social\Cron;

use OCA\Social\Atproto\RecordMapper\OutboundQueue;
use OCA\Social\Atproto\RecordMapper\OutboundWorker;
use OCA\Social\Db\QueueRequest;
use OCP\ILogger;

class AtprotoOutboundWorker {
	public function __construct(
		private readonly OutboundQueue $queue,
		private readonly OutboundWorker $worker,
		private readonly QueueRequest $queueRequest,
		private readonly ILogger $logger
	) {}
	
	/**
	 * Process the outbound queue - called by cron
	 */
	public function run(): void {
		$processed = 0;
		$failed = 0;
		
		while (true) {
			$item = $this->queueRequest->takeItem(OutboundQueue::QUEUE_NAME, 10);
			if (!$item) {
				break; // Queue is empty
			}
			
			$payload = json_decode($item['payload'], true);
			$action = $payload['action'] ?? '';
			
			if (!$action) {
				$this->queueRequest->removeItem($item['id']);
				$failed++;
				continue;
			}
			
			$success = $this->worker->process($action, $payload);
			
			if ($success) {
				$this->queueRequest->removeItem($item['id']);
				$processed++;
			} else {
				$attempts = ($item['attempts'] ?? 0) + 1;
				if ($attempts >= OutboundWorker::MAX_RETRIES) {
					$this->logger->error('Atproto outbound action failed permanently', [
						'action' => $payload['action'] ?? 'unknown',
						'payload' => $payload,
						'attempts' => $attempts
					]);
					$this->queueRequest->removeItem($item['id']);
					$failed++;
				} else {
					$this->queueRequest->updateItem($item['id'], [
						'attempts' => $attempts,
						'last_attempt' => (new \DateTime())->format('Y-m-d H:i:s')
					]);
					$failed++;
				}
			}
		}
		
		if ($processed > 0 || $failed > 0) {
			$this->logger->debug('Atproto outbound worker completed', [
				'processed' => $processed,
				'failed' => $failed
			]);
		}
	}
}