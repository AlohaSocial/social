<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use DateTimeZone;
use Exception;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\Details;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\MiscService;
use OCA\Social\Tools\Exceptions\DateTimeException;
use OCA\Social\Tools\Model\Cache;
use OCA\Social\Tools\Nid;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Class StreamRequest
 *
 * @package OCA\Social\Db
 */
class StreamRequest extends StreamRequestBuilder {
	use StreamTimelines;
	use StreamInterests;

	/** replies other people wrote to this account's posts */
	public const PARTNERS_INBOUND = 1;
	/** replies this account wrote to other people's posts */
	public const PARTNERS_OUTBOUND = 2;

	/**
	 * The accounts every post of which a moderator has marked sensitive, read
	 * once per request. Null until something is saved.
	 *
	 * @var string[]|null
	 */
	private ?array $forcedSensitive = null;

	/**
	 * The width of the random half of a nid.
	 *
	 * A nid is `published_time * NID_LIMIT + random`, which keeps it sortable by
	 * publication time -- cursor pagination relies on that -- while staying
	 * opaque within a second. It is also the primary key of social_stream, so a
	 * collision is a rejected insert, and save() used to swallow exactly that
	 * error: the post was silently lost.
	 *
	 * At the previous width of 1e6 the birthday bound put an even chance of a
	 * collision at about 1,200 posts sharing a second. 1e9 moves that to roughly
	 * 37,000, and published_time * 1e9 still fits a BIGINT for any date this app
	 * will see. Widening does not disturb the ordering of ids issued under the
	 * old width: every new nid is larger than every old one, and both halves
	 * stay monotonic in time.
	 */
	public const NID_LIMIT = 1000000000;

	/**
	 * How much wider than the page the fast home query reads.
	 *
	 * The filters it no longer carries — blocks, mutes, hidden boosts — are
	 * applied to the rows afterwards, so a page of twenty that loses three to
	 * a block would come back short. Three times the page is enough for any
	 * ordinary amount of blocking, and where it is not the page is simply
	 * shorter, which is what an infinite scroll already handles.
	 */
	private const HOME_OVERREAD = 3;

	/** ...but never an unbounded read, whatever limit was asked for. */
	private const HOME_OVERREAD_MAX = 300;

	/**
	 * How far back from its cursor a home page first reads, and how far each
	 * retry reaches, in seconds; a page still short after the last one reads
	 * without a bound. See `homeRecipientNids()`.
	 */
	private const HOME_WINDOWS = [86400, 7 * 86400, 30 * 86400, 365 * 86400];

	/**
	 * How many further windows a page that filtering emptied may read. See
	 * `refilledHomePage()`.
	 */
	private const HOME_REFILL_ROUNDS = 4;

	/**
	 * How much wider than the page a content search reads, and the ceiling on
	 * that. The rows the `LIKE` returns are candidates -- see
	 * `whoseTextCarries()` -- so a page read exactly to its limit would come
	 * back mostly empty.
	 */
	private const SEARCH_OVERREAD = 5;
	private const SEARCH_OVERREAD_MAX = 200;

	/**
	 * How deep a content search pages by `offset`. Every row skipped is a row
	 * read, so a page past this answers with nothing; `max_id` is the way
	 * further back.
	 */
	public const SEARCH_MAX_OFFSET = 400;

	/** Whether the recipient rows carry their post's nid yet; asked once per request. */
	private ?bool $recipientNidsFilled = null;

	/**
	 * Whether the last home page was chosen by the fast query, which carries
	 * none of the per-viewer filters — so the hydration has to apply them.
	 */
	private bool $homeServedFromRecipients = false;

	/** How many fresh nids to try before giving up on an insert. */
	private const NID_ATTEMPTS = 4;

	/** How many posts one pass of deleteByAuthor() removes. */
	public const DELETE_BATCH = 500;

	/**
	 * How far back the closed-poll sweep reads.
	 *
	 * Mastodon caps a poll at six months, so a poll published longer ago than
	 * that has closed already and is not news to anybody.
	 */
	public const POLL_LOOKBACK = 190 * 86400;

	public function __construct(
		IDBConnection $connection,
		LoggerInterface $logger,
		IURLGenerator $urlGenerator,
		private StreamDestRequest $streamDestRequest,
		private StreamTagsRequest $streamTagsRequest,
		ConfigService $configService,
		MiscService $miscService,
		private ModerationRequest $moderationRequest,
		private FediverseService $fediverseService,
		private CacheDocumentService $cacheDocumentService,
		private FollowedTagsRequest $followedTagsRequest,
		private ConversationsRequest $conversationsRequest,
		private RenditionsRequest $renditionsRequest,
		private FollowsRequest $followsRequest,
	) {
		parent::__construct($connection, $logger, $urlGenerator, $configService, $miscService);
	}

	public function save(Stream $stream): void {
		$this->applyForcedSensitive($stream);

		for ($attempt = 1; ; $attempt++) {
			$qb = $this->saveStream($stream);
			// every status kind, not only the plain `Note`: a poll is a
			// `Question`, which *is* a Note and carries hashtags, attachments
			// and a media kind like any other post. Comparing the type name
			// stored the poll with all four fields at their defaults, so a
			// poll never reached a hashtag timeline
			if ($stream instanceof Note) {
				$attachments = [];
				foreach ($stream->getAttachments() as $item) {
					$attachments[] = $item->asLocal(); // get attachment ready for local
				}

				$encoded = (string)json_encode($attachments, JSON_UNESCAPED_SLASHES);
				$qb->setValue('hashtags', $qb->createNamedParameter(json_encode($stream->getHashtags())))
					->setValue('attachments', $qb->createNamedParameter($encoded))
					// what kind of media this is, decided once here rather than
					// by searching the JSON above with `LIKE` on every read of
					// a Photos or Videos timeline
					->setValue('media_kind', $qb->createNamedParameter(
						Stream::mediaKindOf($encoded, $stream->getSubType())
					))
					// and whether it is news, decided here for the same reason:
					// the question is one no database can be asked of stored
					// markup on the way past
					->setValue('news_kind', $qb->createNamedParameter(
						Stream::newsKindOf($stream->getContent(), $stream->getSubType())
					));
			}

			try {
				// One transaction, because the three writes are one fact. The
				// recipient rows are what put a post in a timeline: a post
				// stored without them exists, is in nobody's timeline, and
				// nothing ever notices — `StreamDestRequest::create()` logs a
				// failure and carries on, so the partial state was silent as
				// well as permanent.
				$this->dbConnection->beginTransaction();

				try {
					$qb->executeStatement();

					$this->streamDestRequest->generateStreamDest($stream);
					$this->streamTagsRequest->generateStreamTags($stream);

					$this->dbConnection->commit();
				} catch (\Throwable $t) {
					$this->dbConnection->rollBack();

					throw $t;
				}

				return;
			} catch (DBException $e) {
				if ($e->getReason() !== DBException::REASON_CONSTRAINT_VIOLATION) {
					$this->logger->error("Couldn't save stream: " . $e->getMessage(), [
						'exception' => $e,
					]);

					return;
				}

				// Two different constraints reach here and they want opposite
				// things. A second save of the same status trips the unique
				// index on id_prim, and dropping it is right -- that is what
				// makes an inbox delivery idempotent. A nid collision trips the
				// primary key, and dropping that loses a post that was never
				// stored. The databases do not agree on how to tell the two
				// apart from the exception, so ask instead whether the status is
				// already there; if it is not, the clash was on the nid.
				if ($attempt >= self::NID_ATTEMPTS || $this->has($stream->getId())) {
					if ($attempt >= self::NID_ATTEMPTS) {
						$this->logger->error(
							'Could not find a free nid for stream ' . $stream->getId()
							. ' in ' . self::NID_ATTEMPTS . ' attempts; the post was not stored.',
							['exception' => $e]
						);
					}

					return;
				}

				// force saveStream() to draw a fresh one
				$stream->setNid('0');
			}
		}
	}

	/** Whether a status with this ActivityPub id is already stored. */
	private function has(string $id): bool {
		if ($id === '') {
			return false;
		}

		try {
			$qb = $this->getStreamSelectSql();
			$qb->limitToIdPrim($qb->prim($id));
			$this->getStreamFromRequest($qb);

			return true;
		} catch (StreamNotFoundException) {
			return false;
		}
	}

	public function update(Stream $stream, bool $generateDest = false): void {
		$qb = $this->getStreamUpdateSql();

		$qb->set('to', $qb->createNamedParameter($stream->getTo()));
		$qb->set(
			'cc', $qb->createNamedParameter(json_encode($stream->getCcArray(), JSON_UNESCAPED_SLASHES))
		);
		$qb->set(
			'to_array', $qb->createNamedParameter(json_encode($stream->getToArray(), JSON_UNESCAPED_SLASHES))
		);
		$qb->set('content', $qb->createNamedParameter($stream->getContent()));
		$qb->set('summary', $qb->createNamedParameter($stream->getSummary()));
		$qb->set('sensitive', $qb->createNamedParameter($stream->isSensitive() ? 1 : 0));
		$qb->set('source', $qb->createNamedParameter($stream->getSource()));
		// Only a local edit rebuilds the outbound paths. An incoming Update does
		// not carry our local transport metadata, so leave that column alone for
		// ordinary rewrites instead of erasing it with an empty array.
		if ($generateDest) {
			$qb->set('instances', $qb->createNamedParameter(
				json_encode($stream->getInstancePaths(), JSON_UNESCAPED_SLASHES)
			));
		}
		// the five fields an Update rewrites, in their own columns since
		// Version1000Date20260912000007. They are still inside the wire object
		// this same statement stores, and still read from there for a row
		// written before that step — but an edit that changed the language or
		// took an approval back has to change the column too, or the column and
		// the object it was copied from disagree from the next read on.
		$this->setPostFields($qb, $stream, false);
		if ($stream instanceof Note) {
			$encoded = (string)json_encode($stream->getAttachments(), JSON_UNESCAPED_SLASHES);
			$qb->set('hashtags', $qb->createNamedParameter(json_encode($stream->getHashtags(), JSON_UNESCAPED_SLASHES)));
			$qb->set('attachments', $qb->createNamedParameter($encoded));
			$qb->set('media_kind', $qb->createNamedParameter(
				Stream::mediaKindOf($encoded, $stream->getSubType())
			));
			// an edit that took the link out takes the post out of the News
			// timeline, and one that added a link puts it in: the column is
			// derived from the content this same statement is rewriting
			$qb->set('news_kind', $qb->createNamedParameter(
				Stream::newsKindOf($stream->getContent(), $stream->getSubType())
			));
		}
		$qb->set('published', $qb->createNamedParameter($stream->getPublished()));
		try {
			$dTime = new DateTime();
			$dTime->setTimestamp($stream->getPublishedTime());
			$qb->set('published_time', $qb->createNamedParameter($dTime, IQueryBuilder::PARAM_DATE));
		} catch (Exception $e) {
		}
		$qb->limitToIdPrim($qb->prim($stream->getId()));

		// One transaction, for the reason save() gives: the row, its recipients
		// and its tag rows are one fact. The tag rows are what put a post in a
		// hashtag timeline, and an edit rewrites the `hashtags` column without
		// them, so a tag added by an edit rendered as dead text and a tag taken
		// out left the post in that timeline for good.
		$this->dbConnection->beginTransaction();
		try {
			$qb->executeStatement();

			if ($generateDest) {
				$this->streamDestRequest->generateStreamDest($stream);
			}
			$this->streamTagsRequest->replaceStreamTags($stream);

			$this->dbConnection->commit();
		} catch (\Throwable $t) {
			$this->dbConnection->rollBack();

			throw $t;
		}
	}

