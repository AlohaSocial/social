<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Client;

use OCA\Social\Atproto\Chat\ChatDeclaration;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\StoredRecord;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Protocol\Tid;
use OCA\Social\Atproto\Publisher\BlueskyBlocks;
use OCA\Social\Atproto\Publisher\InteractionPublisher;
use OCA\Social\Atproto\Publisher\PictureService;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Atproto\Publisher\VideoBlobService;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Atproto\Reader\LocalRecordResolver;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Repository\RepoWrite;
use OCA\Social\Atproto\Xrpc\XrpcException;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Post;
use OCA\Social\Model\Report;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\AvatarService;
use OCA\Social\Service\BannerService;
use OCA\Social\Service\BoostService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\LikeService;
use OCA\Social\Service\ModerationService;
use OCA\Social\Service\PeerTubeService;
use OCA\Social\Service\PostReviewService;
use OCA\Social\Service\PostService;
use OCA\Social\Service\RelationshipService;
use OCA\Social\Service\ReportService;
use OCA\Social\Service\StreamService;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What a Bluesky app writes, as the Social action it stands for (§16.5).
 *
 * What Social has a counterpart for reaches a repository only through
 * `RecordMapper`, so an app's `createRecord` is not stored as it came: a
 * post becomes a Social post, a like a like, a repost a boost, a follow a
 * follow, a profile record the profile — and the record the publisher then
 * writes for it is the answer. The app shows what it gets back; the text may
 * differ where Social's mapping does (a long post cut with a link, say).
 * Lists, starter packs, feed generators and gates, which Social has no
 * counterpart for, are kept as written (`KEPT_AS_WRITTEN`). Who may send
 * the person direct messages (`chat.bsky.actor.declaration`) is the setting
 * here, which writes the record (`ChatDeclaration`). Anything else —
 * blocks and list blocks (D16) among it — is refused, and a post can only be
 * public: that is what goes to Bluesky (D8).
 */
class WriteService {
	/**
	 * Records an app writes that mean nothing to Social and everything on
	 * Bluesky — lists and their members, starter packs, feed generators,
	 * reply and quote gates: kept as the app wrote them, checked against
	 * their lexicon. A list block is a block, and refused (D16).
	 */
	public const KEPT_AS_WRITTEN = [
		'app.bsky.graph.list', 'app.bsky.graph.listitem', 'app.bsky.graph.starterpack',
		'app.bsky.feed.generator', 'app.bsky.feed.threadgate', RecordMapper::POSTGATE,
	];
	/** the records whose key is the key of the post they gate */
	private const GATES = ['app.bsky.feed.threadgate', RecordMapper::POSTGATE];

	public function __construct(
		private AccountService $accounts,
		private PostService $posts,
		private PostReviewService $review,
		private ModerationService $moderation,
		private StreamService $streams,
		private LikeService $likes,
		private BoostService $boosts,
		private FollowService $follows,
		private CacheActorService $cacheActors,
		private ReportService $reports,
		private DocumentService $documents,
		private Publisher $publisher,
		private PictureService $pictures,
		private VideoBlobService $videos,
		private InteractionPublisher $interactions,
		private RepositoryService $repositories,
		private LocalRecordResolver $local,
		private PostStore $postStore,
		private AtprotoBlobRequest $blobs,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
		private AvatarService $avatars,
		private BannerService $banners,
		private IdentityService $identities,
		private RelationshipService $relationships,
		private BlueskyBlocks $blocks,
		private ChatDeclaration $declaration,
	) {
	}

	/**
	 * `com.atproto.repo.createRecord`.
	 *
	 * @throws XrpcException
	 */
	public function create(ClientSession $session, array $body): array {
		$this->assertOwnRepo($session, $body);
		$collection = (string)($body['collection'] ?? '');
		$record = is_array($body['record'] ?? null) ? $body['record'] : [];
		if ($collection === ChatDeclaration::COLLECTION) {
			return $this->created($session, $this->declared($session, (string)($body['rkey'] ?? ''), $record));
		}
		$actor = $this->actor($session);
		$written = match ($collection) {
			RecordMapper::POST => $this->post($session, $actor, $record),
			RecordMapper::LIKE => $this->like($actor, $record),
			RecordMapper::REPOST => $this->repost($actor, $record),
			RecordMapper::FOLLOW => $this->follow($session, $actor, $record),
			BlueskyBlocks::COLLECTION => $this->block($session, $actor, $record),
			default => in_array($collection, self::KEPT_AS_WRITTEN, true)
				? $this->keep($session, $collection, (string)($body['rkey'] ?? ''), $record)
				: throw $this->unsupported($collection),
		};

		return $this->created($session, $written);
	}

