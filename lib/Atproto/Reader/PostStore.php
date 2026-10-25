<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\AP;
use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Moderation\Blocklist;
use OCA\Social\Atproto\Moderation\LabelerService;
use OCA\Social\Atproto\Move\PdsClient;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Publisher\InteractionPublisher;
use OCA\Social\Db\StreamCardsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Details;
use OCA\Social\Model\StreamCard;
use OCA\Social\Service\Counts\CountWriter;
use OCA\Social\Service\ImportService;
use OCA\Social\Service\SignatureService;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Puts what the AppView shows into the stream, through the same door a
 * Fediverse server's activities come in: a post as a `Create`, a repost as
 * an `Announce`, a post that is gone as a `Delete` — each from the Bluesky
 * account it is by, which is made a cached actor first when it is not one
 * yet. Nothing downstream knows a Bluesky post from any other.
 */
class PostStore {
	public function __construct(
		private PostMapper $mapper,
		private AppViewClient $appView,
		private InteractionPublisher $interactions,
		private ActorMapper $actorMapper,
		private BlueskyActorService $actors,
		private Blocklist $blocklist,
		private LabelerService $labelers,
		private ImportService $import,
		private StreamRequest $streams,
		private ITimeFactory $time,
		private LoggerInterface $logger,
		private StreamCardsRequest $cards,
		private CountWriter $countWriter,
		private ?PdsClient $pds = null,
	) {
	}

	/**
	 * A feed item: its post, and the repost that put it in the feed.
	 *
	 * @param array $item an `app.bsky.feed.defs#feedViewPost`
	 * @return int how many new rows that made
	 */
	public function storeFeedItem(array $item, bool $fetchParent = true): int {
		$post = is_array($item['post'] ?? null) ? $item['post'] : [];
		$stored = $this->storePost($post, $fetchParent) ? 1 : 0;
		$announce = $this->mapper->announce($item);
		if ($announce === null) {
			return $stored;
		}
		if (!$this->isKnown($announce['object'])) {
			return $stored;
		}
		try {
			$this->streams->getAnnounceBy($announce['object'], $announce['actor']);

			return $stored;
		} catch (StreamNotFoundException) {
		}
		$by = is_array($item['reason']['by'] ?? null) ? $item['reason']['by'] : [];
		if (!$this->ensureActor($by)) {
			return $stored;
		}

		return $stored + ($this->process($announce) ? 1 : 0);
	}

	/**
	 * A post view, stored unless it is here already or a label hides it.
	 *
	 * @param array $post an `app.bsky.feed.defs#postView`
	 */
	public function storePost(array $post, bool $fetchParent = true): bool {
		$create = $this->mapper->create($post);
		if ($create === null) {
			return false;
		}
		$id = (string)$create['object']['id'];
		$known = $this->knownPost($id);
		if ($known !== null) {
			$this->refreshKnownPostRecord($post, $create, $known);
			// Feed views already carry the latest AppView counters. Keep those
			// current as the timeline is read, instead of waiting for a separate
			// count refresh request or for somebody to open the thread.
			$this->refreshKnownPostCountsFromView($post, $known);
			// AppView can index the post before its PDS has exposed the full
			// embed view. The first import then has the text and counters but no
			// usable attachment URL. A later profile/thread/feed response carries
			// the same record again; let that fill the missing media once the PDS
			// endpoint is known, without rewriting the post or fetching on every
			// subsequent read.
			$this->refreshMissingAttachments($post, $id);

			return false;
		}
		if (!$this->ensureActor(is_array($post['author'] ?? null) ? $post['author'] : [])) {
			return false;
		}
		$recordEmbed = $post['record']['embed'] ?? null;
		if (PostMapper::hasGenericBlobs($recordEmbed) && !PostMapper::isLoggedInOnly($post)) {
			$did = (string)($post['author']['did'] ?? '');
			$pds = $this->pdsOf($did);
			if ($pds !== '') {
				$create = $this->mapper->create($post, $pds);
				if ($create === null) {
					return false;
				}
			}
		}
		$parent = (string)($create['object']['inReplyTo'] ?? '');
		if ($fetchParent && BlueskyIds::isPostId($parent) && !$this->isKnown($parent)) {
			// one hop up, so a reply is not stored without what it answers;
			// the parent's own parent is not fetched
			$this->storeByUri((string)($post['record']['reply']['parent']['uri'] ?? ''));
		}

		return $this->process($create);
	}

