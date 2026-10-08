<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Client;

use OCA\Social\Atproto\Model\StoredRecord;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Publisher\InteractionPublisher;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Atproto\Reader\LocalRecordResolver;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Xrpc\XrpcException;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Post;
use OCA\Social\Model\Report;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\BoostService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\LikeService;
use OCA\Social\Service\ModerationService;
use OCA\Social\Service\PeerTubeService;
use OCA\Social\Service\PostReviewService;
use OCA\Social\Service\PostService;
use OCA\Social\Service\ReportService;
use OCA\Social\Service\StreamService;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What a Bluesky app writes, as the Social action it stands for (§16.5).
 *
 * Nothing reaches a repository except through `RecordMapper`, so an app's
 * `createRecord` is not stored as it came: a post becomes a Social post, a
 * like a like, a repost a boost, a follow a follow, a profile record the
 * profile — and the record the publisher then writes for it is the answer.
 * The app shows what it gets back; the text may differ where Social's
 * mapping does (a long post cut with a link, say). Anything else — blocks
 * (D16), lists, feeds, gates — is refused, and a post can only be public:
 * that is what goes to Bluesky (D8).
 */
class WriteService {
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
		private InteractionPublisher $interactions,
		private RepositoryService $repositories,
		private LocalRecordResolver $local,
		private PostStore $postStore,
		private AtprotoBlobRequest $blobs,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
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
		$actor = $this->actor($session);
		$written = match ($collection) {
			RecordMapper::POST => $this->post($session, $actor, $record),
			RecordMapper::LIKE => $this->like($actor, $record),
			RecordMapper::REPOST => $this->repost($actor, $record),
			RecordMapper::FOLLOW => $this->follow($session, $actor, $record),
			default => throw $this->unsupported($collection),
		};

		return $this->created($session, $written);
	}

	/**
	 * `com.atproto.repo.putRecord`: the profile, which is the one record an
	 * app replaces.
	 *
	 * @throws XrpcException
	 */
	public function put(ClientSession $session, array $body): array {
		$this->assertOwnRepo($session, $body);
		$collection = (string)($body['collection'] ?? '');
		if ($collection !== RecordMapper::PROFILE || ($body['rkey'] ?? '') !== RecordMapper::PROFILE_RKEY) {
			throw $this->unsupported($collection);
		}
		$record = is_array($body['record'] ?? null) ? $body['record'] : [];
		$actor = $this->actor($session);
		$this->accounts->changingProfile($session->userId, function () use ($session, $record): void {
			if (is_string($record['displayName'] ?? null)) {
				$this->accounts->setDisplayName($session->userId, mb_substr(trim($record['displayName']), 0, 64));
			}
			if (is_string($record['description'] ?? null)) {
				$this->accounts->setSummary($session->userId, mb_substr($record['description'], 0, 2560));
			}
		});
		$this->publisher->publishProfile($actor);
		$stored = $this->repositories->getRecord($session->identity->did, RecordMapper::PROFILE, RecordMapper::PROFILE_RKEY);
		if ($stored === null) {
			throw new XrpcException(500, 'InternalServerError', 'The profile was not written');
		}

		return $this->created($session, $stored);
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
		$actor = $this->actor($session);
		match ($collection) {
			RecordMapper::POST => $this->deletePost($actor, $record),
			RecordMapper::LIKE => $this->undo($actor, $record, true),
			RecordMapper::REPOST => $this->undo($actor, $record, false),
			RecordMapper::FOLLOW => $this->unfollow($actor, $record),
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
	 * `com.atproto.repo.uploadBlob`: a picture for a post to come, stored as
	 * any upload of the account is, and named by the CID of its bytes.
	 *
	 * @throws XrpcException
	 */
	public function upload(ClientSession $session, string $bytes, string $mime): array {
		if ($bytes === '') {
			throw XrpcException::invalidRequest('Empty blob');
		}
		$path = tempnam(sys_get_temp_dir(), 'social-atproto-blob-');
		if ($path === false) {
			throw new XrpcException(500, 'InternalServerError', 'No room for the upload');
		}
		try {
			file_put_contents($path, $bytes);
			$document = $this->documents->storeLocalAttachment($this->actor($session), $path);
		} catch (Throwable $e) {
			$this->logger->notice('Blob from a Bluesky app refused', ['exception' => $e]);

			throw XrpcException::invalidRequest('This file type is not accepted');
		} finally {
			@unlink($path);
		}
		$cid = Cid::forRaw($bytes);
		$this->blobs->put(new \OCA\Social\Atproto\Model\BlobRef($session->identity->did, $cid, $document->getId(), $document->getMimeType() !== '' ? $document->getMimeType() : $mime, strlen($bytes)));

		return ['blob' => [
			'$type' => 'blob',
			'ref' => ['$link' => $cid->toString()],
			'mimeType' => $document->getMimeType() !== '' ? $document->getMimeType() : $mime,
			'size' => strlen($bytes),
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
			$cid = (string)($image['image']['ref']['$link'] ?? '');
			$blob = $cid === '' ? null : $this->blobs->get($session->identity->did, $cid);
			if ($blob === null) {
				throw XrpcException::invalidRequest('A picture was not uploaded here: ' . $cid);
			}
			try {
				$document = $this->documents->getDocumentById($blob->documentId);
			} catch (Throwable) {
				throw XrpcException::invalidRequest('A picture is gone: ' . $cid);
			}
			$alt = trim((string)($image['alt'] ?? ''));
			if ($alt !== '') {
				$document->setDescription(mb_substr($alt, 0, 1500));
				$this->documents->updateDescription($document);
			}
			$documents[] = $document;
		}

		return $documents;
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
		if ($collection === 'app.bsky.graph.block') {
			return XrpcException::invalidRequest('Blocks stay on this server and are never published; block from Aloha Social instead');
		}

		return XrpcException::invalidRequest('This server does not write ' . $collection . ' records');
	}
}
