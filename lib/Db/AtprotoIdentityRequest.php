<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\InstanceKey;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The AT Protocol identities of local actors, and the instance's own keys.
 */
class AtprotoIdentityRequest extends CoreRequestBuilder {
	private const FIELDS = ['id', 'actor_id', 'did', 'handle', 'signing_key', 'signing_public', 'recovery_public', 'state', 'moved_from_pds', 'creation', 'custom_handle', 'custom_handle_failures'];

	/**
	 * @param string $sealedSigningKey the private half, already sealed
	 * @return int the row id
	 */
	public function create(string $actorId, string $did, string $handle, string $sealedSigningKey, string $signingPublic, string $recoveryPublic): int {
		$qb = $this->getQueryBuilder();
		$now = new DateTime('now');
		$qb->insert(self::TABLE_ATPROTO_IDENTITY)
			->setValue('actor_id', $qb->createNamedParameter($actorId))
			->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId)))
			->setValue('did', $qb->createNamedParameter($did))
			->setValue('handle', $qb->createNamedParameter($handle))
			->setValue('signing_key', $qb->createNamedParameter($sealedSigningKey))
			->setValue('signing_public', $qb->createNamedParameter($signingPublic))
			->setValue('recovery_public', $qb->createNamedParameter($recoveryPublic))
			->setValue('state', $qb->createNamedParameter(Identity::STATE_ACTIVE))
			->setValue('moved_from_pds', $qb->createNamedParameter(''))
			->setValue('creation', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE))
			->setValue('updated', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE));
		$qb->executeStatement();

		return $qb->getLastInsertId();
	}

	/**
	 * @throws AtprotoIdentityNotFoundException
	 */
	public function getByActorId(string $actorId): Identity {
		$qb = $this->getQueryBuilder();
		$qb->select(...self::FIELDS)->from(self::TABLE_ATPROTO_IDENTITY)
			->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		return $this->one($qb);
	}

	/**
	 * @throws AtprotoIdentityNotFoundException
	 */
	public function getByDid(string $did): Identity {
		$qb = $this->getQueryBuilder();
		$qb->select(...self::FIELDS)->from(self::TABLE_ATPROTO_IDENTITY)
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));

		return $this->one($qb);
	}

	/**
	 * @throws AtprotoIdentityNotFoundException
	 */
	public function getByHandle(string $handle): Identity {
		$qb = $this->getQueryBuilder();
		$handle = $qb->createNamedParameter(strtolower($handle));
		$qb->select(...self::FIELDS)->from(self::TABLE_ATPROTO_IDENTITY)
			->where($qb->expr()->orX($qb->expr()->eq('handle', $handle), $qb->expr()->eq('custom_handle', $handle)))
			->setMaxResults(1);

		return $this->one($qb);
	}

	/** whether the handle is already somebody's, assigned or custom, however it is cased */
	public function handleExists(string $handle): bool {
		$qb = $this->getQueryBuilder();
		$handle = $qb->createNamedParameter(strtolower($handle));
		$qb->select('id')->from(self::TABLE_ATPROTO_IDENTITY)
			->where($qb->expr()->orX($qb->expr()->eq('handle', $handle), $qb->expr()->eq('custom_handle', $handle)))
			->setMaxResults(1);
		$cursor = $qb->executeQuery();
		$exists = $cursor->fetch() !== false;
		$cursor->closeCursor();

		return $exists;
	}

	/**
	 * @return Identity[] every identity, newest first, at most $limit
	 */
	public function getAll(int $limit = 1000, int $offset = 0): array {
		$qb = $this->getQueryBuilder();
		$qb->select(...self::FIELDS)->from(self::TABLE_ATPROTO_IDENTITY)
			->orderBy('id', 'asc')
			->setMaxResults($limit)
			->setFirstResult($offset);
		$identities = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$identities[] = $this->identity($row);
		}
		$cursor->closeCursor();

		return $identities;
	}

	public function count(): int {
		$qb = $this->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'n'))->from(self::TABLE_ATPROTO_IDENTITY);
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return (int)($row['n'] ?? 0);
	}

	public function countInState(string $state): int {
		$qb = $this->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'n'))->from(self::TABLE_ATPROTO_IDENTITY)
			->where($qb->expr()->eq('state', $qb->createNamedParameter($state)));
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return (int)($row['n'] ?? 0);
	}

	public function setState(string $did, string $state): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_ATPROTO_IDENTITY)
			->set('state', $qb->createNamedParameter($state))
			->set('updated', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		$qb->executeStatement();
	}

	public function setHandle(string $did, string $handle): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_ATPROTO_IDENTITY)
			->set('handle', $qb->createNamedParameter(strtolower($handle)))
			->set('updated', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		$qb->executeStatement();
	}

	/**
	 * Sets or, with '', clears an account's custom handle; it counts as
	 * checked now.
	 */
	public function setCustomHandle(string $did, string $handle): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_ATPROTO_IDENTITY)
			->set('custom_handle', $qb->createNamedParameter(strtolower($handle)))
			->set('custom_handle_checked', $qb->createNamedParameter($handle === '' ? null : new DateTime('now'), IQueryBuilder::PARAM_DATE))
			->set('custom_handle_failures', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->set('updated', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		$qb->executeStatement();
	}

	/**
	 * Records a check of a custom handle: a failure counts up, a success
	 * starts the count again.
	 */
	public function customHandleChecked(string $did, bool $ok): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_ATPROTO_IDENTITY)
			->set('custom_handle_checked', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE))
			->set('custom_handle_failures', $ok ? $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT) : $qb->createFunction('custom_handle_failures + 1'))
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		$qb->executeStatement();
	}

	/**
	 * @return Identity[] the active identities with a custom handle not checked since a time, oldest check first
	 */
	public function getCustomHandlesDue(int $before, int $limit): array {
		$qb = $this->getQueryBuilder();
		$qb->select(...self::FIELDS)->from(self::TABLE_ATPROTO_IDENTITY)
			->where($qb->expr()->neq('custom_handle', $qb->createNamedParameter('')))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter(Identity::STATE_ACTIVE)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('custom_handle_checked'),
				$qb->expr()->lt('custom_handle_checked', $qb->createNamedParameter(new DateTime('@' . $before), IQueryBuilder::PARAM_DATE)),
			))
			->orderBy('custom_handle_checked', 'asc')
			->setMaxResults($limit);
		$identities = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$identities[] = $this->identity($row);
		}
		$cursor->closeCursor();

		return $identities;
	}

	/**
	 * Gives an identity row the DID that moved here (§13.1), with the
	 * signing key made for it here: the actor keeps one row, its handle and
	 * its custom handle; the DID it had is retired by the caller.
	 */
	public function adoptDid(int $id, string $did, string $sealedSigningKey, string $signingPublic, string $movedFromPds): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_ATPROTO_IDENTITY)
			->set('did', $qb->createNamedParameter($did))
			->set('signing_key', $qb->createNamedParameter($sealedSigningKey))
			->set('signing_public', $qb->createNamedParameter($signingPublic))
			->set('recovery_public', $qb->createNamedParameter(''))
			->set('state', $qb->createNamedParameter(Identity::STATE_ACTIVE))
			->set('moved_from_pds', $qb->createNamedParameter($movedFromPds))
			->set('updated', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function setRecoveryPublic(string $did, string $recoveryPublic): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_ATPROTO_IDENTITY)
			->set('recovery_public', $qb->createNamedParameter($recoveryPublic))
			->set('updated', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		$qb->executeStatement();
	}

	public function setSigningKey(string $did, string $sealedSigningKey, string $signingPublic): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_ATPROTO_IDENTITY)
			->set('signing_key', $qb->createNamedParameter($sealedSigningKey))
			->set('signing_public', $qb->createNamedParameter($signingPublic))
			->set('updated', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		$qb->executeStatement();
	}

	public function deleteByDid(string $did): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_ATPROTO_IDENTITY)
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		$qb->executeStatement();
	}

	/**
	 * @return int the row id
	 */
	public function createInstanceKey(string $kind, string $sealedPrivateKey, string $publicDidKey): int {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_ATPROTO_INSTANCE_KEY)
			->setValue('kind', $qb->createNamedParameter($kind))
			->setValue('private_key', $qb->createNamedParameter($sealedPrivateKey))
			->setValue('public_key', $qb->createNamedParameter($publicDidKey))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));
		$qb->executeStatement();

		return $qb->getLastInsertId();
	}

	/**
	 * The keys of one kind, newest first; the first one that is not retired
	 * is the one in use.
	 *
	 * @return InstanceKey[]
	 */
	public function getInstanceKeys(string $kind): array {
		$qb = $this->getQueryBuilder();
		$qb->select('id', 'kind', 'private_key', 'public_key', 'creation', 'retired')
			->from(self::TABLE_ATPROTO_INSTANCE_KEY)
			->where($qb->expr()->eq('kind', $qb->createNamedParameter($kind)))
			->orderBy('id', 'desc');
		$keys = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$keys[] = new InstanceKey(
				(int)$row['id'],
				(string)$row['kind'],
				(string)$row['private_key'],
				(string)$row['public_key'],
				self::time($row['creation']),
				self::time($row['retired']),
			);
		}
		$cursor->closeCursor();

		return $keys;
	}

	public function retireInstanceKey(int $id): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_ATPROTO_INSTANCE_KEY)
			->set('retired', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function deleteInstanceKey(int $id): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_ATPROTO_INSTANCE_KEY)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * @throws AtprotoIdentityNotFoundException
	 */
	private function one(SocialQueryBuilder $qb): Identity {
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();
		if ($row === false) {
			throw new AtprotoIdentityNotFoundException();
		}

		return $this->identity($row);
	}

	private function identity(array $row): Identity {
		$custom = (string)($row['custom_handle'] ?? '');

		return new Identity(
			(int)$row['id'],
			(string)$row['actor_id'],
			(string)$row['did'],
			$custom !== '' ? $custom : (string)$row['handle'],
			(string)$row['signing_key'],
			(string)$row['signing_public'],
			(string)$row['recovery_public'],
			(string)$row['state'],
			(string)$row['moved_from_pds'],
			self::time($row['creation']),
			(string)$row['handle'],
			$custom,
			(int)($row['custom_handle_failures'] ?? 0),
		);
	}

	/** a DATETIME column as unix time, 0 when null */
	public static function time(mixed $value): int {
		if ($value === null || $value === '') {
			return 0;
		}
		$time = strtotime((string)$value);

		return $time === false ? 0 : $time;
	}
}