	/**
	 * AppView returns the current record even for a post already in the local
	 * stream. A changed CID is an edit, so import it through the ordinary Update
	 * path; otherwise the cache kept the old words and media forever.
	 *
	 * @param array $post an `app.bsky.feed.defs#postView`
	 * @param array<string, mixed> $create the ActivityPub-shaped version of the post
	 */
	private function refreshKnownPostRecord(array $post, array $create, Stream $known): void {
		if ($known->isLocal()) {
			return;
		}
		$did = (string)($post['author']['did'] ?? '');
		if (!Syntax::isDid($did) || $known->getAttributedTo() !== BlueskyIds::actorId($did)) {
			return;
		}
		$atproto = is_array($create['object']['_atproto'] ?? null) ? $create['object']['_atproto'] : [];
		$newCid = $atproto['cid'] ?? '';
		if (!is_string($newCid) || !Cid::isValid($newCid)) {
			return;
		}
		$previous = $known->getDetails(Details::ATPROTO);
		if (($previous['cid'] ?? '') === $newCid) {
			return;
		}

		$embed = $post['record']['embed'] ?? null;
		if (PostMapper::hasGenericBlobs($embed) && !PostMapper::isLoggedInOnly($post)) {
			$pds = $this->pdsOf($did);
			if ($pds !== '') {
				$create = $this->mapper->create($post, $pds) ?? $create;
			} else {
				// Do not lose media that is already cached just because the PDS is
				// temporarily unavailable while the AppView has the edited text.
				$create['object']['attachment'] = array_map(
					static fn ($attachment): array => $attachment->jsonSerialize(),
					$known->getAttachments()
				);
			}
		}

		$atproto = is_array($create['object']['_atproto'] ?? null) ? $create['object']['_atproto'] : [];
		$indexedAt = is_string($atproto['indexed_at'] ?? null) ? $atproto['indexed_at'] : '';
		$create['type'] = 'Update';
		$create['id'] = $known->getId() . '/activity/update/' . $newCid;
		$create['object']['nid'] = $known->getNid();
		$create['object']['updated'] = $this->editTime($indexedAt);

		if (!$this->process($create)) {
			return;
		}
		if (!is_array($atproto['card'] ?? null)) {
			$this->cards->deleteByStreamId($known->getId());
		}
	}

	/** Use the AppView indexing time as the revision time, with a valid fallback. */
	private function editTime(string $indexedAt): string {
		$timestamp = $indexedAt !== '' ? strtotime($indexedAt) : false;

		return gmdate('Y-m-d\\TH:i:s\\Z', $timestamp !== false ? $timestamp : $this->time->getTime());
	}

	/**
	 * Completes a previously imported Bluesky post whose embed was missing
	 * when the AppView first indexed it. Only an empty attachment list is
	 * eligible: a later partial view must never replace media already stored.
	 */
	private function refreshMissingAttachments(array $post, string $id): void {
		$recordEmbed = $post['record']['embed'] ?? null;
		if (!PostMapper::hasGenericBlobs($recordEmbed) || PostMapper::isLoggedInOnly($post)) {
			return;
		}

		$did = (string)($post['author']['did'] ?? '');
		if (!Syntax::isDid($did)) {
			return;
		}
		try {
			$stored = $this->streams->getStreamById($id);
		} catch (StreamNotFoundException) {
			return;
		}
		if ($stored->isLocal()
			|| $stored->getAttributedTo() !== BlueskyIds::actorId($did)
			|| $stored->getAttachments() !== []) {
			return;
		}

		$pds = $this->pdsOf($did);
		if ($pds === '') {
			return;
		}
		$create = $this->mapper->create($post, $pds);
		if ($create === null || !is_array($create['object']['attachment'] ?? null) || $create['object']['attachment'] === []) {
			return;
		}
		$streamedBlobs = is_array($create['object']['_atproto']['streamed_blobs'] ?? null)
			? $create['object']['_atproto']['streamed_blobs'] : [];
		try {
			$activity = AP::instance()->getItemFromData($create, atprotoStreamedBlobs: $streamedBlobs);
			$object = $activity->getObject();
			if (!$object instanceof Stream || $object->getAttachments() === []) {
				return;
			}
			$attachments = (string)json_encode($object->getAttachments(), JSON_UNESCAPED_SLASHES);
			$this->streams->setRecoveredRemoteAttachments($id, $attachments, $stored->getSubType());
		} catch (Throwable $e) {
			// Media enrichment must not turn a readable post into a failed feed or
			// thread refresh; a later AppView response can try it again.
			$this->logger->debug('Bluesky post media not enriched', ['id' => $id, 'exception' => $e]);
		}
	}

