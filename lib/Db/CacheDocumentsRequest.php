<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use Exception;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCP\DB\QueryBuilder\IQueryBuilder;

class CacheDocumentsRequest extends CacheDocumentsRequestBuilder {
	public const CACHING_TIMEOUT = 5; // 5 min

	/**
	 * How many uncached documents one page of the background prefetch reads
	 * (`RemoteMediaPrefetcher::prefetchPending()`). Each is an outbound HTTP
	 * request, so this bounds the cron slot; the rest is the next run's.
	 */
	public const CACHE_BATCH = 50;

	public function save(Document $document): void {
		$qb = $this->getCacheDocumentsInsertSql();
		$qb->setValue('id', $qb->createNamedParameter($document->getId()))
			->setValue('id_prim', $qb->createNamedParameter($qb->prim($document->getId())))
			->setValue('account', $qb->createNamedParameter($document->getAccount()))
			->setValue('type', $qb->createNamedParameter($document->getType()))
			->setValue('url', $qb->createNamedParameter($document->getUrl()))
			->setValue('media_type', $qb->createNamedParameter($document->getMediaType()))
			->setValue('mime_type', $qb->createNamedParameter($document->getMimeType()))
			->setValue('error', $qb->createNamedParameter($document->getError()))
			->setValue('local_copy', $qb->createNamedParameter($document->getLocalCopy()))
			->setValue('local_copy_prim', $qb->createNamedParameter(self::copyPrim($document->getLocalCopy())))
			->setValue('resized_copy', $qb->createNamedParameter($document->getResizedCopy()))
			->setValue('resized_copy_prim', $qb->createNamedParameter(self::copyPrim($document->getResizedCopy())))
			->setValue('blurhash', $qb->createNamedParameter($document->getBlurHash()))
			->setValue('size', $qb->createNamedParameter($document->getSizeBytes(), IQueryBuilder::PARAM_INT))
			->setValue('description', $qb->createNamedParameter($document->getDescription()))
			->setValue('parent_id', $qb->createNamedParameter($document->getParentId()))
			->setValue('parent_id_prim', $qb->createNamedParameter($qb->prim($document->getParentId())))
			->setValue('public', $qb->createNamedParameter(($document->isPublic()) ? '1' : '0'))
			->setValue('transcoded', $qb->createNamedParameter($document->getTranscoded(), IQueryBuilder::PARAM_INT))
			->setValue('ai_source', $qb->createNamedParameter($document->getAiSource(), IQueryBuilder::PARAM_INT));

		// generate Meta
		$document->convertToMediaAttachment();
		if ($document->getMeta() !== null) {
			$qb->setValue('meta', $qb->createNamedParameter(json_encode($document->getMeta())));
		}

		try {
			$qb->setValue(
				'creation',
				$qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)
			);
		} catch (Exception $e) {
		}