	/**
	 * `com.atproto.repo.putRecord`: the profile and who may send direct
	 * messages, which are the records an app replaces.
	 *
	 * @throws XrpcException
	 */
	public function put(ClientSession $session, array $body): array {
		$this->assertOwnRepo($session, $body);
		$collection = (string)($body['collection'] ?? '');
		if (in_array($collection, self::KEPT_AS_WRITTEN, true)) {
			$record = is_array($body['record'] ?? null) ? $body['record'] : [];

			return $this->created($session, $this->keep($session, $collection, (string)($body['rkey'] ?? ''), $record));
		}
		if ($collection === ChatDeclaration::COLLECTION) {
			return $this->created($session, $this->declared($session, (string)($body['rkey'] ?? ''), is_array($body['record'] ?? null) ? $body['record'] : []));
		}
		if ($collection !== RecordMapper::PROFILE || ($body['rkey'] ?? '') !== RecordMapper::PROFILE_RKEY) {
			throw $this->unsupported($collection);
		}
		$record = is_array($body['record'] ?? null) ? $body['record'] : [];
		$actor = $this->actor($session);
		$current = $this->repositories->getRecord($session->identity->did, RecordMapper::PROFILE, RecordMapper::PROFILE_RKEY);
		$was = $current === null ? [] : DagCbor::decode($current->bytes);
		$was = is_array($was) ? $was : [];
		$this->accounts->changingProfile($session->userId, function () use ($session, $record, $was): void {
			if (is_string($record['displayName'] ?? null)) {
				$this->accounts->setDisplayName($session->userId, mb_substr(trim($record['displayName']), 0, 64));
			}
			if (is_string($record['description'] ?? null)) {
				$this->accounts->setSummary($session->userId, mb_substr($record['description'], 0, 2560));
			}
			$this->profilePicture($session, 'avatar', $record, $was);
			$this->profilePicture($session, 'banner', $record, $was);
			$this->profileRows($session, $record, $was);
		});
		// the actor as the changes left it: a new avatar is a new icon
		try {
			$actor = $this->cacheActors->getFromId($actor->getId());
		} catch (Throwable $e) {
			$this->logger->info('Changed actor not read back; the profile goes out as it was', ['exception' => $e]);
		}
		$this->publisher->publishProfile($actor);
		$stored = $this->repositories->getRecord($session->identity->did, RecordMapper::PROFILE, RecordMapper::PROFILE_RKEY);
		if ($stored === null) {
			throw new XrpcException(500, 'InternalServerError', 'The profile was not written');
		}

		return $this->created($session, $stored);
	}

	/**
	 * The pronouns and the website of a profile an app saves, where they
	 * changed: written into the account's profile rows (`Person`), the ones
	 * every other network reads them from. An app sends the whole profile,
	 * so one left out that was there is taken away.
	 */
	private function profileRows(ClientSession $session, array $record, array $was): void {
		$changed = static function (string $key) use ($record, $was): ?string {
			$now = is_string($record[$key] ?? null) ? trim($record[$key]) : '';
			$before = is_string($was[$key] ?? null) ? trim($was[$key]) : '';

			return $now === $before ? null : $now;
		};
		$pronouns = $changed('pronouns');
		$website = $changed('website');
		if ($website !== null && $website !== '' && filter_var($website, FILTER_VALIDATE_URL) === false) {
			$website = null;
		}
		if ($pronouns === null && $website === null) {
			return;
		}
		$actor = $this->accounts->getActorFromUserId($session->userId);
		$this->accounts->setFields($session->userId, Person::withProfileRows($actor->getFields(), $pronouns, $website));
	}

