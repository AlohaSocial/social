<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use InvalidArgumentException;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;

/** Owner-scoped activity-learning consent and reset generation storage. */
class RecommendationPreferenceRequest extends CoreRequestBuilder {
	public const TABLE_PROFILE = 'social_rec_profile';
	public const TABLE_PREFERENCE = 'social_rec_pref';

	/** @return array{revision: int, generation: int, learning_mode: string} */
	public function get(string $actorKey): array {
		$profile = $this->profile($actorKey);
		return [
			'revision' => $profile['profile_ver'],
			'generation' => $profile['generation'],
			'learning_mode' => $profile['learning_mode'],
		];
	}

	/** Update the consent state with profile-version compare-and-swap. */
	public function setLearningMode(string $actorKey, int $expectedRevision, string $mode): ?array {
		if (!in_array($mode, ['off', 'on', 'paused'], true)) {
			throw new InvalidArgumentException('Unknown learning mode');
		}
		$this->ensureProfile($actorKey);
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_PROFILE)
			->set('profile_ver', $qb->createNamedParameter($expectedRevision + 1, IQueryBuilder::PARAM_INT))
			// Invalidate browser buffers and queued deliveries on every consent
			// transition. If learning is paused and later resumed, an event from
			// the earlier consent period must not become valid again.
			->set('generation', $qb->func()->add('generation', $qb->expr()->literal(1)))
			->set('learning_mode', $qb->createNamedParameter($mode))
			->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('actor_key', $qb->createNamedParameter($actorKey)))
			->andWhere($qb->expr()->eq('profile_ver', $qb->createNamedParameter($expectedRevision, IQueryBuilder::PARAM_INT)));
		if ($qb->executeStatement() !== 1) {
			return null;
		}
		return $this->get($actorKey);
	}

	/** Invalidate every open client buffer when the instance learning switch changes. */
	public function invalidateAllLearningGenerations(): void {
		$qb = $this->getQueryBuilder();
		$one = $qb->expr()->literal(1);
		$qb->update(self::TABLE_PROFILE)
			->set('profile_ver', $qb->func()->add('profile_ver', $one))
			->set('generation', $qb->func()->add('generation', $one))
			->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->executeStatement();
	}

	/** @return array{revision: int, generation: int, learning_mode: string}|null */
	public function reset(string $actorKey, int $expectedRevision): ?array {
		$this->ensureProfile($actorKey);
		$connection = $this->getConnection();
		$connection->beginTransaction();
		try {
			$qb = $this->getQueryBuilder();
			$qb->update(self::TABLE_PROFILE)
				->set('profile_ver', $qb->createNamedParameter($expectedRevision + 1, IQueryBuilder::PARAM_INT))
				->set('generation', $qb->createNamedParameter($expectedRevision + 1, IQueryBuilder::PARAM_INT))
				->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('actor_key', $qb->createNamedParameter($actorKey)))
				->andWhere($qb->expr()->eq('profile_ver', $qb->createNamedParameter($expectedRevision, IQueryBuilder::PARAM_INT)));
			if ($qb->executeStatement() !== 1) {
				$connection->rollBack();
				$current = $this->get($actorKey);
				if ($current['revision'] === $expectedRevision + 1
					&& $current['generation'] === $expectedRevision + 1) {
					return $current;
				}
				return null;
			}
			$qb = $this->getQueryBuilder();
			$qb->delete(self::TABLE_PREFERENCE)->where($qb->expr()->eq('actor_key', $qb->createNamedParameter($actorKey)))->executeStatement();
			foreach ([RecommendationFeedbackRequest::TABLE, RecommendationSignalRequest::TABLE_SIGNAL, RecommendationSignalRequest::TABLE_EVENT, RecommendationSignalRequest::TABLE_SEEN] as $table) {
				$delete = $this->getQueryBuilder();
				$delete->delete($table)->where($delete->expr()->eq('actor_key', $delete->createNamedParameter($actorKey)))->executeStatement();
			}
			$connection->commit();
		} catch (\Throwable $e) {
			$connection->rollBack();
			throw $e;
		}

		return $this->get($actorKey);
	}

	/** @return array{revision: int, generation: int, learning_mode: string}|null */
	public function resetLearning(string $actorKey, int $expectedRevision): ?array {
		$this->ensureProfile($actorKey);
		$connection = $this->getConnection();
		$connection->beginTransaction();
		try {
			$qb = $this->getQueryBuilder();
			$qb->update(self::TABLE_PROFILE)
				->set('profile_ver', $qb->createNamedParameter($expectedRevision + 1, IQueryBuilder::PARAM_INT))
				->set('generation', $qb->func()->add('generation', $qb->expr()->literal(1)))
				->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('actor_key', $qb->createNamedParameter($actorKey)))
				->andWhere($qb->expr()->eq('profile_ver', $qb->createNamedParameter($expectedRevision, IQueryBuilder::PARAM_INT)));
			if ($qb->executeStatement() !== 1) {
				$connection->rollBack();
				return null;
			}
			foreach ([RecommendationSignalRequest::TABLE_SIGNAL, RecommendationSignalRequest::TABLE_EVENT, RecommendationSignalRequest::TABLE_SEEN] as $table) {
				$delete = $this->getQueryBuilder();
				$delete->delete($table)->where($delete->expr()->eq('actor_key', $delete->createNamedParameter($actorKey)))->executeStatement();
			}
			$connection->commit();
		} catch (\Throwable $e) {
			$connection->rollBack();
			throw $e;
		}
		return $this->get($actorKey);
	}

	/** Remove all state when its Nextcloud account is deleted. */
	public function purge(string $actorKey): void {
		$connection = $this->getConnection();
		$connection->beginTransaction();
		try {
			foreach ([RecommendationFeedbackRequest::TABLE, RecommendationSignalRequest::TABLE_SIGNAL, RecommendationSignalRequest::TABLE_EVENT, RecommendationSignalRequest::TABLE_SEEN, self::TABLE_PREFERENCE, self::TABLE_PROFILE] as $table) {
				$qb = $this->getQueryBuilder();
				$qb->delete($table)->where($qb->expr()->eq('actor_key', $qb->createNamedParameter($actorKey)))->executeStatement();
			}
			$connection->commit();
		} catch (\Throwable $e) {
			$connection->rollBack();
			throw $e;
		}
	}

	/** @return array{profile_ver: int, generation: int, learning_mode: string} */
	private function profile(string $actorKey): array {
		$this->ensureProfile($actorKey);
		$qb = $this->getQueryBuilder();
		$qb->select('profile_ver', 'generation', 'learning_mode')->from(self::TABLE_PROFILE)
			->where($qb->expr()->eq('actor_key', $qb->createNamedParameter($actorKey)))->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return [
			'profile_ver' => (int)$row['profile_ver'],
			'generation' => (int)$row['generation'],
			'learning_mode' => (string)$row['learning_mode'],
		];
	}

	private function ensureProfile(string $actorKey): void {
		$qb = $this->getQueryBuilder();
		$qb->select('actor_key')->from(self::TABLE_PROFILE)
			->where($qb->expr()->eq('actor_key', $qb->createNamedParameter($actorKey)))->setMaxResults(1);
		$result = $qb->executeQuery();
		$exists = $result->fetch() !== false;
		$result->closeCursor();
		if ($exists) {
			return;
		}

		$qb = $this->getQueryBuilder();
		try {
			$qb->insert(self::TABLE_PROFILE)
				->setValue('actor_key', $qb->createNamedParameter($actorKey))
				->setValue('profile_ver', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
				->setValue('generation', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
				->setValue('learning_mode', $qb->createNamedParameter('off'))
				->setValue('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
				->executeStatement();
		} catch (DBException $e) {
			if ($e->getReason() !== DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
		}
	}
}