	/**
	 * @param ?DateTime $countsAt when this instance last heard the origin's
	 *                            totals for the post, for a write that heard
	 *                            them; null leaves the schedule where it is
	 *
	 * @see \OCA\Social\Service\RemoteCountService
	 */
	public function updateDetails(Stream $stream, ?DateTime $countsAt = null): void {
		$qb = $this->getStreamUpdateSql();
		$qb->set('details', $qb->createNamedParameter(json_encode($stream->getDetailsAll())));

		if ($countsAt !== null) {
			$qb->set('counts_at', $qb->createNamedParameter($countsAt, IQueryBuilder::PARAM_DATE));
		}

		$qb->limitToIdPrim($qb->prim($stream->getId()));
		$qb->executeStatement();
	}

	/**
	 * Counts the replies to a post again and stores the total on it.
	 *
	 * A recount rather than a bump, because the two things that change it —
	 * a reply arriving and a reply being deleted — do not both know which way.
	 * `remote_replies` is what the post's own instance reported and is carried
	 * across untouched: nothing here can see the replies that live over there.
	 *
	 * @param string $inReplyTo the id of the post that was replied to
	 */
	public function recountReplies(string $inReplyTo): void {
		if ($inReplyTo === '') {
			return;
		}

		try {
			$parent = $this->getStreamById($inReplyTo);
		} catch (StreamNotFoundException $e) {
			return;
		}

		$parent->setDetailInt(
			Details::REPLIES, $parent->getDetailInt(Details::REMOTE_REPLIES) + $this->countRepliesTo($inReplyTo)
		);
		$this->updateDetails($parent);
	}

	/**
	 * The remote posts whose counts were last heard from their own server
	 * long enough ago to be worth asking again — including every one never
	 * asked, which has no `counts_at` at all.
	 *
	 * Oldest post first, so a pass that runs out of budget leaves the posts
	 * that have waited longest at the front of the next one rather than
	 * wherever they fell.
	 *
	 * @param DateTime $due not asked since
	 * @param int $limit 0 is every post that is due
	 *
	 * @return Stream[]
	 */
	public function getRemoteStreamsDueForCounts(DateTime $due, int $limit = 0): array {
		$qb = $this->getStreamSelectSql();
		$qb->limitToLocal(false);
		$qb->limitToDBFieldDateTime('counts_at', $due, true);
		$qb->orderBy('s.published_time', 'asc');

		if ($limit > 0) {
			$qb->setMaxResults($limit);
		}

		return $this->getStreamsFromRequest($qb);
	}

	/**
	 * Stamps a post as having been asked for its counts, leaving whatever
	 * they are stored as alone. Written even when the answer did not come, so
	 * that a host which is gone is asked once per interval rather than on
	 * every pass.
	 */
	public function markCountsRefreshed(string $id, DateTime $when): void {
		$qb = $this->getStreamUpdateSql();
		$qb->set('counts_at', $qb->createNamedParameter($when, IQueryBuilder::PARAM_DATE));
		$qb->limitToIdPrim($qb->prim($id));
		$qb->executeStatement();
	}

	public function updateCache(Stream $stream, Cache $cache): void {
		$qb = $this->getStreamUpdateSql();
		$qb->set('cache', $qb->createNamedParameter(json_encode($cache, JSON_UNESCAPED_SLASHES)));

		$qb->limitToIdPrim($qb->prim($stream->getId()));

		$qb->executeStatement();
	}

	/**
	 * The posts that carry a stored attachment copy, a page at a time.
	 *
	 * A post keeps its own copy of its attachments (`save()` writes
	 * `asLocal()` into the `attachments` column), so anything that changes a
	 * *document* after the fact -- a poster frame made for a video that was
	 * stored before posters existed -- never reaches the post that shows it.
	 * `occ social:media:posters` walks these and rewrites the copies.
	 *
	 * Only the id and the blob are read: rebuilding a whole `Stream` with its
	 * joins, for every post with a picture on the instance, would be a great
	 * deal of work to reach one column.
	 *
	 * @return array<array{nid: string, id: string, attachments: string}>
	 */
	public function getStoredAttachmentCopies(int $limit, int|string $after = '0'): array {
		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();
		$qb->select('nid', 'id', 'attachments')
			->from(self::TABLE_STREAM)
			->andWhere($expr->neq('attachments', $qb->createNamedParameter('')))
			->andWhere($expr->neq('attachments', $qb->createNamedParameter('[]')))
			->andWhere($expr->gt('nid', $qb->createNamedParameter($after)))
			->orderBy('nid', 'asc')
			->setMaxResults($limit);

		$rows = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$rows[] = [
				'nid' => (string)$data['nid'],
				'id' => (string)$data['id'],
				'attachments' => (string)$data['attachments'],
			];
		}
		$cursor->closeCursor();

