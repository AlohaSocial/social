<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Firehose;

use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Repository\Repository;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use Ratchet\RFC6455\Messaging\Frame;

class FirehoseHandler implements MessageComponentInterface {
	private array $connections = [];
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
		private readonly EventStore $events,
	) {
	}
	#[\Override]
	public function onOpen(ConnectionInterface $conn): void {
		/** @var object{httpRequest: \Psr\Http\Message\RequestInterface} $requestConnection */
		$requestConnection = $conn;
		$request = $requestConnection->httpRequest;
		if ($request->getUri()->getPath() !== '/xrpc/com.atproto.sync.subscribeRepos') {
			$conn->close();
			return;
		}
		parse_str($request->getUri()->getQuery(), $query);
		$cursor = $query['cursor'] ?? null;
		if ($cursor !== null && (!is_string($cursor) || !ctype_digit($cursor) || strlen($cursor) > 18)) {
			$this->sendFrame($conn, self::errorFrame('InvalidRequest', 'Invalid cursor'));
			$conn->close();
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->min('seq'))->from('social_atpds_event');
		$first = (int)$qb->executeQuery()->fetchOne();
		$last = $this->events->latestSequence();
		$cursor = $cursor === null ? $last : (int)$cursor;
		if ($cursor > $last) {
			$this->sendFrame($conn, self::errorFrame('FutureCursor', 'Cursor is ahead of the stream'));
			$conn->close();
			return;
		}
		if (($first > 0 && $cursor < $first - 1) || ($first === 0 && $cursor < $last)) {
			$this->sendFrame($conn, self::errorFrame('ConsumerTooSlow', 'Cursor is outside the retained window; resync repositories'));
			$conn->close();
			return;
		}
		$this->connections[spl_object_id($conn)] = ['conn' => $conn, 'cursor' => $cursor];
	}
	public function tick(): void {
		foreach ($this->connections as $id => &$client) {
			try {
				$qb = $this->db->getQueryBuilder();
				$qb->select('e.seq', 'e.kind', 'e.bytes')->from('social_atpds_event', 'e')
					->innerJoin('e', 'social_atpds_identity', 'i', 'i.did = e.did')->where($qb->expr()->gt('e.seq', $qb->createNamedParameter($client['cursor'], IQueryBuilder::PARAM_INT)))
					->andWhere($qb->expr()->orX($qb->expr()->eq('i.state', $qb->createNamedParameter('active')), $qb->expr()->eq('e.kind', $qb->createNamedParameter('#account'))))->orderBy('e.seq', 'ASC')->setMaxResults(100);
				foreach ($qb->executeQuery()->fetchAllAssociative() as $event) {
					$this->sendFrame($client['conn'], self::frame($event));
					$client['cursor'] = (int)$event['seq'];
				}
			} catch (\Throwable $e) {
				$this->logger->warning('AT Protocol stream failed', ['exception' => $e]);
				$client['conn']->close();
				unset($this->connections[$id]);
			}
		} unset($client);
	}
	private function sendFrame(ConnectionInterface $conn, Frame $frame): void {
		// WsConnection accepts DataInterface frames; the upstream interface only documents strings.
		/** @psalm-suppress ImplicitToStringCast */
		$conn->send($frame);
	}
	public static function frame(array $event): Frame {
		$body = DagCbor::decode(Repository::bytes($event['bytes']));
		$body['seq'] = (int)$event['seq'];
		return new Frame(DagCbor::encode(['op' => 1, 't' => $event['kind']]) . DagCbor::encode($body), true, Frame::OP_BINARY);
	}
	private static function errorFrame(string $error, string $message): Frame {
		return new Frame(DagCbor::encode(['op' => -1]) . DagCbor::encode(['error' => $error, 'message' => $message]), true, Frame::OP_BINARY);
	}
	#[\Override]
	public function onMessage(ConnectionInterface $from, $msg): void {
		$from->close();
	}
	#[\Override]
	public function onClose(ConnectionInterface $conn): void {
		unset($this->connections[spl_object_id($conn)]);
	}
	#[\Override]
	public function onError(ConnectionInterface $conn, \Exception $e): void {
		$conn->close();
		$this->onClose($conn);
	}
	public function closeAll(): void {
		foreach ($this->connections as $client) {
			$client['conn']->close();
		} $this->connections = [];
	}
}