	/** The PDS endpoint saved on this actor, resolving and caching it once if needed. */
	private function pdsOf(string $did): string {
		$actor = $this->actors->cached($did);
		$details = $actor?->getDetails(Details::ATPROTO);
		$pds = is_array($details) ? (string)($details['pds'] ?? '') : '';
		if ($pds !== '' || $actor === null) {
			return $pds;
		}

		$pds = $this->actors->pdsEndpoint($did);
		if ($pds !== '') {
			$actor->setDetailArray(Details::ATPROTO, array_merge(is_array($details) ? $details : [], ['pds' => $pds]));
			try {
				$this->actors->store($actor);
			} catch (Throwable $e) {
				$this->logger->debug('Bluesky PDS endpoint not cached on actor', ['exception' => $e, 'did' => $did]);
			}
		}

		return $pds;
	}

	/**
	 * A thread response is a fresh AppView view even when its post was already
	 * imported from a feed. Refresh the remote counters in place instead of
	 * treating the known row as if the view had nothing new to say.
	 *
	 * @param array $post an `app.bsky.feed.defs#postView`
	 * @return bool whether the current view contained counts that were stored
	 */
	public function refreshKnownPostCountsFromView(array $post, ?Stream $known = null): bool {
		$id = BlueskyIds::postIdOfUri((string)($post['uri'] ?? ''));
		if ($id === '') {
			return false;
		}
		$known ??= $this->knownPost($id);
		if ($known === null || $known->getId() !== $id || $known->isLocal()) {
			// Own posts use BlueskyPublishedCountSource: it subtracts only the
			// local interactions which were actually published to Bluesky.
			return false;
		}

		$likes = BlueskyCountSource::count($post, 'likeCount');
		$reposts = BlueskyCountSource::count($post, 'repostCount');
		$replies = BlueskyCountSource::count($post, 'replyCount');
		$quotes = BlueskyCountSource::count($post, 'quoteCount');
		$counts = array_filter([
			'likes' => $likes,
			'reposts' => $reposts,
			'replies' => $replies,
			'quotes' => $quotes,
		], static fn (?int $count): bool => $count !== null);
		if ($counts === []) {
			return false;
		}

		return $this->countWriter->write(
			$id,
			$likes,
			$reposts,
			$replies,
			[PostMapper::DETAIL => $counts],
			$quotes,
		);
	}

	private function knownPost(string $id): ?Stream {
		try {
			return $this->streams->getStreamById($id);
		} catch (StreamNotFoundException) {
			return null;
		}
	}

	/**
	 * A post the AppView has by its `at://` URI, stored like one read off a
	 * feed; its parent is not fetched.
	 */
	public function storeByUri(string $uri): bool {
		if ($uri === '') {
			return false;
		}
		try {
			$answer = $this->appView->query('app.bsky.feed.getPosts', ['uris' => [$uri]], ['atproto-accept-labelers' => $this->labelers->acceptHeader()]);
		} catch (Throwable $e) {
			$this->logger->notice('Bluesky post not fetched', ['uri' => $uri, 'exception' => $e]);

			return false;
		}
		$post = is_array($answer['posts'][0] ?? null) ? $answer['posts'][0] : null;

		return $post !== null && $this->storePost($post, false);
	}

	/**
	 * Which of these stored Bluesky posts the AppView still has. A post it
	 * no longer answers for is gone — deleted, or its author is — and is
	 * deleted here the way a remote `Delete` would.
	 *
	 * @param string[] $postIds stored ids, at most 25 (one `getPosts`)
	 * @return int|null how many were deleted, or null when the AppView did not answer completely
	 */
	public function deleteGone(array $postIds): ?int {
		return $this->postViews($postIds, confirmMissing: true)['deleted'] ?? null;
	}

