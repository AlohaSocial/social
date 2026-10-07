<?php
declare(strict_types=1);

namespace OCA\Social\Atproto\Firehose;

use OCP\IDBConnection;
use OCP\ILogger;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use SpomkyLabs\Cbor\CborEncoder;

class FirehoseDaemon {
	private const EVENT_WINDOW_HOURS = 72;
	private const POLL_INTERVAL_MS = 250;
	
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ILogger $logger
	) {}
	
	public function run(string $host, int $port, bool $once = false, int $maxSeconds = 0): void {
		$server = IoServer::factory(
			new HttpServer(
				new WsServer(
					new FirehoseHandler($this->db, $this->logger)
				)
			),
			$port,
			$host
		);
		
		$startTime = time();
		
		if ($once) {
			$this->drainEvents();
			return;
		}
		
		$lastEventId = $this->getLastEventId();
		
		while (true) {
			if ($maxSeconds > 0 && (time() - $startTime) >= $maxSeconds) {
				$this->logger->info('Firehose daemon max runtime reached');
				break;
			}
			
			$lastEventId = $this->pollEvents($lastEventId);
			
			$server->loop->runOne();
			
			usleep(self::POLL_INTERVAL_MS * 1000);
		}
	}
	
	private function drainEvents(): void {
		$lastEventId = 0;
		do {
			$lastEventId = $this->pollEvents($lastEventId);
		} while ($lastEventId > 0);
	}
	
	private function pollEvents(int $lastEventId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('social_atproto_event')
			->where($qb->expr()->gt('seq', $qb->createNamedParameter($lastEventId, \PDO::PARAM_INT)))
			->orderBy('seq', 'ASC')
			->setMaxResults(100);
		
		$events = $qb->executeQuery()->fetchAllAssociative();
		
		foreach ($events as $event) {
			FirehoseHandler::broadcast($event);
			$lastEventId = $event['seq'];
		}
		
		$cutoff = (new \DateTime())->modify('-' . self::EVENT_WINDOW_HOURS . ' hours')->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->delete('social_atproto_event')
			->where($qb->expr()->lt('time', $qb->createNamedParameter($cutoff)))
			->executeStatement();
		
		return $lastEventId;
	}
	
	private function getLastEventId(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('MAX(seq) as max_seq')
			->from('social_atproto_event');
		$result = $qb->executeQuery()->fetchOne();
		return (int)($result ?? 0);
	}
}

class FirehoseHandler implements MessageComponentInterface {
	private static array $connections = [];
	private static int $sequenceCounter = 0;
	
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ILogger $logger
	) {}
	
	public function onOpen(ConnectionInterface $conn): void {
		$query = $conn->WebSocket->request->getQuery();
		$cursor = isset($query['cursor']) ? (int)$query['cursor'] : null;
		
		self::$connections[(int)$conn->resourceId] = [
			'conn' => $conn,
			'cursor' => $cursor
		];
		
		$this->logger->info('Firehose client connected', ['cursor' => $cursor, 'total' => count(self::$connections)]);
		
		if ($cursor !== null) {
			$this->replayFromCursor($conn, $cursor);
		} else {
			$this->sendInfoFrame($conn, 'Connected to firehose');
		}
	}
	
	public function onMessage(ConnectionInterface $conn, \Ratchet\RFC6455\Messaging\MessageInterface $msg): void {
		// Firehose is server-to-client only
	}
	
	public function onClose(ConnectionInterface $conn): void {
		unset(self::$connections[(int)$conn->resourceId]);
		$this->logger->info('Firehose client disconnected', ['total' => count(self::$connections)]);
	}
	
	public function onError(ConnectionInterface $conn, \Exception $e): void {
		$this->logger->error('Firehose connection error', ['error' => $e->getMessage()]);
		$conn->close();
	}
	
	public static function broadcast(array $event): void {
		// Extract the frame data from the event
		$frameData = json_decode($event['bytes'], true);
		if (!$frameData) return;
		
		$frameData['seq'] = (int)($event['seq'] ?? 0);
		
		// Encode frame as CBOR for wire format
		$frameCbor = CborEncoder::encode($frameData);
		
		foreach (self::$connections as $id => $client) {
			try {
				// Send binary frame (CBOR)
				$client['conn']->send($frameCbor, true); // true = binary
			} catch (\Throwable $e) {
				unset(self::$connections[$id]);
			}
		}
	}
	
	private function replayFromCursor(ConnectionInterface $conn, int $cursor): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('social_atproto_event')
			->where($qb->expr()->gt('seq', $qb->createNamedParameter($cursor, \PDO::PARAM_INT)))
			->orderBy('seq', 'ASC');
		
		$events = $qb->executeQuery()->fetchAllAssociative();
		
		foreach ($events as $event) {
			$frameData = json_decode($event['bytes'], true);
			if ($frameData) {
				$frameData['seq'] = (int)$event['seq'];
				$frameCbor = CborEncoder::encode($frameData);
				$conn->send($frameCbor, true);
			}
		}
		
		$this->sendInfoFrame($conn, 'Replay complete, now live');
	}
	
	private function sendInfoFrame(ConnectionInterface $conn, string $message): void {
		$frame = [
			'type' => 'info',
			'seq' => ++self::$sequenceCounter,
			'message' => $message
		];
		$frameCbor = CborEncoder::encode($frame);
		$conn->send($frameCbor, true);
	}
}