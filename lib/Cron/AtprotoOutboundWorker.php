<?php
declare(strict_types=1);
namespace OCA\Social\Cron;
use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\RecordMapper\OutboundWorker as Worker;
use Psr\Log\LoggerInterface;
class AtprotoOutboundWorker extends TimedJob {
	public function __construct(ITimeFactory $time, private readonly Worker $worker, private readonly IdentityService $identities, private readonly LoggerInterface $logger) {
		parent::__construct($time); $this->setInterval(60); $this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}
	protected function run($argument): void {
		if (!$this->identities->isEnabled()) { return; }
		try { $this->worker->run(); } catch (\Throwable $e) { $this->logger->error('AT Protocol background work failed', ['exception' => $e]); }
	}
}
