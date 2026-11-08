<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Stream;
use OCP\DB\QueryBuilder\IQueryBuilder;
use Throwable;

/** The bounded cursor of status deletions for connected Mastodon clients. */
class StatusDeletionEventsRequest extends CoreRequestBuilder {
	private const RETAIN = 10000;
	/** The log is trimmed on every this-many-th insert, so it holds at most `RETAIN + TRIM_EVERY` rows. */
	public const TRIM_EVERY = 100;

	/**
	 * The id at and below which events go after inserting this one, or 0 for
	 * none: only every `TRIM_EVERY`-th insert trims, and only once more than
	 * `RETAIN` events were ever recorded.
	 */
	public static function trimBelow(int $insertedId): int {
		if ($insertedId <= self::RETAIN || $insertedId % self::TRIM_EVERY !== 0) {
			return 0;
		}

		return $insertedId - self::RETAIN;
	}

	/** The newest event id, used to avoid replaying deletions to a new connection. */
	public function newestId(): int {
		$qb = $this->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COALESCE(MAX(id), 0)'), 'max_id')
			->from(self::TABLE_STATUS_DELETIONS);
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return (int)($row['max_id'] ?? 0);
	}

	/**
	 * @return list<array{id: int, status_nid: string, hashtags: list<string>, local: bool, media: bool, visibility: string, recipients: list<string>, audience_prim: ?string}>
	 */
	public function getAfterId(int $afterId, int $limit): array {
		$qb = $this->getQueryBuilder();
		$qb->select('id', 'status_nid', 'hashtags', 'local', 'media', 'visibility', 'recipients', 'audience_prim')
			->from(self::TABLE_STATUS_DELETIONS)
			->where($qb->expr()->gt('id', $qb->createNamedParameter($afterId)))
			->orderBy('id', 'asc')
			->setMaxResults($limit);

		$rows = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$hashtags = json_decode((string)$row['hashtags'], true);
			$recipients = json_decode((string)$row['recipients'], true);
			$rows[] = [
				'id' => (int)$row['id'],
				'status_nid' => (string)$row['status_nid'],
				'hashtags' => is_array($hashtags) ? array_values(array_filter($hashtags, 'is_string')) : [],
				'local' => (bool)$row['local'],
				'media' => (bool)$row['media'],
				'visibility' => (string)$row['visibility'],
				'recipients' => is_array($recipients) ? array_values(array_filter($recipients, 'is_string')) : [],
				'audience_prim' => is_string($row['audience_prim']) ? $row['audience_prim'] : null,
			];
		}
		$cursor->closeCursor();

		return $rows;
	}

	/** @param list<string> $recipientPrims */
	public function record(Stream $status, array $recipientPrims = []): void {
		if (!in_array($status->getType(), [Note::TYPE, Question::TYPE], true)
			|| !in_array($status->getVisibility(), [Stream::TYPE_PUBLIC, Stream::TYPE_UNLISTED, Stream::TYPE_FOLLOWERS, Stream::TYPE_DIRECT], true)) {
			return;
		}
		$visibility = $status->getVisibility();
		$recipients = $visibility === Stream::TYPE_PUBLIC ? [] : array_values(array_unique($recipientPrims));
		if ($visibility !== Stream::TYPE_PUBLIC && $recipients === []) {
			return;
		}

		try {
			$audiencePrim = in_array($visibility, [Stream::TYPE_UNLISTED, Stream::TYPE_FOLLOWERS], true)
				? $this->followersCollectionPrim($status->getAttributedTo())
				: null;
			if (in_array($visibility, [Stream::TYPE_UNLISTED, Stream::TYPE_FOLLOWERS], true) && $audiencePrim === null) {
				return;
			}
			$hashtags = $visibility === Stream::TYPE_PUBLIC ? array_values(array_filter($status->getHashtags(), 'is_string')) : [];
			$qb = $this->getQueryBuilder();
			$qb->insert(self::TABLE_STATUS_DELETIONS)
				->setValue('status_nid', $qb->createNamedParameter((string)$status->getNid()))
				->setValue('hashtags', $qb->createNamedParameter((string)json_encode($hashtags, JSON_UNESCAPED_SLASHES)))
				->setValue('media', $qb->createNamedParameter($status->getAttachments() !== [] ? 1 : 0, IQueryBuilder::PARAM_INT))
				->setValue('local', $qb->createNamedParameter($status->isLocal() ? 1 : 0, IQueryBuilder::PARAM_INT))
				->setValue('visibility', $qb->createNamedParameter($visibility))
				->setValue('recipients', $qb->createNamedParameter((string)json_encode($recipients, JSON_UNESCAPED_SLASHES)))
				->setValue('audience_prim', $qb->createNamedParameter($audiencePrim))
				->executeStatement();

			$id = $qb->getLastInsertId();
			$below = self::trimBelow($id);
			if ($below > 0) {
				$trim = $this->getQueryBuilder();
				$trim->delete(self::TABLE_STATUS_DELETIONS)
					->where($trim->expr()->lte('id', $trim->createNamedParameter($below, IQueryBuilder::PARAM_INT)))
					->executeStatement();
			}
		} catch (Throwable $e) {
			$this->logger->warning('could not record a Mastodon streaming deletion event', [
				'status' => $status->getId(),
				'exception' => $e,
			]);
		}
	}

	private function followersCollectionPrim(string $actorId): ?string {
		$qb = $this->getQueryBuilder();
		$qb->select('followers')
			->from(self::TABLE_CACHE_ACTORS)
			->where($qb->expr()->eq('id_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->setMaxResults(1);

		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();
		$followers = (string)($row['followers'] ?? '');

		return $followers === '' ? null : $qb->prim($followers);
	}
}
