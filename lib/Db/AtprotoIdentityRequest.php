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
	private const FIELDS = ['id', 'actor_id', 'did', 'handle', 'signing_key', 'signing_public', 'recovery_public', 'state', 'moved_from_pds', 'creation'];

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
		$qb->select(...self::FIELDS)->from(self::TABLE_ATPROTO_IDENTITY)
			->where($qb->expr()->eq('handle', $qb->createNamedParameter(strtolower($handle))));

		return $this->one($qb);
	}

	/** whether the handle is already somebody's, however it is cased */
	public function handleExists(string $handle): bool {
		$qb = $this->getQueryBuilder();
		$qb->select('id')->from(self::TABLE_ATPROTO_IDENTITY)
			->where($qb->expr()->eq('handle', $qb->createNamedParameter(strtolower($handle))))
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
		return new Identity(
			(int)$row['id'],
			(string)$row['actor_id'],
			(string)$row['did'],
			(string)$row['handle'],
			(string)$row['signing_key'],
			(string)$row['signing_public'],
			(string)$row['recovery_public'],
			(string)$row['state'],
			(string)$row['moved_from_pds'],
			self::time($row['creation']),
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