	/**
	 * The AppView's views of these stored Bluesky posts, by id. A post is
	 * deleted here only when the AppView explicitly marks it not found; an
	 * incomplete response is not evidence that any post was deleted.
	 *
	 * @param string[] $postIds stored ids, at most 25 (one `getPosts`); others are ignored
	 * @param bool $confirmMissing ask the author's PDS about posts omitted from a complete AppView response; only for the background deletion sweep
	 * @return array{posts: array<string, array>, deleted: int}|null null when the AppView or required PDS answer was incomplete
	 */
	public function postViews(array $postIds, ?int $timeout = null, bool $confirmMissing = false): ?array {
		$uris = [];
		foreach (array_slice($postIds, 0, 25) as $id) {
			$parsed = BlueskyIds::parsePostId($id);
			if ($parsed !== null) {
				$uris[$id] = BlueskyIds::atUri($parsed['did'], BlueskyIds::POST, $parsed['rkey']);
			}
		}
		if ($uris === []) {
			return ['posts' => [], 'deleted' => 0];
		}
		try {
			$params = ['uris' => array_values($uris)];
			$headers = ['atproto-accept-labelers' => $this->labelers->acceptHeader()];
			$answer = $timeout === null
				? $this->appView->query('app.bsky.feed.getPosts', $params, $headers)
				: $this->appView->query('app.bsky.feed.getPosts', $params, $headers, $timeout);
		} catch (Throwable $e) {
			// nothing is concluded from an AppView that did not answer
			$this->logger->notice('Bluesky posts not checked', ['exception' => $e]);

			return null;
		}
		if (!is_array($answer['posts'] ?? null)) {
			return null;
		}
		$requested = array_fill_keys(array_values($uris), true);
		$views = [];
		foreach ($answer['posts'] as $post) {
			$uri = is_array($post) ? ($post['uri'] ?? null) : null;
			if (!is_string($uri) || !isset($requested[$uri]) || isset($views[$uri])
				|| (array_key_exists('notFound', $post) && $post['notFound'] !== true)) {
				$this->logger->notice('Bluesky posts returned an invalid getPosts response');

				return null;
			}
			$views[$uri] = $post;
		}
		$posts = [];
		$deleted = 0;
		foreach ($uris as $id => $uri) {
			$view = $views[$uri] ?? null;
			if (is_array($view) && ($view['notFound'] ?? false) === true) {
				if ($this->delete($id)) {
					$deleted++;
				}
				continue;
			}
			if (is_array($view)) {
				$posts[$id] = $views[$uri];
				continue;
			}
			if (!$confirmMissing) {
				$this->logger->notice('Bluesky getPosts omitted a requested post', ['post' => $id]);

				return null;
			}

			// getPosts omits unavailable records on some AppViews; the official
			// response schema does not promise a notFound item. Confirm absence
			// against the author's PDS before deleting a stored post. An
			// unreachable PDS leaves the page retryable instead of guessing.
			$parsed = BlueskyIds::parsePostId($id);
			$exists = $parsed === null ? null : $this->postExistsAtPds($parsed);
			if ($exists === null) {
				$this->logger->notice('Bluesky post absence could not be confirmed at its PDS', ['post' => $id]);

				return null;
			}
			if (!$exists && $this->delete($id)) {
				$deleted++;
			}
		}

		return ['posts' => $posts, 'deleted' => $deleted];
	}

	/** @param array{did: string, rkey: string} $post */
	private function postExistsAtPds(array $post): ?bool {
		if ($this->pds === null) {
			return null;
		}
		$endpoint = $this->actors->pdsEndpoint($post['did']);
		if ($endpoint === '') {
			return null;
		}
		try {
			$answer = $this->pds->call($this->pds->origin($endpoint), 'com.atproto.repo.getRecord', 'GET', [
				'repo' => $post['did'],
				'collection' => BlueskyIds::POST,
				'rkey' => $post['rkey'],
			]);
		} catch (Throwable $e) {
			$this->logger->notice('Bluesky post record not checked at its PDS', ['did' => $post['did'], 'exception' => $e]);

			return null;
		}

		if ($answer['status'] >= 200 && $answer['status'] < 300 && is_array($answer['body']['value'] ?? null)) {
			return true;
		}
		if (in_array($answer['status'], [400, 404], true) && ($answer['body']['error'] ?? '') === 'RecordNotFound') {
			return false;
		}

		return null;
	}

	/**
	 * A direct message's `Create` (`ChatStore`), its author cached already:
	 * stored through the same door as a post, once.
	 */
	public function storeMessage(array $create): bool {
		$id = (string)($create['object']['id'] ?? '');

		return $id !== '' && !$this->isKnown($id) && $this->process($create);
	}

	/**
	 * A post the AppView no longer has, removed the way a remote `Delete` is.
	 */
	public function delete(string $postId): bool {
		if (!$this->isKnown($postId)) {
			return false;
		}
		$did = BlueskyIds::didOf($postId);
		$deleted = $this->process([
			'id' => $postId . '/delete',
			'type' => 'Delete',
			'actor' => BlueskyIds::actorId($did),
			'object' => $postId,
		]);
		if ($deleted) {
			try {
				$this->interactions->removeAllOf($postId);
			} catch (Throwable $e) {
				$this->logger->warning('Bluesky records of a deleted post not removed', ['post' => $postId, 'exception' => $e]);
			}
		}

		return $deleted;
	}

