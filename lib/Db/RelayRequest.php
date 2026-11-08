<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Model\Relay;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The relays this instance subscribes to.
 *
 * Three reads, all small: the panel's list, "is this actor one of ours" on
 * every activity a relay could have sent, and the accepted inboxes a public
 * post is delivered to.
 *
 * @package OCA\Social\Db
 */
class RelayRequest extends CoreRequestBuilder {
	/**
	 * Records a subscription, replacing whatever stood before.
	 *
	 * Subscribing twice is the same row — that is what the unique index is
	 * for, and why the violation is handled rather than avoided by reading
	 * first: between the read and the write is where the second administrator's
	 * click lands.
	 */
	public function save(Relay $relay): void {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_RELAYS)
			->setValue('actor_id', $qb->createNamedParameter($relay->getActorId()))
			->setValue('actor_id_prim', $qb->createNamedParameter(md5($relay->getActorId())))
			->setValue('inbox', $qb->createNamedParameter($relay->getInbox()))
			->setValue('status', $qb->createNamedParameter($relay->getStatus()))
			->setValue('follow_id', $qb->createNamedParameter($relay->getFollowId()))
			->setValue('error', $qb->createNamedParameter($relay->getError()))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE))
			->setValue('last_update', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		if ($this->insertUnlessDuplicate($qb)) {
			return;
		}

		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_RELAYS)
			->set('inbox', $qb->createNamedParameter($relay->getInbox()))
			->set('status', $qb->createNamedParameter($relay->getStatus()))
			->set('follow_id', $qb->createNamedParameter($relay->getFollowId()))
			->set('error', $qb->createNamedParameter($relay->getError()))
			->set('last_update', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter(md5($relay->getActorId()))));

		$qb->executeStatement();
	}

	/**
	 * Records what a relay answered.
	 *
	 * @return bool whether a row changed
	 */
	public function setStatus(string $actorId, string $status, string $error = ''): bool {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_RELAYS)
			->set('status', $qb->createNamedParameter($status))
			->set('error', $qb->createNamedParameter($error))
			->set('last_update', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter(md5($actorId))));

		return $qb->executeStatement() > 0;
	}

	/** @return Relay[] newest first */
	public function getAll(): array {
		$qb = $this->getQueryBuilder();
		$this->select($qb)->orderBy('id', 'desc');

		return $this->rows($qb);
	}

	/** @return Relay[] the ones that answered yes, which is where posts go */
	public function getAccepted(): array {
		$qb = $this->getQueryBuilder();
		$this->select($qb)
			->where($qb->expr()->eq('status', $qb->createNamedParameter(Relay::STATUS_ACCEPTED)))
			->orderBy('id', 'desc');

		return $this->rows($qb);
	}

	/**
	 * Where a local public post has to go besides its followers' inboxes.
	 *
	 * Read straight off the table rather than through `RelayService`, because
	 * the caller is `ActivityService` and the service depends on half the app
	 * — routing one query through it would tie delivery to the fetch stack.
	 *
	 * @return string[] the inboxes of the relays that accepted, deduplicated
	 */
	public function acceptedInboxes(): array {
		$inboxes = [];
		foreach ($this->getAccepted() as $relay) {
			if ($relay->getInbox() !== '') {
				$inboxes[] = $relay->getInbox();
			}
		}

		return array_values(array_unique($inboxes));
	}

	public function getById(int $id): ?Relay {
		$qb = $this->getQueryBuilder();
		$this->select($qb)->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->rows($qb)[0] ?? null;
	}

	public function getByActorId(string $actorId): ?Relay {
		$qb = $this->getQueryBuilder();
		$this->select($qb)->where(
			$qb->expr()->eq('actor_id_prim', $qb->createNamedParameter(md5($actorId)))
		);

		return $this->rows($qb)[0] ?? null;
	}

	public function delete(int $id): bool {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_RELAYS)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement() > 0;
	}

	private function select(IQueryBuilder $qb): IQueryBuilder {
		return $qb->select('id', 'actor_id', 'inbox', 'status', 'follow_id', 'error', 'creation', 'last_update')
			->from(self::TABLE_RELAYS);
	}

	/**
	 * @return Relay[]
	 */
	private function rows(IQueryBuilder $qb): array {
		$relays = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$relays[] = $this->parse($data);
		}
		$cursor->closeCursor();

		return $relays;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function parse(array $data): Relay {
		$relay = new Relay();
		$relay->setId((int)$data['id'])
			->setActorId((string)($data['actor_id'] ?? ''))
			->setInbox((string)($data['inbox'] ?? ''))
			->setStatus((string)($data['status'] ?? ''))
			->setFollowId((string)($data['follow_id'] ?? ''))
			->setError((string)($data['error'] ?? ''))
			->setCreation($this->timestamp((string)($data['creation'] ?? '')))
			->setLastUpdate($this->timestamp((string)($data['last_update'] ?? '')));

		return $relay;
	}

	/** A DATE column as a timestamp, and 0 for anything that is not one. */
	private function timestamp(string $date): int {
		if ($date === '') {
			return 0;
		}

		try {
			return (new DateTime($date))->getTimestamp();
		} catch (\Exception $e) {
			return 0;
		}
	}
}