	/**
	 * The avatar or the banner of a profile an app saves, when it is not the
	 * one the profile had: the picture the app uploaded becomes the
	 * account's, one left out is taken away. An app sends the whole profile
	 * every time, so an unchanged picture is left alone.
	 *
	 * @param 'avatar'|'banner' $field
	 * @throws XrpcException
	 */
	private function profilePicture(ClientSession $session, string $field, array $record, array $was): void {
		$cid = self::blobCid($record[$field] ?? null);
		if ($cid === self::blobCid($was[$field] ?? null)) {
			return;
		}
		try {
			if ($cid === '') {
				$field === 'avatar' ? $this->avatars->remove($session->userId) : $this->banners->remove($session->userId);

				return;
			}
			$blob = $this->blobs->get($session->identity->did, $cid);
			if ($blob === null) {
				throw XrpcException::invalidRequest('Upload the ' . $field . ' first');
			}
			$path = (string)tempnam(sys_get_temp_dir(), 'social-atproto-profile-');
			try {
				file_put_contents($path, $this->pictures->read($blob));
				$field === 'avatar' ? $this->avatars->setFromFile($session->userId, $path) : $this->banners->setFromTempFile($session->userId, $path);
			} finally {
				@unlink($path);
			}
		} catch (XrpcException $e) {
			throw $e;
		} catch (Throwable $e) {
			throw XrpcException::invalidRequest('The ' . $field . ' was not taken: ' . $e->getMessage());
		}
	}

	/**
	 * The CID a blob value names, as an app sends it (`{$link}`) or as the
	 * repository holds it; '' for none.
	 */
	private static function blobCid(mixed $blob): string {
		$ref = is_array($blob) && ($blob['$type'] ?? 'blob') === 'blob' ? ($blob['ref'] ?? null) : null;
		if ($ref instanceof Cid) {
			return $ref->toString();
		}

		return is_array($ref) && is_string($ref['$link'] ?? null) && Cid::isValid($ref['$link']) ? $ref['$link'] : '';
	}

	/**
	 * `com.atproto.repo.deleteRecord`: the Social action the record stands
	 * for is undone, and its record goes with it.
	 *
	 * @throws XrpcException
	 */
	public function delete(ClientSession $session, array $body): array {
		$this->assertOwnRepo($session, $body);
		$collection = (string)($body['collection'] ?? '');
		$rkey = (string)($body['rkey'] ?? '');
		$record = $this->repositories->getRecord($session->identity->did, $collection, $rkey);
		if ($record === null) {
			// already gone: what the app asked for is the case
			return ['commit' => $this->commit($session)];
		}
		if (in_array($collection, self::KEPT_AS_WRITTEN, true)) {
			$this->writeRaw($session, RepoWrite::delete($collection, $rkey));

			return ['commit' => $this->commit($session)];
		}
		$actor = $this->actor($session);
		match ($collection) {
			RecordMapper::POST => $this->deletePost($actor, $record),
			RecordMapper::LIKE => $this->undo($actor, $record, true),
			RecordMapper::REPOST => $this->undo($actor, $record, false),
			RecordMapper::FOLLOW => $this->unfollow($actor, $record),
			BlueskyBlocks::COLLECTION => $this->unblock($session, $actor, $record),
			default => throw $this->unsupported($collection),
		};

		return ['commit' => $this->commit($session)];
	}

	/**
	 * `com.atproto.repo.applyWrites`: the same, one after the other. Not one
	 * commit: each Social action is its own, so a failure part-way leaves
	 * the earlier ones done.
	 *
	 * @throws XrpcException
	 */
	public function apply(ClientSession $session, array $body): array {
		$this->assertOwnRepo($session, $body);
		$writes = $body['writes'] ?? null;
		if (!is_array($writes) || !array_is_list($writes) || count($writes) > 10) {
			throw XrpcException::invalidRequest('writes must be a list of at most 10');
		}
		$results = [];
		foreach ($writes as $write) {
			$type = (string)($write['$type'] ?? '');
			$one = ['repo' => $session->identity->did, 'collection' => $write['collection'] ?? '', 'rkey' => $write['rkey'] ?? '', 'record' => $write['value'] ?? []];
			if ($type === 'com.atproto.repo.applyWrites#create') {
				$results[] = ['$type' => 'com.atproto.repo.applyWrites#createResult'] + array_intersect_key($this->create($session, $one), ['uri' => 1, 'cid' => 1, 'validationStatus' => 1]);
			} elseif ($type === 'com.atproto.repo.applyWrites#delete') {
				$this->delete($session, $one);
				$results[] = ['$type' => 'com.atproto.repo.applyWrites#deleteResult'];
			} elseif ($type === 'com.atproto.repo.applyWrites#update') {
				$results[] = ['$type' => 'com.atproto.repo.applyWrites#updateResult'] + array_intersect_key($this->put($session, $one), ['uri' => 1, 'cid' => 1, 'validationStatus' => 1]);
			} else {
				throw XrpcException::invalidRequest('Unknown write ' . $type);
			}
		}

		return ['commit' => $this->commit($session), 'results' => $results];
	}

