<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use InvalidArgumentException;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\ConflictResolutionMode;
use OCP\DB\QueryBuilder\IQueryBuilder;

/** Owner-scoped, bounded storage for behavioural aggregates and HMAC exposure keys. */
class RecommendationSignalRequest extends CoreRequestBuilder {
	public const TABLE_SIGNAL = 'social_rec_signal';
	public const TABLE_EVENT = 'social_rec_event';
	public const TABLE_SEEN = 'social_rec_seen';
	private const MAX_TOPICS = 300;
	private const MAX_AUTHORS = 400;
	private const MAX_FORMATS = 20;
	private const MAX_SEEN = 8000;
	private const MAX_EVENTS = 10000;
	private const SIGNAL_RETENTION_SECONDS = 180 * 86400;
	private const MAX_UNIQUE_CONFLICT_RETRIES = 2;
	private const EVENT_TTL = 7 * 86400;
	private const SEEN_TTL = 30 * 86400;

	/** @return array{topics: array<string, array{fast: float, slow: float, updated_at: int}>, authors: array<string, array{fast: float, slow: float, updated_at: int}>, formats: array<string, array{fast: float, slow: float, updated_at: int}>} */
	public function profile(string $actorKey, int $limit = 720): array {
		$profile = ['topics' => [], 'authors' => [], 'formats' => []];
		$qb = $this->getQueryBuilder();
		$qb->select('dimension', 'entity_key', 'fast_score', 'slow_score', 'updated_at')->from(self::TABLE_SIGNAL)
			->where($qb->expr()->eq('actor_key', $qb->createNamedParameter($actorKey)))
			->orderBy('evidence_count', 'DESC')->setMaxResults(max(1, min(720, $limit)));
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$dimension = (string)$row['dimension'];
			if (!isset($profile[$dimension])) {
				continue;
			}
			$profile[$dimension][(string)$row['entity_key']] = [
				'fast' => (float)$row['fast_score'],
				'slow' => (float)$row['slow_score'],
				'updated_at' => (int)$row['updated_at'],
			];
		}
		$result->closeCursor();

