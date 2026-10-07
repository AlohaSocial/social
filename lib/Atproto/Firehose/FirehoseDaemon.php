<?php

declare(strict_types=1);

namespace OCA\Social\Atproto\Firehose;

use OCA\Social\Atproto\Identity\IdentityService;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

class FirehoseDaemon {
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
		private readonly IdentityService $identities,
		private readonly EventStore $events,
	) {
	}
	public function run(string $host, int $port, bool $once = false, int $maxSeconds = 0): void {
		if (!$this->identities->isEnabled()) {
			throw new \RuntimeException('AT Protocol is disabled');
		}
		if ($port < 1 || $port > 65535) {
			throw new \InvalidArgumentException('Invalid port');
		}
		$this->prune();
		if ($once) {
			return;
		}
		$handler = new FirehoseHandler($this->db, $this->logger, $this->events);
		$server = BoundedIoServer::factory(new HttpServer(new WsServer($handler)), $port, $host);
		$server->loop->addPeriodicTimer(0.25, function () use ($handler, $server) {
			if (!$this->identities->isEnabled()) {
				$handler->closeAll();
				$server->loop->stop();
				return;
			} $handler->tick();
		});
		$server->loop->addPeriodicTimer(3600, $this->prune(...));
		if ($maxSeconds > 0) {
			$server->loop->addTimer($maxSeconds, static function () use ($server) {
				$server->loop->stop();
			});
		}
		try {
			$server->run();
		} finally {
			$handler->closeAll();
			$server->socket->close();
		}
	}
	private function prune(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('social_atpds_event')->where($qb->expr()->lt('time', $qb->createNamedParameter(gmdate('Y-m-d H:i:s', time() - 72 * 3600))))->executeStatement();
	}
}