	/**
	 * `com.atproto.repo.uploadBlob`, from the file the body was copied to:
	 * stored as any upload is, and named by the CID of what was stored.
	 * Storing takes a picture's camera metadata off and may re-encode it to
	 * fit Bluesky, so the bytes the app sent are not the bytes `getBlob`
	 * serves, and the answer names the stored ones. A video is stored as it
	 * came.
	 *
	 * @param string $mime what the app said it is; what is stored is sniffed
	 * @throws XrpcException
	 */
	public function upload(ClientSession $session, string $path, string $mime): array {
		if ((int)filesize($path) === 0) {
			throw XrpcException::invalidRequest('Empty blob');
		}
		$actor = $this->actor($session);
		try {
			$document = $this->documents->storeLocalAttachment($actor, $path);
		} catch (Throwable $e) {
			$this->logger->notice('Blob from a Bluesky app refused', ['mime' => $mime, 'exception' => $e]);

			throw XrpcException::invalidRequest('This file type is not accepted');
		}
		$blob = str_starts_with($document->getMimeType(), 'video/')
			? $this->videos->blobFor($session->identity, $document)
			: ($this->pictures->blobFor($session->identity, $actor, $document)['blob'] ?? null);
		if ($blob === null) {
			throw XrpcException::invalidRequest('This file cannot be shown on Bluesky');
		}

		return ['blob' => [
			'$type' => 'blob',
			'ref' => ['$link' => $blob->cid->toString()],
			'mimeType' => $blob->mime,
			'size' => $blob->size,
		]];
	}

	/**
	 * `com.atproto.moderation.createReport` from an app: a report here, passed
	 * on to Bluesky's moderation service in this server's name.
	 *
	 * @throws XrpcException
	 */
	public function report(ClientSession $session, array $body): array {
		$subject = is_array($body['subject'] ?? null) ? $body['subject'] : [];
		$statusIds = [];
		if (is_string($subject['uri'] ?? null)) {
			$postId = $this->knownPost($subject['uri']);
			$statusIds[] = $postId;
			$did = BlueskyIds::didOf($postId);
		} else {
			$did = (string)($subject['did'] ?? '');
		}
		if (!Syntax::isDid($did)) {
			throw XrpcException::invalidRequest('Nothing to report');
		}
		try {
			$target = $this->cacheActors->getFromId(BlueskyIds::actorId($did));
		} catch (Throwable) {
			throw XrpcException::invalidRequest('Unknown account');
		}
		$category = match ((string)($body['reasonType'] ?? '')) {
			'com.atproto.moderation.defs#reasonSpam' => Report::CATEGORY_SPAM,
			'com.atproto.moderation.defs#reasonViolation' => Report::CATEGORY_VIOLATION,
			default => Report::CATEGORY_OTHER,
		};
		$report = $this->reports->reportFromLocal($this->actor($session), $target, $statusIds, mb_substr((string)($body['reason'] ?? ''), 0, 2000), $category, true);

		return [
			'id' => $report->getId(),
			'reasonType' => (string)($body['reasonType'] ?? 'com.atproto.moderation.defs#reasonOther'),
			'subject' => $subject,
			'reportedBy' => $session->identity->did,
			'createdAt' => Syntax::datetime(time()),
		];
	}

