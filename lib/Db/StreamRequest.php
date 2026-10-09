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
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\MiscService;
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
	use StreamCounters;
	use StreamMedia;
	use StreamSearch;
	use StreamThreads;
	use StreamStatistics;
	use StreamDeletion;

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

	/**
	 * How many rounds of candidates one page of a search reads before it
	 * answers with what it has: a word common in posts the viewer cannot see
	 * is otherwise a walk down the whole index.
	 */
	private const SEARCH_ROUNDS = 5;

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
		private SearchTermsRequest $searchTermsRequest,
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
					$this->searchTermsRequest->index($stream);
					$this->linkLocalMedia($stream, false);

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
		// an `Update{Question}` can move the end of a poll, and the sweep
		// that announces it reads only this column
		if ($stream instanceof Question) {
			$qb->set('poll_ends_at', $qb->createNamedParameter(
				$stream->getEndTimestamp(), IQueryBuilder::PARAM_INT
			));
		}
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
			// and its words, for the same reason: an edit that took a word
			// out left the post answering a search for it
			$this->searchTermsRequest->reindex($stream);
			$this->linkLocalMedia($stream, true);

			$this->dbConnection->commit();
		} catch (\Throwable $t) {
			$this->dbConnection->rollBack();

			throw $t;
		}
	}

	public function updateCache(Stream $stream, Cache $cache): void {
		$qb = $this->getStreamUpdateSql();
		$qb->set('cache', $qb->createNamedParameter(json_encode($cache, JSON_UNESCAPED_SLASHES)));

		$qb->limitToIdPrim($qb->prim($stream->getId()));

		$qb->executeStatement();
	}

	public function updateAttributedTo(string $itemId, string $to): void {
		$qb = $this->getStreamUpdateSql();
		$qb->set('attributed_to', $qb->createNamedParameter($to));
		$qb->set('attributed_to_prim', $qb->createNamedParameter($qb->prim($to)));
		$qb->set('author_host', $qb->createNamedParameter(DomainBlocksRequestBuilder::authorHostOf($to)));

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

	public function getStreamByNid(int|string $nid, bool $asViewer = true): Stream {
		$qb = $this->getStreamSelectSql(ACore::FORMAT_LOCAL);
		$qb->limitToNid($nid);
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');

		if ($asViewer) {
			$qb->limitToViewer('sd', 'f', true, true, SocialCoreQueryBuilder::HIDDEN_DIRECT);
			$qb->leftJoinStreamAction('sa');
		}

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
	 * Only the keys: the repair hydrates the page with `getIndexStreams()` in
	 * one query, and falls back to a stream at a time when that fails.
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
	 * The streams of one repair page, by nid, in one query — the same
	 * projection `getStream()` reads, without the joins a timeline adds, so a
	 * stream whose author is not cached is still returned.
	 *
	 * @param list<string> $nids
	 *
	 * @return array<string, Stream> nid => stream
	 */
	public function getIndexStreams(array $nids): array {
		if ($nids === []) {
			return [];
		}

		$qb = $this->getStreamSelectSql();
		$qb->andWhere(
			$qb->expr()->in('s.nid', $qb->createNamedParameter($nids, IQueryBuilder::PARAM_STR_ARRAY))
		);

		$streams = [];
		foreach ($this->getStreamsFromRequest($qb) as $stream) {
			$streams[(string)$stream->getNid()] = $stream;
		}

		return $streams;
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
	 * The ids of stored Bluesky posts published since a moment, after a
	 * stream number, in order: what the check for posts deleted on Bluesky
	 * walks through a page at a time.
	 *
	 * @return array<int, string> id by nid
	 */
	public function getBlueskyPostIds(int $since, int $afterNid, int $limit): array {
		$qb = $this->getQueryBuilder();
		$qb->select('nid', 'id')
			->from(self::TABLE_STREAM)
			->where($qb->expr()->like('attributed_to', $qb->createNamedParameter('https://bsky.app/profile/%')))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter(Note::TYPE)))
			->andWhere($qb->expr()->gt('nid', $qb->createNamedParameter($afterNid, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('published_time', $qb->createNamedParameter(new \DateTime('@' . $since), IQueryBuilder::PARAM_DATE)))
			->orderBy('nid', 'asc')
			->setMaxResults(max(1, $limit));
		$ids = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$ids[(int)$row['nid']] = (string)$row['id'];
		}
		$result->closeCursor();

		return $ids;
	}

	/**
	 * The posts local accounts made since a point in time that quote a
	 * Bluesky post, after a stream number, in order: what the check for
	 * quotes detached on Bluesky walks through a page at a time.
	 *
	 * @return Stream[]
	 */
	public function getLocalQuotesOfBluesky(int $since, int $afterNid, int $limit): array {
		$qb = $this->getStreamSelectSql();
		$qb->limitToLocal(true);
		$qb->andWhere($qb->expr()->like('s.quote', $qb->createNamedParameter('https://bsky.app/profile/%')))
			->andWhere($qb->expr()->gt('s.nid', $qb->createNamedParameter($afterNid, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('s.published_time', $qb->createNamedParameter(new \DateTime('@' . $since), IQueryBuilder::PARAM_DATE)))
			->orderBy('s.nid', 'asc')
			->setMaxResults(max(1, $limit));

		return $this->getStreamsFromRequest($qb);
	}

	/**
	 * The public posts local accounts made since a point in time, newest
	 * first: what the Bluesky reconcile pass checks for a missing record.
	 *
	 * @return Stream[]
	 */
	public function getLocalPublicSince(int $since, int $limit): array {
		$qb = $this->getStreamSelectSql();
		$qb->limitToStatusTypes();
		$qb->limitToLocal(true);
		$qb->andWhere($qb->expr()->eq('s.visibility', $qb->createNamedParameter(Stream::TYPE_PUBLIC)));
		$qb->andWhere($qb->expr()->gt('s.published_time', $qb->createNamedParameter(new \DateTime('@' . $since), IQueryBuilder::PARAM_DATE)));
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');
		$qb->orderBy('s.nid', 'desc');
		$qb->setMaxResults(max(1, $limit));

		return $this->getStreamsFromRequest($qb);
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
	 * The polls whose end time has passed since a moment, the earliest to
	 * close first.
	 *
	 * Read on `poll_ends_at`, the end time `save()` and `update()` copy out
	 * of the wire object, which is indexed: every poll that closed in the
	 * window is found however long ago it was published and however many
	 * other polls there are, and only those rows are read.
	 *
	 * @return Question[]
	 */
	public function getPollsClosedSince(int $since, int $limit = 50): array {
		$qb = $this->getStreamSelectSql(ACore::FORMAT_LOCAL);
		$expr = $qb->expr();
		$qb->andWhere($expr->gt('s.poll_ends_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)));
		$qb->andWhere($expr->lte('s.poll_ends_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT)));
		$qb->limitToType(Question::TYPE);
		$qb->orderBy('s.poll_ends_at', 'asc');
		$qb->addOrderBy('s.nid', 'asc');
		$qb->setMaxResults(max(1, $limit));
		$qb->linkToCacheActors('ca', 's.attributed_to_prim');

		return array_values(array_filter(
			$this->getStreamsFromRequest($qb),
			static fn (Stream $poll): bool => $poll instanceof Question
		));
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

	/**
	 * @param string $actorId
	 */
	public function updateAuthor(string $actorId, string $newId) {
		$qb = $this->getStreamUpdateSql();
		$qb->set('attributed_to', $qb->createNamedParameter($newId))
			->set('attributed_to_prim', $qb->createNamedParameter($qb->prim($newId)))
			->set('author_host', $qb->createNamedParameter(DomainBlocksRequestBuilder::authorHostOf($newId)));
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
			->setValue('author_host', $qb->createNamedParameter(DomainBlocksRequestBuilder::authorHostOf($attributedTo)))
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

		// the counts a remote post arrived with, as the origin stated them
		foreach (Stream::COUNTER_COLUMNS as $key => $column) {
			$qb->setValue($column, $qb->createNamedParameter(max(0, $stream->getDetailInt($key)), IQueryBuilder::PARAM_INT));
		}
		$this->setPostFields($qb, $stream, true);
		if ($stream instanceof Question) {
			$qb->setValue('poll_ends_at', $qb->createNamedParameter(
				$stream->getEndTimestamp(), IQueryBuilder::PARAM_INT
			));
		}

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
}
