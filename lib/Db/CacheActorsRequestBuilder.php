<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Tools\Exceptions\RowNotFoundException;
use OCA\Social\Tools\Traits\TArrayTools;

class CacheActorsRequestBuilder extends CoreRequestBuilder {
	use TArrayTools;

	/**
	 * Base of the Sql Insert request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getCacheActorsInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_CACHE_ACTORS);

		return $qb;
	}

	/**
	 * Base of the Sql Update request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getCacheActorsUpdateSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_CACHE_ACTORS);

		return $qb;
	}

	/**
	 * Base of the Sql Select request for Shares
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getCacheActorsSelectSql(int $format = Stream::FORMAT_ACTIVITYPUB): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->setFormat($format);

		/** @noinspection PhpMethodParametersCountMismatchInspection */
		$qb->select(
			'ca.nid', 'ca.id', 'ca.account', 'ca.following', 'ca.followers', 'ca.inbox',
			'ca.shared_inbox', 'ca.outbox', 'ca.featured', 'ca.url', 'ca.type', 'ca.preferred_username',
			'ca.name', 'ca.summary', 'ca.description', 'ca.sanitized', 'ca.public_key', 'ca.local', 'ca.details', 'ca.source', 'ca.creation',
			'ca.details_update',
			// the three counters, which `Person::importFromDatabase()` lays over
			// what `details` says. Without them in the row the overlay has
			// nothing to lay on, and every profile showed the JSON until the
			// cron's next recount rewrote it: a post count that did not move
			// when you posted.
			'ca.count_followers', 'ca.count_following', 'ca.count_posts'
		)
			->from(self::TABLE_CACHE_ACTORS, 'ca');

		$qb->setDefaultSelectAlias('ca');

		/** @deprecated */
		$this->defaultSelectAlias = 'ca';

		return $qb;
	}

	/**
	 * Base of the Sql Delete request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getCacheActorsDeleteSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_CACHE_ACTORS);

		return $qb;
	}

	/**
	 * @param SocialQueryBuilder $qb
	 *
	 * @return Person
	 * @throws CacheActorDoesNotExistException
	 */
	protected function getCacheActorFromRequest(SocialQueryBuilder $qb): Person {
		/** @var Person $result */
		try {
			$result = $qb->getRow([$this, 'parseCacheActorsSelectSql']);
		} catch (RowNotFoundException $e) {
			throw new CacheActorDoesNotExistException('Actor is not known');
		}

		return $result;
	}

	/**
	 * @param SocialQueryBuilder $qb
	 *
	 * @return Person[]
	 */
	public function getCacheActorsFromRequest(SocialQueryBuilder $qb): array {
		/** @var Person[] $result */
		$result = $qb->getRows([$this, 'parseCacheActorsSelectSql']);

		return $result;
	}

	/**
	 * @param array $data
	 *
	 * @param SocialQueryBuilder $qb
	 *
	 * @return Person
	 */
	public function parseCacheActorsSelectSql(array $data, SocialQueryBuilder $qb): Person {
		$actor = $this->parseCacheActorRow($data, $qb);
		$this->attachMovedTarget($actor);

		return $actor;
	}

	/**
	 * Hands an actor that moved the cached copy of the account it moved to, so
	 * the client entity's `moved` is a real account rather than a stub derived
	 * from the id. An actor that did not move costs nothing here; a target
	 * that is not cached leaves the model to derive the stub.
	 */
	public function attachMovedTarget(Person $actor): void {
		if ($actor->getMovedTo() === '') {
			return;
		}

		try {
			$actor->setMovedToActor($this->cachedMovedTarget($actor->getMovedTo()));
		} catch (RowNotFoundException $e) {
			$actor->setMovedToActor(null);
		}
	}

	/**
	 * The cached row behind a `movedTo` id, parsed one hop deep: the target's
	 * own target is deliberately not resolved, so two accounts pointing at
	 * each other cannot send this round in circles.
	 *
	 * @throws RowNotFoundException
	 */
	protected function cachedMovedTarget(string $id): Person {
		$qb = $this->getCacheActorsSelectSql(Stream::FORMAT_LOCAL);
		$qb->limitToIdPrim($qb->prim($id));
		$qb->leftJoinCacheDocuments('icon_id');

		/** @var Person $target */
		$target = $qb->getRow(
			fn (array $data, SocialQueryBuilder $qb): Person => $this->parseCacheActorRow($data, $qb)
		);

		return $target;
	}

	/**
	 * One cache row to one Person, without following `movedTo`.
	 */
	private function parseCacheActorRow(array $data, SocialQueryBuilder $qb): Person {
		$actor = new Person();
		$actor->setExportFormat($qb->getFormat());

		try {
			$icon = $qb->parseLeftJoinCacheDocuments($data);
			$actor->setIcon($icon);
		} catch (InvalidResourceException $e) {
		}

		$actor->importFromDatabase($data);
		$actor->setUrlSocial($this->configService->getSocialUrl());

		$this->assignViewerLink($qb, $actor);
		$this->assignDetails($actor, $data);

		return $actor;
	}

	/**
	 * @param SocialQueryBuilder $qb
	 * @param Person $actor
	 */
	private function assignViewerLink(SocialQueryBuilder $qb, Person $actor) {
		if ($actor->isLocal()) {
			$link = Person::LINK_LOCAL;
			if ($qb->hasViewer()
				&& $qb->getViewer()
					->getId() === $actor->getId()) {
				$link = Person::LINK_VIEWER;
			}
		} else {
			$link = Person::LINK_REMOTE;
		}

		$actor->setViewerLink($link);
	}
}
