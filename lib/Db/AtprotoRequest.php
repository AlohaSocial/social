<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Model\Atproto\AtprotoAccount;
use OCA\Social\Model\Atproto\AtprotoLink;
use OCA\Social\Model\Atproto\AtprotoWatch;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The three tables of the AT-Proto (Bluesky) bridge: a linked account, the
 * records copied across, and the actors whose posts this instance reads.
 *
 * Three shapes of read and nothing else — a row by its key, a row by what it
 * is due for, and the whole small set for the status page — because every one
 * of these tables holds rows by the hundred at most: one per account that
 * linked, one per post read, one per followed actor.
 *
 * Writes are update-then-insert rather than an upsert verb: the four database
 * backends this app runs on spell that verb four ways, and a row that is
 * written twice concurrently here is a linked account or a record URI, both
 * of which are unique in the schema — so a losing insert is caught and the
 * update that raced with it already wrote what the caller wanted.
 *
 * @package OCA\Social\Db
 */
class AtprotoRequest extends CoreRequestBuilder {
	/** The most watched actors one pass will pick up. */
	public const SYNC_BATCH = 25;

	/** The most links one read of "what did this record become" returns. */
	public const LINK_BATCH = 10;

	// ---------------------------------------------------------------- accounts

	/**
	 * A linked account, by the Nextcloud account that linked it. A second
	 * link overwrites the first — one Bluesky account per account here.
	 */
	public function getAccount(string $userId): ?AtprotoAccount {
		$qb = $this->getQueryBuilder();
		$this->selectAccount($qb)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		return $this->accounts($qb)[0] ?? null;
	}