	/**
	 * The replies a Bluesky thread's author hid, as its threadgate lists them
	 * (`threadgateView`), kept on the thread's first post here — so they are
	 * hidden in the conversation here as they are on Bluesky.
	 */
	public function rememberHiddenReplies(?array $threadgate): void {
		$root = BlueskyIds::postIdOfUri((string)($threadgate['record']['post'] ?? ''));
		$uris = $threadgate['record']['hiddenReplies'] ?? [];
		if ($root === '' || !is_array($uris)) {
			return;
		}
		try {
			$post = $this->streams->getStreamById($root);
		} catch (StreamNotFoundException) {
			return;
		}
		$ids = array_values(array_filter(array_map(static fn (mixed $uri): string => is_string($uri) ? BlueskyIds::postIdOfUri($uri) : '', $uris)));
		if ($ids !== $post->getHiddenReplies()) {
			$post->setHiddenReplies($ids);
			$this->streams->updateDetails($post, Details::HIDDEN_REPLIES);
		}
	}

	public function isKnown(string $id): bool {
		try {
			$this->streams->getStreamById($id);

			return true;
		} catch (StreamNotFoundException) {
			return false;
		}
	}

	/**
	 * The author as a cached actor; from the cache, or from the basic
	 * profile the feed carries when the account was never seen here.
	 */
	private function ensureActor(array $profile): bool {
		$did = (string)($profile['did'] ?? '');
		if ($did === '' || $this->blocklist->isBlockedDid($did)) {
			return false;
		}
		$actor = $this->actors->cached($did);
		if ($actor !== null) {
			return !BlueskyActorService::isLimited($actor) && !$this->blocklist->isBlockedActor($actor);
		}
		try {
			$this->actors->store($this->actorMapper->person($profile));
		} catch (Throwable $e) {
			$this->logger->notice('Bluesky account not stored', ['did' => $did, 'exception' => $e]);

			return false;
		}

		return true;
	}

	private function process(array $data): bool {
		$details = $data['object']['_atproto'] ?? null;
		$card = null;
		$streamedBlobs = [];
		if (is_array($details)) {
			unset($data['object']['_atproto']);
			$card = is_array($details['card'] ?? null) ? $details['card'] : null;
			$streamedBlobs = is_array($details['streamed_blobs'] ?? null) ? $details['streamed_blobs'] : [];
			unset($details['card']);
			unset($details['streamed_blobs']);
		}
		try {
			$activity = AP::instance()->getItemFromData($data, atprotoStreamedBlobs: $streamedBlobs);
			$activity->setOrigin(BlueskyIds::HOST, SignatureService::ORIGIN_REQUEST, $this->time->getTime());
			if (is_array($details) && $activity->hasObject()) {
				$object = $activity->getObject();
				if ($object instanceof Stream) {
					$object->setDetailArray(PostMapper::DETAIL, $details);
					$object->setDetailInt(Details::LIKES, $details['likes']);
					$object->setDetailInt(Details::REMOTE_LIKES, $details['likes']);
					$object->setDetailInt(Details::BOOSTS, $details['reposts']);
					$object->setDetailInt(Details::REMOTE_BOOSTS, $details['reposts']);
					$object->setDetailInt(Details::REPLIES, $details['replies']);
					$object->setDetailInt(Details::REMOTE_REPLIES, $details['replies']);
					$object->setDetailInt(Details::QUOTES, $details['quotes']);
					$object->setDetailInt(Details::REMOTE_QUOTES, $details['quotes']);
					if (($details['quote_detached'] ?? false) === true) {
						$object->setQuoteState(Stream::QUOTE_REVOKED);
					}
				}
			}
			$this->import->parseIncomingRequest($activity);
			if ($card !== null && $activity->hasObject()) {
				$this->cards->save((new StreamCard($activity->getObject()->getId(), $card['url']))
					->setTitle($card['title'])
					->setDescription($card['description'])
					->setImage($card['image'])
					->setProviderName($card['provider']));
			}
		} catch (Throwable $e) {
			$this->logger->warning('Bluesky ' . ($data['type'] ?? '') . ' not stored', ['id' => $data['id'] ?? '', 'exception' => $e]);

			return false;
		}

		return true;
	}
}
