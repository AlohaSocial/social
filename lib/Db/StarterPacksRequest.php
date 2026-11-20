<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * `social_starter_pack`: the starter packs read in from other servers, one
 * row each, `origin` saying which network's (`fediverse`, `bluesky`). See
 * `Service\CollectionPackService` for what goes in and why.
 *
 * @psalm-type StoredPack = array{id: int, uri: string, slug: string, origin: string, curator: string, curator_acct: string, name: string, summary: string, topic: string, language: string, url: string, members: list<string>, fetched: int}
 * @psalm-type IndexedPack = array{id: int, slug: string, origin: string, curator: string, curator_acct: string, name: string, summary: string, topic: string, language: string, url: string, member_count: int}
 * @psalm-type StalePack = array{id: int, uri: string, curator: string, curator_acct: string}
 */
class StarterPacksRequest extends CoreRequestBuilder {
	/** The most packs the index reads; far more than a page of them draws. */
	public const MAX_PACKS = 200;

	/** The most ids one `IN` list binds. */
	private const CHUNK = 500;

	/**
	 * Writes a collection, replacing what is stored for the same id. A pack read
	 * again keeps its first slug, the address its page was shared at; concurrent
	 * runs write one row.
	 *
	 * @param array{uri: string, slug: string, origin: string, curator: string, curator_acct: string, name: string, summary: string, topic: string, language: string, url: string, members: list<string>, fetched: int} $pack
	 *
	 * @return bool false when another pack already has the slug
	 */
	public function save(array $pack): bool {
		$prim = md5($pack['uri']);
		$members = array_values($pack['members']);
		$values = [
			'origin' => $pack['origin'],
			'curator' => $pack['curator'],
			'curator_acct' => $pack['curator_acct'],
			'name' => $pack['name'],
			'summary' => $pack['summary'],
			'topic' => $pack['topic'],
			'language' => $pack['language'],
			'url' => $pack['url'],
			'members' => (string)json_encode($members, JSON_UNESCAPED_SLASHES),
			'member_count' => count($members),
			'fetched' => $pack['fetched'],
		];

		$existing = $this->idOf($prim);
		if ($existing === null) {
			$inserted = $this->dbConnection->insertIgnoreConflict(
				self::TABLE_STARTER_PACKS,
				['uri' => $pack['uri'], 'uri_prim' => $prim, 'slug' => $pack['slug']] + $values
			);
			if ($inserted > 0) {
				return true;
			}

			// written by another run meanwhile, or the slug is another pack's
			$existing = $this->idOf($prim);
			if ($existing === null) {
				return false;
			}
		}

		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_STARTER_PACKS);
		foreach ($values as $column => $value) {
			$qb->set($column, $qb->createNamedParameter($value, is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR));
		}
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($existing, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();

		return true;
	}

