<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\Client\Place;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The places this instance has seen.
 *
 * Shared rows: two posts taken at the same place point at one, which is what
 * makes "everything posted here" a single indexed lookup rather than a scan for
 * a matching string.
 *
 * @package OCA\Social\Db
 */
class PlacesRequest extends CoreRequestBuilder {
	use TArrayTools;

	public const SEARCH_LIMIT = 20;

	private function getPlacesSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->select('p.id', 'p.name', 'p.name_prim', 'p.country', 'p.lat', 'p.lon')
			->from(self::TABLE_PLACES, 'p');

		$this->defaultSelectAlias = 'p';
		$qb->setDefaultSelectAlias('p');

		return $qb;
	}

	/** @return Place[] */
	private function getPlacesFromRequest(SocialQueryBuilder $qb): array {
		$places = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$place = new Place();
			$place->importFromDatabase($data);
			$places[] = $place;
		}
		$cursor->closeCursor();

		return $places;
	}

	/**
	 * The row for this place, creating it if the instance has not seen it.
	 *
	 * The unique index on (name_prim, country) is what makes posting "Berlin"
	 * twice yield one Berlin: the second insert is refused and the existing row
	 * is read instead. Two callers racing therefore both end up with the same
	 * row rather than one of them failing.
	 */
	public function findOrCreate(Place $place): Place {
		try {
			return $this->getByNameAndCountry($place->getName(), $place->getCountry());
		} catch (ItemNotFoundException $e) {
			// not there yet
		}

		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_PLACES)
			->setValue('name', $qb->createNamedParameter($place->getName()))
			->setValue('name_prim', $qb->createNamedParameter($place->getNamePrim()))
			->setValue('country', $qb->createNamedParameter($place->getCountry()))
			->setValue('lat', $qb->createNamedParameter($place->getLat()))
			->setValue('lon', $qb->createNamedParameter($place->getLon()))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		if ($this->insertUnlessDuplicate($qb)) {
			return $place->setId($qb->getLastInsertId());
		}

		// somebody else created it between the read and the insert
		return $this->getByNameAndCountry($place->getName(), $place->getCountry());
	}

	/** @throws ItemNotFoundException */
	public function getById(int $id): Place {
		$qb = $this->getPlacesSelectSql();
		$qb->andWhere($qb->expr()->eq('p.id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		$places = $this->getPlacesFromRequest($qb);
		if ($places === []) {
			throw new ItemNotFoundException('unknown place');
		}

		return $places[0];
	}

	/**
	 * Several at once, for a whole page of posts.
	 *
	 * @param int[] $ids
	 *
	 * @return array<int, Place> keyed by id
	 */
	public function getByIds(array $ids): array {
		$ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
		if ($ids === []) {
			return [];
		}

		$qb = $this->getPlacesSelectSql();
		$qb->andWhere($qb->expr()->in('p.id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));

		$byId = [];
		foreach ($this->getPlacesFromRequest($qb) as $place) {
			$byId[$place->getId()] = $place;
		}

		return $byId;
	}

	/** @throws ItemNotFoundException */
	public function getByNameAndCountry(string $name, string $country): Place {
		$probe = (new Place())->setName($name)->setCountry($country);

		$qb = $this->getPlacesSelectSql();
		$qb->andWhere($qb->expr()->eq('p.name_prim', $qb->createNamedParameter($probe->getNamePrim())))
			->andWhere($qb->expr()->eq('p.country', $qb->createNamedParameter($probe->getCountry())));

		$places = $this->getPlacesFromRequest($qb);
		if ($places === []) {
			throw new ItemNotFoundException('unknown place');
		}

		return $places[0];
	}

	/**
	 * Places whose name begins with what was typed.
	 *
	 * A prefix match, not a substring one: `LIKE '%term%'` cannot use an index
	 * and this route is called on every keystroke. The name column is TEXT and
	 * has no index of its own, so the search is bounded hard and is a scan of a
	 * table that holds one row per distinct place this instance has ever seen --
	 * which is small, and is the reason a geocoder is not needed to make this
	 * useful.
	 *
	 * @return Place[]
	 */
	public function search(string $term, int $limit = self::SEARCH_LIMIT): array {
		$term = trim($term);
		if ($term === '') {
			return [];
		}

		$qb = $this->getPlacesSelectSql();
		$qb->andWhere(
			$qb->expr()->iLike(
				'p.name',
				$qb->createNamedParameter($this->dbConnection->escapeLikeParameter($term) . '%')
			)
		);
		$qb->orderBy('p.name', 'asc');
		$qb->setMaxResults(max(1, min($limit, self::SEARCH_LIMIT)));

		return $this->getPlacesFromRequest($qb);
	}
}