	/**
	 * @throws XrpcException
	 */
	private function post(ClientSession $session, Person $actor, array $record): StoredRecord {
		$text = self::fullText((string)($record['text'] ?? ''), $record['facets'] ?? []);
		$post = new Post($actor);
		$post->setContent($text);
		$post->setType(Stream::TYPE_PUBLIC);
		$langs = $record['langs'] ?? [];
		if (is_array($langs) && is_string($langs[0] ?? null) && Syntax::isLanguage($langs[0])) {
			$post->setLanguage($langs[0]);
		}
		$parent = (string)($record['reply']['parent']['uri'] ?? '');
		if ($parent !== '') {
			$post->setReplyTo($this->knownPost($parent));
		}
		$embed = is_array($record['embed'] ?? null) ? $record['embed'] : [];
		$quoted = (string)($embed['record']['uri'] ?? $embed['record']['record']['uri'] ?? '');
		if ($quoted !== '') {
			$post->setQuotedId($this->knownPost($quoted));
		}
		$images = $embed['images'] ?? $embed['media']['images'] ?? [];
		$documents = is_array($images) && $images !== [] ? $this->pictures($session, $images) : [];
		$video = self::videoEmbedOf($embed);
		if ($video !== null && $documents === []) {
			$documents = [$this->uploaded($session, (string)($video['video']['ref']['$link'] ?? ''), (string)($video['alt'] ?? ''))];
		}
		$post->setMedias(array_map(
			fn (Document $document) => $document->convertToMediaAttachment($this->urlGenerator, ACore::FORMAT_ACTIVITYPUB),
			$documents
		));
		foreach (is_array($record['labels']['values'] ?? null) ? $record['labels']['values'] : [] as $label) {
			if (in_array($label['val'] ?? '', ['porn', 'sexual', 'nudity', 'graphic-media'], true)) {
				$post->setSensitive(true);
			}
		}
		$this->holdForReview($actor, $post, $documents);
		try {
			$activity = $this->posts->createPost($post);
			$note = $activity === null ? null : $this->streams->getStreamById($activity->getObjectId());
		} catch (XrpcException $e) {
			throw $e;
		} catch (Throwable $e) {
			throw XrpcException::invalidRequest($e->getMessage());
		}
		if (!$note instanceof Stream) {
			throw new XrpcException(500, 'InternalServerError', 'The post was not made');
		}
		$this->publisher->publishPost($note);

		return $this->recordOf($note->getId(), RecordMapper::POST);
	}

	/**
	 * @throws XrpcException
	 */
	private function like(Person $actor, array $record): StoredRecord {
		$postId = $this->knownPost((string)($record['subject']['uri'] ?? ''));
		try {
			$like = $this->likes->create($actor, $postId);
			$this->interactions->like($actor, $this->streams->getStreamById($postId), $like->getId());
		} catch (Throwable $e) {
			throw XrpcException::invalidRequest('Could not like: ' . $e->getMessage());
		}

		return $this->recordOf($like->getId(), RecordMapper::LIKE);
	}

	/**
	 * @throws XrpcException
	 */
	private function repost(Person $actor, array $record): StoredRecord {
		$postId = $this->knownPost((string)($record['subject']['uri'] ?? ''));
		try {
			$announce = $this->boosts->create($actor, $postId);
			$this->interactions->repost($actor, $this->streams->getStreamById($postId), $announce->getId());
		} catch (Throwable $e) {
			throw XrpcException::invalidRequest('Could not repost: ' . $e->getMessage());
		}

		return $this->recordOf($announce->getId(), RecordMapper::REPOST);
	}

	/**
	 * @throws XrpcException
	 */
	private function follow(ClientSession $session, Person $actor, array $record): StoredRecord {
		$did = (string)($record['subject'] ?? '');
		if (!Syntax::isDid($did)) {
			throw XrpcException::invalidRequest('A follow names a DID');
		}
		try {
			$localId = $this->local->actorId($did);
			$target = $this->cacheActors->getFromId($localId !== '' ? $localId : BlueskyIds::actorId($did));
			$this->follows->followActor($actor, $target);
		} catch (Throwable $e) {
			throw XrpcException::invalidRequest('Could not follow: ' . $e->getMessage());
		}
		foreach ($this->repositories->listRecords($session->identity->did, RecordMapper::FOLLOW, 100) as $stored) {
			if (($stored->value()['subject'] ?? '') === $did) {
				return $stored;
			}
		}

		throw new XrpcException(400, 'InvalidRequest', 'Following that account writes no Bluesky record');
	}