	/**
	 * The packs with members, by curator and then name, without their
	 * member lists: what the index of packs draws.
	 *
	 * @return list<IndexedPack>
	 */
	public function getIndex(): array {
		$qb = $this->getQueryBuilder();
		$qb->select('id', 'slug', 'origin', 'curator', 'curator_acct', 'name', 'summary', 'topic', 'language', 'url', 'member_count')
			->from(self::TABLE_STARTER_PACKS)
			->where($qb->expr()->gt('member_count', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('curator_acct', 'asc')
			->addOrderBy('name', 'asc')
			->addOrderBy('id', 'asc')
			->setMaxResults(self::MAX_PACKS);

		$packs = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$packs[] = [
				'id' => (int)$data['id'],
				'slug' => (string)$data['slug'],
				'origin' => (string)$data['origin'],
				'curator' => (string)($data['curator'] ?? ''),
				'curator_acct' => (string)$data['curator_acct'],
				'name' => (string)$data['name'],
				'summary' => (string)($data['summary'] ?? ''),
				'topic' => (string)$data['topic'],
				'language' => (string)$data['language'],
				'url' => (string)($data['url'] ?? ''),
				'member_count' => (int)$data['member_count'],
			];
		}
		$cursor->closeCursor();

		return $packs;
	}

	/** @return StoredPack|null */
	public function getBySlug(string $slug): ?array {
		$qb = $this->getQueryBuilder();
		$qb->where($qb->expr()->eq('slug', $qb->createNamedParameter($slug)));

		return $this->one($qb);
	}

	/** @return StoredPack|null */
	public function getByUri(string $uri): ?array {
		$qb = $this->getQueryBuilder();
		$qb->where($qb->expr()->eq('uri_prim', $qb->createNamedParameter(md5($uri))));

		return $this->one($qb);
	}

	/**
	 * The packs of one origin but the ones named, for the reader that has
	 * just read that origin to remove what it no longer publishes.
	 *
	 * @param list<string> $keep the ids (`uri`) of the packs read this pass
	 *
	 * @return list<StalePack>
	 */
	public function staleOf(string $origin, array $keep): array {
		$qb = $this->getQueryBuilder();
		$qb->select('id', 'uri', 'curator', 'curator_acct')
			->from(self::TABLE_STARTER_PACKS)
			->where($qb->expr()->eq('origin', $qb->createNamedParameter($origin)));
		$kept = array_values(array_unique(array_map('md5', $keep)));
		if ($kept !== []) {
			$qb->andWhere($qb->expr()->notIn('uri_prim', $qb->createNamedParameter($kept, IQueryBuilder::PARAM_STR_ARRAY)));
		}

		$packs = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$packs[] = [
				'id' => (int)$data['id'],
				'uri' => (string)($data['uri'] ?? ''),
				'curator' => (string)($data['curator'] ?? ''),
				'curator_acct' => (string)$data['curator_acct'],
			];
		}
		$cursor->closeCursor();

		return $packs;
	}

	/** @param list<int> $ids */
	public function deleteByIds(array $ids): void {
		foreach (array_chunk(array_values(array_unique($ids)), self::CHUNK) as $chunk) {
			$qb = $this->getQueryBuilder();
			$qb->delete(self::TABLE_STARTER_PACKS)
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
	}

	public function deleteById(int $id): void {
		$this->deleteByIds([$id]);
	}

	private function idOf(string $prim): ?int {
		$qb = $this->getQueryBuilder();
		$qb->select('id')
			->from(self::TABLE_STARTER_PACKS)
			->where($qb->expr()->eq('uri_prim', $qb->createNamedParameter($prim)))
			->setMaxResults(1);
		$cursor = $qb->executeQuery();
		$id = $cursor->fetchOne();
		$cursor->closeCursor();

		return ($id === false || $id === null) ? null : (int)$id;
	}

	/** @return StoredPack|null the one row the condition already on `$qb` names */
	private function one(IQueryBuilder $qb): ?array {
		$qb->select('id', 'uri', 'slug', 'origin', 'curator', 'curator_acct', 'name', 'summary', 'topic', 'language', 'url', 'members', 'fetched')
			->from(self::TABLE_STARTER_PACKS)
			->setMaxResults(1);
		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return is_array($data) ? self::pack($data) : null;
	}

	/**
	 * @param array<string, mixed> $data
	 *
	 * @return StoredPack
	 */
	private static function pack(array $data): array {
		$members = json_decode((string)($data['members'] ?? ''), true);

		return [
			'id' => (int)$data['id'],
			'uri' => (string)($data['uri'] ?? ''),
			'slug' => (string)$data['slug'],
			'origin' => (string)$data['origin'],
			'curator' => (string)($data['curator'] ?? ''),
			'curator_acct' => (string)$data['curator_acct'],
			'name' => (string)$data['name'],
			'summary' => (string)($data['summary'] ?? ''),
			'topic' => (string)$data['topic'],
			'language' => (string)$data['language'],
			'url' => (string)($data['url'] ?? ''),
			'members' => is_array($members) ? array_values(array_map('strval', $members)) : [],
			'fetched' => (int)$data['fetched'],
		];
	}
}
