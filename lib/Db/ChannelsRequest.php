<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Model\Channel;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The channels an account publishes videos under.
 *
 * @package OCA\Social\Db
 */
class ChannelsRequest extends CoreRequestBuilder {
	public function create(Channel $channel): void {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_CHANNELS)
			->setValue('actor_id', $qb->createNamedParameter($channel->getActorId()))
			->setValue('actor_id_prim', $qb->createNamedParameter(md5($channel->getActorId())))
			->setValue('owner_id', $qb->createNamedParameter($channel->getOwnerId()))
			->setValue('owner_id_prim', $qb->createNamedParameter(md5($channel->getOwnerId())))
			->setValue('name', $qb->createNamedParameter($channel->getName()))
			->setValue('description', $qb->createNamedParameter($channel->getDescription()))
			->setValue('is_default', $qb->createNamedParameter($channel->isDefault(), IQueryBuilder::PARAM_BOOL))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		$qb->executeStatement();
		// the row's own id, which is how a client names the channel afterwards:
		// without it every channel answered as 0 and none could be renamed
		$channel->setId($qb->getLastInsertId());
	}

	public function update(Channel $channel): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_CHANNELS)
			->set('name', $qb->createNamedParameter($channel->getName()))
			->set('description', $qb->createNamedParameter($channel->getDescription()))
			->set('is_default', $qb->createNamedParameter($channel->isDefault(), IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter(md5($channel->getActorId()))));

		$qb->executeStatement();
	}

	/** @return Channel[] the channels of one account, oldest first */
	public function getByOwner(string $ownerId): array {
		$qb = $this->getQueryBuilder();
		$this->select($qb)
			->where($qb->expr()->eq('owner_id_prim', $qb->createNamedParameter(md5($ownerId))))
			->orderBy('id', 'asc');

		return $this->rows($qb);
	}

	public function getByActorId(string $actorId): ?Channel {
		$qb = $this->getQueryBuilder();
		$this->select($qb)
			->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter(md5($actorId))));

		return $this->rows($qb)[0] ?? null;
	}

	/**
	 * Every channel on this instance, oldest first. Capped, not paged: an
	 * instance with more channels than that wants a directory, not a list.
	 *
	 * @return Channel[]
	 */
	public function all(int $limit = 50): array {
		$qb = $this->getQueryBuilder();
		$this->select($qb)->orderBy('id', 'asc')->setMaxResults($limit);

		return $this->rows($qb);
	}

	/**
	 * Who owns a channel, or '' when the id names no channel of ours.
	 *
	 * The hottest of the reads — every serving of a channel actor asks it —
	 * and the reason it projects one column instead of a whole row.
	 */
	public function ownerOf(string $actorId): string {
		$qb = $this->getQueryBuilder();
		$qb->select('owner_id')
			->from(self::TABLE_CHANNELS)
			->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter(md5($actorId))))
			->setMaxResults(1);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return ($data === false) ? '' : (string)($data['owner_id'] ?? '');
	}

	/** Forgets a channel; the actor itself is deleted the way any actor is. */
	public function delete(string $actorId): bool {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_CHANNELS)
			->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter(md5($actorId))));

		return $qb->executeStatement() > 0;
	}

	/** Everything an account leaves behind here when its actor is deleted. */
	public function deleteRelatedId(string $actorId): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_CHANNELS)
			->where($qb->expr()->orX(
				$qb->expr()->eq('actor_id_prim', $qb->createNamedParameter(md5($actorId))),
				$qb->expr()->eq('owner_id_prim', $qb->createNamedParameter(md5($actorId)))
			));

		$qb->executeStatement();
	}

	private function select(IQueryBuilder $qb): IQueryBuilder {
		return $qb->select('id', 'actor_id', 'owner_id', 'name', 'description', 'is_default', 'creation')
			->from(self::TABLE_CHANNELS);
	}

	/**
	 * @return Channel[]
	 */
	private function rows(IQueryBuilder $qb): array {
		$channels = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$channel = new Channel();
			$channel->setId((int)$data['id'])
				->setActorId((string)($data['actor_id'] ?? ''))
				->setOwnerId((string)($data['owner_id'] ?? ''))
				->setName((string)($data['name'] ?? ''))
				->setDescription((string)($data['description'] ?? ''))
				->setDefault((bool)($data['is_default'] ?? false));
			$channels[] = $channel;
		}
		$cursor->closeCursor();

		return $channels;
	}
}
