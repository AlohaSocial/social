<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use Exception;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCP\DB\QueryBuilder\IQueryBuilder;

class ActorsRequest extends ActorsRequestBuilder {
	/**
	 * Create a new Person in the database.
	 *
	 * @throws SocialAppConfigException
	 */
	public function create(Person $actor): void {
		$actor->setId($this->configService->getSocialUrl() . '@' . $actor->getPreferredUsername());
		$qb = $this->getActorsInsertSql();

		$qb->setValue('id', $qb->createNamedParameter($actor->getId()))
			->setValue('id_prim', $qb->createNamedParameter($qb->prim($actor->getId())))
			->setValue('user_id', $qb->createNamedParameter($actor->getUserId()))
			->setValue('name', $qb->createNamedParameter($actor->getName()))
			->setValue('summary', $qb->createNamedParameter($actor->getSummary()))
			->setValue('avatar_version', $qb->createNamedParameter($actor->getAvatarVersion()))
			->setValue(
				'preferred_username', $qb->createNamedParameter($actor->getPreferredUsername())
			)
			->setValue('locked', $qb->createNamedParameter($actor->isLocked() ? 1 : 0))
			->setValue('discoverable', $qb->createNamedParameter($actor->isDiscoverable() ? 1 : 0))
			->setValue('indexable', $qb->createNamedParameter($actor->isIndexable() ? 1 : 0))
			->setValue('bot', $qb->createNamedParameter($actor->isBot() ? 1 : 0))
			// what the actor is served as, where it is anything but the two the
			// `bot` flag decides between; '' means "decide as before"
			->setValue('actor_type', $qb->createNamedParameter($actor->storedActorType()))
			->setValue('public_key', $qb->createNamedParameter($actor->getPublicKey()))
			->setValue('private_key', $qb->createNamedParameter($this->keyCipher->seal($actor->getPrivateKey())))
			->setValue(
				'creation',
				$qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)
			);