	private function deletePost(Person $actor, StoredRecord $record): void {
		try {
			$post = $this->streams->getStreamById($record->localId);
		} catch (Throwable) {
			$this->publisher->deletePost($record->localId);

			return;
		}
		if ($post->getAttributedTo() !== $actor->getId()) {
			throw XrpcException::invalidRequest('Not your post');
		}
		$this->streams->deleteLocalItem($post, $post->getType());
		$this->publisher->deletePost($record->localId);
	}

	private function undo(Person $actor, StoredRecord $record, bool $like): void {
		$postId = $this->knownPost((string)($record->value()['subject']['uri'] ?? ''), false);
		if ($postId !== '') {
			try {
				$like ? $this->likes->delete($actor, $postId) : $this->boosts->delete($actor, $postId);
			} catch (Throwable $e) {
				$this->logger->notice('Undo from a Bluesky app failed', ['exception' => $e]);
			}
		}
		$like ? $this->interactions->unlike($record->localId) : $this->interactions->unrepost($record->localId);
	}

	/**
	 * A block an app makes: a block here, published as the person publishes
	 * theirs — and refused while they do not, since it would stay here.
	 *
	 * @throws XrpcException
	 */
	private function block(ClientSession $session, Person $actor, array $record): StoredRecord {
		if (!$this->blocks->isPublished($session->userId)) {
			throw $this->unsupported(BlueskyBlocks::COLLECTION);
		}
		$did = (string)($record['subject'] ?? '');
		if (!Syntax::isDid($did)) {
			throw XrpcException::invalidRequest('A block names a DID');
		}
		try {
			$localId = $this->local->actorId($did);
			$target = $this->cacheActors->getFromId($localId !== '' ? $localId : BlueskyIds::actorId($did));
			$this->relationships->block($actor, $target);
		} catch (Throwable $e) {
			throw XrpcException::invalidRequest('Could not block: ' . $e->getMessage());
		}
		foreach ($this->repositories->getRecordsByLocalId(BlueskyBlocks::localId($actor->getId(), $target->getId())) as $stored) {
			if ($stored->collection === BlueskyBlocks::COLLECTION) {
				return $stored;
			}
		}

		throw new XrpcException(400, 'InvalidRequest', 'Blocking that account writes no Bluesky record');
	}

	/**
	 * A published block an app takes back: unblocked here, which withdraws
	 * the record; a record this server did not write is only deleted.
	 */
	private function unblock(ClientSession $session, Person $actor, StoredRecord $record): void {
		$did = (string)($record->value()['subject'] ?? '');
		try {
			$localId = $this->local->actorId($did);
			$this->relationships->unblock($actor, $this->cacheActors->getFromId($localId !== '' ? $localId : BlueskyIds::actorId($did)));
		} catch (Throwable $e) {
			$this->logger->notice('Unblock from a Bluesky app failed', ['exception' => $e]);
		}
		if ($this->repositories->getRecord($session->identity->did, $record->collection, $record->rkey) !== null) {
			$this->writeRaw($session, RepoWrite::delete($record->collection, $record->rkey));
		}
	}

	private function unfollow(Person $actor, StoredRecord $record): void {
		$did = (string)($record->value()['subject'] ?? '');
		try {
			$localId = $this->local->actorId($did);
			$target = $this->cacheActors->getFromId($localId !== '' ? $localId : BlueskyIds::actorId($did));
			$this->follows->unfollowAccount($actor, $target->getAccount());
		} catch (Throwable $e) {
			$this->logger->notice('Unfollow from a Bluesky app failed', ['exception' => $e]);
		}
		$this->publisher->removeRecord(RecordMapper::FOLLOW, $record->localId);
	}

	/**
	 * The Social post an `at://` URI names, stored first when it is a
	 * Bluesky post not read here yet.
	 *
	 * @throws XrpcException
	 */
	private function knownPost(string $uri, bool $fetch = true): string {
		$postId = $this->local->postId($uri);
		if ($postId === '') {
			throw XrpcException::invalidRequest('Not a post: ' . $uri);
		}
		if (BlueskyIds::isPostId($postId) && $fetch && !$this->postStore->isKnown($postId)) {
			$this->postStore->storeByUri($uri);
			if (!$this->postStore->isKnown($postId)) {
				throw XrpcException::invalidRequest('That post cannot be read here');
			}
		}

		return $postId;
	}