		return $profile;
	}

	/**
	 * The current generation's qualified impressions for a bounded candidate set.
	 * Results retain session grouping so fuzzy story bands must match within a
	 * single exposure session, without returning raw post identifiers or text.
	 *
	 * @param list<string> $postKeys owner-scoped HMAC story, headline, topic or exact-post keys
	 * @return array<string, array<string, array{impressions: int, updated_at: int}>> keyed by fingerprint, then session key
	 */
	public function exposures(string $actorKey, int $generation, array $postKeys, int $since): array {
		$postKeys = array_values(array_unique(array_filter($postKeys, static fn (string $key): bool => preg_match('/^[a-f0-9]{64}$/', $key) === 1)));
		if ($postKeys === []) {
			return [];
		}

		$exposures = [];
		foreach (array_chunk($postKeys, 200) as $chunk) {
			$qb = $this->getQueryBuilder();
			$qb->select('post_key', 'session_key', 'impressions', 'updated_at')
				->from(self::TABLE_SEEN)
				->where($qb->expr()->eq('actor_key', $qb->createNamedParameter($actorKey)))
				->andWhere($qb->expr()->eq('generation', $qb->createNamedParameter($generation, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->gte('updated_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->in('post_key', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)));
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$key = (string)$row['post_key'];
				$session = (string)$row['session_key'];
				$exposures[$key][$session] ??= ['impressions' => 0, 'updated_at' => 0];
				$exposures[$key][$session]['impressions'] = min(32767, $exposures[$key][$session]['impressions'] + (int)$row['impressions']);
				$exposures[$key][$session]['updated_at'] = max($exposures[$key][$session]['updated_at'], (int)$row['updated_at']);
			}
			$result->closeCursor();
		}

		return $exposures;
	}

	/**
	 * Records one event and its bounded profile updates atomically with respect
	 * to consent, reset generation and duplicate event delivery.
	 *
	 * @param list<array{dimension: string, entity_key: string, delta: float}> $signals
	 * @param array{post_keys: list<string>, session_key: string}|null $impression owner-scoped HMAC story, headline, topic or exact-post keys
	 * @return array{accepted: bool, duplicate: bool, profile_version: int}
	 */
	public function record(
		string $actorKey,
		string $eventId,
		int $generation,
		string $kind,
		array $signals,
		?array $impression,
		int $now,
	): array {
		return $this->recordMany($actorKey, $generation, [[
			'event_id' => $eventId, 'kind' => $kind, 'signals' => $signals, 'impression' => $impression,
		]], $now)[0];
	}

	/**
	 * Records a request's events in one transaction: consent, the reset
	 * generation and the global epoch are checked once for the batch, and the
	 * epoch row is locked once, just before the commit. An event already
	 * recorded, or named twice in the batch, is a duplicate and changes
	 * nothing. When the checks fail no event of the batch is accepted.
	 *
	 * @param list<array{event_id: string, kind: string, signals: list<array{dimension: string, entity_key: string, delta: float}>, impression: array{post_keys: list<string>, session_key: string}|null}> $events
	 * @return list<array{accepted: bool, duplicate: bool, profile_version: int}> one per event, in order
	 */
	public function recordMany(string $actorKey, int $generation, array $events, int $now): array {
		if ($events === []) {
			return [];
		}

		return $this->recordAttempt($actorKey, $generation, $events, $now, 0);
	}

	/**
	 * @param list<array{event_id: string, kind: string, signals: list<array{dimension: string, entity_key: string, delta: float}>, impression: array{post_keys: list<string>, session_key: string}|null}> $events
	 * @return list<array{accepted: bool, duplicate: bool, profile_version: int}>
	 */
	private function recordAttempt(string $actorKey, int $generation, array $events, int $now, int $attempt): array {
		/** @var array<int, bool> $duplicates event index => whether it was a duplicate */
		$duplicates = [];
		$connection = $this->getConnection();
		$connection->beginTransaction();
		try {
			$fresh = [];
			foreach ($events as $index => $event) {
				$duplicates[$index] = isset($fresh[$event['event_id']]) || $this->event($actorKey, $event['event_id']) !== null;
				$fresh[$event['event_id']] = true;
			}
			if (!in_array(false, $duplicates, true)) {
				$connection->commit();
				return $this->results($actorKey, $duplicates, true);
			}

			// Read the epoch in this transaction to validate the generation the
			// client submitted. The exclusive lock is taken again just before
			// commit, after the per-user aggregates have been written, so events
			// for different readers can do their expensive work concurrently.
			$epochQuery = $this->getQueryBuilder();
			$epochQuery->select('epoch')->from(RecommendationPreferenceRequest::TABLE_EPOCH)
				->where($epochQuery->expr()->eq('id', $epochQuery->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
				->setMaxResults(1);
			$epochResult = $epochQuery->executeQuery();
			$epochRow = $epochResult->fetch();
			$epochResult->closeCursor();
			if ($epochRow === false) {
				$connection->rollBack();
				return $this->results($actorKey, $duplicates, false);
			}

			$profileState = $this->getQueryBuilder();
			$profileState->select('generation', 'learning_mode')->from(RecommendationPreferenceRequest::TABLE_PROFILE)
				->where($profileState->expr()->eq('actor_key', $profileState->createNamedParameter($actorKey)));
			if ($this->supportsRowLevelLocks()) {
				$profileState->forUpdate(ConflictResolutionMode::Ordinary);
			}
			$profileResult = $profileState->executeQuery();
			$profileRow = $profileResult->fetch();
			$profileResult->closeCursor();
			if ($profileRow === false) {
				$connection->rollBack();
				return $this->results($actorKey, $duplicates, false);
			}
			$localGeneration = (int)$profileRow['generation'];
			$effectiveGeneration = RecommendationPreferenceRequest::effectiveGeneration((int)$epochRow['epoch'], $localGeneration);
			if ($effectiveGeneration !== $generation || (string)$profileRow['learning_mode'] !== 'on') {
				$connection->rollBack();
				return $this->results($actorKey, $duplicates, false);
			}

			$profile = $this->getQueryBuilder();
			$profile->update(RecommendationPreferenceRequest::TABLE_PROFILE)
				// Keep the consent/CAS revision stable while observations arrive.
				// Timeline snapshots keep their order across newly learned signals;
				// only a consent change or reset invalidates their cursor.
				->set('updated_at', $profile->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->where($profile->expr()->eq('actor_key', $profile->createNamedParameter($actorKey)))
				->andWhere($profile->expr()->eq('generation', $profile->createNamedParameter($localGeneration, IQueryBuilder::PARAM_INT)))
				->andWhere($profile->expr()->eq('learning_mode', $profile->createNamedParameter('on')));
			if ($profile->executeStatement() !== 1) {
				$connection->rollBack();
				return $this->results($actorKey, $duplicates, false);
			}

			foreach ($events as $index => $entry) {
				if ($duplicates[$index]) {
					continue;
				}
				$event = $this->getQueryBuilder();
				$event->insert(self::TABLE_EVENT)
					->setValue('actor_key', $event->createNamedParameter($actorKey))
					->setValue('event_id', $event->createNamedParameter($entry['event_id']))
					->setValue('generation', $event->createNamedParameter($generation, IQueryBuilder::PARAM_INT))
					->setValue('kind', $event->createNamedParameter($entry['kind']))
					->setValue('created_at', $event->createNamedParameter($now, IQueryBuilder::PARAM_INT))
					->executeStatement();

				foreach ($entry['signals'] as $signal) {
					$this->fold($actorKey, $signal['dimension'], $signal['entity_key'], $signal['delta'], $now);
				}
				$impression = $entry['impression'];
				if ($impression !== null) {
					foreach (array_values(array_unique($impression['post_keys'])) as $postKey) {
						if (preg_match('/^[a-f0-9]{64}$/', $postKey) === 1) {
							$this->recordImpression($actorKey, $postKey, $impression['session_key'], $generation, $now);
						}
					}
				}
			}

			// Linearize an administrator's global switch against signal commits.
			// Holding this lock only across the final comparison and commit avoids
			// serializing profile lookups, deduplication, and aggregate folding for
			// all users on the one global epoch row.
			$commitEpochQuery = $this->getQueryBuilder();
			$commitEpochQuery->select('epoch')->from(RecommendationPreferenceRequest::TABLE_EPOCH)
				->where($commitEpochQuery->expr()->eq('id', $commitEpochQuery->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
			if ($this->supportsRowLevelLocks()) {
				$commitEpochQuery->forUpdate(ConflictResolutionMode::Ordinary);
			}
			$commitEpochResult = $commitEpochQuery->executeQuery();
			$commitEpochRow = $commitEpochResult->fetch();
			$commitEpochResult->closeCursor();
			if ($commitEpochRow === false || (int)$commitEpochRow['epoch'] !== (int)$epochRow['epoch']) {
				$connection->rollBack();
				return $this->results($actorKey, $duplicates, false);
			}
			$connection->commit();
		} catch (DBException $e) {
			$connection->rollBack();
			if ($e->getReason() === DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION && $attempt < self::MAX_UNIQUE_CONFLICT_RETRIES) {
				// a concurrent request stored one of these events or aggregates;
				// the next attempt reads it as a duplicate or folds onto it
				return $this->recordAttempt($actorKey, $generation, $events, $now, $attempt + 1);
			}
			throw $e;
		} catch (\Throwable $e) {
			$connection->rollBack();
			throw $e;
		}

		return $this->results($actorKey, $duplicates, true);
	}

	/**
	 * @param array<int, bool> $duplicates
	 * @return list<array{accepted: bool, duplicate: bool, profile_version: int}>
	 */
	private function results(string $actorKey, array $duplicates, bool $accepted): array {
		$version = $this->profileVersion($actorKey);
		$results = [];
		foreach ($duplicates as $duplicate) {
			$results[] = $duplicate
				? ['accepted' => true, 'duplicate' => true, 'profile_version' => $version]
				: ['accepted' => $accepted, 'duplicate' => false, 'profile_version' => $version];
		}

		return $results;
	}

	/** Remove learned aggregates and exposures while preserving the consent profile. */
	public function reset(string $actorKey): void {
		foreach ([self::TABLE_SIGNAL, self::TABLE_EVENT, self::TABLE_SEEN] as $table) {
			$qb = $this->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq('actor_key', $qb->createNamedParameter($actorKey)))->executeStatement();
		}
	}

	private function event(string $actorKey, string $eventId): ?array {
		$qb = $this->getQueryBuilder();
		$qb->select('generation')->from(self::TABLE_EVENT)
			->where($qb->expr()->eq('actor_key', $qb->createNamedParameter($actorKey)))
			->andWhere($qb->expr()->eq('event_id', $qb->createNamedParameter($eventId)))->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : ['generation' => (int)$row['generation']];
	}

	/** SQLite serializes writers at the database level and does not support FOR UPDATE. */
	private function supportsRowLevelLocks(): bool {
		$platform = strtolower(get_class($this->getConnection()->getDatabasePlatform()));

		return !str_contains($platform, 'sqlite');
	}

	private function fold(string $actorKey, string $dimension, string $entityKey, float $delta, int $now): void {
		if (!in_array($dimension, ['topics', 'authors', 'formats'], true) || $entityKey === '' || strlen($entityKey) > 255 || !is_finite($delta)) {
			throw new InvalidArgumentException('Invalid recommendation signal');
		}
		$qb = $this->getQueryBuilder();
		$qb->select('fast_score', 'slow_score', 'updated_at', 'evidence_count')->from(self::TABLE_SIGNAL)
			->where($qb->expr()->eq('actor_key', $qb->createNamedParameter($actorKey)))
			->andWhere($qb->expr()->eq('dimension', $qb->createNamedParameter($dimension)))
			->andWhere($qb->expr()->eq('entity_key', $qb->createNamedParameter($entityKey)))->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false && $this->dimensionCount($actorKey, $dimension) >= self::limitFor($dimension)
			&& !$this->evictStaleSignal($actorKey, $dimension, $now)) {
			return;
		}

		$elapsed = $row === false ? 0 : max(0, $now - (int)$row['updated_at']);
		$fastDecay = 2.0 ** (-((float)$elapsed) / (36.0 * 3600.0));
		$slowDecay = 2.0 ** (-((float)$elapsed) / (45.0 * 86400.0));
		$fast = max(-3.0, min(3.0, ($row === false ? 0.0 : (float)$row['fast_score'] * $fastDecay) + 0.75 * max(-1.0, min(1.0, $delta))));
		$slow = max(-3.0, min(3.0, ($row === false ? 0.0 : (float)$row['slow_score'] * $slowDecay) + 0.25 * max(-1.0, min(1.0, $delta))));
		$evidence = min(1000000, ($row === false ? 0 : (int)$row['evidence_count']) + 1);
		$qb = $this->getQueryBuilder();
		if ($row === false) {
			$qb->insert(self::TABLE_SIGNAL)
				->setValue('actor_key', $qb->createNamedParameter($actorKey))
				->setValue('dimension', $qb->createNamedParameter($dimension))
				->setValue('entity_key', $qb->createNamedParameter($entityKey))
				->setValue('fast_score', $qb->createNamedParameter($fast))
				->setValue('slow_score', $qb->createNamedParameter($slow))
				->setValue('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->setValue('evidence_count', $qb->createNamedParameter($evidence, IQueryBuilder::PARAM_INT))
				->executeStatement();
			return;
		}
		$qb->update(self::TABLE_SIGNAL)
			->set('fast_score', $qb->createNamedParameter($fast))
			->set('slow_score', $qb->createNamedParameter($slow))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->set('evidence_count', $qb->createNamedParameter($evidence, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('actor_key', $qb->createNamedParameter($actorKey)))
			->andWhere($qb->expr()->eq('dimension', $qb->createNamedParameter($dimension)))
			->andWhere($qb->expr()->eq('entity_key', $qb->createNamedParameter($entityKey)))
			->executeStatement();
	}

	private function recordImpression(string $actorKey, string $postKey, string $sessionKey, int $generation, int $now): void {
		$qb = $this->getQueryBuilder();
		$qb->select('generation', 'impressions')->from(self::TABLE_SEEN)
			->where($qb->expr()->eq('actor_key', $qb->createNamedParameter($actorKey)))
			->andWhere($qb->expr()->eq('post_key', $qb->createNamedParameter($postKey)))
			->andWhere($qb->expr()->eq('session_key', $qb->createNamedParameter($sessionKey)))->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false && $this->seenCount($actorKey) >= self::MAX_SEEN) {
			$this->deleteOldestSeen($actorKey);
		}

		$qb = $this->getQueryBuilder();
		if ($row === false) {
			$qb->insert(self::TABLE_SEEN)
				->setValue('actor_key', $qb->createNamedParameter($actorKey))
				->setValue('post_key', $qb->createNamedParameter($postKey))
				->setValue('session_key', $qb->createNamedParameter($sessionKey))
				->setValue('generation', $qb->createNamedParameter($generation, IQueryBuilder::PARAM_INT))
				->setValue('impressions', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
				->setValue('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->executeStatement();
			return;
		}
		$impressions = (int)$row['generation'] === $generation
			? min(32767, (int)$row['impressions'] + 1)
			: 1;
		$qb->update(self::TABLE_SEEN)
			->set('generation', $qb->createNamedParameter($generation, IQueryBuilder::PARAM_INT))
			->set('impressions', $qb->createNamedParameter($impressions, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('actor_key', $qb->createNamedParameter($actorKey)))
			->andWhere($qb->expr()->eq('post_key', $qb->createNamedParameter($postKey)))
			->andWhere($qb->expr()->eq('session_key', $qb->createNamedParameter($sessionKey)))
			->executeStatement();
	}

	/**
	 * Trims the event and exposure logs, for every reader at once (the
	 * `Cron\InterestHides` job): events older than their week, exposures
	 * older than their month, and of a reader with more than `MAX_EVENTS`
	 * events the oldest beyond that, each by a time threshold on an index.
	 *
	 * @return int rows deleted
	 */
	public function trim(int $now): int {
		$events = $this->getQueryBuilder();
		$events->delete(self::TABLE_EVENT)
			->where($events->expr()->lt('created_at', $events->createNamedParameter($now - self::EVENT_TTL, IQueryBuilder::PARAM_INT)));
		$deleted = $events->executeStatement();

		$seen = $this->getQueryBuilder();
		$seen->delete(self::TABLE_SEEN)
			->where($seen->expr()->lt('updated_at', $seen->createNamedParameter($now - self::SEEN_TTL, IQueryBuilder::PARAM_INT)));
		$deleted += $seen->executeStatement();

		$crowded = $this->getQueryBuilder();
		$crowded->select('actor_key')->from(self::TABLE_EVENT)
			->groupBy('actor_key')
			->having($crowded->expr()->gt($crowded->func()->count('*'), $crowded->createNamedParameter(self::MAX_EVENTS, IQueryBuilder::PARAM_INT)));
		$result = $crowded->executeQuery();
		$actors = [];
		while ($row = $result->fetch()) {
			$actors[] = (string)$row['actor_key'];
		}
		$result->closeCursor();

		foreach ($actors as $actorKey) {
			$oldestKept = $this->getQueryBuilder();
			$oldestKept->select('created_at')->from(self::TABLE_EVENT)
				->where($oldestKept->expr()->eq('actor_key', $oldestKept->createNamedParameter($actorKey)))
				->orderBy('created_at', 'DESC')
				->setFirstResult(self::MAX_EVENTS - 1)
				->setMaxResults(1);
			$cursor = $oldestKept->executeQuery();
			$row = $cursor->fetch();
			$cursor->closeCursor();
			if ($row === false) {
				continue;
			}
			$delete = $this->getQueryBuilder();
			$delete->delete(self::TABLE_EVENT)
				->where($delete->expr()->eq('actor_key', $delete->createNamedParameter($actorKey)))
				->andWhere($delete->expr()->lt('created_at', $delete->createNamedParameter((int)$row['created_at'], IQueryBuilder::PARAM_INT)));
			$deleted += $delete->executeStatement();
		}

		return $deleted;
	}

	private function dimensionCount(string $actorKey, string $dimension): int {
		$qb = $this->getQueryBuilder();
		$qb->selectAlias($qb->func()->count('*'), 'count')->from(self::TABLE_SIGNAL)
			->where($qb->expr()->eq('actor_key', $qb->createNamedParameter($actorKey)))
			->andWhere($qb->expr()->eq('dimension', $qb->createNamedParameter($dimension)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['count'] ?? 0);
	}

	private function evictStaleSignal(string $actorKey, string $dimension, int $now): bool {
		$cutoff = $now - self::SIGNAL_RETENTION_SECONDS;
		$lookup = $this->getQueryBuilder();
		$lookup->select('entity_key')->from(self::TABLE_SIGNAL)
			->where($lookup->expr()->eq('actor_key', $lookup->createNamedParameter($actorKey)))
			->andWhere($lookup->expr()->eq('dimension', $lookup->createNamedParameter($dimension)))
			->andWhere($lookup->expr()->lt('updated_at', $lookup->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)))
			->orderBy('updated_at', 'ASC')->setMaxResults(1);
		$result = $lookup->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false) {
			return false;
		}

		$delete = $this->getQueryBuilder();
		$delete->delete(self::TABLE_SIGNAL)
			->where($delete->expr()->eq('actor_key', $delete->createNamedParameter($actorKey)))
			->andWhere($delete->expr()->eq('dimension', $delete->createNamedParameter($dimension)))
			->andWhere($delete->expr()->eq('entity_key', $delete->createNamedParameter((string)$row['entity_key'])))
			->andWhere($delete->expr()->lt('updated_at', $delete->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)));

		return $delete->executeStatement() === 1;
	}

	private function seenCount(string $actorKey): int {
		$qb = $this->getQueryBuilder();
		$qb->selectAlias($qb->func()->count('*'), 'count')->from(self::TABLE_SEEN)
			->where($qb->expr()->eq('actor_key', $qb->createNamedParameter($actorKey)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['count'] ?? 0);
	}

	private function deleteOldestSeen(string $actorKey): void {
		$qb = $this->getQueryBuilder();
		$qb->select('post_key', 'session_key')->from(self::TABLE_SEEN)
			->where($qb->expr()->eq('actor_key', $qb->createNamedParameter($actorKey)))
			->orderBy('updated_at', 'ASC')->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false) {
			return;
		}
		$delete = $this->getQueryBuilder();
		$delete->delete(self::TABLE_SEEN)
			->where($delete->expr()->eq('actor_key', $delete->createNamedParameter($actorKey)))
			->andWhere($delete->expr()->eq('post_key', $delete->createNamedParameter((string)$row['post_key'])))
			->andWhere($delete->expr()->eq('session_key', $delete->createNamedParameter((string)$row['session_key'])))
			->executeStatement();
	}

	private function profileVersion(string $actorKey): int {
		$qb = $this->getQueryBuilder();
		$qb->select('profile_ver')->from(RecommendationPreferenceRequest::TABLE_PROFILE)
			->where($qb->expr()->eq('actor_key', $qb->createNamedParameter($actorKey)))->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? 0 : (int)$row['profile_ver'];
	}

	private static function limitFor(string $dimension): int {
		return match ($dimension) {
			'topics' => self::MAX_TOPICS,
			'authors' => self::MAX_AUTHORS,
			'formats' => self::MAX_FORMATS,
			default => 0,
		};
	}
}
