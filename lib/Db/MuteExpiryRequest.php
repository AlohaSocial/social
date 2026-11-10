<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use DateTimeZone;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * When a mute runs out.
 *
 * One row per (muter, muted), and only for a mute that was given a duration: a
 * permanent mute — the common case — stores nothing here at all. The mute
 * itself is a row in `social_actor_relation`; this says when to stop reading
 * it, and nothing ever deletes a row here to make that happen.
 */
class MuteExpiryRequest extends MuteExpiryRequestBuilder {
	/**
	 * @param int $expiresAt unix time; the caller clears the expiry instead of
	 *                       passing 0, so that "no expiry" cannot be written as
	 *                       "expired in 1970"
	 */
	public function save(string $actorId, string $objectId, int $expiresAt): void {
		$qb = $this->getMuteExpiryInsertSql();
		$qb->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId)))
			->setValue('object_id_prim', $qb->createNamedParameter($qb->prim($objectId)))
			->setValue(
				'expires_at',
				$qb->createNamedParameter($this->dateTime($expiresAt), IQueryBuilder::PARAM_DATE)
			)
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		if (!$this->insertUnlessDuplicate($qb)) {
			// re-muting with a different duration moves the expiry; the row is
			// the mute's, not the mute attempt's
			$update = $this->getMuteExpiryUpdateSql();
			$update->set(
				'expires_at',
				$update->createNamedParameter($this->dateTime($expiresAt), IQueryBuilder::PARAM_DATE)
			);
			$update->where(
				$update->expr()->eq('actor_id_prim', $update->createNamedParameter($update->prim($actorId))),
				$update->expr()->eq('object_id_prim', $update->createNamedParameter($update->prim($objectId)))
			);
			$update->executeStatement();
		}
	}

	/** Clearing an expiry that is not there is not an error: the mute is permanent either way. */
	public function delete(string $actorId, string $objectId): void {
		$qb = $this->getMuteExpiryDeleteSql();
		$qb->where(
			$qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))),
			$qb->expr()->eq('object_id_prim', $qb->createNamedParameter($qb->prim($objectId)))
		);

		$qb->executeStatement();
	}

	/** Unix time, or 0 for a mute that does not expire. */
	public function getExpiry(string $actorId, string $objectId): int {
		$qb = $this->getMuteExpirySelectSql();
		$qb->andWhere($qb->expr()->eq('mx.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere($qb->expr()->eq('mx.object_id_prim', $qb->createNamedParameter($qb->prim($objectId))));
		$qb->setMaxResults(1);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			return 0;
		}

		return $this->timestamp($this->get('expires_at', $data));
	}

	/**
	 * The expiries of a set of muted accounts, in one query rather than one per
	 * account: the mutes listing asks about a whole page at once.
	 *
	 * @param string[] $objectIds
	 *
	 * @return array<string, int> actor id => unix time; an account with no
	 *                            expiry is absent, not 0
	 */
	public function getExpiries(string $actorId, array $objectIds): array {
		if ($objectIds === []) {
			return [];
		}

		$qb = $this->getMuteExpirySelectSql();
		$prims = [];
		foreach ($objectIds as $objectId) {
			$prims[$qb->prim($objectId)] = $objectId;
		}

		$qb->andWhere($qb->expr()->eq('mx.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere(
				$qb->expr()->in(
					'mx.object_id_prim',
					$qb->createNamedParameter(array_keys($prims), IQueryBuilder::PARAM_STR_ARRAY)
				)
			);

		$expiries = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$objectId = $prims[$this->get('object_id_prim', $data)] ?? '';
			if ($objectId === '') {
				continue;
			}

			$expiries[$objectId] = $this->timestamp($this->get('expires_at', $data));
		}
		$cursor->closeCursor();

		return $expiries;
	}

	/**
	 * The expiries of the mutes this account holds, and only those: anybody
	 * else's timed mute *of* it belongs to them, and must survive a decision —
	 * a suspension — that can be lifted again.
	 */
	public function deleteByActor(string $actorId): void {
		$qb = $this->getMuteExpiryDeleteSql();
		$qb->where(
			$qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId)))
		);

		$qb->executeStatement();
	}

	/**
	 * Everything an account leaves behind here when it is deleted, in both
	 * directions: the expiries of its own mutes, and the expiry of anybody
	 * else's mute of it.
	 */
	public function deleteRelatedId(string $actorId): void {
		$qb = $this->getMuteExpiryDeleteSql();
		$prim = $qb->prim($actorId);
		$qb->where(
			$qb->expr()->orX(
				$qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($prim)),
				$qb->expr()->eq('object_id_prim', $qb->createNamedParameter($prim))
			)
		);

		$qb->executeStatement();
	}

	private function dateTime(int $timestamp): DateTime {
		return (new DateTime('@' . $timestamp))->setTimezone(new DateTimeZone(date_default_timezone_get()));
	}

	private function timestamp(string $stored): int {
		if ($stored === '') {
			return 0;
		}

		return (int)strtotime($stored);
	}
}
