<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use DateTime;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\RequestQueueRequest;
use OCA\Social\Db\StreamQueueRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Retention for federated content: remote statuses older than the configured
 * number of days are deleted together with their dest/action/tag rows and
 * cached attachments — unless someone local still cares about them.
 *
 * A remote status is protected when:
 *  - a local user interacted with it (like/boost/reply/bookmark flags, or a
 *    Like/Announce action row),
 *  - its author is followed by a local user,
 *  - a local status replies to it (threads under local posts stay readable),
 *  - a local stream row references it as its object (a local boost),
 *  - it is a direct message.
 *
 * Local content is never touched. Disabled by default (`retention_days` = 0).
 *
 * Two smaller sweeps run on every pass whatever `retention_days` says: the
 * queue rows nothing will act on again, and the in-app notification rows
 * older than `notification_retention_days` (default 90) — local rows about a
 * local user's post that nothing reads once they have scrolled out of the
 * notifications timeline.
 */
class StreamPruneService {
	public const CHUNK = 500;

	public function __construct(
		private IDBConnection $connection,
		private ConfigService $configService,
		private StreamRequest $streamRequest,
		private RequestQueueRequest $requestQueueRequest,
		private StreamQueueRequest $streamQueueRequest,
		private LoggerInterface $logger,
	) {
	}

	public function getRetentionDays(): int {
		return (int)$this->configService->getAppValue(ConfigService::SOCIAL_RETENTION_DAYS);
	}

	public function getNotificationRetentionDays(): int {
		return (int)$this->configService->getAppValue(ConfigService::SOCIAL_NOTIFICATION_RETENTION_DAYS);
	}

	/**
	 * @return array{streams: int, documents: int, notifications: int} what was (or would be) removed
	 */
	public function prune(?int $days = null, bool $dryRun = false, int $max = 0): array {
		$days ??= $this->getRetentionDays();

		if (!$dryRun) {
			$this->pruneQueues();
		}
		$notifications = $this->pruneNotifications($dryRun, $max);

		if ($days <= 0) {
			return ['streams' => 0, 'documents' => 0, 'notifications' => $notifications];
		}

		$cutoff = new DateTime($days . ' days ago');
		if ($dryRun) {
			return ['streams' => $this->countPrunable($cutoff), 'documents' => 0, 'notifications' => $notifications];
		}

		$streams = 0;
		$documents = 0;
		while (true) {
			$limit = self::CHUNK;
			if ($max > 0) {
				$limit = min($limit, $max - $streams);
				if ($limit <= 0) {
					break;
				}
			}

			$prims = $this->selectPrunable($cutoff, $limit);
			if ($prims === []) {
				break;
			}

			$streams += count($prims);
			// one cascade, defined next to the delete it belongs to
			$documents += $this->streamRequest->deleteRelatedTo($prims);
			$this->streamRequest->deleteByPrims($prims);
		}

		if ($streams > 0) {
			$this->logger->info('stream retention pruned rows', [
				'days' => $days, 'streams' => $streams, 'documents' => $documents,
			]);
		}

		return ['streams' => $streams, 'documents' => $documents, 'notifications' => $notifications];
	}

	/**
	 * Notification rows past `notification_retention_days`, in the same chunks
	 * as the statuses and under the same ceiling; 0 keeps them all.
	 *
	 * @return int how many were (or would be) removed
	 */
	public function pruneNotifications(bool $dryRun = false, int $max = 0): int {
		$days = $this->getNotificationRetentionDays();
		if ($days <= 0) {
			return 0;
		}

		$cutoff = new DateTime($days . ' days ago');
		if ($dryRun) {
			return $this->streamRequest->countNotificationsBefore($cutoff);
		}

		$pruned = 0;
		while (true) {
			$limit = self::CHUNK;
			if ($max > 0) {
				$limit = min($limit, $max - $pruned);
				if ($limit <= 0) {
					break;
				}
			}

			$prims = $this->streamRequest->getNotificationPrimsBefore($cutoff, $limit);
			if ($prims === []) {
				break;
			}

			$pruned += count($prims);
			$this->streamRequest->deleteRelatedTo($prims);
			$this->streamRequest->deleteByPrims($prims);
		}

		if ($pruned > 0) {
			$this->logger->info('notification retention pruned rows', ['days' => $days, 'notifications' => $pruned]);
		}

		return $pruned;
	}