		return $rows;
	}

	/**
	 * Whether one of `$actorId`'s posts carries the upload with this nid in
	 * its stored attachment copies. Narrowed by the author first, which is
	 * indexed, before the copies are matched.
	 */
	public function carriesUpload(string $actorId, string $nid): bool {
		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();
		$qb->select('s.nid')
			->from(self::TABLE_STREAM, 's')
			->setMaxResults(1);
		$qb->setDefaultSelectAlias('s');
		$qb->limitToAttributedTo($actorId, true);
		$qb->andWhere($expr->like('s.attachments', $qb->createNamedParameter(
			'%"id":"' . $this->dbConnection->escapeLikeParameter($nid) . '"%'
		)));

		$cursor = $qb->executeQuery();
		$found = $cursor->fetch() !== false;
		$cursor->closeCursor();

		return $found;
	}

	/** Replaces one post's stored attachment copies with the JSON given. */
	public function setStoredAttachmentCopies(string $id, string $attachments): void {
		$qb = $this->getStreamUpdateSql();
		$qb->set('attachments', $qb->createNamedParameter($attachments));
		$qb->limitToIdPrim($qb->prim($id));

		$qb->executeStatement();
	}

	/**
	 * Remote posts whose original ActivityPub object may still contain media
	 * even though the attachment column was written empty. The JSON is checked
	 * by the repair command; this bounded query only finds candidates.
	 *
	 * @return array<array{nid: string, id: string, source: string, subtype: string}>
	 */
	public function getMissingRemoteAttachments(int $limit, int|string $after = '0'): array {
		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();
		$qb->select('nid', 'id', 'source', 'subtype')
			->from(self::TABLE_STREAM)
			->where($expr->eq('local', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($expr->eq('attachments', $qb->createNamedParameter('[]')))
			->andWhere($expr->gt('nid', $qb->createNamedParameter($after)))
			->orderBy('nid', 'asc')
			->setMaxResults($limit);

		$rows = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$rows[] = [
				'nid' => (string)$data['nid'],
				'id' => (string)$data['id'],
				'source' => (string)$data['source'],
				'subtype' => (string)$data['subtype'],
			];
		}
		$cursor->closeCursor();

		return $rows;
	}

	/** Keep a concurrent import's attachments and update the Photos/Video index together. */
	public function setRecoveredRemoteAttachments(string $id, string $attachments, string $subtype): bool {
		$qb = $this->getStreamUpdateSql();
		$qb->set('attachments', $qb->createNamedParameter($attachments))
			->set('media_kind', $qb->createNamedParameter(Stream::mediaKindOf($attachments, $subtype)))
			->where($qb->expr()->eq('attachments', $qb->createNamedParameter('[]')));
		$qb->limitToIdPrim($qb->prim($id));

		return $qb->executeStatement() > 0;
	}

	public function updateAttachments(Document $document): void {
		$qb = $this->getStreamSelectSql();
		$qb->limitToIdPrim($qb->prim($document->getParentId()));

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			return;
		}

		$new = $this->updateAttachmentInList($document, $this->getArray('attachments', $data, []));
		$qb = $this->getStreamUpdateSql();
		$qb->set('attachments', $qb->createNamedParameter(json_encode($new, JSON_UNESCAPED_SLASHES)));
		$qb->limitToIdPrim($qb->prim($document->getParentId()));

		$qb->executeStatement();
	}

	/**
	 * Rebuilds the attachment copy of $document on every post of this
	 * instance that carries it.
	 *
	 * An upload is not tied to its post by `parent_id` the way a fetched
	 * attachment is — it is uploaded before the post exists — so the posts
	 * are found by the copy itself, which is keyed by the document's nid. The
	 * candidates are narrowed first by the indexed `media_kind` (a row the
	 * backfill has not reached is NULL there, and counts), and only this
	 * instance's own posts: a remote post names the origin's file, not ours.
	 *
	 * @return int how many posts were rewritten
	 */
	public function updateLocalAttachmentCopies(Document $document): int {
		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();
		$qb->select('id', 'attachments')
			->from(self::TABLE_STREAM)
			->where($expr->eq('local', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($expr->orX(
				$expr->in('media_kind', $qb->createNamedParameter(
					['video', Stream::MEDIA_KIND_MIXED], IQueryBuilder::PARAM_STR_ARRAY
				)),
				$expr->isNull('media_kind')
			))
			->andWhere($expr->like('attachments', $qb->createNamedParameter(
				'%"id":"' . (string)$document->getNid() . '"%'
			)));

		$rows = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$rows[] = $data;
		}
		$cursor->closeCursor();

		$rewritten = 0;
		foreach ($rows as $data) {
			$stored = json_decode((string)$data['attachments'], true);
			if (!is_array($stored)) {
				continue;
			}

			$new = $this->updateAttachmentInList($document, $stored);
			if ($new === $stored) {
				continue;
			}

			$this->setStoredAttachmentCopies(
				(string)$data['id'], json_encode($new, JSON_UNESCAPED_SLASHES)
			);
			$rewritten++;
		}

		return $rewritten;
	}

	/**
	 * The post's stored attachment copies with the one for $document rebuilt
	 * from it.
	 *
	 * The copies are the client format `save()` writes, keyed by the
	 * document's nid, not cache rows: read back as `Document`s they matched
	 * nothing, and the whole list was written out again in the ActivityPub
	 * shape, without ids, previews or alt text. The picture the cron had just
	 * fetched kept its empty link, and every other picture on the post lost
	 * its preview with it. The copies that are not this document's are kept
	 * exactly as stored.
	 *
	 * @return array<mixed>
	 */
	private function updateAttachmentInList(Document $document, array $attachments): array {
		$nid = (string)$document->getNid();

		$new = [];
		foreach ($attachments as $attachment) {
			if (is_array($attachment) && (string)($attachment['id'] ?? '') === $nid) {
				$new[] = $document->convertToMediaAttachment($this->urlGenerator)->asLocal();
			} else {
				$new[] = $attachment;
			}
		}

		return $new;
	}

	public function updateAttributedTo(string $itemId, string $to): void {
		$qb = $this->getStreamUpdateSql();
		$qb->set('attributed_to', $qb->createNamedParameter($to));
		$qb->set('attributed_to_prim', $qb->createNamedParameter($qb->prim($to)));

		$qb->limitToIdPrim($qb->prim($itemId));

		$qb->executeStatement();
	}

	/**
	 * @param string $type
	 *
	 * @return Stream[]
	 */
	public function getAll(string $type = ''): array {
		$qb = $this->getStreamSelectSql();

		if ($type !== '') {
			$qb->limitToType($type);
		}

		return $this->getStreamsFromRequest($qb);
	}

	/**
	 * @param string $id
	 * @param bool $asViewer
	 * @param int $format
	 *
	 * @return Stream
	 * @throws StreamNotFoundException
	 */
	public function getStreamById(
		string $id,
		bool $asViewer = false,
		int $format = ACore::FORMAT_ACTIVITYPUB,
	): Stream {
		if ($id === '') {
			throw new StreamNotFoundException();
		};

		$qb = $this->getStreamSelectSql($format);
		$qb->limitToIdPrim($qb->prim($id));
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');

		if ($asViewer) {
			$qb->limitToViewer('sd', 'f', true, true, SocialCoreQueryBuilder::HIDDEN_DIRECT);
			$qb->leftJoinStreamAction('sa');
		}

		try {
			return $this->getStreamFromRequest($qb);
		} catch (ItemUnknownException $e) {
			throw new StreamNotFoundException('Malformed Stream');
		} catch (StreamNotFoundException $e) {
			throw new StreamNotFoundException('Stream not found');
		}
	}

	/**
	 * @param string $id
	 * @param bool $asViewer
	 * @param int $format
	 *
	 * @return Stream
	 * @throws StreamNotFoundException
	 */
	/**
	 * Full-text search over the statuses the viewer is allowed to see: their
	 * own posts, public/unlisted content, and what is addressed to them. A
	 * plain (case-insensitive) substring match — fine at the instance sizes
	 * this app targets; no external search engine required.
	 *
	 * `$authorId` narrows the answers to one account's posts, still within
	 * what the viewer may see. `$offset` skips that many answers, up to
	 * SEARCH_MAX_OFFSET; `$maxId` and `$minId` bound the status nid, leaving
	 * the newest-first order as it is.
	 *
	 * @return Stream[]
	 */
	public function searchContent(
		string $term, int $limit = 20, int $offset = 0, string $authorId = '',
		int|string $maxId = 0, int|string $minId = 0,
	): array {
		$offset = max(0, $offset);
		if (strlen($term) < 3 || $offset > self::SEARCH_MAX_OFFSET) {
			return [];
		}

		// candidates, not answers, are what the database is asked for, so the
		// rows an offset skips are read on top of the over-read page
		$window = min(self::SEARCH_OVERREAD_MAX, max($limit, $limit * self::SEARCH_OVERREAD)) + $offset;

		$qb = $this->getStreamSelectSql(ACore::FORMAT_LOCAL);
		$qb->limitToStatusTypes();
		$expr = $qb->expr();
		$qb->andWhere($expr->iLike(
			's.content',
			$qb->createNamedParameter('%' . $this->dbConnection->escapeLikeParameter($term) . '%')
		));

		if ($authorId !== '') {
			$qb->andWhere($expr->eq('s.attributed_to_prim', $qb->createNamedParameter($qb->prim($authorId))));
		}
		if (Nid::compare($maxId, 0) > 0) {
			$qb->andWhere($expr->lt('s.nid', $qb->createNamedParameter(Nid::fromStorage($maxId))));
		}
		if (Nid::compare($minId, 0) > 0) {
			$qb->andWhere($expr->gt('s.nid', $qb->createNamedParameter(Nid::fromStorage($minId))));
		}

		$qb->limitToViewer('sd', 'f', true, true, SocialCoreQueryBuilder::HIDDEN_DIRECT);
		$qb->leftJoinStreamAction();
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		$qb->orderBy('s.published_time', 'desc');
		$qb->setMaxResults($window);

		// The window is what keeps this from being a full scan. `content
		// ILIKE '%term%'` cannot use an index — a leading wildcard never
		// can — so without a bound the database reads every post the instance
		// has ever stored, joined to seven other tables, on every search. At
		// ten million rows that is a table scan per keystroke, and the rate
		// limit is the only thing between it and the CPU.
		//
		// `published_time` is indexed and is what the result is ordered by, so
		// a range on it is both the narrowing and the ordering. Searching the
		// recent past and saying so is a different promise from searching
		// everything and timing out; a full-text index is what would let this
		// promise more, and it is a per-database feature this app has nowhere
		// else — see `SearchService`.
		$since = $this->searchWindowStart();
		if ($since > 0) {
			$qb->andWhere($expr->gt(
				's.published_time',
				$qb->createNamedParameter($this->dateTime($since), IQueryBuilder::PARAM_DATE)
			));
		}

		return array_slice($this->whoseTextCarries($this->getStreamsFromRequest($qb), $term), $offset, $limit);
	}

	/**
	 * The posts whose *text* carries the term.
	 *
	 * `content` is stored as markup, so the `LIKE` above matches the markup as
	 * well as the words: `span`, `href`, `class` and `http` each answered with
	 * very nearly every post the instance holds, and a search for any of them
	 * was a page of unrelated posts. The database cannot be asked to ignore
	 * the tags without a column to search, so the rows it offers are
	 * candidates and the flattened text decides which of them are answers.
	 *
	 * @param Stream[] $posts
	 *
	 * @return Stream[]
	 */
	private function whoseTextCarries(array $posts, string $term): array {
		return array_values(array_filter($posts, static function (Stream $post) use ($term): bool {
			$text = html_entity_decode(
				ACore::withoutMarkup($post->getContent()), ENT_QUOTES | ENT_HTML5, 'UTF-8'
			);

			return mb_stripos($text, $term) !== false;
		}));
	}

	/**
	 * How far back a content search looks, as a timestamp, or 0 for "all of
	 * it".
	 *
	 * An administrator can widen it — an instance with a hundred thousand
	 * posts can afford to search all of them, and one with ten million cannot.
	 * The default is a year, which covers what anybody is actually looking for
	 * and bounds the scan at roughly the instance's yearly output rather than
	 * its whole history.
	 */
	private function searchWindowStart(): int {
		$days = $this->configService->getAppValueInt(ConfigService::SOCIAL_SEARCH_WINDOW_DAYS);

		return ($days > 0) ? time() - ($days * 86400) : 0;
	}

	/** A timestamp as the DateTime the date parameters take. */
	private function dateTime(int $timestamp): DateTime {
		$date = new DateTime();
		$date->setTimestamp($timestamp);

		return $date;
	}

	public function getStreamByNid(int|string $nid): Stream {
		$qb = $this->getStreamSelectSql(ACore::FORMAT_LOCAL);
		$qb->limitToNid($nid);
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');

		$qb->limitToViewer('sd', 'f', true, true, SocialCoreQueryBuilder::HIDDEN_DIRECT);
		$qb->leftJoinStreamAction('sa');

		return $this->getStreamFromRequest($qb);
	}

	/**
	 * The posts this instance holds that quote one post, newest first.
	 *
	 * What `GET /api/v1/statuses/{id}/quotes` answers. It is the posts this
	 * server *has*: a local quote, and a remote one that reached somebody here.
	 * A quote written on a server nobody here follows was approved and is real
	 * and is not in this list, because there is no status entity to put in it —
	 * Mastodon's own answer has the same edge.
	 *
	 * Read as the viewer, so a quote in a followers-only post of somebody the
	 * reader does not follow is not handed to them by a list about their own
	 * post.
	 *
	 * @return Stream[]
	 */
	public function getQuotesOf(string $objectId, int $limit = 20, int|string $maxId = '0'): array {
		if ($objectId === '') {
			return [];
		}

		$qb = $this->getStreamSelectSql(ACore::FORMAT_LOCAL);
		$qb->andWhere($qb->expr()->eq('s.quote', $qb->createNamedParameter($objectId)));
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		$qb->limitToViewer('sd', 'f', true, true, SocialCoreQueryBuilder::HIDDEN_DIRECT);
		$qb->leftJoinStreamAction('sa');

		if ($maxId > 0) {
			$qb->andWhere($qb->expr()->lt('s.nid', $qb->createNamedParameter($maxId)));
		}

		$qb->orderBy('s.nid', 'desc');
		$qb->setMaxResults(max(1, min($limit, 40)));

		return $this->getStreamsFromRequest($qb);
	}

	/**
	 * @param string $idPrim
	 *
	 * @return Stream
	 * @throws StreamNotFoundException
	 */
	public function getStream(string $idPrim): Stream {
		$qb = $this->getStreamSelectSql();
		$qb->limitToIdPrim($idPrim);

		return $this->getStreamFromRequest($qb);
	}

	/**
	 * The next ordered page for the incremental stream-index repair job.
	 *
	 * Keep this projection small: the repair reads only ids here and lets this
	 * request hydrate one stream at a time, rather than holding whole posts for
	 * the duration of a cron batch.
	 *
	 * @return list<array{nid: string, id_prim: string}>
	 */
	public function getIndexChunk(int|string $afterNid, int $limit): array {
		$qb = $this->getQueryBuilder();
		$qb->select('nid', 'id_prim')
			->from(self::TABLE_STREAM)
			->where($qb->expr()->gt('nid', $qb->createNamedParameter((string)$afterNid)))
			->orderBy('nid', 'asc')
			->setMaxResults($limit);

		$cursor = $qb->executeQuery();
		$rows = [];
		while ($row = $cursor->fetch()) {
			$rows[] = [
				'nid' => (string)$row['nid'],
				'id_prim' => (string)$row['id_prim'],
			];
		}
		$cursor->closeCursor();

		return $rows;
	}

	/**
	 * @param string $id
	 * @param int $since
	 * @param int $limit
	 * @param bool $asViewer
	 *
	 * @return Stream[]
	 * @throws StreamNotFoundException
	 * @throws DateTimeException
	 */
	public function getRepliesByParentId(string $id, int $since = 0, int $limit = 5, bool $asViewer = false,
	): array {
		if ($id === '') {
			throw new StreamNotFoundException();
		};

		$qb = $this->getStreamSelectSql();
		$qb->limitToInReplyTo($id);
		$qb->limitPaginate($since, $limit);

		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		if ($asViewer) {
			$qb->limitToViewer('sd', 'f', true);
			$qb->leftJoinStreamAction();
		}

		return $this->getStreamsFromRequest($qb);
	}

	/**
	 * @param string $id
	 *
	 * @return Stream
	 * @throws StreamNotFoundException
	 * @throws Exception
	 */
	public function getStreamByActivityId(string $id): Stream {
		if ($id === '') {
			throw new StreamNotFoundException();
		};

		$qb = $this->getStreamSelectSql();
		$qb->limitToActivityId($id);

		return $this->getStreamFromRequest($qb);
	}

	/**
	 * @param string $objectId
	 * @param string $type
	 * @param string $subType
	 *
	 * @return Stream
	 * @throws StreamNotFoundException
	 */
	public function getStreamByObjectId(string $objectId, string $type, string $subType = '',
	): Stream {
		if ($objectId === '') {
			throw new StreamNotFoundException('missing objectId');
		};

		$qb = $this->getStreamSelectSql();
		$qb->limitToObjectId($objectId);
		$qb->limitToType($type);
		$qb->limitToSubType($subType);

		return $this->getStreamFromRequest($qb);
	}

	/**
	 * One account's Announce of one post.
	 *
	 * A boost is a row per (post, booster): the lookup by object and type
	 * alone answers with whichever booster's row comes first, which is the
	 * wrong row for everybody but one of them.
	 *
	 * @throws StreamNotFoundException when this account has not boosted the post
	 */
	public function getAnnounceBy(string $objectId, string $actorId): Stream {
		if ($objectId === '' || $actorId === '') {
			throw new StreamNotFoundException('missing objectId or actorId');
		}

		$qb = $this->getStreamSelectSql();
		$qb->limitToObjectId($objectId);
		$qb->limitToType(Announce::TYPE);
		$qb->limitToAttributedTo($actorId, true);

		return $this->getStreamFromRequest($qb);
	}

	/**
	 * The public replies to a post, oldest first, for the `replies` collection
	 * a peer walks to discover a thread.
	 *
	 * Public only. The collection is served to anybody who asks for it, and a
	 * followers-only or direct reply is not theirs to read — not even as an id,
	 * which is enough to fetch the reply itself from the instance that holds
	 * it. This is the same audience test `getPublicByAuthor()` applies to an
	 * outbox.
	 *
	 * Oldest first, because that is the order a thread is read in and the order
	 * `OrderedCollectionPage` offsets are stable under: newest-first paging
	 * renumbers every page as soon as somebody replies again. Ordered on the
	 * nid, which is the publication time with a random suffix, so that a page
	 * can start after the last one's final reply (`$after`) rather than read
	 * and discard every reply before it.
	 *
	 * @param string $after the nid the page starts after, '' for none
	 *
	 * @return Stream[]
	 */
	public function getPublicRepliesTo(string $id, int $limit, int $offset = 0, string $after = ''): array {
		if ($id === '' || $limit < 1) {
			return [];
		}

		$qb = $this->getStreamSelectSql();
		$qb->limitToInReplyTo($id, true);
		$qb->limitToStatusTypes();

		$qb->selectDestFollowing('sd', '');
		$qb->innerJoinStreamDest('recipient', 'id_prim', 'sd', 's');
		$qb->limitToDest(ACore::CONTEXT_PUBLIC, 'recipient', '', 'sd');

		$qb->linkToCacheActors('ca', 's.attributed_to_prim');

		if ($after !== '') {
			$qb->andWhere($qb->expr()->gt('s.nid', $qb->createNamedParameter(Nid::normalize($after))));
		}

		$qb->orderBy('s.nid', 'asc');
		$qb->setMaxResults($limit);
		$qb->setFirstResult($offset);

		return $this->getStreamsFromRequest($qb);
	}

	/**
	 * How many replies {@see self::getPublicRepliesTo()} would return: the
	 * `totalItems` of the collection, counted over the same audience so the
	 * number and the pages cannot disagree.
	 */
	public function countPublicRepliesTo(string $id): int {
		if ($id === '') {
			return 0;
		}

		$qb = $this->countNotesSelectSql();
		$qb->limitToInReplyTo($id, true);
		$qb->limitToStatusTypes();

		$qb->selectDestFollowing('sd', '');
		$qb->innerJoinStreamDest('recipient', 'id_prim', 'sd', 's');
		$qb->limitToDest(ACore::CONTEXT_PUBLIC, 'recipient', '', 'sd');

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return $this->getInt('count', $data, 0);
	}

	/**
	 * @param string $id
	 *
	 * @return int
	 */
	public function countRepliesTo(string $id): int {
		$qb = $this->countNotesSelectSql();
		$qb->limitToInReplyTo($id, true);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return $this->getInt('count', $data, 0);
	}

	/**
	 * A moderator's decision that every post by an account is sensitive.
	 *
	 * Applied here, at the one place a post is written, rather than at each of
	 * the paths that reach it: a local post, a post that arrived in the inbox
	 * and a post restored by the importer are the same row, and a rule that
	 * held for one of them and not the others would be a rule nobody could
	 * explain.
	 *
	 * The set is read once per request and is empty on nearly every instance,
	 * so the common case is one query that returns nothing and a comparison
	 * against an empty array.
	 */
	private function applyForcedSensitive(Stream $stream): void {
		if ($stream->isSensitive() || $stream->getAttributedTo() === '') {
			return;
		}

		$this->forcedSensitive ??= $this->moderationRequest->forcedSensitive();
		if (in_array($stream->getAttributedTo(), $this->forcedSensitive, true)) {
			$stream->setSensitive(true);
		}
	}

	/**
	 * Puts one of the author's own posts away, or brings it back.
	 *
	 * The author is in the statement rather than checked beforehand: a request
	 * naming somebody else's post changes no row, which is the same guarantee
	 * every other per-account write here makes and one that cannot be
	 * forgotten by a caller.
	 *
	 * Local posts only. Archiving is about what this account shows on its own
	 * profile; a post somebody else wrote is theirs, and the answer to not
	 * wanting to see it is a mute or a block.
	 *
	 * @return bool whether a row changed
	 */
	public function setArchived(int|string $nid, string $actorId, bool $archived): bool {
		$qb = $this->getStreamUpdateSql();
		$qb->set('archived', $qb->createNamedParameter($archived, IQueryBuilder::PARAM_BOOL));
		$qb->where(
			$qb->expr()->eq('nid', $qb->createNamedParameter($nid)),
			$qb->expr()->eq('attributed_to_prim', $qb->createNamedParameter($qb->prim($actorId))),
			$qb->expr()->eq('local', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
		);

		return $qb->executeStatement() > 0;
	}

	/**
	 * The posts this account has put away, newest first.
	 *
	 * The one read that asks for archived posts, and the only one: everything
	 * else is fail-closed (`hideArchived()`).
	 *
	 * @return Stream[]
	 */
	public function getArchivedByActor(string $actorId, int $limit = 50, int|string $maxId = '0'): array {
		$qb = $this->getStreamSelectSql(Stream::FORMAT_LOCAL, true);
		$qb->andWhere($qb->expr()->eq('s.attributed_to_prim', $qb->createNamedParameter($qb->prim($actorId))));
		$qb->andWhere($qb->expr()->eq('s.archived', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
		if ($maxId > 0) {
			$qb->andWhere($qb->expr()->lt('s.nid', $qb->createNamedParameter($maxId)));
		}
		$qb->orderBy('s.nid', 'desc');
		$qb->setMaxResults($limit);

		return $this->getStreamsFromRequest($qb);
	}

	/** How many posts this account has put away. */
	public function countArchivedByActor(string $actorId): int {
		$qb = $this->getQueryBuilder();
		$qb->selectAlias($qb->func()->count('*'), 'total')
			->from(self::TABLE_STREAM, 's')
			->where($qb->expr()->eq('s.attributed_to_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere($qb->expr()->eq('s.archived', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return (int)($data['total'] ?? 0);
	}

	/**
	 * What this instance's own people have been writing, for the two numbers
	 * an administrator asks for first: how busy is it, and how many of the
	 * accounts are actually used.
	 *
	 * Local posts only. A count that included the fediverse's would say how
	 * much this server has *received*, which is a number about everybody
	 * else's activity and about this instance's retention settings.
	 *
	 * @param int $since unix time to count from
	 * @return array{posts: int, authors: int}
	 */
	public function localActivitySince(int $since): array {
		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();

		$date = new DateTime();
		$date->setTimestamp($since);

		$qb->selectAlias($qb->func()->count('s.id'), 'posts')
			->selectAlias($qb->createFunction('COUNT(DISTINCT s.attributed_to_prim)'), 'authors')
			->from(self::TABLE_STREAM, 's')
			->where($expr->eq('s.local', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->andWhere($expr->in(
				's.type',
				$qb->createNamedParameter([Note::TYPE, Question::TYPE], IQueryBuilder::PARAM_STR_ARRAY)
			))
			->andWhere($expr->gte(
				's.published_time', $qb->createNamedParameter($date, IQueryBuilder::PARAM_DATE)
			));

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return [
			'posts' => (int)($data['posts'] ?? 0),
			'authors' => (int)($data['authors'] ?? 0),
		];
	}

	/**
	 * How many posts this account has published here to anybody but one person.
	 *
	 * The twin of `countNotesFromActorId()`, which counts only the public ones
	 * because that is what a profile reports. This one is asked a different
	 * question — has this account posted here before at all — so a first post
	 * that went to followers is still not a first post.
	 *
	 * Direct messages are left out, and that is the point rather than a
	 * detail. `PostReviewService` never holds a direct message, so counting
	 * them here would mean an account could send one message to itself and be
	 * past first-post review a second later — the rule would hold nobody who
	 * had read the rule.
	 */
	public function countPostsBy(string $actorId): int {
		$qb = $this->countNotesSelectSql();
		$qb->limitToAttributedTo($actorId, true);
		$qb->limitToStatusTypes();
		$qb->andWhere(
			$qb->expr()->neq(
				's.visibility', $qb->createNamedParameter(Stream::TYPE_DIRECT)
			)
		);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return $this->getInt('count', $data, 0);
	}

	/**
	 * @param string $actorId
	 *
	 * @return int
	 */
	public function countNotesFromActorId(string $actorId): int {
		$qb = $this->countNotesSelectSql();
		$qb->limitToAttributedTo($actorId, true);
		$qb->limitToStatusTypes();

		$qb->selectDestFollowing('sd', '');
		$qb->innerJoinStreamDest('recipient', 'id_prim', 'sd', 's');
		$qb->limitToDest(ACore::CONTEXT_PUBLIC, 'recipient', '', 'sd');

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return $this->getInt('count', $data, 0);
	}

	/**
	 * @param string $actorId
	 *
	 * @return Stream
	 * @throws StreamNotFoundException
	 */
	public function lastNoteFromActorId(string $actorId): Stream {
		$qb = $this->getStreamSelectSql();
		$qb->limitToAttributedTo($actorId, true);
		$qb->limitToStatusTypes();

		$qb->selectDestFollowing('sd', '');
		$qb->innerJoinStreamDest('recipient', 'id_prim', 'sd', 's');
		$qb->limitToDest(ACore::CONTEXT_PUBLIC, 'recipient', '', 'sd');

		// qualified, and by the time rather than the id: `id` is a column on the
		// joined dest table too, so the unqualified name was ambiguous and the
		// database refused the query outright — and the id it meant to sort on
		// is the post's URL, which says nothing about when it was written
		$qb->orderBy('s.published_time', 'desc');
		$qb->setMaxResults(1);

		return $this->getStreamFromRequest($qb);
	}

	/**
	 * The public posts of one author, oldest last, in fixed-size windows.
	 *
	 * Newest first, on the nid. The collection's `first` is still numbered and
	 * a numbered page is still answered, by offset, because those are the
	 * addresses peers already hold; every `next` link is a cursor (`$before`,
	 * the nid of the previous page's last post), which the author's
	 * `attributed_to_prim` index answers in nid order without reading the
	 * pages before it.
	 *
	 * @param string $before the nid the page starts below, '' for none
	 *
	 * @return Stream[]
	 */
	public function getPublicByAuthor(string $actorId, int $limit, int $offset = 0, string $before = ''): array {
		if ($actorId === '' || $limit < 1) {
			return [];
		}

		$qb = $this->getStreamSelectSql();
		$qb->limitToStatusTypes();
		$qb->limitToAttributedTo($actorId, true);

		$qb->selectDestFollowing('sd', '');
		$qb->innerJoinStreamDest('recipient', 'id_prim', 'sd', 's');
		$qb->limitToDest(ACore::CONTEXT_PUBLIC, 'recipient', '', 'sd');

		$qb->linkToCacheActors('ca', 's.attributed_to_prim');

		if ($before !== '') {
			$qb->andWhere($qb->expr()->lt('s.nid', $qb->createNamedParameter(Nid::normalize($before))));
		}

		$qb->orderBy('s.nid', 'desc');
		$qb->setMaxResults($limit);
		$qb->setFirstResult($offset);

		return $this->getStreamsFromRequest($qb);
	}

	/**
	 * When each of an author's public posts was published, since a point in
	 * time.
	 *
	 * Only the timestamps: what the profile's little activity chart needs is
	 * how many posts fell in each week, and hydrating the posts to count them
	 * would read every column of every note to throw all of it away. Public
	 * posts only — a chart drawn from what the viewer happens to be allowed to
	 * see would be a different chart per viewer, and a chart that counted
	 * posts the viewer cannot see would leak that they exist.
	 *
	 * Boosts are left out by `limitToStatusTypes()`: a boost is something the
	 * account did, not something it wrote.
	 *
	 * @param string $actorId the author
	 * @param int $since unix time to start at
	 * @param int $limit a ceiling, so a prolific account cannot make this
	 *                   query grow without bound
	 * @return list<int> publication times, newest first
	 */
	public function publishedTimesByAuthor(string $actorId, int $since, int $limit = 2000): array {
		if ($actorId === '' || $limit < 1) {
			return [];
		}

		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();

		$date = new DateTime();
		$date->setTimestamp($since);

		$qb->select('s.published_time')
			->from(self::TABLE_STREAM, 's')
			->where($expr->gte(
				's.published_time', $qb->createNamedParameter($date, IQueryBuilder::PARAM_DATE)
			))
			->orderBy('s.published_time', 'desc')
			->setMaxResults($limit);

		$qb->setDefaultSelectAlias('s');
		$qb->limitToAttributedTo($actorId, true);
		$qb->limitToStatusTypes();

		// the recipients are where "public" is recorded, the same join the
		// author's public timeline uses
		$qb->selectDestFollowing('sd', '');
		$qb->innerJoinStreamDest('recipient', 'id_prim', 'sd', 's');
		$qb->limitToDest(ACore::CONTEXT_PUBLIC, 'recipient', '', 'sd');

		$times = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$time = (int)strtotime((string)$data['published_time']);
			if ($time > 0) {
				$times[] = $time;
			}
		}
		$cursor->closeCursor();

		return $times;
	}

	/**
	 * An author's own posts published inside a window of time.
	 *
	 * Used to look the reader up their own past: unlike the profile charts
	 * above, this is only ever called for the caller's own account, so it is
	 * not held to public posts — somebody looking back at their own year
	 * should see what they actually wrote, followers-only posts included.
	 * Every caller must therefore check that the actor is the viewer.
	 *
	 * @param string $actorId the author
	 * @param int $from unix time, inclusive
	 * @param int $until unix time, exclusive
	 * @param int $limit how many to return at most
	 * @param int $format how the posts are to be exported
	 * @return Stream[] newest first
	 */
	public function getByAuthorBetween(
		string $actorId,
		int $from,
		int $until,
		int $limit = 10,
		int $format = ACore::FORMAT_ACTIVITYPUB,
	): array {
		if ($actorId === '' || $limit < 1 || $until <= $from) {
			return [];
		}

		$fromDate = new DateTime();
		$fromDate->setTimestamp($from);
		$untilDate = new DateTime();
		$untilDate->setTimestamp($until);

		$qb = $this->getStreamSelectSql($format);
		$qb->limitToAttributedTo($actorId, true);
		$qb->limitToStatusTypes();

		$expr = $qb->expr();
		$qb->andWhere($expr->gte(
			's.published_time', $qb->createNamedParameter($fromDate, IQueryBuilder::PARAM_DATE)
		));
		$qb->andWhere($expr->lt(
			's.published_time', $qb->createNamedParameter($untilDate, IQueryBuilder::PARAM_DATE)
		));

		// a reply is an answer to somebody else's post and reads as a
		// fragment out of its thread, which is not much of a memory
		$qb->limitToDBFieldEmpty('in_reply_to');

		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		$qb->leftJoinStreamAction();

		$qb->orderBy('s.published_time', 'desc');
		$qb->setMaxResults($limit);

		return $this->getStreamsFromRequest($qb);
	}

	/**
	 * The hashtags an author uses most, over their public posts since a point
	 * in time.
	 *
	 * Grouped in SQL rather than walked in PHP: unlike the per-post details
	 * blob, a hashtag is a row of its own in `social_stream_tag`, so the three
	 * databases can all count them the same way.
	 *
	 * @param string $actorId the author
	 * @param int $since unix time to start at
	 * @param int $limit how many to name
	 * @return list<array{name: string, count: int}> most used first
	 */
	public function topHashtagsByAuthor(string $actorId, int $since, int $limit = 3): array {
		if ($actorId === '' || $limit < 1) {
			return [];
		}

		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();

		$date = new DateTime();
		$date->setTimestamp($since);

		$qb->select('st.hashtag')
			->selectAlias($qb->func()->count('*'), 'total')
			->from(self::TABLE_STREAM_TAGS, 'st')
			->innerJoin('st', self::TABLE_STREAM, 's', $expr->eq('s.id_prim', 'st.stream_id'))
			->where($expr->eq('s.attributed_to_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere($expr->gte(
				's.published_time', $qb->createNamedParameter($date, IQueryBuilder::PARAM_DATE)
			))
			->groupBy('st.hashtag')
			->orderBy('total', 'desc')
			->addOrderBy('st.hashtag', 'asc')
			->setMaxResults($limit);

		$qb->setDefaultSelectAlias('s');
		$qb->limitToStatusTypes();

		// public posts only, through the same recipient join as the chart
		// above: the profile is read by strangers, and a tag used only in
		// followers-only posts is a fact about those posts
		$qb->selectDestFollowing('sd', '');
		$qb->innerJoinStreamDest('recipient', 'id_prim', 'sd', 's');
		$qb->limitToDest(ACore::CONTEXT_PUBLIC, 'recipient', '', 'sd');

		$tags = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$name = (string)$data['hashtag'];
			if ($name === '') {
				continue;
			}
			$tags[] = ['name' => $name, 'count' => (int)$data['total']];
		}
		$cursor->closeCursor();

		return $tags;
	}

	/**
	 * How often each hashtag was used since a point in time.
	 *
	 * One window of `countHashtagsInWindows()`, for a caller that wants one.
	 *
	 * @return array<string, int> hashtag => how many posts used it
	 */
	public function countHashtagsSince(int $since): array {
		return $this->countHashtagsInWindows(['since' => $since])['since'];
	}

	/**
	 * How often each hashtag was used in each of several windows, in one pass.
	 *
	 * This is what the trends cron needs, and all it needs. It used to hydrate
	 * the posts themselves — every column of every note, plus its action row —
	 * and count the tags in PHP, bounded to a sample of the most recent
	 * thousand notes; on a busy instance all five windows saw the same
	 * thousand notes and every period therefore reported the same count. Then
	 * it was one grouped query per window, which read the tag rows of the
	 * widest window five times over; now the widest window is read once and
	 * each narrower one is a conditional sum over the same rows.
	 *
	 * A hashtag used in none of a window's posts is absent from that window
	 * rather than zero.
	 *
	 * @param array<string, int> $windows name => since, as a timestamp
	 *
	 * @return array<string, array<string, int>> name => (hashtag => how many posts used it)
	 */
	public function countHashtagsInWindows(array $windows): array {
		$result = array_fill_keys(array_keys($windows), []);
		if ($windows === []) {
			return $result;
		}

		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();

		$qb->select('st.hashtag');
		$aliases = [];
		foreach (array_keys($windows) as $i => $name) {
			$date = new DateTime();
			$date->setTimestamp($windows[$name]);
			$aliases[$name] = 'w' . $i;
			$qb->selectAlias(
				$qb->createFunction(
					'SUM(CASE WHEN '
					. $expr->gte('s.published_time', $qb->createNamedParameter($date, IQueryBuilder::PARAM_DATE))
					. ' THEN 1 ELSE 0 END)'
				),
				'w' . $i
			);
		}

		$widest = new DateTime();
		$widest->setTimestamp(min($windows));
		$qb->from(self::TABLE_STREAM_TAGS, 'st')
			->innerJoin('st', self::TABLE_STREAM, 's', $expr->eq('s.id_prim', 'st.stream_id'))
			->where($expr->gte(
				's.published_time', $qb->createNamedParameter($widest, IQueryBuilder::PARAM_DATE)
			))
			// public posts only, the rule `HashtagsRequest::related()` counts
			// by: a tag used inside a followers-only thread or a direct message
			// is not public knowledge, and counting it published the tag — and
			// its usage count — through `/api/v1/trends/tags`, `tagHistory()`
			// and search
			->andWhere($expr->eq(
				's.visibility', $qb->createNamedParameter(Stream::TYPE_PUBLIC)
			))
			->groupBy('st.hashtag');

		$qb->setDefaultSelectAlias('s');
		$qb->limitToStatusTypes();

		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			foreach ($aliases as $name => $alias) {
				$count = (int)($data[$alias] ?? 0);
				if ($count > 0) {
					$result[$name][(string)$data['hashtag']] = $count;
				}
			}
		}
		$cursor->closeCursor();

		return $result;
	}

	/**
	 * Who an account actually talks with, and how often.
	 *
	 * Both directions are the same self-join on the stream, read from either
	 * end: `PARTNERS_INBOUND` counts the replies other people wrote to this
	 * account's posts, `PARTNERS_OUTBOUND` the replies this account wrote to
	 * theirs. The window applies to the reply in both cases — it is the reply
	 * that happened in the window, whatever the age of the post it answers.
	 *
	 * The account itself is excluded: a thread somebody continues on their own
	 * is not a conversation with anybody, and left in it would outrank every
	 * real partner.
	 *
	 * @param string $actorId the account whose conversations
	 * @param int $direction self::PARTNERS_INBOUND or self::PARTNERS_OUTBOUND
	 * @param int $since only replies from this moment on, or 0 for all of them
	 *
	 * @return list<array{id: string, account: string, replies: int}> most talkative first
	 */
	public function countConversationPartners(
		string $actorId,
		int $direction,
		int $since = 0,
		int $limit = 10,
	): array {
		if ($actorId === '') {
			return [];
		}

		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();
		$prim = $qb->prim($actorId);

		// 'r' is always the reply, 'p' always the post it answers; which of the
		// two belongs to this account is the whole difference between the
		// directions
		$partner = ($direction === self::PARTNERS_INBOUND) ? 'r' : 'p';
		$mine = ($direction === self::PARTNERS_INBOUND) ? 'p' : 'r';

		$qb->select($partner . '.attributed_to')
			->selectAlias($qb->func()->count('*'), 'total')
			->from(self::TABLE_STREAM, 'r')
			->innerJoin('r', self::TABLE_STREAM, 'p', $expr->eq('p.id_prim', 'r.in_reply_to_prim'))
			->where($expr->eq(
				$mine . '.attributed_to_prim', $qb->createNamedParameter($prim)
			))
			->andWhere($expr->neq(
				$partner . '.attributed_to_prim', $qb->createNamedParameter($prim)
			))
			->andWhere($expr->nonEmptyString($partner . '.attributed_to'))
			->groupBy($partner . '.attributed_to')
			->orderBy('total', 'desc')
			->setMaxResults($limit);

		if ($since > 0) {
			$date = new DateTime();
			$date->setTimestamp($since);
			$qb->andWhere($expr->gte(
				'r.published_time', $qb->createNamedParameter($date, IQueryBuilder::PARAM_DATE)
			));
		}

		$qb->setDefaultSelectAlias('r');
		$qb->limitToStatusTypes();

		$partners = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$id = (string)$data['attributed_to'];
			$partners[] = [
				'id' => $id,
				'account' => $this->handleFromActorId($id),
				'replies' => (int)$data['total'],
			];
		}
		$cursor->closeCursor();

		return $partners;
	}

	/**
	 * The handle an actor URI belongs to, for a name to put on a number.
	 *
	 * The cache is the only place a remote actor's handle is written down, and
	 * an actor this account has held a conversation with is in it by
	 * definition. If it is not, the URI's own last segment and host say who it
	 * was well enough for a list of names.
	 */
	private function handleFromActorId(string $id): string {
		$qb = $this->getQueryBuilder();
		$qb->select('account')
			->from(self::TABLE_CACHE_ACTORS)
			->where($qb->expr()->eq('id_prim', $qb->createNamedParameter($qb->prim($id))))
			->setMaxResults(1);

		$cursor = $qb->executeQuery();
		$account = (string)($cursor->fetchOne() ?: '');
		$cursor->closeCursor();

		if ($account !== '') {
			return $account;
		}

		$host = (string)parse_url($id, PHP_URL_HOST);
		$name = basename((string)parse_url($id, PHP_URL_PATH));

		return ($name === '' || $host === '') ? $id : $name . '@' . $host;
	}

	/**
	 * The polls whose end time has passed since a moment.
	 *
	 * A poll's end time lives in the stored wire object rather than in a
	 * column, so it cannot be a predicate: what this does is read the recent
	 * `Question` rows and let the model answer. That is bounded twice over —
	 * by how far back it looks, and by `$scan` — and on any instance the set
	 * is a handful of rows, because a poll is a rare kind of post and one that
	 * closed a year ago is not news.
	 *
	 * @return Question[]
	 */
	public function getPollsClosedSince(int $since, int $limit = 50, int $scan = 500): array {
		$qb = $this->getStreamSelectSql(ACore::FORMAT_LOCAL);
		$qb->limitToType(Question::TYPE);
		$qb->andWhere($qb->expr()->gte(
			's.published_time',
			$qb->createNamedParameter(
				(new DateTime())->setTimestamp(time() - self::POLL_LOOKBACK),
				IQueryBuilder::PARAM_DATE
			)
		));
		$qb->orderBy('s.nid', 'desc');
		$qb->setMaxResults(max(1, $scan));
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');

		$closed = [];
		foreach ($this->getStreamsFromRequest($qb) as $poll) {
			if (!($poll instanceof Question)) {
				continue;
			}

			$ends = $poll->getEndTime() === '' ? 0 : (int)strtotime($poll->getEndTime());
			if ($ends > $since && $ends <= time()) {
				$closed[] = $poll;
			}

			if (count($closed) >= $limit) {
				break;
			}
		}

		return $closed;
	}

	/**
	 * The public posts taken at one place, newest first.
	 *
	 * Public only, whoever asks: a place page is a public page, and a
	 * followers-only post's location is as private as the post. Keyset by
	 * `nid`, like every other list here.
	 *
	 * @return Stream[]
	 */
	public function getPublicByPlace(int $placeId, int $limit = 20, int|string $maxId = '0'): array {
		if ($placeId < 1 || $limit < 1) {
			return [];
		}

		$qb = $this->getStreamSelectSql(ACore::FORMAT_LOCAL);
		$qb->limitToStatusTypes();
		$expr = $qb->expr();
		$qb->andWhere($expr->eq('s.place_id', $qb->createNamedParameter($placeId, IQueryBuilder::PARAM_INT)));
		$qb->andWhere($expr->eq('s.visibility', $qb->createNamedParameter(Stream::TYPE_PUBLIC)));
		// a reply is a fragment of somebody else's thread, not a picture of the place
		$qb->limitToDBFieldEmpty('in_reply_to');
		if ($maxId > 0) {
			$qb->andWhere($expr->lt('s.nid', $qb->createNamedParameter($maxId)));
		}

		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		$qb->leftJoinStreamAction();
		$qb->orderBy('s.nid', 'desc');
		$qb->setMaxResults($limit);

		return $this->getStreamsFromRequest($qb);
	}

	/**
	 * The direct messages between the viewer and one other account, as one
	 * thread.
	 *
	 * A direct message writes a `dm` destination row for every party to it,
	 * its author included (`StreamDestRequest::generateStreamDirect()`), so
	 * the thread is every direct post that has a row for both of them —
	 * whichever of the two wrote it. Keyset-paged on `nid` like every other
	 * timeline here.
	 *
	 * @return Stream[] newest first, or oldest first when paging forward with `minId`
	 */
	public function directBetween(Person $viewer, string $otherId, int $limit = 20, int|string $maxId = '0', int|string $minId = 0): array {
		if ($otherId === '' || $limit < 1) {
			return [];
		}

		$options = new ProbeOptions();
		$options->setFormat(ACore::FORMAT_LOCAL)
			->setLimit($limit);
		if ($maxId > 0) {
			$options->setMaxId($maxId);
		}
		if ($minId > 0) {
			$options->setMinId($minId);
		}

		$this->setViewer($viewer);
		$page = $this->getStreamNidsSelectSql(false);
		$page->limitToStatusTypes();
		$page->paginate($options);
		$page->limitToDBField('visibility', Stream::TYPE_DIRECT, true, 's');

		$page->selectDestFollowing('sd1', '');
		$page->from(self::TABLE_STREAM_DEST, 'sd2');
		$page->andWhere($page->exprLimitToDest($viewer->getId(), 'dm', '', 'sd1'));
		$page->andWhere($page->exprLimitToDest($otherId, 'dm', '', 'sd2'));

		$nids = $this->getNidsFromRequest($page);
		if ($nids === []) {
			return [];
		}

		return $this->streamsByNids($nids, $options);
	}

	/**
	 * Who boosted each of these posts.
	 *
	 * One query for the whole set rather than one per post: the statistics
	 * page asks about a month of posts at once, and a round trip per post to
	 * fill in one column is a page that gets slower the more an account posts.
	 *
	 * The same account boosting the same post twice is one audience, so the
	 * actors come back deduplicated per post.
	 *
	 * @param string[] $ids the posts, by id
	 * @param int $limit how many Announce rows to read at most
	 * @return array<string, list<string>> post id => the actors that boosted it
	 */
	public function boostersOf(array $ids, int $limit = 5000): array {
		if ($ids === [] || $limit < 1) {
			return [];
		}

		$qb = $this->getQueryBuilder();

		$byPrim = [];
		foreach ($ids as $id) {
			$prim = $qb->prim($id);
			if ($prim !== '') {
				$byPrim[$prim] = $id;
			}
		}

		if ($byPrim === []) {
			return [];
		}

		$expr = $qb->expr();
		$qb->select('s.object_id_prim', 's.attributed_to')
			->from(self::TABLE_STREAM, 's')
			->where($expr->eq('s.type', $qb->createNamedParameter(Announce::TYPE)))
			->andWhere($expr->in(
				's.object_id_prim',
				$qb->createNamedParameter(array_keys($byPrim), IQueryBuilder::PARAM_STR_ARRAY)
			))
			->setMaxResults($limit);

		$boosters = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$id = $byPrim[(string)$data['object_id_prim']] ?? '';
			$actor = (string)$data['attributed_to'];
			if ($id === '' || $actor === '') {
				continue;
			}
			$boosters[$id][$actor] = $actor;
		}
		$cursor->closeCursor();

		return array_map(static fn (array $actors): array => array_values($actors), $boosters);
	}

	/**
	 * Removes a post and everything that hangs off it.
	 *
	 * A post is not one row: its recipients (which is what puts it in a
	 * timeline), the interaction flags on it, its hashtags, its link card, the
	 * Like/Announce rows pointing at it and its cached attachments all key on
	 * it. Deleting only the `social_stream` row left every one of those behind
	 * in five tables plus the files on disk, for good — nothing else ever
	 * looks at them again.
	 *
	 * @param string $type when given, the post is only removed if it is of
	 *                     that type — and then nothing else is touched either
	 */
	public function deleteById(string $id, string $type = '') {
		$qb = $this->getStreamDeleteSql();
		$prim = $this->streamPrim($qb, $id);
		if ($prim === '') {
			return;
		}

		$qb->limitToIdPrim($prim);
		if ($type !== '') {
			$qb->limitToType($type);
		}

		$deleted = $qb->executeStatement();
		if ($type !== '' && $deleted === 0) {
			// a guarded delete that matched nothing: the post is of another
			// type and still exists, so its related rows are still in use
			return;
		}

		$this->deleteRelatedTo([$prim]);
	}

	/**
	 * Removes every post of an author, and everything that hangs off each of
	 * them. Done in batches: an account at scale has more posts than the
	 * cascade wants to name in one IN () list.
	 */
	public function deleteByAuthor(string $actorId) {
		while (true) {
			$prims = $this->getIdPrimsByAuthor($actorId, self::DELETE_BATCH);
			if ($prims === []) {
				return;
			}

			$this->deleteRelatedTo($prims);

			$qb = $this->getStreamDeleteSql();
			$qb->andWhere($qb->expr()->in(
				'id_prim',
				$qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)
			));
			if ($qb->executeStatement() === 0) {
				// nothing was removed, so the next pass would select the very
				// same rows: stop rather than spin
				return;
			}
		}
	}

	/**
	 * The authors of one instance that still have something stored here.
	 *
	 * Distinct authors rather than posts: the caller deletes an author's posts
	 * with `deleteByAuthor()`, which is already batched, so this only has to
	 * name who is left. Once an author's posts are gone they are not named
	 * again, which is what lets a purge resume where it stopped.
	 *
	 * @return string[] actor ids
	 *
	 * @throws InvalidResourceException the domain is not one
	 */
	public function getAuthorsFromDomain(string $domain, int $limit = 100): array {
		$qb = $this->getQueryBuilder();
		$qb->selectDistinct('s.attributed_to')
			->from(self::TABLE_STREAM, 's')
			->where(DomainBlocksRequestBuilder::onDomain($qb, 's.attributed_to', $domain))
			->setMaxResults($limit);

		$cursor = $qb->executeQuery();
		$authors = array_map(
			static fn (array $row): string => (string)$row['attributed_to'], $cursor->fetchAll()
		);
		$cursor->closeCursor();

		return $authors;
	}

	/**
	 * @return string[] id_prim of the posts of an author
	 */
	private function getIdPrimsByAuthor(string $actorId, int $limit): array {
		$qb = $this->getQueryBuilder();
		$qb->select('s.id_prim')
			->from(self::TABLE_STREAM, 's')
			->where($qb->expr()->eq(
				's.attributed_to_prim', $qb->createNamedParameter($qb->prim($actorId))
			))
			->setMaxResults($limit);

		$cursor = $qb->executeQuery();
		$prims = array_map(static fn (array $row): string => (string)$row['id_prim'], $cursor->fetchAll());
		$cursor->closeCursor();

		return $prims;
	}

	/**
	 * The in-app notification rows (`SocialAppNotification`) created before a
	 * cutoff, oldest first. A notification is a local row about a local user's
	 * post; it is never federated, and nothing reads it again once it has
	 * scrolled out of the notifications timeline.
	 *
	 * @return string[] id_prim
	 */
	public function getNotificationPrimsBefore(DateTime $cutoff, int $limit): array {
		$qb = $this->notificationsBeforeQuery($cutoff);
		$qb->select('s.id_prim')
			->orderBy('s.creation', 'asc')
			->setMaxResults($limit);

		$cursor = $qb->executeQuery();
		$prims = array_map(static fn (array $row): string => (string)$row['id_prim'], $cursor->fetchAll());
		$cursor->closeCursor();

		return $prims;
	}

	public function countNotificationsBefore(DateTime $cutoff): int {
		$qb = $this->notificationsBeforeQuery($cutoff);
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'count');

		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return (int)($row['count'] ?? 0);
	}

	/**
	 * How many unread notifications of each sub-type a local account received
	 * in a window: the rows the notifications page would list (less the ones
	 * from muted threads; the policy's holds are the caller's), with a nid past
	 * the account's read marker and a creation inside `($since, $until]`.
	 *
	 * The page is chosen exactly as the badge's query chooses it, projected to
	 * the nid and the sub-type, and grouped by the database: what comes back
	 * is a handful of numbers, however busy the account was.
	 *
	 * @param int|string $afterNid the notifications read marker; `0` for none
	 *
	 * @return array<string, int> sub-type => rows
	 */
	public function countNotificationsBySubType(Person $actor, int|string $afterNid, DateTime $since, DateTime $until): array {
		$page = $this->getQueryBuilder();
		$page->selectDistinct('s.nid')
			->addSelect('s.subtype')
			->from(self::TABLE_STREAM, 's');
		$page->setDefaultSelectAlias('s');
		$page->setViewer($actor);
		$page->limitToType(SocialAppNotification::TYPE);
		$page->selectDestFollowing('sd', '');
		$page->limitToDest($actor->getId(), 'notif', '', 'sd');
		$page->filterHiddenActors(SocialCoreQueryBuilder::HIDDEN_NOTIFICATIONS);
		// a muted thread raises nothing, and a digest is a count of what was raised late
		$this->filterMutedConversations($page, $actor->getId());

		$expr = $page->expr();
		$page->andWhere($expr->gt('s.creation', $page->createNamedParameter($since, IQueryBuilder::PARAM_DATE)));
		$page->andWhere($expr->lte('s.creation', $page->createNamedParameter($until, IQueryBuilder::PARAM_DATE)));
		if (Nid::compare($afterNid, '0') > 0) {
			$page->andWhere($expr->gt('s.nid', $page->createNamedParameter(Nid::normalize($afterNid))));
		}

		$qb = $this->getQueryBuilder();
		$qb->select('subtype')
			->selectAlias($qb->func()->count('*'), 'count')
			->from($qb->createFunction('(' . $page->getSQL() . ')'), 'unread_page')
			->groupBy('subtype');
		$qb->setParameters($page->getParameters(), $page->getParameterTypes());

		$counts = [];
		$cursor = $qb->executeQuery();
		while (($row = $cursor->fetch()) !== false) {
			$counts[(string)$row['subtype']] = (int)$row['count'];
		}
		$cursor->closeCursor();

		return $counts;
	}

	private function notificationsBeforeQuery(DateTime $cutoff): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$expr = $qb->expr();
		$qb->from(self::TABLE_STREAM, 's')
			->where($expr->eq('s.type', $qb->createNamedParameter(SocialAppNotification::TYPE)))
			->andWhere($expr->eq('s.local', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($expr->lt('s.creation', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_DATE)));

		return $qb;
	}

	/**
	 * Removes the stream rows themselves; what hangs off them is
	 * `deleteRelatedTo()`'s business and goes first, because the cascade is
	 * keyed on rows that are about to be gone.
	 *
	 * @param string[] $prims
	 */
	public function deleteByPrims(array $prims): void {
		if ($prims === []) {
			return;
		}

		$qb = $this->getStreamDeleteSql();
		$qb->andWhere($qb->expr()->in(
			'id_prim', $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)
		));
		$qb->executeStatement();
	}

	/**
	 * Removes the rows and the cached files that belong to a set of posts,
	 * addressed by their id_prim. The single place that knows what "related to
	 * a post" means.
	 *
	 * @param string[] $prims
	 *
	 * @return int how many cached attachment rows were removed
	 */
	public function deleteRelatedTo(array $prims): int {
		if ($prims === []) {
			return 0;
		}

		$documents = $this->deleteDocumentsOf($prims);

		foreach ([
			// dest and tag rows hold the prim in a column that is not named for it
			[self::TABLE_STREAM_DEST, 'stream_id'],
			[self::TABLE_STREAM_TAGS, 'stream_id'],
			[self::TABLE_STREAM_ACTIONS, 'stream_id_prim'],
			[self::TABLE_STREAM_CARDS, 'stream_id_prim'],
			[self::TABLE_STATUS_REVISIONS, 'stream_id_prim'],
			// the Like and Announce activities pointing at the post
			[self::TABLE_ACTIONS, 'object_id_prim'],
			// and the emoji reactions on it, which are a table of their own
			[self::TABLE_REACTIONS, 'object_id_prim'],
			// and its place in any album its author put it in: a collection
			// entry pointing at a post that is gone would draw a gap
			[self::TABLE_COLLECTION_ITEMS, 'stream_id_prim'],
			// and who opened it, which is a row about a post that no longer
			// exists and that nothing will ever read again
			[self::TABLE_STREAM_VIEWS, 'stream_id_prim'],
			// and the people named in its pictures
			[self::TABLE_MEDIA_TAGS, 'stream_id_prim'],
			// where readers had got to in it, which is a bookmark into a video
			// that no longer exists
			[self::TABLE_WATCH, 'stream_id_prim'],
			// and which member of a team wrote it, which is the trail a team
			// account keeps and has nothing left to be about
			[self::TABLE_TEAM_POSTS, 'stream_id_prim'],
			// and that it was brought over from an archive: the import skips
			// any source id it remembers, so a deleted import could never be
			// brought over again
			[self::TABLE_IMPORTED_POSTS, 'stream_id_prim'],
		] as [$table, $field]) {
			$qb = $this->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->in(
					$field, $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)
				));
			$qb->executeStatement();
		}

		return $documents;
	}

	/**
	 * The cached attachments of a set of posts, and the ladders built from
	 * them: the files first, then the rows that name them — a row without its
	 * file is recoverable, a file without its row is not.
	 *
	 * @param string[] $prims
	 */
	private function deleteDocumentsOf(array $prims): int {
		$qb = $this->getQueryBuilder();
		$qb->select('nid', 'id_prim', 'local_copy', 'resized_copy')
			->from(self::TABLE_CACHE_DOCUMENTS)
			->where($qb->expr()->in(
				'parent_id_prim', $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)
			));

		$cursor = $qb->executeQuery();
		$rows = $cursor->fetchAll();
		$cursor->closeCursor();
		if ($rows === []) {
			return 0;
		}

		foreach ($rows as $row) {
			$this->cacheDocumentService->removeFromCache((string)$row['local_copy']);
			$this->cacheDocumentService->removeFromCache((string)$row['resized_copy']);

			// and the rungs of its ladder, which are files of this server's
			// own making that nothing else names: a document's row going away
			// without them is disk that no later run would ever free, because
			// the only thing that knew about them was the row
			foreach ($this->renditionsRequest->deleteForDocument((string)$row['nid']) as $rung) {
				$this->cacheDocumentService->removeFromCache($rung);
			}
		}

		$delete = $this->getQueryBuilder();
		$delete->delete(self::TABLE_CACHE_DOCUMENTS)
			->where($delete->expr()->in('id_prim', $delete->createNamedParameter(
				array_map(static fn (array $row): string => (string)$row['id_prim'], $rows),
				IQueryBuilder::PARAM_STR_ARRAY
			)));

		return $delete->executeStatement();
	}

	/**
	 * A post is addressed either by its uri or, from the dest table, by the
	 * prim it is stored under there — the two are not distinguishable by the
	 * signature, and reading an already-hashed id as a uri hashes it twice and
	 * silently matches nothing.
	 */
	private function streamPrim(SocialQueryBuilder $qb, string $id): string {
		$prim = $qb->prim($id);
		if ($prim !== '') {
			return $prim;
		}

		return (preg_match('/^[0-9a-f]{32}$/', $id) === 1) ? $id : '';
	}

	/**
	 * @param string $actorId
	 */
	public function updateAuthor(string $actorId, string $newId) {
		$qb = $this->getStreamUpdateSql();
		$qb->set('attributed_to', $qb->createNamedParameter($newId))
			->set('attributed_to_prim', $qb->createNamedParameter($qb->prim($newId)));
		$qb->limitToAttributedTo($actorId, true);

		$qb->executeStatement();
	}

	/**
	 * The fields that used to live only inside the stored wire object,
	 * written to the columns Version1000Date20260912000007 added.
	 *
	 * One helper for both write paths on purpose: an insert and an edit have to
	 * agree about what the columns hold, and `sensitive` is the reminder of
	 * what happens when only one of the two writes a field.
	 *
	 * `updated` is bound as a date in UTC. Doctrine renders a DateTime in
	 * whatever zone the object carries, so a remote `updated` of
	 * `2026-09-12T10:00:00+02:00` would otherwise be stored as 10:00 and read
	 * back as 10:00 UTC — the right text for the wrong instant.
	 */
	private function setPostFields(IQueryBuilder $qb, Stream $stream, bool $insert): void {
		$values = [
			'tags' => [json_encode($stream->getTags(), JSON_UNESCAPED_SLASHES), IQueryBuilder::PARAM_STR],
			'language' => [$stream->getLanguage(), IQueryBuilder::PARAM_STR],
			'quote' => [$stream->getQuote(), IQueryBuilder::PARAM_STR],
			'quote_authorization' => [$stream->getQuoteAuthorization(), IQueryBuilder::PARAM_STR],
			'quote_policy' => [$stream->getQuotePolicy(), IQueryBuilder::PARAM_STR],
			'updated' => [$this->updatedAsDate($stream->getUpdated()), IQueryBuilder::PARAM_DATE],
		];

		foreach ($values as $column => [$value, $type]) {
			if ($insert) {
				$qb->setValue($column, $qb->createNamedParameter($value, $type));
			} else {
				$qb->set($column, $qb->createNamedParameter($value, $type));
			}
		}
	}

	/** The ActivityPub `updated` of a post as a UTC date, or null for one never edited. */
	private function updatedAsDate(string $updated): ?DateTime {
		if ($updated === '') {
			return null;
		}

		try {
			return (new DateTime($updated))->setTimezone(new DateTimeZone('UTC'));
		} catch (Exception) {
			return null;
		}
	}

	/**
	 * Insert a new Stream in the database.
	 *
	 * @param Stream $stream
	 *
	 * @return IQueryBuilder
	 */
	public function saveStream(Stream $stream): IQueryBuilder {
		try {
			$dTime = new DateTime();
			$dTime->setTimestamp($stream->getPublishedTime());
		} catch (Exception $e) {
		}

		$cache = '[]';
		if ($stream->hasCache()) {
			$cache = json_encode($stream->getCache(), JSON_UNESCAPED_SLASHES);
		}

		$attributedTo = $stream->getAttributedTo();
		if ($attributedTo === '' && $stream->isLocal()) {
			$attributedTo = $stream->getActor()
				->getId();
		}

		if (Nid::compare($stream->getNid(), '0') === 0) {
			$stream->setNid(Nid::fromPublishedTime(
				$stream->getPublishedTime(), random_int(1, self::NID_LIMIT - 1), self::NID_LIMIT
			));
		}

		$qb = $this->getStreamInsertSql();
		$qb->setValue('nid', $qb->createNamedParameter($stream->getNid()))
			->setValue('id', $qb->createNamedParameter($stream->getId()))
			->setValue('visibility', $qb->createNamedParameter($stream->getVisibility()))
			->setValue('sensitive', $qb->createNamedParameter($stream->isSensitive() ? 1 : 0))
			->setValue('place_id', $qb->createNamedParameter($stream->getPlaceId()))
			->setValue('type', $qb->createNamedParameter($stream->getType()))
			->setValue('subtype', $qb->createNamedParameter($stream->getSubType()))
			->setValue('to', $qb->createNamedParameter($stream->getTo()))
			->setValue(
				'to_array', $qb->createNamedParameter(
					json_encode($stream->getToArray(), JSON_UNESCAPED_SLASHES)
				)
			)
			->setValue(
				'cc', $qb->createNamedParameter(
					json_encode($stream->getCcArray(), JSON_UNESCAPED_SLASHES)
				)
			)
			->setValue(
				'bcc', $qb->createNamedParameter(
					json_encode($stream->getBccArray(), JSON_UNESCAPED_SLASHES)
				)
			)
			->setValue('content', $qb->createNamedParameter($stream->getContent()))
			->setValue('summary', $qb->createNamedParameter($stream->getSummary()))
			->setValue('published', $qb->createNamedParameter($stream->getPublished()))
			->setValue('attributed_to', $qb->createNamedParameter($attributedTo))
			->setValue('attributed_to_prim', $qb->createNamedParameter($qb->prim($attributedTo)))
			->setValue('in_reply_to', $qb->createNamedParameter($stream->getInReplyTo()))
			->setValue('in_reply_to_prim', $qb->createNamedParameter($qb->prim($stream->getInReplyTo())))
			->setValue('source', $qb->createNamedParameter($stream->getSource()))
			->setValue('activity_id', $qb->createNamedParameter($stream->getActivityId()))
			->setValue('object_id', $qb->createNamedParameter($stream->getObjectId()))
			->setValue('object_id_prim', $qb->createNamedParameter($qb->prim($stream->getObjectId())))
			->setValue('details', $qb->createNamedParameter(json_encode($stream->getDetailsAll())))
			->setValue('counts_at', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE))
			->setValue('cache', $qb->createNamedParameter($cache))
			->setValue(
				'filter_duplicate',
				$qb->createNamedParameter(($stream->isFilterDuplicate()) ? '1' : '0')
			)
			->setValue(
				'instances', $qb->createNamedParameter(
					json_encode($stream->getInstancePaths(), JSON_UNESCAPED_SLASHES)
				)
			)
			->setValue('local', $qb->createNamedParameter(($stream->isLocal()) ? '1' : '0'));

		$this->setPostFields($qb, $stream, true);

		try {
			$dTime = new DateTime();
			$dTime->setTimestamp($stream->getPublishedTime());
			$qb->setValue(
				'published_time', $qb->createNamedParameter($dTime, IQueryBuilder::PARAM_DATE)
			)
				->setValue(
					'creation',
					$qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)
				);
		} catch (Exception $e) {
		}

		$qb->generatePrimaryKey($stream->getId(), 'id_prim');

		return $qb;
	}

	public function getRelatedToActor(string $actorId) {
	}

	/** How much of a thread one context request returns. */
	public const MAX_DESCENDANTS = 200;
	/** How many levels below a post one context request walks. */
	public const MAX_DESCENDANT_DEPTH = 20;

	/**
	 * The whole conversation under a post: its replies, the replies to those,
	 * and so on — what `/statuses/{id}/context` calls the descendants. Used to
	 * return the direct replies only, so a thread three messages deep showed as
	 * one reply with nothing under it.
	 *
	 * Bounded in depth and in count (Mastodon bounds the same walk), filtered
	 * for the viewer at every level, and returned depth first with siblings
	 * oldest first, so each reply follows what it answers.
	 *
	 * @return Stream[]
	 */
	public function getDescendants(string $id): array {
		$byParent = [];
		$parents = [$id];
		$count = 0;
		for ($depth = 0; $depth < self::MAX_DESCENDANT_DEPTH && $parents !== []; $depth++) {
			$remaining = self::MAX_DESCENDANTS - $count;
			if ($remaining <= 0) {
				break;
			}

			$level = $this->getRepliesTo($parents, $remaining);
			$count += count($level);
			$parents = [];
			foreach ($level as $reply) {
				$byParent[$reply->getInReplyTo()][] = $reply;
				$parents[] = $reply->getId();
			}
		}

		$thread = [];
		$this->flattenThread($id, $byParent, $thread);
		// a row was only ever selected as the child of a row above it, so this is
		// empty unless a stored in_reply_to differs from its parent's id in spelling
		foreach ($byParent as $unplaced) {
			array_push($thread, ...$unplaced);
		}

		return $thread;
	}

	/**
	 * @param array<string, Stream[]> $byParent
	 * @param Stream[] $thread
	 */
	private function flattenThread(string $parent, array &$byParent, array &$thread): void {
		foreach ($byParent[$parent] ?? [] as $reply) {
			$thread[] = $reply;
			$this->flattenThread($reply->getId(), $byParent, $thread);
		}
		unset($byParent[$parent]);
	}

	/**
	 * One level of a thread: the direct replies to a set of posts, as the
	 * viewer may see them, oldest first.
	 *
	 * @param string[] $ids
	 *
	 * @return Stream[]
	 */
	protected function getRepliesTo(array $ids, int $limit): array {
		$qb = $this->getStreamSelectSql(ACore::FORMAT_LOCAL);

		$qb->filterType(SocialAppNotification::TYPE);
		// direct messages included, as `getStreamById()` and the ancestor walk
		// both include them: a DM's recipient row is keyed on the viewer's own
		// id, so without this the descendants of a direct thread could never
		// match and a client was handed the ancestors and nothing else
		$qb->limitToViewer('sd', 'f', true, true);
		$qb->limitToDBFieldArray(
			'in_reply_to_prim',
			array_map(static fn (string $id): string => $qb->prim($id), $ids)
		);
		// a thread is read by anyone, logged in or not, and every row comes
		// back fully hydrated: the context of a post that thousands replied to
		// is not something to hand to PHP whole
		$qb->setMaxResults($limit);
		$qb->orderBy('s.published_time', 'asc');

		$qb->linkToCacheActors('ca', 's.attributed_to_prim');

		return $this->getStreamsFromRequest($qb);
	}

	/**
	 * The boosts of a post and the replies to it, each with its author joined
	 * in: the actors a Delete of the post has to reach beyond the author's own
	 * followers, because each of them carried the post to followers of theirs.
	 *
	 * @return Stream[]
	 */
	public function getAnnouncesAndRepliesTo(string $id, int $limit = 500): array {
		$qb = $this->getStreamSelectSql();
		$prim = $qb->prim($id);
		if ($prim === '') {
			return [];
		}

		$expr = $qb->expr();
		$qb->andWhere(
			$expr->orX(
				$expr->andX(
					$qb->exprLimitToDBField('type', Announce::TYPE),
					$qb->exprLimitToDBField('object_id_prim', $prim)
				),
				$qb->exprLimitToDBField('in_reply_to_prim', $prim)
			)
		);
		$qb->setMaxResults($limit);
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');

		return $this->getStreamsFromRequest($qb);
	}
}