	/**
	 * A post the review rules hold is kept for a moderator, as one from the
	 * web interface or a Mastodon app is, and the app is told so. It is not
	 * published until it is approved.
	 *
	 * @param Document[] $documents
	 * @throws XrpcException
	 */
	private function holdForReview(Person $actor, Post $post, array $documents): void {
		try {
			$this->moderation->assertNotMoved($actor);
			$reason = $this->review->assess($actor, $post->getContent(), $post->getType(), PeerTubeService::soleVideo($post->getMedias()) !== null);
			if ($reason === '') {
				return;
			}
			$replyTo = $post->getReplyTo() === '' ? null : (string)$this->streams->getStreamById($post->getReplyTo())->getNid();
			$this->review->hold($actor, [
				'text' => $post->getContent(),
				'media_ids' => array_map(static fn (Document $document): string => (string)$document->getNid(), $documents),
				'poll' => [],
				'in_reply_to_id' => $replyTo,
				'quoted_status_id' => $post->getQuotedId() !== '' ? $post->getQuotedId() : null,
				'sensitive' => $post->isSensitive(),
				'spoiler_text' => '',
				'visibility' => $post->getType(),
				'language' => $post->getLanguage(),
			], $reason);
		} catch (Throwable $e) {
			throw XrpcException::invalidRequest($e->getMessage());
		}

		throw XrpcException::invalidRequest('This post is waiting for a moderator to look at it. It has been kept, there is no need to write it again.');
	}

	/**
	 * The uploads a post's pictures name, in order, with their alt text.
	 *
	 * @return Document[]
	 * @throws XrpcException
	 */
	private function pictures(ClientSession $session, array $images): array {
		$documents = [];
		foreach (array_slice($images, 0, 4) as $image) {
			$documents[] = $this->uploaded($session, (string)($image['image']['ref']['$link'] ?? ''), (string)($image['alt'] ?? ''));
		}

		return $documents;
	}

	/**
	 * The stored upload a blob reference names, with its alt text.
	 *
	 * @throws XrpcException
	 */
	private function uploaded(ClientSession $session, string $cid, string $alt): Document {
		$blob = $cid === '' ? null : $this->blobs->get($session->identity->did, $cid);
		if ($blob === null) {
			throw XrpcException::invalidRequest('A file was not uploaded here: ' . $cid);
		}
		try {
			$document = $this->documents->getDocumentById($blob->documentId);
		} catch (Throwable) {
			throw XrpcException::invalidRequest('A file is gone: ' . $cid);
		}
		$alt = trim($alt);
		if ($alt !== '') {
			$document->setDescription(mb_substr($alt, 0, 1500));
			$this->documents->updateDescription($document);
		}

		return $document;
	}

	/**
	 * The video of a post's embed, alone or beside a quote, or null.
	 */
	private static function videoEmbedOf(array $embed): ?array {
		$type = (string)($embed['$type'] ?? '');
		if ($type === 'app.bsky.embed.recordWithMedia') {
			$embed = is_array($embed['media'] ?? null) ? $embed['media'] : [];
			$type = (string)($embed['$type'] ?? '');
		}

		return $type === 'app.bsky.embed.video' && is_array($embed['video'] ?? null) ? $embed : null;
	}