	private function countPrunable(DateTime $cutoff): int {
		$qb = $this->prunableQuery($cutoff);
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'count');

		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return (int)($row['count'] ?? 0);
	}

	/**
	 * @return string[] id_prim of prunable remote streams, oldest first
	 */
	private function selectPrunable(DateTime $cutoff, int $limit): array {
		$qb = $this->prunableQuery($cutoff);
		$qb->select('s.id_prim')
			->orderBy('s.creation', 'asc')
			->setMaxResults($limit);

		$cursor = $qb->executeQuery();
		$prims = array_map(static fn (array $row): string => (string)$row['id_prim'], $cursor->fetchAll());
		$cursor->closeCursor();

		return $prims;
	}

	/**
	 * The conditions that make a remote stream prunable; see the class doc for
	 * the protection rules.
	 */
	private function prunableQuery(DateTime $cutoff): IQueryBuilder {
		$qb = $this->connection->getQueryBuilder();
		$expr = $qb->expr();

		$qb->from(CoreRequestBuilder::TABLE_STREAM, 's')
			->where($expr->eq('s.local', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($expr->in('s.type', $qb->createNamedParameter(
				[Note::TYPE, Announce::TYPE], IQueryBuilder::PARAM_STR_ARRAY
			)))
			->andWhere($expr->lt('s.creation', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_DATE)))
			->andWhere($expr->neq('s.visibility', $qb->createNamedParameter(Stream::TYPE_DIRECT)));

		// a local interaction flag on the status
		$act = $this->connection->getQueryBuilder();
		$act->select($act->createFunction('1'))
			->from(CoreRequestBuilder::TABLE_STREAM_ACTIONS, 'sa')
			->where('sa.stream_id_prim = s.id_prim')
			// quoted literals: these are boolean columns on PostgreSQL, ints elsewhere
			->andWhere("(sa.liked = '1' OR sa.boosted = '1' OR sa.replied = '1' OR sa.bookmarked = '1')");
		$qb->andWhere('NOT EXISTS (' . $act->getSQL() . ')');

		// a Like/Announce action row pointing at the status
		$action = $this->connection->getQueryBuilder();
		$action->select($action->createFunction('1'))
			->from(CoreRequestBuilder::TABLE_ACTIONS, 'a')
			->where('a.object_id_prim = s.id_prim');
		$qb->andWhere('NOT EXISTS (' . $action->getSQL() . ')');

		// its author is followed by a local user
		$follow = $this->connection->getQueryBuilder();
		$follow->select($follow->createFunction('1'))
			->from(CoreRequestBuilder::TABLE_FOLLOWS, 'f')
			->where('f.object_id_prim = s.attributed_to_prim')
			->andWhere("f.accepted = '1'");
		$qb->andWhere('NOT EXISTS (' . $follow->getSQL() . ')');

		// a local status replies to it, or a local stream (a boost) references it
		$local = $this->connection->getQueryBuilder();
		$local->select($local->createFunction('1'))
			->from(CoreRequestBuilder::TABLE_STREAM, 's2')
			->where("s2.local = '1'")
			->andWhere('(s2.in_reply_to_prim = s.id_prim OR s2.object_id_prim = s.id_prim)');
		$qb->andWhere('NOT EXISTS (' . $local->getSQL() . ')');

		return $qb;
	}

	/**
	 * Queue rows nothing will ever act on again: the deliveries and the cache
	 * items that have exhausted their retries, and the items an older version
	 * of the app left behind marked as done. Both drains skip all of them, so
	 * without this they stay in the table for good.
	 *
	 * @return array{requests: int, items: int}
	 */
	public function pruneQueues(): array {
		$pruned = ['requests' => 0, 'items' => 0];
		try {
			// the outbound queue keeps its finished rows for a while so a post can
			// say where it got to; pruning marks the exhausted ones and applies
			// that same retention, nothing shorter
			$this->requestQueueRequest->abandonExhausted();
			$pruned['requests'] = $this->requestQueueRequest->deleteFinished(time() - RequestQueueService::RETENTION_SECONDS);
			$pruned['items'] = $this->streamQueueRequest->deleteExhausted()
				+ $this->streamQueueRequest->deleteCompleted();
		} catch (\Exception $e) {
			$this->logger->warning('could not prune the queues', ['exception' => $e]);
		}

		return $pruned;
	}
}