		$qb->executeStatement();
	}

	public function update(Person $actor): void {
		$qb = $this->getActorsUpdateSql();
		$qb->set('avatar_version', $qb->createNamedParameter($actor->getAvatarVersion()))
			->set('summary', $qb->createNamedParameter($actor->getSummary()))
			->set('name', $qb->createNamedParameter($actor->getName()));
		$this->limitToRow($qb, $actor);

		$qb->executeStatement();
	}

	/**
	 * Stores changed profile metadata fields.
	 */
	public function updateFields(Person $actor): void {
		$qb = $this->getActorsUpdateSql();
		$qb->set('fields', $qb->createNamedParameter(json_encode($actor->getFields())));
		$this->limitToRow($qb, $actor);

		$qb->executeStatement();
	}

	/**
	 * Stores the bio — the plain text an actor's `summary` is rendered from.
	 */
	public function updateSummary(Person $actor): void {
		$qb = $this->getActorsUpdateSql();
		$qb->set('summary', $qb->createNamedParameter($actor->getSummary()));
		$this->limitToRow($qb, $actor);

		$qb->executeStatement();
	}

	/**
	 * Stores a changed locked flag (manuallyApprovesFollowers).
	 */
	public function updateLocked(Person $actor): void {
		$qb = $this->getActorsUpdateSql();
		$qb->set('locked', $qb->createNamedParameter($actor->isLocked() ? 1 : 0));
		$this->limitToRow($qb, $actor);

		$qb->executeStatement();
	}

	/**
	 * Stores the directory flags: `discoverable` (may be listed in directories
	 * and suggestions), `indexable` (posts may be full-text indexed) and `bot`
	 * (an automated account, which the actor document publishes as a `Service`
	 * rather than a `Person`).
	 */
	public function updateFlags(Person $actor): void {
		$qb = $this->getActorsUpdateSql();
		$qb->set('discoverable', $qb->createNamedParameter($actor->isDiscoverable() ? 1 : 0))
			->set('indexable', $qb->createNamedParameter($actor->isIndexable() ? 1 : 0))
			->set('bot', $qb->createNamedParameter($actor->isBot() ? 1 : 0));
		$this->limitToRow($qb, $actor);

		$qb->executeStatement();
	}

	/**
	 * Stores the `alsoKnownAs` list — the actor ids this one also answers to,
	 * which is what a remote server checks before it accepts a Move towards
	 * here.
	 */
	public function updateAlsoKnownAs(Person $actor): void {
		$qb = $this->getActorsUpdateSql();
		$qb->set('also_known_as', $qb->createNamedParameter(json_encode($actor->getAlsoKnownAs())));
		$this->limitToRow($qb, $actor);

		$qb->executeStatement();
	}

	/**
	 * Stores where the actor moved to; empty means it has not.
	 */
	public function updateMovedTo(Person $actor): void {
		$qb = $this->getActorsUpdateSql();
		$qb->set('moved_to', $qb->createNamedParameter($actor->getMovedTo()));
		$this->limitToRow($qb, $actor);

		$qb->executeStatement();
	}

	public function refreshKeys(Person $actor): void {
		$qb = $this->getActorsUpdateSql();
		$qb->set('public_key', $qb->createNamedParameter($actor->getPublicKey()))
			->set('private_key', $qb->createNamedParameter($this->keyCipher->seal($actor->getPrivateKey())));

		try {
			$qb->set(
				'creation',
				$qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)
			);
		} catch (Exception $e) {
		}

		$this->limitToRow($qb, $actor);

		$qb->executeStatement();
	}

	/**
	 * Return Actor from database based on the username
	 *
	 * @throws ActorDoesNotExistException
	 * @throws SocialAppConfigException
	 */
	public function getFromUsername(string $username): Person {
		$qb = $this->getActorsSelectSql();
		$qb->limitToPreferredUsername($username);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new ActorDoesNotExistException('Actor not found');
		}

		return $this->parseActorsSelectSql($data);
	}

	/**
	 * The Nextcloud users of these local usernames, one query per 500 of them:
	 * the usernames that are held by an account not deleted, each with its
	 * user. The cached copy of an account, which is what the client is handed,
	 * does not carry the user, and the username need not be it. A cached copy
	 * carries the username exactly as this table does, so they are compared
	 * as they are.
	 *
	 * @param string[] $usernames
	 * @return array<string, string> user id by username
	 */
	public function userIdsOf(array $usernames): array {
		$userIds = [];
		foreach (array_chunk(array_values(array_unique($usernames)), 500) as $chunk) {
			$qb = $this->getQueryBuilder();
			$qb->select('a.preferred_username', 'a.user_id')
				->from(self::TABLE_ACTORS, 'a')
				->where($qb->expr()->in('a.preferred_username', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)))
				->andWhere($qb->expr()->isNull('a.deleted'));

			$cursor = $qb->executeQuery();
			while ($row = $cursor->fetch()) {
				$userIds[(string)$row['preferred_username']] = (string)$row['user_id'];
			}
			$cursor->closeCursor();
		}

		return $userIds;
	}

	/**
	 * @throws ActorDoesNotExistException
	 * @throws SocialAppConfigException
	 */
	public function getFromId(string $id): Person {
		$qb = $this->getActorsSelectSql();
		$this->limitToIdPrimString($qb, $id);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new ActorDoesNotExistException('Actor not found');
		}

		return $this->parseActorsSelectSql($data);
	}

	/**
	 * The **live** local account a Nextcloud user owns.
	 *
	 * Deleted rows are skipped, which is what makes deleting an account
	 * something a person can do and then carry on: a deleted actor is kept for
	 * at least an hour, and while its deliveries are still queued, before
	 * `manageDeletedActors()` purges it, so that the handle
	 * cannot be taken by somebody else straight away — and while it sat there,
	 * this method went on answering with it. The person who had just deleted
	 * their account was shown the account they had deleted, and `createActor()`
	 * refused to make them a new one because "account for this user already
	 * exist". The retention is about the *handle*, not about the person.
	 *
	 * @param string $userId
	 *
	 * @return Person
	 * @throws ActorDoesNotExistException
	 * @throws SocialAppConfigException
	 */
	public function getFromUserId(string $userId): Person {
		$qb = $this->getActorsSelectSql();
		$qb->limitToUserId($userId);
		$qb->andWhere($qb->expr()->isNull('a.deleted'));

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new ActorDoesNotExistException('Actor not found');
		}

		return $this->parseActorsSelectSql($data);
	}

	public function setAsDeleted(string $handle): void {
		$qb = $this->getActorsUpdateSql();
		$qb->set(
			'deleted',
			$qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)
		);
		$qb->limitToPreferredUsername($handle);

		$qb->executeStatement();
	}

	/**
	 * @param string $handle
	 */
	public function delete(string $handle): void {
		$qb = $this->getActorsDeleteSql();
		$qb->limitToPreferredUsername($handle);

		$qb->executeStatement();
	}

	/**
	 * One page of local accounts, oldest first.
	 *
	 * `getAll()` below reads every one of them into a PHP array, which is the
	 * right thing for a command somebody typed and the wrong thing for a cron
	 * job: `Cron\Cache` refreshed the cached copy of **every** local account on
	 * every pass, twelve minutes apart. At a million accounts that array does
	 * not fit in memory, and if it did the work behind it — a dozen queries
	 * and an avatar read each — is hours inside a job that fires every twelve
	 * minutes. The failure is silent, too: the cron just gets slower.
	 *
	 * So the job walks instead, a page at a time, remembering where it got to.
	 * The ordering is by `creation` and then `id_prim` so the walk is total and
	 * stable — a new account arriving mid-walk lands at the end rather than
	 * shifting the page boundary under it.
	 *
	 * @return Person[]
	 * @throws SocialAppConfigException
	 */
	public function getPage(int $limit, string $afterPrim = '', bool $withLinksOnly = false): array {
		$qb = $this->getActorsSelectSql();
		if ($afterPrim !== '') {
			$qb->andWhere($qb->expr()->gt('a.id_prim', $qb->createNamedParameter($afterPrim)));
		}
		if ($withLinksOnly) {
			// a coarse filter for the profile-link walk, so the accounts with
			// nothing to verify are skipped in the database rather than built
			// into a Person each; ProfileLinkVerifier::linkOf() decides the rest
			$qb->andWhere($qb->expr()->like('a.fields', $qb->createNamedParameter('%http%')));
		}
		$qb->orderBy('a.id_prim', 'asc');
		$qb->setMaxResults($limit);

		$accounts = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$accounts[] = $this->parseActorsSelectSql($data);
		}
		$cursor->closeCursor();

		return $accounts;
	}

	/**
	 * Local accounts marked deleted before `$before`, oldest first, at most
	 * `$limit` of them — what the cron removes once the grace period is over.
	 *
	 * The zero date some MySQL installations hold for "not deleted" is not a
	 * deletion; the lower bound keeps it out.
	 *
	 * @return Person[]
	 * @throws SocialAppConfigException
	 */
	public function getDeletedBefore(int $before, int $limit): array {
		$qb = $this->getActorsSelectSql();
		$qb->andWhere($qb->expr()->isNotNull('a.deleted'));
		$qb->andWhere($qb->expr()->gt('a.deleted', $qb->createNamedParameter(new DateTime('@86400'), IQueryBuilder::PARAM_DATE)));
		$qb->andWhere($qb->expr()->lt('a.deleted', $qb->createNamedParameter(new DateTime('@' . $before), IQueryBuilder::PARAM_DATE)));
		$qb->orderBy('a.deleted', 'asc');
		$qb->setMaxResults($limit);

		$accounts = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$accounts[] = $this->parseActorsSelectSql($data);
		}
		$cursor->closeCursor();

		return $accounts;
	}

	/** How many accounts `walk()` reads per query. */
	public const WALK_PAGE = 100;

	/**
	 * Every local account, read a page at a time and handed over one by one.
	 *
	 * For the walks that have to visit every account and keep nothing — a
	 * key rotation, an upgrade step — where `getAll()` would hold every
	 * account as a model at once: a few kilobytes each, so hundreds of
	 * megabytes on a large instance, inside `occ upgrade`. The page is read
	 * and its cursor closed before the first account is handed over, so the
	 * caller may write to the table as it goes. The walk is keyed on the
	 * stored `id_prim`, not on a hash of the id the model is given, so it
	 * advances even where the social URL changed since an account was made.
	 *
	 * @return iterable<int, Person>
	 * @throws SocialAppConfigException
	 */
	public function walk(int $pageSize = self::WALK_PAGE): iterable {
		$after = '';
		while (true) {
			$qb = $this->getActorsSelectSql();
			if ($after !== '') {
				$qb->andWhere($qb->expr()->gt('a.id_prim', $qb->createNamedParameter($after)));
			}
			$qb->orderBy('a.id_prim', 'asc');
			$qb->setMaxResults($pageSize);

			$rows = [];
			$cursor = $qb->executeQuery();
			while ($data = $cursor->fetch()) {
				$rows[] = $data;
			}
			$cursor->closeCursor();

			if ($rows === []) {
				return;
			}

			$after = (string)$rows[count($rows) - 1]['id_prim'];
			foreach ($rows as $data) {
				yield $this->parseActorsSelectSql($data);
			}

			if (count($rows) < $pageSize || $after === '') {
				return;
			}
		}
	}

	/**
	 * @return Person[]
	 * @throws SocialAppConfigException
	 */
	public function getAll(): array {
		$qb = $this->getActorsSelectSql();

		$accounts = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$accounts[] = $this->parseActorsSelectSql($data);
		}
		$cursor->closeCursor();

		return $accounts;
	}

	/**
	 * Any one live local account, or null when nobody has opened the app yet.
	 *
	 * For a probe that needs a real account to ask about — a WebFinger check
	 * run by an administrator who may not have a Social account themselves.
	 *
	 * @throws SocialAppConfigException
	 */
	public function getAny(): ?Person {
		$qb = $this->getActorsSelectSql();
		$qb->andWhere($qb->expr()->isNull('a.deleted'));
		$qb->orderBy('a.creation', 'asc');
		$qb->setMaxResults(1);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			return null;
		}

		return $this->parseActorsSelectSql($data);
	}

	/**
	 * @return Person[]
	 * @throws SocialAppConfigException
	 */
	public function searchFromUsername(string $search): array {
		$qb = $this->getActorsSelectSql();
		$qb->searchInPreferredUsername($search);

		$accounts = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$accounts[] = $this->parseActorsSelectSql($data);
		}
		$cursor->closeCursor();

		return $accounts;
	}

	/**
	 * The `id_prim` an account's row is stored under: the one it was read
	 * with, or the md5 of its id for a model not read from the table.
	 */
	public static function rowPrimOf(Person $actor): string {
		$stored = $actor->getStoredIdPrim();

		return ($stored !== '') ? $stored : md5($actor->getId());
	}

	/**
	 * Narrows to the account's own row. By the stored `id_prim` where the
	 * model was read from the table: its id is derived from the social URL of
	 * today, and an account made under another one is stored under that.
	 */
	private function limitToRow(SocialQueryBuilder $qb, Person $actor): void {
		if ($actor->getStoredIdPrim() !== '') {
			$qb->limitToDBField('id_prim', $actor->getStoredIdPrim());

			return;
		}

		$this->limitToIdPrimString($qb, $actor->getId());
	}
}