	/** A linked account by the `did` it posts under. */
	public function getAccountByDid(string $did): ?AtprotoAccount {
		$qb = $this->getQueryBuilder();
		$this->selectAccount($qb)
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));

		return $this->accounts($qb)[0] ?? null;
	}

	/** Every linked account, newest link first. */
	public function getAccounts(int $limit = 100): array {
		$qb = $this->getQueryBuilder();
		$this->selectAccount($qb)->orderBy('nid', 'desc')->setMaxResults($limit);

		return $this->accounts($qb);
	}

	public function saveAccount(AtprotoAccount $account): void {
		$now = new DateTime('@' . time());

		$qb = $this->getQueryBuilder();
		$updated = $qb->update(self::TABLE_ATPROTO_ACCOUNTS)
			->set('handle', $qb->createNamedParameter($account->getHandle()))
			->set('did', $qb->createNamedParameter($account->getDid()))
			->set('pds', $qb->createNamedParameter($account->getPds()))
			->set('state', $qb->createNamedParameter($account->getState()))
			->set('last_error', $qb->createNamedParameter($account->getLastError()))
			->set('last_sync', $qb->createNamedParameter($account->getLastSync(), IQueryBuilder::PARAM_INT))
			->set('updated', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($account->getUserId())));
		// a re-link keeps the password that is stored unless the caller sent a
		// new one; sealing happens in the caller, so an empty value here means
		// "do not change it" rather than "clear it"
		if ($account->getAppPassword() !== '') {
			$updated->set('app_password', $qb->createNamedParameter($account->getAppPassword()));
		}
		if ($updated->executeStatement() > 0) {
			return;
		}

		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_ATPROTO_ACCOUNTS)
			->setValue('user_id', $qb->createNamedParameter($account->getUserId()))
			->setValue('handle', $qb->createNamedParameter($account->getHandle()))
			->setValue('did', $qb->createNamedParameter($account->getDid()))
			->setValue('pds', $qb->createNamedParameter($account->getPds()))
			->setValue('app_password', $qb->createNamedParameter($account->getAppPassword()))
			->setValue('state', $qb->createNamedParameter($account->getState()))
			->setValue('last_error', $qb->createNamedParameter($account->getLastError()))
			->setValue('last_sync', $qb->createNamedParameter($account->getLastSync(), IQueryBuilder::PARAM_INT))
			->setValue('creation', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE))
			->setValue('updated', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE));
		try {
			$qb->executeStatement();
		} catch (DBException $e) {
			// the same account linked from two requests at once: the other
			// one wrote the row, and it says the same thing
			$this->logger->debug('social_atproto_account already written', ['exception' => $e]);
		}
	}

	/** What a write came to, so the status page can say so. */
	public function setAccountState(string $userId, string $state, string $lastError = ''): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_ATPROTO_ACCOUNTS)
			->set('state', $qb->createNamedParameter($state))
			->set('last_error', $qb->createNamedParameter(mb_substr($lastError, 0, 255)))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	public function deleteAccount(string $userId): bool {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_ATPROTO_ACCOUNTS)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		return $qb->executeStatement() > 0;
	}

	// ------------------------------------------------------------------- links

	public function getLinkByLocalId(string $localId): ?AtprotoLink {
		$qb = $this->getQueryBuilder();
		$this->selectLink($qb)
			->where($qb->expr()->eq('local_id_prim', $qb->createNamedParameter($qb->prim($localId))));

		return $this->links($qb)[0] ?? null;
	}

	public function getLinkByAtUri(string $atUri): ?AtprotoLink {
		$qb = $this->getQueryBuilder();
		$this->selectLink($qb)
			->where($qb->expr()->eq('at_uri', $qb->createNamedParameter($atUri)));

		return $this->links($qb)[0] ?? null;
	}

	/**
	 * What this instance knows about the records of one actor, newest first —
	 * the link table is where a record that was read in and a record that was
	 * written out meet, so both directions of the mapping are read from it.
	 *
	 * @return AtprotoLink[]
	 */
	public function getLinksForDid(string $did, int $limit = self::LINK_BATCH): array {
		$qb = $this->getQueryBuilder();
		$this->selectLink($qb)
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))
			->orderBy('nid', 'desc')
			->setMaxResults($limit);

		return $this->links($qb);
	}

	public function saveLink(AtprotoLink $link): void {
		$link->setLocalIdPrim($link->getLocalIdPrim() !== '' ? $link->getLocalIdPrim() : $this->getQueryBuilder()->prim($link->getLocalId()));

		$qb = $this->getQueryBuilder();
		$updated = $qb->update(self::TABLE_ATPROTO_LINKS)
			->set('local_id', $qb->createNamedParameter($link->getLocalId()))
			->set('local_id_prim', $qb->createNamedParameter($link->getLocalIdPrim()))
			->set('cid', $qb->createNamedParameter($link->getCid()))
			->set('collection', $qb->createNamedParameter($link->getCollection()))
			->set('rkey', $qb->createNamedParameter($link->getRkey()))
			->set('handle', $qb->createNamedParameter($link->getHandle()))
			->where($qb->expr()->eq('at_uri', $qb->createNamedParameter($link->getAtUri())));
		if ($updated->executeStatement() > 0) {
			return;
		}

		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_ATPROTO_LINKS)
			->setValue('local_id', $qb->createNamedParameter($link->getLocalId()))
			->setValue('local_id_prim', $qb->createNamedParameter($link->getLocalIdPrim()))
			->setValue('at_uri', $qb->createNamedParameter($link->getAtUri()))
			->setValue('cid', $qb->createNamedParameter($link->getCid()))
			->setValue('did', $qb->createNamedParameter($link->getDid()))
			->setValue('collection', $qb->createNamedParameter($link->getCollection()))
			->setValue('rkey', $qb->createNamedParameter($link->getRkey()))
			->setValue('handle', $qb->createNamedParameter($link->getHandle()))
			->setValue('creation', $qb->createNamedParameter(new DateTime('@' . time()), IQueryBuilder::PARAM_DATE));
		try {
			$qb->executeStatement();
		} catch (DBException $e) {
			// the local id was mapped already — by a pass that ran beside this
			// one — and the row it wrote is the mapping asked for
			$this->logger->debug('social_atproto_link already written', ['exception' => $e]);
		}
	}

	/** Forgets what a deleted post was, so a re-read of it writes it again. */
	public function deleteLinkByLocalId(string $localId): bool {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_ATPROTO_LINKS)
			->where($qb->expr()->eq('local_id_prim', $qb->createNamedParameter($qb->prim($localId))));

		return $qb->executeStatement() > 0;
	}

	// ----------------------------------------------------------------- watches

	public function getWatch(string $did): ?AtprotoWatch {
		$qb = $this->getQueryBuilder();
		$this->selectWatch($qb)
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));

		return $this->watches($qb)[0] ?? null;
	}

	/** Whether anything here follows this actor. */
	public function isWatched(string $did): bool {
		return $this->getWatch($did) !== null;
	}

	/**
	 * The watched actors a pass may read now, in the order it reads them.
	 *
	 * @return AtprotoWatch[]
	 */
	public function getDueWatches(int $now, int $limit = self::SYNC_BATCH): array {
		$qb = $this->getQueryBuilder();
		$this->selectWatch($qb)
			->andWhere($qb->expr()->lte('next_sync', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
			->orderBy('next_sync', 'asc')
			->setMaxResults($limit);

		return $this->watches($qb);
	}

	/**
	 * Every watched actor, for the status page.
	 *
	 * @return AtprotoWatch[]
	 */
	public function getWatches(int $limit = 100): array {
		$qb = $this->getQueryBuilder();
		$this->selectWatch($qb)->orderBy('handle', 'asc')->setMaxResults($limit);

		return $this->watches($qb);
	}

	public function saveWatch(AtprotoWatch $watch): void {
		$qb = $this->getQueryBuilder();
		$updated = $qb->update(self::TABLE_ATPROTO_WATCHES)
			->set('handle', $qb->createNamedParameter($watch->getHandle()))
			->set('cursor', $qb->createNamedParameter($watch->getCursor()))
			->set('last_sync', $qb->createNamedParameter($watch->getLastSync(), IQueryBuilder::PARAM_INT))
			->set('next_sync', $qb->createNamedParameter($watch->getNextSync(), IQueryBuilder::PARAM_INT))
			->set('failures', $qb->createNamedParameter($watch->getFailures(), IQueryBuilder::PARAM_INT))
			->set('imported', $qb->createNamedParameter($watch->getImported(), IQueryBuilder::PARAM_INT))
			->set('last_error', $qb->createNamedParameter(mb_substr($watch->getLastError(), 0, 255)))
			->where($qb->expr()->eq('did', $qb->createNamedParameter($watch->getDid())));
		if ($updated->executeStatement() > 0) {
			return;
		}

		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_ATPROTO_WATCHES)
			->setValue('did', $qb->createNamedParameter($watch->getDid()))
			->setValue('handle', $qb->createNamedParameter($watch->getHandle()))
			->setValue('cursor', $qb->createNamedParameter($watch->getCursor()))
			->setValue('last_sync', $qb->createNamedParameter($watch->getLastSync(), IQueryBuilder::PARAM_INT))
			->setValue('next_sync', $qb->createNamedParameter($watch->getNextSync(), IQueryBuilder::PARAM_INT))
			->setValue('failures', $qb->createNamedParameter($watch->getFailures(), IQueryBuilder::PARAM_INT))
			->setValue('imported', $qb->createNamedParameter($watch->getImported(), IQueryBuilder::PARAM_INT))
			->setValue('last_error', $qb->createNamedParameter(mb_substr($watch->getLastError(), 0, 255)))
			->setValue('creation', $qb->createNamedParameter(new DateTime('@' . time()), IQueryBuilder::PARAM_DATE));
		try {
			$qb->executeStatement();
		} catch (DBException $e) {
			// the actor is watched already, by the follow that ran beside this
			$this->logger->debug('social_atproto_watch already written', ['exception' => $e]);
		}
	}

	/**
	 * Stops reading an actor when nothing follows it here any more.
	 *
	 * What is kept: the links, because a post read in and a post written out
	 * are still the same records and their mapping is what the way back needs.
	 */
	public function deleteWatch(string $did): bool {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_ATPROTO_WATCHES)
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));

		return $qb->executeStatement() > 0;
	}

	// ------------------------------------------------------------------ selects

	private function selectAccount(IQueryBuilder $qb): IQueryBuilder {
		return $qb->select(
			'nid', 'user_id', 'handle', 'did', 'pds', 'app_password', 'state',
			'last_error', 'last_sync', 'creation'
		)->from(self::TABLE_ATPROTO_ACCOUNTS);
	}

	private function selectLink(IQueryBuilder $qb): IQueryBuilder {
		return $qb->select(
			'nid', 'local_id', 'local_id_prim', 'at_uri', 'cid', 'did',
			'collection', 'rkey', 'handle', 'creation'
		)->from(self::TABLE_ATPROTO_LINKS);
	}

	private function selectWatch(IQueryBuilder $qb): IQueryBuilder {
		return $qb->select(
			'nid', 'did', 'handle', 'cursor', 'last_sync', 'next_sync', 'failures',
			'imported', 'last_error', 'creation'
		)->from(self::TABLE_ATPROTO_WATCHES);
	}

	/** @return AtprotoAccount[] */
	private function accounts(IQueryBuilder $qb): array {
		$rows = $this->rows($qb);
		return array_map(function (array $data): AtprotoAccount {
			$account = new AtprotoAccount();
			$account->setNid((int)($data['nid'] ?? 0))
				->setUserId((string)($data['user_id'] ?? ''))
				->setHandle((string)($data['handle'] ?? ''))
				->setDid((string)($data['did'] ?? ''))
				->setPds((string)($data['pds'] ?? ''))
				->setAppPassword((string)($data['app_password'] ?? ''))
				->setState((string)($data['state'] ?? AtprotoAccount::STATE_LINKED))
				->setLastError((string)($data['last_error'] ?? ''))
				->setLastSync((int)($data['last_sync'] ?? 0))
				->setCreation($this->timestampOf($data['creation'] ?? null));
			return $account;
		}, $rows);
	}

	/** @return AtprotoLink[] */
	private function links(IQueryBuilder $qb): array {
		return array_map(function (array $data): AtprotoLink {
			$link = new AtprotoLink();
			$link->setNid((int)($data['nid'] ?? 0))
				->setLocalId((string)($data['local_id'] ?? ''))
				->setLocalIdPrim((string)($data['local_id_prim'] ?? ''))
				->setAtUri((string)($data['at_uri'] ?? ''))
				->setCid((string)($data['cid'] ?? ''))
				->setDid((string)($data['did'] ?? ''))
				->setCollection((string)($data['collection'] ?? ''))
				->setRkey((string)($data['rkey'] ?? ''))
				->setHandle((string)($data['handle'] ?? ''))
				->setCreation($this->timestampOf($data['creation'] ?? null));
			return $link;
		}, $this->rows($qb));
	}

	/** @return AtprotoWatch[] */
	private function watches(IQueryBuilder $qb): array {
		return array_map(function (array $data): AtprotoWatch {
			$watch = new AtprotoWatch();
			$watch->setNid((int)($data['nid'] ?? 0))
				->setDid((string)($data['did'] ?? ''))
				->setHandle((string)($data['handle'] ?? ''))
				->setCursor((string)($data['cursor'] ?? ''))
				->setLastSync((int)($data['last_sync'] ?? 0))
				->setNextSync((int)($data['next_sync'] ?? 0))
				->setFailures((int)($data['failures'] ?? 0))
				->setImported((int)($data['imported'] ?? 0))
				->setLastError((string)($data['last_error'] ?? ''))
				->setCreation($this->timestampOf($data['creation'] ?? null));
			return $watch;
		}, $this->rows($qb));
	}

	/** @return array<int, array<string, mixed>> */
	private function rows(IQueryBuilder $qb): array {
		$rows = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$rows[] = $data;
		}
		$cursor->closeCursor();

		return $rows;
	}

	/** The creation column as a unix timestamp, whatever the backend answers. */
	private function timestampOf(mixed $value): int {
		if ($value === null || $value === '' || $value === false) {
			return 0;
		}
		if (is_int($value)) {
			return $value;
		}
		$time = strtotime((string)$value);

		return ($time === false) ? 0 : $time;
	}
}