	/**
	 * The text with every link as its whole address: an app shortens a link
	 * it shows (`example.com/a-long-pa…`) and keeps the address in the facet,
	 * and Social links what the text says.
	 */
	public static function fullText(string $text, mixed $facets): string {
		$links = [];
		foreach (is_array($facets) ? $facets : [] as $facet) {
			foreach (is_array($facet['features'] ?? null) ? $facet['features'] : [] as $feature) {
				if (($feature['$type'] ?? '') === 'app.bsky.richtext.facet#link' && is_string($feature['uri'] ?? null) && preg_match('#^https?://#i', $feature['uri']) === 1) {
					$start = (int)($facet['index']['byteStart'] ?? -1);
					$end = (int)($facet['index']['byteEnd'] ?? -1);
					if ($start >= 0 && $end > $start && $end <= strlen($text)) {
						$links[] = [$start, $end, $feature['uri']];
					}
				}
			}
		}
		usort($links, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
		$last = PHP_INT_MAX;
		foreach ($links as [$start, $end, $uri]) {
			if ($end > $last) {
				continue;
			}
			$text = substr($text, 0, $start) . $uri . substr($text, $end);
			$last = $start;
		}

		return $text;
	}

	/**
	 * @throws XrpcException
	 */
	private function recordOf(string $localId, string $collection): StoredRecord {
		foreach ($this->repositories->getRecordsByLocalId($localId) as $record) {
			if ($record->collection === $collection) {
				return $record;
			}
		}

		throw new XrpcException(400, 'InvalidRequest', 'That is not something that goes to Bluesky');
	}

	/**
	 * A record kept as the app wrote it: made, or replaced under its key.
	 *
	 * @throws XrpcException
	 */
	private function keep(ClientSession $session, string $collection, string $rkey, array $record): StoredRecord {
		$did = $session->identity->did;
		if (in_array($collection, self::GATES, true)) {
			$gated = Syntax::parseAtUri((string)($record['post'] ?? ''));
			if ($gated === null || $gated['authority'] !== $did || $gated['collection'] !== RecordMapper::POST || ($rkey !== '' && $rkey !== $gated['rkey'])) {
				throw XrpcException::invalidRequest('A gate is kept under the key of one of the account\'s own posts');
			}
			$rkey = $gated['rkey'];
		}
		$rkey = $rkey !== '' ? $rkey : Tid::next();
		if (($record['$type'] ?? $collection) !== $collection) {
			throw XrpcException::invalidRequest('The record is not of its collection');
		}
		$record['$type'] = $collection;
		$this->writeRaw($session, $this->repositories->getRecord($did, $collection, $rkey) === null
			? RepoWrite::create($collection, $record, '', $rkey)
			: RepoWrite::update($collection, $rkey, $record));

		return $this->repositories->getRecord($did, $collection, $rkey)
			?? throw new XrpcException(500, 'InternalServerError', 'The record was not written');
	}

	/**
	 * Who may send direct messages, as an app chose it: the setting here,
	 * and the record it wrote.
	 *
	 * @throws XrpcException
	 */
	private function declared(ClientSession $session, string $rkey, array $record): StoredRecord {
		$this->declaration->fromApp($session, $rkey, $record);

		return $this->repositories->getRecord($session->identity->did, ChatDeclaration::COLLECTION, RecordMapper::PROFILE_RKEY)
			?? throw new XrpcException(500, 'InternalServerError', 'The record was not written');
	}

	/**
	 * @throws XrpcException
	 */
	private function writeRaw(ClientSession $session, RepoWrite $write): void {
		try {
			$this->repositories->write($session->identity->did, $this->identities->signingKey($session->identity), [$write]);
		} catch (AtprotoException $e) {
			throw XrpcException::invalidRequest($e->getMessage());
		}
	}

	private function created(ClientSession $session, StoredRecord $record): array {
		return ['uri' => $record->uri(), 'cid' => $record->cid->toString(), 'commit' => $this->commit($session), 'validationStatus' => 'valid'];
	}

	private function commit(ClientSession $session): array {
		$head = $this->repositories->getHead($session->identity->did);

		return $head === null ? [] : ['cid' => $head->commitCid, 'rev' => $head->rev];
	}

	/**
	 * @throws XrpcException
	 */
	private function assertOwnRepo(ClientSession $session, array $body): void {
		$repo = strtolower((string)($body['repo'] ?? ''));
		if ($repo !== $session->identity->did && $repo !== $session->identity->handle) {
			throw new XrpcException(403, 'InvalidRequest', 'You can only write to your own repository');
		}
		if (isset($body['validate']) && $body['validate'] === false) {
			throw XrpcException::invalidRequest('Records are always validated here');
		}
	}

	private function actor(ClientSession $session): Person {
		return $this->accounts->getActorFromUserId($session->userId);
	}

	private function unsupported(string $collection): XrpcException {
		if ($collection === BlueskyBlocks::COLLECTION) {
			return XrpcException::invalidRequest('Blocks stay on this server unless you publish them: turn on publishing your blocks in Aloha Social\'s Bluesky settings');
		}

		return XrpcException::invalidRequest('This server does not write ' . $collection . ' records');
	}
}