		if (Document::isStoredName($document->getLocalCopy())) {
			$qb->setValue('cached', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));
		}

		$qb->executeStatement();
		$document->setNid($qb->getLastInsertId());
	}

	/**
	 * Writes a cached document back.
	 */
	public function update(Document $document): void {
		$qb = $this->getCacheDocumentsUpdateSql();
		$qb->set('type', $qb->createNamedParameter($document->getType()))
			->set('url', $qb->createNamedParameter($document->getUrl()))
			->set('media_type', $qb->createNamedParameter($document->getMediaType()))
			->set('mime_type', $qb->createNamedParameter($document->getMimeType()))
			->set('error', $qb->createNamedParameter($document->getError()))
			->set('local_copy', $qb->createNamedParameter($document->getLocalCopy()))
			->set('local_copy_prim', $qb->createNamedParameter(self::copyPrim($document->getLocalCopy())))
			->set('resized_copy', $qb->createNamedParameter($document->getResizedCopy()))
			->set('resized_copy_prim', $qb->createNamedParameter(self::copyPrim($document->getResizedCopy())))
			->set('blurhash', $qb->createNamedParameter($document->getBlurHash()))
			->set('size', $qb->createNamedParameter($document->getSizeBytes(), IQueryBuilder::PARAM_INT))
			->set('description', $qb->createNamedParameter($document->getDescription()))
			->set('parent_id', $qb->createNamedParameter($document->getParentId()))
			->set('parent_id_prim', $qb->createNamedParameter($qb->prim($document->getParentId())))
			->set('public', $qb->createNamedParameter(($document->isPublic()) ? '1' : '0'))
			->set('ai_source', $qb->createNamedParameter($document->getAiSource(), IQueryBuilder::PARAM_INT));

		try {
			$qb->set(
				'creation',
				$qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)
			);
		} catch (Exception $e) {
		}

		$qb->limitToIdPrim($qb->prim($document->getId()));
		$qb->executeStatement();
	}

	/**
	 * @throws \OCP\DB\Exception
	 */
	public function updateDescription(Document $document): void {
		$qb = $this->getCacheDocumentsUpdateSql();
		$qb->limitToIdString($document->getId());
		$qb->set('description', $qb->createNamedParameter($document->getDescription()));

		$qb->executeStatement();
	}

	/**
	 * Writes a poster frame back: the copy it was stored under and the `meta`
	 * blob. Not `update()`, which rewrites `creation` and leaves `meta` alone.
	 */
	public function updatePoster(Document $document): void {
		$qb = $this->getCacheDocumentsUpdateSql();
		$qb->limitToIdString($document->getId());
		$qb->set('resized_copy', $qb->createNamedParameter($document->getResizedCopy()));
		$qb->set('resized_copy_prim', $qb->createNamedParameter(self::copyPrim($document->getResizedCopy())));
		$qb->set('meta', $qb->createNamedParameter(json_encode($document->getMeta())));

		$qb->executeStatement();
	}

	/**
	 * Writes an icon's rebuilt thumbnail (`CacheDocumentService::rebuildIconThumbnail()`):
	 * its name, which is new only where the icon had no resized copy, and the
	 * blurhash read off it. Leaves `creation` and `cached` as they are.
	 */
	public function updateThumbnail(Document $document): void {
		$qb = $this->getCacheDocumentsUpdateSql();
		$qb->limitToIdString($document->getId());
		$qb->set('resized_copy', $qb->createNamedParameter($document->getResizedCopy()));
		$qb->set('resized_copy_prim', $qb->createNamedParameter(self::copyPrim($document->getResizedCopy())));
		$qb->set('blurhash', $qb->createNamedParameter($document->getBlurHash()));

		$qb->executeStatement();
	}

	/**
	 * Writes the focal point back, which means rewriting the whole `meta` blob
	 * it rides in -- there is no column of its own to set.
	 */
	public function updateFocus(Document $document): void {
		$qb = $this->getCacheDocumentsUpdateSql();
		$qb->limitToIdString($document->getId());
		$qb->set('meta', $qb->createNamedParameter(json_encode($document->getMeta())));

		$qb->executeStatement();
	}

	/**
	 * Writes a type that was worked out after the row was written. Not
	 * `update()`, which would rewrite `creation` to today.
	 */
	public function updateMediaType(Document $document): void {
		$qb = $this->getCacheDocumentsUpdateSql();
		$qb->limitToIdString($document->getId());
		$qb->set('media_type', $qb->createNamedParameter($document->getMediaType()));
		$qb->set('mime_type', $qb->createNamedParameter($document->getMimeType()));

		$qb->executeStatement();
	}

	/**
	 * Stored documents whose type was never recorded. Only rows that hold
	 * bytes, since the type is read from the file.
	 *
	 * @return Document[]
	 */
	public function getWithoutMediaType(int $limit): array {
		$qb = $this->getCacheDocumentsSelectSql();
		$expr = $qb->expr();

		$qb->andWhere($expr->eq('cd.media_type', $qb->createNamedParameter('')))
			->andWhere($expr->notIn(
				'cd.local_copy',
				$qb->createNamedParameter(
					['', Document::COPY_LOCAL_AVATAR, 'header', Document::COPY_STREAMED],
					IQueryBuilder::PARAM_STR_ARRAY
				)
			));
		$qb->setMaxResults($limit);

		$documents = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$documents[] = $this->parseCacheDocumentsSelectSql($data);
		}
		$cursor->closeCursor();

		return $documents;
	}

	public function initCaching(Document $document): void {
		$qb = $this->getCacheDocumentsUpdateSql();
		$qb->limitToIdString($document->getId());

		try {
			$qb->set(
				'caching', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)
			);
		} catch (Exception $e) {
		}

		$qb->executeStatement();
	}

	/**
	 * @throws \OCP\DB\Exception
	 */
	public function endCaching(Document $document): void {
		$qb = $this->getCacheDocumentsUpdateSql();
		$qb->limitToIdString($document->getId());
		$qb->set('local_copy', $qb->createNamedParameter($document->getLocalCopy()));
		$qb->set('local_copy_prim', $qb->createNamedParameter(self::copyPrim($document->getLocalCopy())));
		$qb->set('resized_copy', $qb->createNamedParameter($document->getResizedCopy()));
		$qb->set('resized_copy_prim', $qb->createNamedParameter(self::copyPrim($document->getResizedCopy())));
		$qb->set('blurhash', $qb->createNamedParameter($document->getBlurHash()));
		$qb->set('size', $qb->createNamedParameter($document->getSizeBytes(), IQueryBuilder::PARAM_INT));
		$qb->set('description', $qb->createNamedParameter($document->getDescription()));
		$qb->set('error', $qb->createNamedParameter($document->getError()));
		// the mime type is sniffed from the downloaded bytes, and this is the
		// only write after that
		$qb->set('mime_type', $qb->createNamedParameter($document->getMimeType()));
		$qb->set('media_type', $qb->createNamedParameter($document->getMediaType()));
		// read off the downloaded bytes, like the mime, and stripped from them
		// a moment later: this write is the only record of it
		$qb->set('ai_source', $qb->createNamedParameter($document->getAiSource(), IQueryBuilder::PARAM_INT));
		// when the copy now on disk was written: the age the media cache
		// purge goes by, and null where nothing was stored
		$qb->set('cached', Document::isStoredName($document->getLocalCopy())
			? $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)
			: $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL));

		$qb->executeStatement();
	}

	/**
	 * The document row behind a served copy, by the uuid `/media/{uuid}` exposes.
	 *
	 * @throws CacheDocumentDoesNotExistException
	 */
	public function getByLocalCopy(string $uuid): Document {
		$data = $this->firstByCopyPrim('local_copy_prim', $uuid);
		if ($data === null) {
			throw new CacheDocumentDoesNotExistException();
		}

		return $this->parseCacheDocumentsSelectSql($data);
	}

	/**
	 * The document row by its own key: the only lookup a streamed document
	 * has, since it holds no copy and its ActivityPub id is a remote URL a
	 * media route must not accept.
	 *
	 * @throws CacheDocumentDoesNotExistException
	 */
	public function getByNid(int|string $nid): Document {
		$qb = $this->getCacheDocumentsSelectSql();
		// an autoincrement rather than a snowflake, but a route hands it over
		// as the string it was in the url, which limitToDBFieldInt() refuses
		$qb->limitToNid($nid);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new CacheDocumentDoesNotExistException();
		}

		return $this->parseCacheDocumentsSelectSql($data);
	}

	/**
	 * The document a media uuid belongs to, whether the uuid names its full
	 * copy or its resized one (as a preview link does). Two indexed lookups
	 * rather than one `OR`, the full copy first.
	 *
	 * @throws CacheDocumentDoesNotExistException
	 */
	public function getByCopy(string $uuid): Document {
		$data = $this->firstByCopyPrim('local_copy_prim', $uuid)
			?? $this->firstByCopyPrim('resized_copy_prim', $uuid);
		if ($data === null) {
			throw new CacheDocumentDoesNotExistException();
		}

		return $this->parseCacheDocumentsSelectSql($data);
	}

	/**
	 * @param 'local_copy_prim'|'resized_copy_prim' $column
	 * @return ?array<string, mixed> the first row whose copy in that column is `$copy`
	 */
	private function firstByCopyPrim(string $column, string $copy): ?array {
		if ($copy === '') {
			return null;
		}

		$qb = $this->getCacheDocumentsSelectSql();
		$qb->limitToDBField($column, self::copyPrim($copy));
		$qb->setMaxResults(1);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return ($data === false) ? null : $data;
	}

	public function getByUrl(string $url) {
		$qb = $this->getCacheDocumentsSelectSql();
		$qb->limitToUrl($url);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new CacheDocumentDoesNotExistException();
		}

		return $this->parseCacheDocumentsSelectSql($data);
	}

	/**
	 * One attachment at this URL which has a permanent media error and no
	 * local copy. Duplicate posts can reference the same URL; filtering here
	 * avoids choosing an already-cached sibling before the failed row.
	 *
	 * @throws CacheDocumentDoesNotExistException
	 */
	public function getFailedUncachedByUrl(string $url): Document {
		$qb = $this->getCacheDocumentsSelectSql();
		$qb->limitToUrl($url);
		$qb->andWhere($qb->expr()->gt('cd.error', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		$qb->limitToDBFieldEmpty('local_copy');
		$qb->setMaxResults(1);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new CacheDocumentDoesNotExistException();
		}

		return $this->parseCacheDocumentsSelectSql($data);
	}

	/**
	 * @param array $mediaIds
	 * @param string $account - limit to account
	 *
	 * @return Document[]
	 */
	public function getFromArray(array $mediaIds, string $account = ''): array {
		$qb = $this->getCacheDocumentsSelectSql();
		$qb->limitToDBFieldArray('nid', $mediaIds);
		$qb->limitToAccount($account);

		$documents = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$documents[] = $this->parseCacheDocumentsSelectSql($data);
		}
		$cursor->closeCursor();

		return $documents;
	}

	/**
	 * The document row for an id. Unless `$public` is set this is unscoped,
	 * so a caller taking the id from a request must check visibility itself
	 * (see `DocumentService::getFromCacheAsViewer()`).
	 *
	 * @return Document
	 * @throws CacheDocumentDoesNotExistException
	 */
	public function getById(string $id, bool $public = false) {
		$qb = $this->getCacheDocumentsSelectSql();
		$qb->limitToIdPrim($qb->prim($id));

		if ($public === true) {
			$qb->limitToPublic();
		}

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new CacheDocumentDoesNotExistException();
		}

		return $this->parseCacheDocumentsSelectSql($data);
	}

	/**
	 * @param Document $item
	 *
	 * @return bool
	 */
	public function isDuplicate(Document $item): bool {
		try {
			$this->getByUrlAndParent($item->getUrl(), $item->getParentId());
			return true;
		} catch (CacheDocumentDoesNotExistException) {
			return false;
		}
	}

	/** Find the cached row belonging to this post, not another use of the URL. */
	public function getByUrlAndParent(string $url, string $parentId): Document {
		$qb = $this->getCacheDocumentsSelectSql();
		$qb->limitToUrl($url);
		$qb->limitToParentId($parentId);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new CacheDocumentDoesNotExistException();
		}

		return $this->parseCacheDocumentsSelectSql($data);
	}

	/**
	 * How many stored copies an account has of its own files, by media type,
	 * in one grouped count for the export size estimate. `account` is only
	 * set on a local user's own uploads, so nothing federated is counted.
	 *
	 * @return array<string, int> media type => how many rows
	 */
	public function countLocalCopiesByType(string $account): array {
		if ($account === '') {
			return [];
		}

		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();
		$qb->select('media_type')
			->selectAlias($qb->createFunction('COUNT(*)'), 'count')
			->from(self::TABLE_CACHE_DOCUMENTS)
			->where($expr->eq('account', $qb->createNamedParameter($account)))
			->andWhere($expr->neq('local_copy', $qb->createNamedParameter('')))
			->groupBy('media_type');

		$counts = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$counts[(string)$data['media_type']] = (int)$data['count'];
		}
		$cursor->closeCursor();

		return $counts;
	}

	/**
	 * How many bytes of media, of any type, one account has uploaded here.
	 * Streamed rows are somebody else's bytes and are left out.
	 */
	public function localBytesOf(string $account): int {
		return $this->localBytesByAccount([$account])[$account] ?? 0;
	}

	/**
	 * The bytes each of these accounts has uploaded, in one query.
	 *
	 * @param string[] $accounts
	 * @return array<string, int> account => bytes, for the accounts holding any
	 */
	public function localBytesByAccount(array $accounts): array {
		$accounts = array_values(array_unique(array_filter($accounts, static fn (string $a): bool => $a !== '')));
		if ($accounts === []) {
			return [];
		}

		$bytes = [];
		foreach (array_chunk($accounts, 500) as $chunk) {
			$qb = $this->getQueryBuilder();
			$expr = $qb->expr();
			$qb->select('account')
				->selectAlias($qb->func()->sum('size'), 'total')
				->from(self::TABLE_CACHE_DOCUMENTS)
				->where($expr->in('account', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)))
				->andWhere($expr->neq('local_copy', $qb->createNamedParameter('')))
				->andWhere($expr->neq('local_copy', $qb->createNamedParameter(Document::COPY_STREAMED)))
				->groupBy('account');

			$cursor = $qb->executeQuery();
			while ($data = $cursor->fetch()) {
				$bytes[(string)$data['account']] = (int)($data['total'] ?? 0);
			}
			$cursor->closeCursor();
		}

		return $bytes;
	}

	/**
	 * How many bytes of video one account is holding here, from the stored
	 * `size` rather than a `stat` per file, since this runs on every upload.
	 * Rows not yet sized count 0 until `MediaUsageService` fills them in.
	 * Streamed rows are excluded.
	 */
	public function videoBytesOf(string $account): int {
		if ($account === '') {
			return 0;
		}

		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();
		$qb->selectAlias($qb->func()->sum('size'), 'total')
			->from(self::TABLE_CACHE_DOCUMENTS)
			->where($expr->eq('account', $qb->createNamedParameter($account)))
			->andWhere($expr->like('media_type', $qb->createNamedParameter('video/%')))
			->andWhere($expr->neq('local_copy', $qb->createNamedParameter('')))
			->andWhere($expr->neq('local_copy', $qb->createNamedParameter(Document::COPY_STREAMED)));

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return (int)($data['total'] ?? 0);
	}

	/**
	 * Every account holding video here, most first, in one capped query.
	 *
	 * @return array<array{account: string, bytes: int, files: int}>
	 */
	public function videoBytesByAccount(int $limit = 50): array {
		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();
		$qb->select('account')
			->selectAlias($qb->func()->sum('size'), 'total')
			->selectAlias($qb->createFunction('COUNT(*)'), 'files')
			->from(self::TABLE_CACHE_DOCUMENTS)
			->where($expr->like('media_type', $qb->createNamedParameter('video/%')))
			->andWhere($expr->neq('local_copy', $qb->createNamedParameter('')))
			->andWhere($expr->neq('local_copy', $qb->createNamedParameter(Document::COPY_STREAMED)))
			->andWhere($expr->neq('account', $qb->createNamedParameter('')))
			->groupBy('account')
			->orderBy('total', 'desc')
			->setMaxResults($limit);

		$rows = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$rows[] = [
				'account' => (string)$data['account'],
				'bytes' => (int)($data['total'] ?? 0),
				'files' => (int)($data['files'] ?? 0),
			];
		}
		$cursor->closeCursor();

		return $rows;
	}

	/**
	 * Records how big a stored file is, for rows written without a size; the
	 * usage walk fills them in as it goes.
	 */
	public function setSize(int|string $nid, int $size): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_CACHE_DOCUMENTS)
			->set('size', $qb->createNamedParameter($size, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('nid', $qb->createNamedParameter($nid)));

		$qb->executeStatement();
	}

	/**
	 * The documents waiting to be fetched, newest first, at most `$limit` of
	 * them, since each is an outbound request inside a cron slot.
	 * `local_copy_prim` is the indexed key of `local_copy` and is empty exactly
	 * when the copy name is. `$before` pages on: only documents older than that nid.
	 *
	 * @return Document[]
	 */
	public function getNotCachedDocuments(int $limit = self::CACHE_BATCH, int $before = 0): array {
		$qb = $this->getCacheDocumentsSelectSql();
		$alias = $qb->getDefaultSelectAlias();
		$qb->limitToDBFieldEmpty('local_copy_prim');
		$qb->limitToCaching(self::CACHING_TIMEOUT);
		$qb->limitToDBFieldInt('error', 0);
		if ($before > 0) {
			$qb->andWhere($qb->expr()->lt($alias . '.nid', $qb->createNamedParameter($before, IQueryBuilder::PARAM_INT)));
		}
		$qb->orderBy($alias . '.nid', 'desc');
		if ($limit > 0) {
			$qb->setMaxResults($limit);
		}

		$documents = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$documents[] = $this->parseCacheDocumentsSelectSql($data);
		}
		$cursor->closeCursor();

		return $documents;
	}

	/**
	 * Marks a document as being cached, unless it no longer waits for that:
	 * it has a copy or a recorded refusal by now, or another worker marked it
	 * within `CACHING_TIMEOUT`. One statement, so two workers that read the
	 * same row cannot both take it.
	 *
	 * @return bool whether this caller took it
	 */
	public function claimForCaching(Document $document): bool {
		$qb = $this->getCacheDocumentsUpdateSql();
		$qb->set('caching', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));
		$qb->limitToIdPrim($qb->prim($document->getId()));
		$qb->limitToDBFieldEmpty('local_copy_prim');
		$qb->limitToDBFieldInt('error', 0);
		$qb->limitToCaching(self::CACHING_TIMEOUT);

		return $qb->executeStatement() > 0;
	}

	/**
	 * Videos stored here that have no poster frame: a `local_copy` with an
	 * empty `resized_copy`, which is where a video's poster lives. Fixed by
	 * `occ social:media:posters`. Keyset by `nid`, since the rows read are the
	 * rows being updated.
	 *
	 * @param int $limit how many to return
	 * @param int|string $after only documents past this nid
	 *
	 * @return Document[]
	 */
	public function getVideosWithoutPoster(int $limit, int|string $after = '0'): array {
		$qb = $this->getCacheDocumentsSelectSql();
		$alias = $qb->getDefaultSelectAlias();
		$expr = $qb->expr();

		$qb->andWhere($expr->like($alias . '.media_type', $qb->createNamedParameter('video/%')));
		$qb->andWhere($expr->neq($alias . '.local_copy', $qb->createNamedParameter('')));
		// a streamed document has no bytes here to take a frame from
		$qb->andWhere($expr->neq($alias . '.local_copy', $qb->createNamedParameter(Document::COPY_STREAMED)));
		$qb->limitToDBFieldEmpty('resized_copy');
		$qb->andWhere($expr->gt($alias . '.nid', $qb->createNamedParameter($after)));
		$qb->orderBy($alias . '.nid', 'asc');
		$qb->setMaxResults($limit);

		$documents = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$documents[] = $this->parseCacheDocumentsSelectSql($data);
		}
		$cursor->closeCursor();

		return $documents;
	}

	/**
	 * Whether a document is the icon of a cached actor: its parent is a row of
	 * `social_cache_actor` whose `icon_id` names it. Looked up by the actor's
	 * primary key, which is indexed.
	 */
	public function isActorIcon(Document $document): bool {
		if ($document->getParentId() === '' || $document->getId() === '') {
			return false;
		}

		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();
		$qb->select('ca.id_prim')
			->from(self::TABLE_CACHE_ACTORS, 'ca')
			->where($expr->eq('ca.id_prim', $qb->createNamedParameter($qb->prim($document->getParentId()))))
			->andWhere($expr->eq('ca.icon_id', $qb->createNamedParameter($qb->prim($document->getId()))))
			->setMaxResults(1);

		$cursor = $qb->executeQuery();
		$found = $cursor->fetch() !== false;
		$cursor->closeCursor();

		return $found;
	}

	/**
	 * The cached icons of remote actors whose files are on disk, a page at a
	 * time after the nid `$after`, for `AvatarThumbnailBackfill`.
	 *
	 * @return Document[]
	 */
	public function getCachedActorIcons(int $limit, int|string $after = '0'): array {
		$qb = $this->getCacheDocumentsSelectSql();
		$alias = $qb->getDefaultSelectAlias();
		$expr = $qb->expr();

		$qb->innerJoin($alias, self::TABLE_CACHE_ACTORS, 'ca', $expr->andX(
			$expr->eq('ca.id_prim', $alias . '.parent_id_prim'),
			$expr->eq('ca.icon_id', $alias . '.id_prim')
		));
		$qb->andWhere($expr->eq($alias . '.account', $qb->createNamedParameter('')));
		$qb->andWhere($expr->isNotNull($alias . '.cached'));
		$qb->andWhere($expr->like($alias . '.media_type', $qb->createNamedParameter('image/%')));
		$qb->andWhere($expr->gt($alias . '.nid', $qb->createNamedParameter($after)));
		$qb->orderBy($alias . '.nid', 'asc');
		$qb->setMaxResults($limit);

		$documents = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$documents[] = $this->parseCacheDocumentsSelectSql($data)->setActorIcon(true);
		}
		$cursor->closeCursor();

		return $documents;
	}

	/**
	 * Local video and sound uploads with bytes stored here, a page at a time
	 * after the nid `$after`, for `occ social:media:strip-metadata`.
	 *
	 * @return Document[]
	 */
	public function getLocalMediaToStrip(int $limit, int|string $after = '0'): array {
		$qb = $this->getCacheDocumentsSelectSql();
		$alias = $qb->getDefaultSelectAlias();
		$expr = $qb->expr();

		$qb->andWhere($expr->orX(
			$expr->like($alias . '.media_type', $qb->createNamedParameter('video/%')),
			$expr->like($alias . '.media_type', $qb->createNamedParameter('audio/%'))
		));
		$qb->andWhere($expr->neq($alias . '.account', $qb->createNamedParameter('')));
		$qb->andWhere($expr->neq($alias . '.local_copy', $qb->createNamedParameter('')));
		$qb->andWhere($expr->neq($alias . '.local_copy', $qb->createNamedParameter(Document::COPY_STREAMED)));
		$qb->andWhere($expr->gt($alias . '.nid', $qb->createNamedParameter($after)));
		$qb->orderBy($alias . '.nid', 'asc');
		$qb->setMaxResults($limit);

		$documents = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$documents[] = $this->parseCacheDocumentsSelectSql($data);
		}
		$cursor->closeCursor();

		return $documents;
	}

	/**
	 * Points a document at a rewritten copy of the same file, of the same
	 * type: a new local copy and its size. A ladder built from the old bytes
	 * is rebuilt from the new ones.
	 */
	public function replaceLocalCopy(int|string $nid, string $localCopy, int $size): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_CACHE_DOCUMENTS)
			->set('local_copy', $qb->createNamedParameter($localCopy))
			->set('local_copy_prim', $qb->createNamedParameter(self::copyPrim($localCopy)))
			->set('size', $qb->createNamedParameter($size, IQueryBuilder::PARAM_INT))
			->set('laddered', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('nid', $qb->createNamedParameter($nid)));

		$qb->executeStatement();
	}

	/**
	 * Local videos that have not been through the transcoder yet: rows with
	 * bytes here and an `account` (empty on rows cached from a peer). Paged
	 * by `nid`.
	 *
	 * @return Document[]
	 */
	public function getVideosToTranscode(int $limit = 5, int|string $after = '0'): array {
		$qb = $this->getCacheDocumentsSelectSql();
		$alias = $qb->getDefaultSelectAlias();
		$expr = $qb->expr();

		$qb->andWhere($expr->like($alias . '.media_type', $qb->createNamedParameter('video/%')));
		$qb->andWhere($expr->neq($alias . '.account', $qb->createNamedParameter('')));
		$qb->andWhere($expr->neq($alias . '.local_copy', $qb->createNamedParameter('')));
		$qb->andWhere($expr->neq($alias . '.local_copy', $qb->createNamedParameter(Document::COPY_STREAMED)));
		// 0 is right for both things an unmarked row can be: a video needing
		// conversion, and an MP4 that is marked as not needing it when read
		$qb->andWhere($expr->orX(
			$expr->eq($alias . '.transcoded', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)),
			$expr->isNull($alias . '.transcoded')
		));
		$qb->andWhere($expr->gt($alias . '.nid', $qb->createNamedParameter($after)));
		$qb->orderBy($alias . '.nid', 'asc');
		$qb->setMaxResults($limit);

		$documents = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$documents[] = $this->parseCacheDocumentsSelectSql($data);
		}
		$cursor->closeCursor();

		return $documents;
	}

	/**
	 * Local videos that have not been up the ladder yet: narrowed as the
	 * transcoder's candidates are, and to `video/mp4`, since a ladder is
	 * built from the format everything decodes. With the transcoder off, a
	 * non-MP4 upload gets no ladder.
	 *
	 * @return Document[]
	 */
	public function getVideosToLadder(int $limit = 5, int|string $after = '0'): array {
		$qb = $this->getCacheDocumentsSelectSql();
		$alias = $qb->getDefaultSelectAlias();
		$expr = $qb->expr();

		$qb->andWhere($expr->eq($alias . '.media_type', $qb->createNamedParameter('video/mp4')));
		$qb->andWhere($expr->neq($alias . '.local_copy', $qb->createNamedParameter('')));
		$qb->andWhere($expr->neq($alias . '.local_copy', $qb->createNamedParameter(Document::COPY_STREAMED)));
		$qb->andWhere($expr->orX(
			$expr->eq($alias . '.laddered', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)),
			$expr->isNull($alias . '.laddered')
		));
		$qb->andWhere($expr->gt($alias . '.nid', $qb->createNamedParameter($after)));
		$qb->orderBy($alias . '.nid', 'asc');
		$qb->setMaxResults($limit);

		$documents = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$documents[] = $this->parseCacheDocumentsSelectSql($data);
		}
		$cursor->closeCursor();

		return $documents;
	}

	/** Remembers that a video has been up the ladder, or will not be. */
	public function setLaddered(int|string $nid, int $state): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_CACHE_DOCUMENTS)
			->set('laddered', $qb->createNamedParameter($state, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('nid', $qb->createNamedParameter($nid)));

		$qb->executeStatement();
	}

	/**
	 * Records what became of one video, including "not worth converting" and
	 * "failed", so the job does not retry the same file on every run.
	 */
	public function setTranscoded(int|string $nid, int $state): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_CACHE_DOCUMENTS)
			->set('transcoded', $qb->createNamedParameter($state, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('nid', $qb->createNamedParameter($nid)));

		$qb->executeStatement();
	}

	/**
	 * The converted file takes the place of the original. Type, location, size
	 * and the ladder state change in one statement, so the row never describes
	 * the old file with the new bytes.
	 */
	public function replaceVideo(int|string $nid, string $localCopy, string $mediaType): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_CACHE_DOCUMENTS)
			->set('local_copy', $qb->createNamedParameter($localCopy))
			->set('local_copy_prim', $qb->createNamedParameter(self::copyPrim($localCopy)))
			->set('media_type', $qb->createNamedParameter($mediaType))
			->set('mime_type', $qb->createNamedParameter($mediaType))
			->set('transcoded', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
			// the bytes have changed, so the ladder job rebuilds the ladder
			->set('laddered', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('nid', $qb->createNamedParameter($nid)));

		$qb->executeStatement();
	}

	/**
	 * @param string $url
	 */
	public function deleteByUrl(string $url) {
		$qb = $this->getCacheDocumentsDeleteSql();
		$qb->limitToUrl($url);

		$qb->executeStatement();
	}

	/**
	 * @param string $id
	 */
	public function deleteById(string $id) {
		$qb = $this->getCacheDocumentsDeleteSql();
		$qb->limitToIdString($id);

		$qb->executeStatement();
	}

	/**
	 * Makes one previously refused remote attachment eligible for an explicit
	 * administrator retry. Only an errored row without stored bytes is changed;
	 * a healthy cache entry and any local upload are left alone.
	 */
	public function resetRemoteErrorForRetry(string $id): bool {
		$qb = $this->getCacheDocumentsUpdateSql();
		$qb->set('error', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->set('caching', $qb->createNamedParameter(new DateTime('@0'), IQueryBuilder::PARAM_DATE))
			->limitToIdString($id)
			->andWhere($qb->expr()->gt('error', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('local_copy', $qb->createNamedParameter('')));

		return $qb->executeStatement() > 0;
	}

	/**
	 * The documents attached to one parent: for an actor, its avatar (and a
	 * header, where one was stored as a document). Read before
	 * `deleteByParent()`, since the delete forgets the file names.
	 *
	 * @return Document[]
	 */
	public function getByParent(string $parentId): array {
		$qb = $this->getCacheDocumentsSelectSql();
		$qb->limitToDBField('parent_id_prim', $qb->prim($parentId));

		$documents = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$documents[] = $this->parseCacheDocumentsSelectSql($data);
		}
		$cursor->closeCursor();

		return $documents;
	}

	/**
	 * One page of every document row for `occ social:media:usage`, keyed by
	 * `nid`. The join says whether the parent is a cached actor (an avatar)
	 * and whether that actor is local; `media_type` and `size` let the same
	 * walk fill in missing sizes.
	 *
	 * @return list<array{nid: string, id: string, url: string, account: string, local_copy: string, resized_copy: string, media_type: string, size: int, actor_local: ?bool}>
	 */
	public function getUsagePage(int $limit, int|string $after = '0'): array {
		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();
		$qb->select('cd.nid', 'cd.id', 'cd.url', 'cd.account', 'cd.local_copy', 'cd.resized_copy')
			->addSelect('cd.media_type', 'cd.size')
			->selectAlias('ca.local', 'actor_local')
			->from(self::TABLE_CACHE_DOCUMENTS, 'cd')
			->leftJoin('cd', self::TABLE_CACHE_ACTORS, 'ca', $expr->eq('ca.id_prim', 'cd.parent_id_prim'))
			->where($expr->gt('cd.nid', $qb->createNamedParameter($after)))
			->orderBy('cd.nid', 'asc')
			->setMaxResults($limit);

		$rows = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$rows[] = [
				'nid' => (string)$data['nid'],
				'id' => (string)($data['id'] ?? ''),
				'url' => (string)($data['url'] ?? ''),
				'account' => (string)($data['account'] ?? ''),
				'local_copy' => (string)($data['local_copy'] ?? ''),
				'resized_copy' => (string)($data['resized_copy'] ?? ''),
				'media_type' => (string)($data['media_type'] ?? ''),
				// 0 on a row not yet sized
				'size' => (int)($data['size'] ?? 0),
				// NULL when the parent is not a cached actor; the boolean column
				// comes back as an int on MySQL and as a bool on PostgreSQL
				'actor_local' => ($data['actor_local'] === null) ? null : (bool)(int)$data['actor_local'],
			];
		}
		$cursor->closeCursor();

		return $rows;
	}

	/**
	 * The documents whose parent is gone, oldest rows first. A `parent_id`
	 * names a post, a cached actor or a short, so no row in any of the three
	 * means nothing refers to the file. Rows with no parent are uploads; see
	 * `getUnattachedUploads()`.
	 *
	 * @param int $afterNid where the page starts: after the last row of the
	 *                      one before, so a row that would not go is not read
	 *                      again at the head of every page
	 *
	 * @return Document[]
	 */
	public function getOrphanedByParent(int $olderThanDays, int $limit, int $afterNid = 0): array {
		$qb = $this->getCacheDocumentsSelectSql();
		$expr = $qb->expr();

		$qb->leftJoin('cd', self::TABLE_STREAM, 'st', $expr->eq('st.id_prim', 'cd.parent_id_prim'))
			->leftJoin('cd', self::TABLE_CACHE_ACTORS, 'ca', $expr->eq('ca.id_prim', 'cd.parent_id_prim'))
			->leftJoin('cd', self::TABLE_STORIES, 'so', $expr->eq('so.source_id_prim', 'cd.parent_id_prim'));

		$qb->andWhere($expr->neq('cd.parent_id_prim', $qb->createNamedParameter('')))
			->andWhere($expr->lt(
				'cd.creation',
				$qb->createNamedParameter(
					new DateTime('-' . max(1, $olderThanDays) . ' days'), IQueryBuilder::PARAM_DATE
				)
			))
			->andWhere($expr->isNull('st.id_prim'))
			->andWhere($expr->isNull('ca.id_prim'))
			->andWhere($expr->isNull('so.source_id_prim'));
		if ($afterNid > 0) {
			$qb->andWhere($expr->gt('cd.nid', $qb->createNamedParameter($afterNid, IQueryBuilder::PARAM_INT)));
		}

		$qb->orderBy('cd.nid', 'asc');
		$qb->setMaxResults($limit);

		$documents = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$documents[] = $this->parseCacheDocumentsSelectSql($data);
		}
		$cursor->closeCursor();

		return $documents;
	}

	/**
	 * The uploads of people here that nothing has taken up, oldest first: no
	 * parent, not in `social_stream_media` or `social_atproto_blob`, and older
	 * than `$olderThanDays`. A scheduled post's uploads are checked by the
	 * caller. Banners, short photos and imported attachments have a parent.
	 *
	 * @param int $afterNid where the page starts: after the last row of the
	 *                      one before
	 *
	 * @return Document[]
	 */
	public function getUnattachedUploads(int $olderThanDays, int $limit, int $afterNid = 0): array {
		$qb = $this->getCacheDocumentsSelectSql();
		$expr = $qb->expr();

		$qb->leftJoin('cd', self::TABLE_STREAM_MEDIA, 'sm', $expr->eq('sm.doc_nid', 'cd.nid'))
			->leftJoin('cd', self::TABLE_ATPROTO_BLOB, 'ab', $expr->eq('ab.document_id_prim', 'cd.id_prim'));

		$qb->andWhere($expr->neq('cd.account', $qb->createNamedParameter('')))
			->andWhere($expr->eq('cd.parent_id_prim', $qb->createNamedParameter('')))
			->andWhere($expr->neq('cd.local_copy', $qb->createNamedParameter(Document::COPY_STREAMED)))
			->andWhere($expr->lt(
				'cd.creation',
				$qb->createNamedParameter(
					new DateTime('-' . max(1, $olderThanDays) . ' days'), IQueryBuilder::PARAM_DATE
				)
			))
			->andWhere($expr->isNull('sm.doc_nid'))
			->andWhere($expr->isNull('ab.document_id_prim'));
		if ($afterNid > 0) {
			$qb->andWhere($expr->gt('cd.nid', $qb->createNamedParameter($afterNid, IQueryBuilder::PARAM_INT)));
		}

		$qb->orderBy('cd.nid', 'asc');
		$qb->setMaxResults($limit);

		$documents = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$documents[] = $this->parseCacheDocumentsSelectSql($data);
		}
		$cursor->closeCursor();

		return $documents;
	}

	/**
	 * One page of the cached copies of other servers' media, oldest copy
	 * first, for `MediaPurgeService::purgeCache()`: `cached` is set, `account`
	 * is empty, the origin URL is known and the row is not refused. Keyset on
	 * (`cached`, `nid`), so a dry run still moves forward. `actor` says the
	 * row is a cached actor's avatar or header.
	 *
	 * @param string $afterCached the `cached` of the last row of the previous
	 *                            page, '' for the first page
	 *
	 * @return list<array{nid: string, id: string, local_copy: string, resized_copy: string, cached: string, actor: bool}>
	 */
	public function getCachedRemotePage(int $limit, string $afterCached = '', int|string $afterNid = 0): array {
		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();
		$qb->select('cd.nid', 'cd.id', 'cd.local_copy', 'cd.resized_copy', 'cd.cached')
			->selectAlias('ca.id_prim', 'actor_prim')
			->from(self::TABLE_CACHE_DOCUMENTS, 'cd')
			->leftJoin('cd', self::TABLE_CACHE_ACTORS, 'ca', $expr->eq('ca.id_prim', 'cd.parent_id_prim'))
			->where($expr->isNotNull('cd.cached'))
			->andWhere($expr->eq('cd.account', $qb->createNamedParameter('')))
			->andWhere($expr->neq('cd.url', $qb->createNamedParameter('')))
			->andWhere($expr->orX(
				$expr->isNull('cd.error'),
				$expr->eq('cd.error', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			));

		if ($afterCached !== '') {
			$qb->andWhere($expr->orX(
				$expr->gt('cd.cached', $qb->createNamedParameter($afterCached)),
				$expr->andX(
					$expr->eq('cd.cached', $qb->createNamedParameter($afterCached)),
					$expr->gt('cd.nid', $qb->createNamedParameter((int)$afterNid, IQueryBuilder::PARAM_INT))
				)
			));
		}

		$qb->orderBy('cd.cached', 'asc')
			->addOrderBy('cd.nid', 'asc')
			->setMaxResults($limit);

		$rows = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$rows[] = [
				'nid' => (string)$data['nid'],
				'id' => (string)($data['id'] ?? ''),
				'local_copy' => (string)($data['local_copy'] ?? ''),
				'resized_copy' => (string)($data['resized_copy'] ?? ''),
				'cached' => (string)($data['cached'] ?? ''),
				'actor' => ($data['actor_prim'] ?? null) !== null,
			];
		}
		$cursor->closeCursor();

		return $rows;
	}

	/**
	 * Records that a row's copies were purged. The names stay: they are in
	 * every link this instance has served, and `DocumentService` fetches the
	 * file again under them when one is next asked for.
	 */
	public function markEvicted(int|string $nid): void {
		$qb = $this->getCacheDocumentsUpdateSql();
		$qb->set('cached', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL));
		$qb->limitToNid($nid);

		$qb->executeStatement();
	}

	public function deleteByParent(string $parentId): void {
		$qb = $this->getCacheDocumentsDeleteSql();
		$qb->limitToDBField('parent_id_prim', $qb->prim($parentId));

		$qb->executeStatement();
	}
}
