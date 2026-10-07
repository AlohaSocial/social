<?php
declare(strict_types=1);
namespace OCA\Social\Atproto\Firehose;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Repository\Repository;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
use Ratchet\{MessageComponentInterface, ConnectionInterface};
use Ratchet\RFC6455\Messaging\Frame;
use Psr\Log\LoggerInterface;
class FirehoseHandler implements MessageComponentInterface {
	private array $connections = [];
	public function __construct(private readonly IDBConnection $db, private readonly LoggerInterface $logger) {}
	public function onOpen(ConnectionInterface $conn): void {
		$request = $conn->httpRequest;
		if ($request->getUri()->getPath() !== '/xrpc/com.atproto.sync.subscribeRepos') { $conn->close(); return; }
		parse_str($request->getUri()->getQuery(), $query); $cursor = $query['cursor'] ?? null;
		if ($cursor !== null && (!is_string($cursor) || !ctype_digit($cursor) || strlen($cursor) > 18)) {
			$conn->send(self::errorFrame('InvalidRequest', 'Invalid cursor')); $conn->close(); return;
		}
		$qb = $this->db->getQueryBuilder(); $qb->select($qb->func()->max('seq', 'last_seq'), $qb->func()->min('seq', 'first_seq'))->from('social_atproto_event');
		$range = $qb->executeQuery()->fetchAssociative(); $last = (int)($range['last_seq'] ?? 0); $first = (int)($range['first_seq'] ?? 0);
		$cursor = $cursor === null ? $last : (int)$cursor;
		if ($cursor > $last) { $conn->send(self::errorFrame('FutureCursor', 'Cursor is ahead of the stream')); $conn->close(); return; }
		if ($first > 0 && $cursor < $first - 1) { $conn->send(self::errorFrame('ConsumerTooSlow', 'Cursor is outside the retained window; resync repositories')); $conn->close(); return; }
		$this->connections[$conn->resourceId] = ['conn' => $conn, 'cursor' => $cursor];
	}
	public function tick(): void {
		foreach ($this->connections as $id => &$client) {
			try {
				$qb = $this->db->getQueryBuilder(); $qb->select('e.seq', 'e.kind', 'e.bytes')->from('social_atproto_event', 'e')
					->innerJoin('e', 'social_atproto_identity', 'i', 'i.did = e.did')->where($qb->expr()->gt('e.seq', $qb->createNamedParameter($client['cursor'], IQueryBuilder::PARAM_INT)))
					->andWhere($qb->expr()->orX($qb->expr()->eq('i.state', $qb->createNamedParameter('active')), $qb->expr()->eq('e.kind', $qb->createNamedParameter('#account'))))->orderBy('e.seq', 'ASC')->setMaxResults(100);
				foreach ($qb->executeQuery()->fetchAllAssociative() as $event) {
					$client['conn']->send(self::frame($event)); $client['cursor'] = (int)$event['seq'];
				}
			} catch (\Throwable $e) { $this->logger->warning('AT Protocol stream failed', ['exception' => $e]); $client['conn']->close(); unset($this->connections[$id]); }
		} unset($client);
	}
	public static function frame(array $event): Frame {
		$body = DagCbor::decode(Repository::bytes($event['bytes'])); $body['seq'] = (int)$event['seq'];
		return new Frame(DagCbor::encode(['op' => 1, 't' => $event['kind']]) . DagCbor::encode($body), true, Frame::OP_BINARY);
	}
	private static function errorFrame(string $error, string $message): Frame {
		return new Frame(DagCbor::encode(['op' => -1]) . DagCbor::encode(['error' => $error, 'message' => $message]), true, Frame::OP_BINARY);
	}
	public function onMessage(ConnectionInterface $conn, $msg): void { $conn->close(); }
	public function onClose(ConnectionInterface $conn): void { unset($this->connections[$conn->resourceId]); }
	public function onError(ConnectionInterface $conn, \Exception $e): void { $conn->close(); $this->onClose($conn); }
	public function closeAll(): void { foreach ($this->connections as $client) { $client['conn']->close(); } $this->connections = []; }
}
