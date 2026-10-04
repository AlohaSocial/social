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
	 * How many uncached documents one pass may take on. Each one is an
	 * outbound HTTP request, so this is a bound on the cron slot rather than on
	 * the work: what is left over is the next run's.
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
			->setValue('resized_copy', $qb->createNamedParameter($document->getResizedCopy()))
			->setValue('blurhash', $qb->createNamedParameter($document->getBlurHash()))
			->setValue('size', $qb->createNamedParameter($document->getSizeBytes(), IQueryBuilder::PARAM_INT))
			->setValue('description', $qb->createNamedParameter($document->getDescription()))
			->setValue('parent_id', $qb->createNamedParameter($document->getParentId()))
			->setValue('parent_id_prim', $qb->createNamedParameter($qb->prim($document->getParentId())))
			->setValue('public', $qb->createNamedParameter(($document->isPublic()) ? '1' : '0'))
			->setValue('transcoded', $qb->createNamedParameter($document->getTranscoded(), IQueryBuilder::PARAM_INT));

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

		$qb->executeStatement();
		$document->setNid($qb->getLastInsertId());
	}

	/**
	 * Insert cache about an Actor in database.
	 */
	public function update(Document $document): void {
		$qb = $this->getCacheDocumentsUpdateSql();
		$qb->set('type', $qb->createNamedParameter($document->getType()))
			->set('url', $qb->createNamedParameter($document->getUrl()))
			->set('media_type', $qb->createNamedParameter($document->getMediaType()))
			->set('mime_type', $qb->createNamedParameter($document->getMimeType()))
			->set('error', $qb->createNamedParameter($document->getError()))
			->set('local_copy', $qb->createNamedParameter($document->getLocalCopy()))
			->set('resized_copy', $qb->createNamedParameter($document->getResizedCopy()))
			->set('blurhash', $qb->createNamedParameter($document->getBlurHash()))
			->set('size', $qb->createNamedParameter($document->getSizeBytes(), IQueryBuilder::PARAM_INT))
			->set('description', $qb->createNamedParameter($document->getDescription()))
			->set('parent_id', $qb->createNamedParameter($document->getParentId()))
			->set('parent_id_prim', $qb->createNamedParameter($qb->prim($document->getParentId())))
			->set('public', $qb->createNamedParameter(($document->isPublic()) ? '1' : '0'));

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
	 * Writes a poster frame back: the copy it was stored under, and the `meta`
	 * blob the duration and the dimensions ride in.
	 *
	 * Its own statement rather than `update()`, which rewrites `creation` --
	 * making a still for a two-year-old video would date the video to today --
	 * and which does not touch `meta` at all.
	 */
	public function updatePoster(Document $document): void {
		$qb = $this->getCacheDocumentsUpdateSql();
		$qb->limitToIdString($document->getId());
		$qb->set('resized_copy', $qb->createNamedParameter($document->getResizedCopy()));
		$qb->set('meta', $qb->createNamedParameter(json_encode($document->getMeta())));

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
	 * Writes a type that was worked out after the row was written.
	 *
	 * Its own statement rather than `update()`, which rewrites `creation` --
	 * recording what a two-year-old picture is would date it to today.
	 */
	public function updateMediaType(Document $document): void {
		$qb = $this->getCacheDocumentsUpdateSql();
		$qb->limitToIdString($document->getId());
		$qb->set('media_type', $qb->createNamedParameter($document->getMediaType()));
		$qb->set('mime_type', $qb->createNamedParameter($document->getMimeType()));

		$qb->executeStatement();
	}

	/**
	 * Stored documents whose type was never recorded.
	 *
	 * Only rows that hold bytes: the type is read back from the file, and the
	 * three sentinels (`avatar`, `header`, a streamed pointer) name no file.
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
					['', 'avatar', 'header', Document::COPY_STREAMED],
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
		$qb->set('resized_copy', $qb->createNamedParameter($document->getResizedCopy()));
		$qb->set('blurhash', $qb->createNamedParameter($document->getBlurHash()));
		$qb->set('size', $qb->createNamedParameter($document->getSizeBytes(), IQueryBuilder::PARAM_INT));
		$qb->set('description', $qb->createNamedParameter($document->getDescription()));
		$qb->set('error', $qb->createNamedParameter($document->getError()));
		// The mime type is sniffed from the downloaded bytes and this is the only
		// write that runs afterwards; without it the row keeps the empty type it
		// was created with and the copy is served with no Content-Type at all.
		$qb->set('mime_type', $qb->createNamedParameter($document->getMimeType()));
		$qb->set('media_type', $qb->createNamedParameter($document->getMediaType()));

		$qb->executeStatement();
	}

	/**
	 * @param string $url
	 *
	 * @return Document
	 * @throws CacheDocumentDoesNotExistException
	 */
	/**
	 * The document row behind a served copy, by the uuid `/media/{uuid}` exposes.
	 *
	 * @throws CacheDocumentDoesNotExistException
	 */
	public function getByLocalCopy(string $uuid): Document {
		$qb = $this->getCacheDocumentsSelectSql();
		$qb->limitToDBField('local_copy', $uuid, false);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new CacheDocumentDoesNotExistException();
		}

		return $this->parseCacheDocumentsSelectSql($data);
	}

	/**
	 * The document row by its own key.
	 *
	 * The one lookup a streamed document has: it holds no copy, so there is no
	 * uuid to find it by, and its ActivityPub id is somebody else's url --
	 * which is exactly what a media route must not accept from a caller.
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
	 * copy or its resized one.
	 *
	 * A preview link carries the resized uuid, so a lookup that knew only
	 * about `local_copy` could never serve one.
	 *
	 * @throws CacheDocumentDoesNotExistException
	 */
	public function getByCopy(string $uuid): Document {
		$qb = $this->getCacheDocumentsSelectSql();

		$expr = $qb->expr();
		$qb->andWhere(
			$expr->orX(
				$expr->eq('cd.local_copy', $qb->createNamedParameter($uuid)),
				$expr->eq('cd.resized_copy', $qb->createNamedParameter($uuid))
			)
		);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new CacheDocumentDoesNotExistException();
		}

		return $this->parseCacheDocumentsSelectSql($data);
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
	 * The document row for an id.
	 *
	 * Unless `$public` is set this is an unscoped lookup: a document id names any
	 * row in the table, so anything that takes the id from a request must decide
	 * for itself whether the caller may see the result — see
	 * `DocumentService::getFromCacheAsViewer()`.
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
	 * How many stored copies an account has of its own files, by media type.
	 *
	 * One grouped count rather than a walk: the caller is an export size
	 * estimate, which runs before anything is read and must not cost a query per
	 * file. `account` is only ever set on what this instance stored for a local
	 * user -- an upload from the composer and a profile banner -- so this counts
	 * the user's own media and nothing federated.
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
	 *
	 * The same stored `size` the video quota reads, and for the same reason;
	 * streamed rows are somebody else's bytes and are left out.
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
	 * How many bytes of video one account is holding here.
	 *
	 * The stored `size`, not a walk of the files: this is asked on every video
	 * upload, and a `stat` per row would make the quota cost more than the
	 * thing it guards. A row written before that column existed carries 0 and
	 * is invisible here until the usage cron has been past it, which is the
	 * honest trade — see `MediaUsageService`, which fills them in as it walks.
	 *
	 * Streamed rows are excluded: those are somebody else's bytes on somebody
	 * else's server.
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
	 * Every account holding video here, most first.
	 *
	 * What an administrator actually wants to know when the disk is filling:
	 * who has the four hundred gigabytes. One query rather than one per
	 * account, and capped, because the answer is a page and not a report.
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
	 * Records how big a stored file turned out to be.
	 *
	 * For the rows written before there was a column to put it in: the usage
	 * walk is already stat-ing every one of them, so it fills them in as it
	 * goes and the quota becomes accurate after one pass rather than never.
	 */
	public function setSize(int|string $nid, int $size): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_CACHE_DOCUMENTS)
			->set('size', $qb->createNamedParameter($size, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('nid', $qb->createNamedParameter($nid)));

		$qb->executeStatement();
	}

	/**
	 * @return Document[]
	 * @throws Exception
	 */
	/**
	 * The documents waiting to be fetched, at most `CACHE_BATCH` of them.
	 *
	 * Capped because the caller does one outbound HTTP request per row, inside
	 * a cron slot: an instance that was offline for a day, or that has just
	 * followed a busy account, came back to a backlog it tried to fetch in a
	 * single pass. Its two siblings — `CacheActorsRequest::getRemoteActorsToUpdateDetails()`
	 * and `StreamQueueRequest::getStandby()` — were capped long ago and this one
	 * was missed. The rest of the backlog is picked up on the next run, which is
	 * what the other two do.
	 */
	public function getNotCachedDocuments(int $limit = self::CACHE_BATCH) {
		$qb = $this->getCacheDocumentsSelectSql();
		$qb->limitToDBFieldEmpty('local_copy');
		$qb->limitToCaching(self::CACHING_TIMEOUT);
		$qb->limitToDBFieldInt('error', 0);
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
	 * Videos stored here that have no poster frame.
	 *
	 * `resized_copy` is where a video's poster lives (see
	 * `CacheDocumentService::saveMediaFromTemp()`), so an empty one on a video
	 * with a `local_copy` means the file is here and the still was never made:
	 * an upload from before posters existed, or one made while the server had
	 * no ffmpeg. Both are fixable after the fact, which is what
	 * `occ social:media:posters` does.
	 *
	 * Keyset by `nid` rather than an offset: the rows being read are the rows
	 * being updated, so a paging query would walk past the ones it just fixed.
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
		// a streamed document has no bytes here to take a frame from: its
		// `local_copy` is the marker `stream`, not a uuid, and its poster is
		// the origin's own thumbnail or nothing
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
	 * Local videos that have not been through the transcoder yet.
	 *
	 * Narrowed to the ones with bytes here to convert: a streamed document's
	 * `local_copy` is the marker rather than a uuid, and a video on another
	 * server is not this instance's to re-encode — `account` names the local
	 * uploader and is empty on every row cached from a peer, which is also how
	 * the quota queries tell the two apart. Ordered and paged by `nid` so that
	 * a job which converts one file per run works through them without ever
	 * reading the same page twice.
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
		// a row written before this column existed is 0, which is the right
		// answer for both of the things it might be: a video that needs
		// converting, and one that is already an MP4 and will be marked as not
		// needing it the first time it is read
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
	 * Records what became of one video.
	 *
	 * Written whatever the outcome, including "not worth converting" and
	 * "tried and failed": a job that only recorded its successes would read
	 * the same unconvertible file on every run for ever.
	 */
	/**
	 * Local videos that have not been up the ladder yet.
	 *
	 * Narrowed the same way the transcoder's candidates are — bytes here, not
	 * a streamed marker — and to `video/mp4`, because a ladder is built from
	 * the format everything decodes and a `.mov` should go through the
	 * transcoder first. On an instance with the transcoder off, that means a
	 * non-MP4 upload never gets a ladder, which is the honest answer: there is
	 * no ladder to build from a file this server has decided not to touch.
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

	public function setTranscoded(int|string $nid, int $state): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_CACHE_DOCUMENTS)
			->set('transcoded', $qb->createNamedParameter($state, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('nid', $qb->createNamedParameter($nid)));

		$qb->executeStatement();
	}

	/**
	 * The converted file takes the place of the original.
	 *
	 * The things that change together, in one statement: what the file is,
	 * where it is, how big the video now is, and that whatever ladder was
	 * built from the old bytes is void. Apart they would be a window in which
	 * a document said `video/quicktime` about an MP4.
	 */
	public function replaceVideo(int|string $nid, string $localCopy, string $mediaType): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_CACHE_DOCUMENTS)
			->set('local_copy', $qb->createNamedParameter($localCopy))
			->set('media_type', $qb->createNamedParameter($mediaType))
			->set('mime_type', $qb->createNamedParameter($mediaType))
			->set('transcoded', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
			// the bytes have changed, so any ladder built from them is of a
			// video that no longer exists: back to nought, and the ladder job
			// rebuilds it and throws the old rungs away as it goes
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
	 * The documents attached to one parent — for an actor, its avatar (and a
	 * header, where one was stored as a document).
	 *
	 * Read before `deleteByParent()` by the caller that also owns the files:
	 * the row knows the file's name and the delete forgets it, so a delete
	 * that does not read first leaves the bytes in appdata for good.
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
	 * One page of every document row, with what `occ social:media:usage`
	 * needs to tell them apart, keyed by `nid` so the next page starts after
	 * the last row of this one.
	 *
	 * The join answers "is the parent a cached actor" — which is what makes
	 * a row an avatar rather than an attachment — and whether that actor is
	 * one of ours, without hydrating either side.
	 *
	 * `media_type` and `size` are here for the same walk's second job: filling
	 * in the size of a video row written before there was a column to put it
	 * in, which is what makes the per-account quota accurate after one pass
	 * rather than never. Two columns off a row already being read.
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
				// what the row *says* it is, which for a row written before
				// there was a column to say it in is 0
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
	 * The documents whose parent is gone, oldest rows first.
	 *
	 * A `parent_id` names one of three things — a post, a cached actor or a
	 * story — so "no row in any of the three" is the one safe reading of
	 * "nothing can refer to this file any more". The joins are all on indexed
	 * `_prim` columns.
	 *
	 * Rows with no parent are not candidates: for a local upload that says
	 * nothing, because nothing in this schema points from a post back at the
	 * attachment it shows.
	 *
	 * @return Document[]
	 */
	public function getOrphanedByParent(int $olderThanDays, int $limit): array {
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

	public function deleteByParent(string $parentId): void {
		$qb = $this->getCacheDocumentsDeleteSql();
		$qb->limitToDBField('parent_id_prim', $qb->prim($parentId));

		$qb->executeStatement();
	}

	public function moveAccount(string $actorId, string $newId): void {
		$qb = $this->getCacheDocumentsUpdateSql();
		$qb->set('parent_id', $qb->createNamedParameter($newId))
			->set('parent_id_prim', $qb->createNamedParameter($qb->prim($newId)));

		$qb->limitToDBField('parent_id_prim', $qb->prim($actorId));

		$qb->executeStatement();
	}
}
