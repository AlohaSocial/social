<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\AP;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Events\PostDeletedEvent;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Group;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\OrderedCollection;
use OCA\Social\Model\ActivityPub\OrderedCollectionPage;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\Details;
use OCA\Social\Model\InstancePath;
use OCA\Social\Service\Counts\CountService;
use OCA\Social\Service\Thread\ThreadService;
use OCA\Social\Tools\Exceptions\DateTimeException;
use OCA\Social\Tools\Exceptions\MalformedArrayException;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCA\Social\Tools\Nid;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

class StreamService {
	use TArrayTools;

	/** Who is reading, once somebody has said; null for an anonymous read. */
	private ?Person $viewer = null;

	/** How many rows the last `getTimeline()` read; see `lastTimelineRowCount()`. */
	private int $lastTimelineRows = 0;

	/** Whether posts on the last page still have a remote count refresh due. */
	private bool $lastTimelineCountsFilling = false;

	/** Whether posts read through `visiblePosts()` still have counts due. */
	private bool $visiblePostsCountsFilling = false;

	private RemoteOutboxPageReader $remoteOutboxPageReader;

	/** How far up a thread one context request walks; Mastodon's cap. */
	private const ANCESTOR_LIMIT = 40;

	/**
	 * How many entries of a remote outbox page a single sync walks: the page is
	 * bounded only by the body size cap, and each entry is a lookup and an
	 * INSERT. `ActivityPubFollowListSource` bounds its walk the same way.
	 */
	private const SYNC_ITEM_LIMIT = 20;

	public function __construct(
		private IUrlGenerator $urlGenerator,
		private StreamRequest $streamRequest,
		private ActivityService $activityService,
		private CacheActorService $cacheActorService,
		private ConfigService $configService,
		private CurlService $curlService,
		private LinkPreviewService $linkPreviewService,
		private EmojiService $emojiService,
		private IEventDispatcher $eventDispatcher,
		private LoggerInterface $logger,
		private PostDecorationService $postDecorationService,
		private AccountService $accountService,
		private ChannelService $channelService,
		private ?RemoteFetchQueue $remoteFetchQueue = null,
		private ?CountService $countService = null,
		private ?ThreadService $threadService = null,
		?RemoteOutboxPageReader $remoteOutboxPageReader = null,
	) {
		$this->remoteOutboxPageReader = $remoteOutboxPageReader ?? new RemoteOutboxPageReader($curlService, $logger);
	}

	/**
	 * @param Person $viewer
	 */
	public function setViewer(Person $viewer) {
		// kept here as well as handed down: the reaction bars on the posts
		// this service serves say whether the reader is one of the people in them
		$this->viewer = $viewer;
		$this->streamRequest->setViewer($viewer);
	}

	/**
	 * @param ACore $stream
	 * @param Person $actor
	 * @param string $type
	 *
	 * @throws SocialAppConfigException
	 * @throws Exception
	 */
	public function assignItem(Acore $stream, Person $actor, string $type) {
		$stream->setId($this->configService->generateId('@' . $actor->getPreferredUsername()));
		$stream->setPublished(date('c'));

		$this->setRecipient($stream, $actor, $type);
		$stream->setLocal(true);

		if ($stream instanceof Stream) {
			$this->assignStream($stream);
		}
	}

	/**
	 * @param Stream $stream
	 *
	 * @throws Exception
	 */
	public function assignStream(Stream $stream) {
		$stream->convertPublished();
	}

	/**
	 * @param ACore $stream
	 * @param Person $actor
	 * @param string $type
	 */
	private function setRecipient(ACore $stream, Person $actor, string $type) {
		switch ($type) {
			case Stream::TYPE_UNLISTED:
				$stream->setTo($actor->getFollowers());
				$stream->addInstancePath(
					new InstancePath(
						$actor->getId(), InstancePath::TYPE_FOLLOWERS,
						InstancePath::PRIORITY_LOW
					)
				);
				$stream->addCc(ACore::CONTEXT_PUBLIC);
				break;

			case Stream::TYPE_FOLLOWERS:
				$stream->setTo($actor->getFollowers());
				$stream->addInstancePath(
					new InstancePath(
						$actor->getId(), InstancePath::TYPE_FOLLOWERS,
						InstancePath::PRIORITY_LOW
					)
				);
				break;

			case Stream::TYPE_ANNOUNCE:
				$stream->addInstancePath(
					new InstancePath(
						$actor->getId(), InstancePath::TYPE_FOLLOWERS,
						InstancePath::PRIORITY_LOW
					)
				);
				$stream->addCc($actor->getFollowers());
				break;

			case Stream::TYPE_DIRECT:
				break;

			case Stream::TYPE_PUBLIC:
				$stream->setTo(ACore::CONTEXT_PUBLIC);
				$stream->addCc($actor->getFollowers());
				$stream->addInstancePath(
					new InstancePath(
						$actor->getId(), InstancePath::TYPE_FOLLOWERS,
						InstancePath::PRIORITY_LOW
					)
				);
				break;

			default:
				// Fail closed: `public` is handled above, so anything here is a
				// visibility this app does not know and must not reach the public
				// collection. Mastodon's `private` is already `followers` by now; see
				// `Stream::visibilityFromClient()`.
				$this->logger->warning(
					'refusing to address a stream with an unknown visibility; kept private',
					['visibility' => $type, 'stream' => $stream->getId()]
				);
				break;
		}
	}

	/**
	 * Classify a stream by who can see it, for `DetailsService`.
	 */
	public function detectType(Stream $stream): void {
		$visibility = $this->visibilityOf($stream);
		if ($visibility !== '') {
			$stream->setTimeline($visibility);
		}
	}

	/**
	 * Who can see a post, read off what it addresses. Telling followers-only
	 * from direct needs the author's followers collection, so an uncached
	 * author gives the empty answer, which a caller treats as direct.
	 *
	 * @return string one of Stream::TYPE_PUBLIC, TYPE_UNLISTED,
	 *                TYPE_FOLLOWERS or TYPE_DIRECT, or '' when the author is unknown
	 */
	public function visibilityOf(Stream $stream): string {
		if (in_array(ACore::CONTEXT_PUBLIC, $stream->getToAll(), true)) {
			return Stream::TYPE_PUBLIC;
		}

		if (in_array(ACore::CONTEXT_PUBLIC, $stream->getCcArray(), true)) {
			return Stream::TYPE_UNLISTED;
		}

		try {
			$actor = $this->cacheActorService->getFromId($stream->getAttributedTo());
		} catch (Exception $e) {
			return '';
		}

		$followers = $actor->getFollowers();
		$recipients = array_merge($stream->getToAll(), $stream->getCcArray());

		return ($followers !== '' && in_array($followers, $recipients, true))
			? Stream::TYPE_FOLLOWERS
			: Stream::TYPE_DIRECT;
	}

	/**
	 * Addresses an activity about a video post to the followers of its
	 * channel as well as the author's, as PeerTube follows the channel. Only
	 * for a public or unlisted post published as a `Video`.
	 */
	public function addChannelFollowers(Stream $stream): void {
		$visibility = $stream->getVisibility();
		if ($visibility === '') {
			$visibility = $this->visibilityOf($stream);
		}

		if (!in_array($visibility, [Stream::TYPE_PUBLIC, Stream::TYPE_UNLISTED], true)
			|| !$this->configService->getAppValueBool(ConfigService::SOCIAL_PUBLISH_VIDEO)) {
			return;
		}

		$hasVideo = false;
		foreach ($stream->getAttachments() as $attachment) {
			if ($attachment->getType() === 'video') {
				$hasVideo = true;
				break;
			}
		}

		if (!$hasVideo) {
			return;
		}

		foreach ($this->channelService->attributionOf($stream->getAttributedTo()) as $attribution) {
			if ($attribution['type'] === Group::TYPE) {
				$stream->addInstancePath(
					new InstancePath($attribution['id'], InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW)
				);
			}
		}
	}

	/**
	 * Whether an activity about this post may be delivered to the author's
	 * followers: that path expands to every shared inbox with a follower, so
	 * not for a direct message. A row without `visibility` is judged by what
	 * it addresses.
	 */
	public function reachesFollowers(Stream $stream): bool {
		$visibility = $stream->getVisibility();
		if ($visibility === '') {
			$visibility = $this->visibilityOf($stream);
		}

		return in_array(
			$visibility,
			[Stream::TYPE_PUBLIC, Stream::TYPE_UNLISTED, Stream::TYPE_FOLLOWERS],
			true
		);
	}

	/**
	 * @param Stream $stream
	 * @param string $type
	 * @param string $account
	 */
	public function addRecipient(Stream $stream, string $type, string $account) {
		if ($account === '') {
			return;
		}

		try {
			$actor = $this->cacheActorService->getFromAccount($account, true);
		} catch (Exception $e) {
			$this->logger->notice('cannot resolve a mentioned account for outbound delivery', [
				'account' => $account,
				'exception' => $e,
			]);
			return;
		}

		// A mention goes to the instance's shared inbox where it publishes one,
		// the personal inbox otherwise, as Mastodon does
		// (`Account#preferred_inbox_url`): `to`/`cc` say who it is for, so one
		// server is one delivery, the same one the follower fan-out uses.
		$inbox = $actor->getSharedInbox() !== '' ? $actor->getSharedInbox() : $actor->getInbox();

		$priority = InstancePath::PRIORITY_MEDIUM;
		if ($type === Stream::TYPE_DIRECT) {
			$priority = InstancePath::PRIORITY_HIGH;
			$stream->addToArray($actor->getId());
			// `filter_duplicate` is what keeps a post out of its own author's
			// home timeline (SocialFiltersQueryBuilder::filterDuplicate()). A
			// direct message belongs in the conversation it is part of, and
			// the sender already has it there.
			$stream->setFilterDuplicate(true);
		} else {
			$stream->addCc($actor->getId());
		}

		$this->tagMention($stream, $actor, $account);

		// the addressing and the tag stand whatever happens next: they are what
		// renders the mention here and what tells every recipient who it names
		if ($inbox === '') {
			$this->logger->notice(
				'cannot deliver a mention: the actor has neither a shared inbox nor an inbox',
				['actor' => $actor->getId(), 'account' => $account]
			);

			return;
		}

		$stream->addInstancePath(
			new InstancePath($inbox, InstancePath::TYPE_INBOX, $priority)
		);
	}

	/**
	 * The `Emoji` tags a post needs for the shortcodes written in it, content
	 * and spoiler text alike. The shortcode stays in the text and the tag
	 * says where its image is, as on every fediverse server; a shortcode
	 * without an image stays text.
	 */
	public function addCustomEmojis(Stream $stream, string ...$texts): void {
		// rebuilt rather than appended to, so an edit drops tags for removed
		// shortcodes and gains them for new ones
		$stream->setTags(array_values(array_filter(
			$stream->getTags(),
			static fn (array $tag): bool => ($tag['type'] ?? '') !== 'Emoji'
		)));

		foreach ($this->emojiService->tagsFor(implode(' ', $texts)) as $tag) {
			$stream->addTag($tag);
		}
	}

	/**
	 * The href is the hashtag timeline this instance serves, the URL
	 * `UnifiedSearchProvider` hands out for a hashtag.
	 */
	public function addHashtag(Note $note, string $hashtag) {
		$note->addTag(
			[
				'type' => 'Hashtag',
				'href' => $this->urlGenerator->linkToRouteAbsolute(
					'social.Navigation.timeline', ['path' => 'tags/' . strtolower($hashtag)]
				),
				'name' => '#' . $hashtag
			]
		);
	}

	/**
	 * @param Stream $stream
	 * @param string $type
	 * @param array $accounts
	 */
	public function addRecipients(Stream $stream, string $type, array $accounts) {
		foreach ($accounts as $account) {
			$this->addRecipient($stream, $type, $account);
		}
	}

	/**
	 * @param Note $note
	 * @param array $hashtags
	 */
	public function addHashtags(Note $note, array $hashtags) {
		$note->setHashtags($hashtags);
		foreach ($hashtags as $hashtag) {
			$this->addHashtag($note, $hashtag);
		}
	}

	/**
	 * @param Note $note
	 * @param string $replyTo
	 *
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws ItemUnknownException
	 * @throws MalformedArrayException
	 * @throws RedundancyLimitException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultNotJsonException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws StreamNotFoundException
	 * @throws UnauthorizedFediverseException
	 */
	public function replyTo(Note $note, string $replyTo, ?Stream $parent = null) {
		if ($replyTo === '') {
			return;
		}

		$parent ??= $this->streamRequest->getStreamById($replyTo, true);

		// what the author said may be done with their post: a reply their server
		// will not take is refused here, before anything is written or addressed
		if (!$parent->allowsInteraction(Stream::INTERACTION_REPLY, $note->getAttributedTo())) {
			throw new InvalidActionException(
				'the author of this post does not allow replies to it'
			);
		}

		$author = $this->cacheActorService->getFromId($parent->getAttributedTo());
		$note->setInReplyTo($replyTo);

		// Named in the reply as well as sent to, as Mastodon does: a server that
		// routes its shared inbox by the addressed accounts (Loops) would
		// otherwise have nobody to give it to.
		if ($author->getId() !== $note->getAttributedTo()) {
			if ($note->getVisibility() === Stream::TYPE_DIRECT) {
				$note->addToArray($author->getId());
				// a direct message is seen by whom it mentions, not by whom it
				// is addressed to: GoToSocial stores a direct reply that names
				// its addressee in `to` alone and shows it to nobody
				$this->tagMention($note, $author, $author->getAccount());
			} else {
				$note->addCc($author->getId());
			}
		}

		// A PeerTube video whose comments need approving shows a reply to nobody
		// until a human approves it; the state moves to `approved` when the
		// `ApproveReply` arrives (FEP-5624). Read off the parent just fetched.
		if ($parent->repliesNeedApproval()) {
			$note->setReplyState(Stream::REPLY_PENDING);
		}

		// `endpoints.sharedInbox` is optional, so the personal inbox stands in
		$inbox = $author->getSharedInbox() !== '' ? $author->getSharedInbox() : $author->getInbox();
		if ($inbox === '') {
			$this->logger->notice(
				'cannot deliver a reply: the author has neither a shared inbox nor an inbox',
				['author' => $author->getId(), 'inReplyTo' => $replyTo]
			);

			return;
		}

		// the person being answered is addressed whatever the reply's audience,
		// which decides only who else it reaches
		$note->addInstancePath(
			new InstancePath($inbox, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_HIGH)
		);
	}

	/**
	 * Tags an actor as mentioned, once, and lists it in the client's
	 * `mentions`, which a client links handles from and fills a reply with.
	 */
	private function tagMention(Stream $stream, Person $actor, string $account): void {
		foreach ($stream->getTags('Mention') as $tag) {
			if (($tag['href'] ?? '') === $actor->getId()) {
				return;
			}
		}

		$stream->addTag(['type' => 'Mention', 'href' => $actor->getId(), 'name' => '@' . $account]);
		if ($stream instanceof Note) {
			$stream->addMention($actor, $actor->getId());
		}
	}

	/**
	 * @param Stream $item
	 * @param string $type
	 *
	 * @throws Exception
	 */
	public function deleteLocalItem(Stream $item, string $type = '') {
		if (!$item->isLocal()) {
			return;
		}

		$item->setActorId($item->getAttributedTo());
		try {
			$actor = $this->cacheActorService->getFromId($item->getAttributedTo());
			// only where the post itself reached them: the followers path reaches
			// every shared inbox with a follower, which for a direct message would
			// tell servers that were never recipients that the post existed
			if ($this->reachesFollowers($item)) {
				$item->addInstancePath(
					new InstancePath(
						$actor->getId(), InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW
					)
				);
			}
		} catch (\Exception $e) {
		}
		// a channel's follower that took the video in has to be told it is gone
		$this->addChannelFollowers($item);
		$this->addressBoostersAndRepliers($item);
		$this->activityService->deleteActivity($item);
		$this->streamRequest->deleteById($item->getId(), $type);
		// their profile has one post fewer, under the recount's own condition:
		// a public status
		if ($item->addressesPublic()
			&& in_array($item->getType(), [Note::TYPE, Question::TYPE], true)) {
			$this->accountService->bumpActorCount($item->getAttributedTo(), 'count_posts', -1);
		}
		// the post it answered counts one reply fewer now; deleting your own
		// reply comes through here rather than `NoteInterface`, and the recount
		// has to run after the row has gone
		$this->streamRequest->recountReplies($item->getInReplyTo());
		$this->streamRequest->recountQuotes($item->getQuote());

		// after the row has gone and the Delete is queued, with the post as it
		// last was: anything that copied it somewhere else needs the id to
		// remove its own copy, and nothing can hand that over afterwards
		$this->eventDispatcher->dispatchTyped(new PostDeletedEvent($item));
	}

	/**
	 * A post travels further than the author's followers and the instances it
	 * was addressed to, through boosts and replies, so the Delete also goes to
	 * everyone who boosted or replied, as Mastodon sends it; by shared inbox
	 * where there is one.
	 */
	private function addressBoostersAndRepliers(Stream $item): void {
		$inboxes = [];
		foreach ($this->streamRequest->getAnnouncesAndRepliesTo($item->getId()) as $interaction) {
			$actor = $interaction->getActor();
			if ($actor === null || $actor->isLocal()) {
				continue;
			}

			$inbox = $actor->getSharedInbox() !== '' ? $actor->getSharedInbox() : $actor->getInbox();
			if ($inbox === '' || isset($inboxes[$inbox])) {
				continue;
			}

			$inboxes[$inbox] = true;
			$item->addInstancePath(
				new InstancePath($inbox, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_MEDIUM)
			);
		}
	}

	/**
	 * @param string $id
	 * @param bool $asViewer
	 *
	 * @return Stream
	 * @throws StreamNotFoundException
	 */
	public function getStreamById(
		string $id,
		bool $asViewer = false,
		int $format = ACore::FORMAT_ACTIVITYPUB,
	): Stream {
		return $this->streamRequest->getStreamById($id, $asViewer, $format);
	}

	public function getStreamByIdPrim(string $idPrim, bool $asViewer = false): Stream {
		return $this->streamRequest->getStreamByIdPrim($idPrim, $asViewer);
	}

	/**
	 * @param int|string $nid
	 *
	 * @return array
	 */
	public function getContextByNid(int|string $nid, bool $withHidden = false, string $threadSort = 'oldest'): array {
		// Router path parameters arrive as decimal strings. Normalize the id at
		// the service boundary before it reaches the query builder, whose
		// integer predicate cannot accept even an in-range numeric string.
		$nid = Nid::fromStorage($nid);
		$curr = $post = $this->streamRequest->getStreamByNid($nid);

		$ancestors = [];
		for ($i = 0; $i < self::ANCESTOR_LIMIT; $i++) {
			if ($curr->getInReplyTo() === '') {
				break;
			}

			try {
				$curr = $this->streamRequest->getStreamById($curr->getInReplyTo(), true);
				$curr->setExportFormat(ACore::FORMAT_LOCAL);
				$ancestors[] = $curr;
			} catch (StreamNotFoundException $e) {
				break; // ancestor might be out of range for viewer
			}
		}

		// the rest of the conversation, from wherever it lives, read in the
		// background: from its first post, and from this one when that is
		// deeper than one read goes. The Fediverse's first reply page is also
		// read inline under a short timeout, like Bluesky's thread view.
		$root = ($ancestors === []) ? $post : end($ancestors);
		$this->threadService?->fillActivityPub($root);
		$blueskyResult = $this->threadService?->fillBluesky($root, $this->viewer);
		$blueskyRead = $blueskyResult === true;
		$blueskyPending = $this->threadService !== null && $blueskyResult === null;
		// A cached visible read may still have a deeper reply fill running, so
		// keep telling the client to poll until that job writes its replies.
		$blueskyFillPending = $blueskyRead && !$root->isLocal()
			? ($this->remoteFetchQueue?->isThreadFillPending($root->getId()) ?? false)
			: false;
		// Bluesky is read inline so the usual replies are visible immediately.
		// Queue a background fill on successful reads only when getPostThread
		// reached its ten-level limit and left a deeper branch to continue.
		$filling = $blueskyPending;
		if (!$blueskyPending && (!$blueskyRead || $root->isLocal() || $blueskyFillPending || ($this->threadService?->hasPendingBlueskyReplies() ?? false))) {
			$filling = $blueskyFillPending
				|| ($this->remoteFetchQueue?->fillThread($root) ?? false)
				|| ($this->remoteFetchQueue?->isThreadFillPending($root->getId()) ?? false);
		}
		// An inline read in progress will save the replies after this request
		// read the database, so the client asks for the context again.
		if (!$blueskyPending && $root !== $post && (!$blueskyRead || $post->isLocal())) {
			$filling = ($this->remoteFetchQueue?->fillThread($post) ?? false)
				|| ($this->remoteFetchQueue?->isThreadFillPending($post->getId()) ?? false)
				|| $filling;
		}

		// the replies the thread's author hid: left out, or marked when the
		// reader asks to see them; the author may hide or show any of them
		$hidden = array_flip($root->getHiddenReplies());
		$readerIsAuthor = $this->viewer !== null && $this->viewer->getId() === $root->getAttributedTo();
		$descendants = [];
		$hiddenCount = 0;
		$descendants = $threadSort === 'oldest'
			? $this->streamRequest->getDescendants($post->getId())
			: $this->streamRequest->getDescendants($post->getId(), $threadSort);
		$visibleDescendants = [];
		foreach ($descendants as $reply) {
			$isHidden = isset($hidden[$reply->getId()]);
			$hiddenCount += $isHidden ? 1 : 0;
			if ($isHidden && !$withHidden) {
				continue;
			}
			$visibleDescendants[] = $reply->setThreadVisibility($isHidden, $readerIsAuthor);
		}

		$context = [
			'ancestors' => array_reverse($ancestors),
			'descendants' => $visibleDescendants,
			'filling' => $filling,
			'hidden' => $hiddenCount,
		];
		// the post itself as well: it is drawn with the thread — the open
		// direct message is rendered from it
		$thread = array_merge($context['ancestors'], [$post], $context['descendants']);
		$this->postDecorationService->decorate($thread, $this->viewerId());
		// counts being asked for are more of the thread on its way, which the
		// web app reads again a little later
		if ($this->countService?->seen($thread) === true) {
			$context['filling'] = true;
		}

		return $context;
	}

	/**
	 * @return Stream
	 * @throws StreamNotFoundException
	 */
	public function getStreamByNid(int|string $nid, bool $asViewer = true): Stream {
		return $this->streamRequest->getStreamByNid($nid, $asViewer);
	}

	public function updateStream(Stream $stream, bool $generateDest = false): void {
		$this->streamRequest->update($stream, $generateDest);
	}

	/** Refresh both parents after moving or removing a local reply. */
	public function recountReplies(string $parentId): void {
		$this->streamRequest->recountReplies($parentId);
	}

	/** Refresh the cached count on a post after a local quote was moved. */
	public function recountQuotes(string $quotedId): void {
		$this->streamRequest->recountQuotes($quotedId);
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
	public function getRepliesByParentId(
		string $id,
		int $since = 0,
		int $limit = 5,
		bool $asViewer = false,
	): array {
		return $this->streamRequest->getRepliesByParentId($id, $since, $limit, $asViewer);
	}

	/**
	 * @param ProbeOptions $options
	 *
	 * @return Note[]
	 */
	public function getTimeline(ProbeOptions $options): array {
		$rows = $this->streamRequest->getTimeline($options);
		$this->lastTimelineRows = count($rows);
		$this->lastTimelineCountsFilling = false;
		$posts = $this->withoutRepeatsOfPostsAlreadyInThePage($rows, $options);
		if ($options->getFormat() === ACore::FORMAT_LOCAL) {
			// one query each for the whole page, and only for pages a client
			// reads -- none of them is part of the wire object
			$this->postDecorationService->decorate($posts, $this->viewerId());
			// what the remote posts on the page count now, asked in the background
			$this->lastTimelineCountsFilling = $this->countService?->seen($posts) ?? false;
		}

		return $posts;
	}

	/**
	 * The direct timeline's page as its messages' places in a conversation,
	 * nothing read or dressed beyond them (`StreamRequest::getDirectTimelineLinks()`).
	 *
	 * @return list<array{id: string, nid: string, inReplyTo: string, attributedTo: string}>
	 */
	public function getDirectTimelineLinks(ProbeOptions $options): array {
		return $this->streamRequest->getDirectTimelineLinks($options);
	}

	/**
	 * A page of the notification timeline as what the notification policy
	 * asks of each row (`StreamRequest::getNotificationSenders()`).
	 *
	 * @return list<array{nid: string, subType: string, sender: string, creation: int, privateMention: bool}>
	 */
	public function getNotificationSenders(ProbeOptions $options): array {
		return $this->streamRequest->getNotificationSenders($options);
	}

	/**
	 * Posts named by nid, as the viewer may see them and dressed the way a
	 * timeline page is — the page For you ranks is decided before the
	 * posts are read, so it cannot come out of `getTimeline()`.
	 *
	 * @param string[] $nids
	 *
	 * @return array<string, Stream> by nid; a post the viewer may no longer
	 *                               see is simply not there
	 */
	public function visiblePosts(array $nids): array {
		$posts = $this->streamRequest->getVisibleByNids($nids);
		$list = array_values($posts);
		$this->postDecorationService->decorate($list, $this->viewerId());
		$this->visiblePostsCountsFilling = $this->countService?->seen($list) ?? false;

		return $posts;
	}

	/** Whether posts from the last `visiblePosts()` read have counts still due. */
	public function visiblePostsCountsFilling(): bool {
		return $this->visiblePostsCountsFilling;
	}

	/** The reader's actor id, or '' for an anonymous read. */
	private function viewerId(): string {
		return $this->viewer?->getId() ?? '';
	}

	/**
	 * A page shows a post once: a boost of a post already on the page is
	 * dropped, as Mastodon does, and several boosts of one post collapse to
	 * the first. Notifications are left alone, as a like and a boost of the
	 * same post are two different things that happened.
	 *
	 * @param Stream[] $posts
	 *
	 * @return Stream[]
	 */
	private function withoutRepeatsOfPostsAlreadyInThePage(array $posts, ProbeOptions $options): array {
		if ($options->getProbe() === ProbeOptions::NOTIFICATIONS || count($posts) < 2) {
			return $posts;
		}

		$originals = [];
		foreach ($posts as $post) {
			if ($post->getType() !== Announce::TYPE && $post->getId() !== '') {
				$originals[$post->getId()] = true;
			}
		}

		$page = [];
		$repeated = [];
		foreach ($posts as $post) {
			if ($post->getType() !== Announce::TYPE) {
				$page[] = $post;
				continue;
			}

			$of = $post->getObjectId();
			if ($of !== '' && (isset($originals[$of]) || isset($repeated[$of]))) {
				continue;
			}
			if ($of !== '') {
				$repeated[$of] = true;
			}
			$page[] = $post;
		}

		return $page;
	}

	/**
	 * How many rows the last `getTimeline()` read, before deduplication:
	 * whether a further page exists depends on what the query returned, or a
	 * page that lost a boost would end the client's paging.
	 */
	public function lastTimelineRowCount(): int {
		return $this->lastTimelineRows;
	}

	/** Whether the last timeline page has count refreshes still due. */
	public function lastTimelineCountsFilling(): bool {
		return $this->lastTimelineCountsFilling;
	}

	/**
	 * The link preview of a single post, for the paths that serve one status
	 * rather than a page.
	 */
	public function attachCard(Stream $post): Stream {
		$this->linkPreviewService->attachCard($post);

		return $post;
	}

	/**
	 * @param $noteId
	 *
	 * @return Person
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws MalformedArrayException
	 * @throws StreamNotFoundException
	 * @throws RedundancyLimitException
	 * @throws SocialAppConfigException
	 * @throws ItemUnknownException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws RequestResultNotJsonException
	 * @throws UnauthorizedFediverseException
	 */
	public function getAuthorFromPostId(string $noteId) {
		$note = $this->streamRequest->getStreamById($noteId);

		return $this->cacheActorService->getFromId($note->getAttributedTo());
	}

	/**
	 * @param Person $actor
	 *
	 * @return OrderedCollection
	 */
	/**
	 * @param Person $actor
	 *
	 * @return OrderedCollection
	 */
	public function getOutboxCollection(Person $actor): OrderedCollection {
		// `paged()` derives both links from the page size the outbox pages at
		return OrderedCollection::paged(
			$actor->getOutbox(),
			$this->getInt('post', $actor->getDetails(Details::COUNT)),
			$actor->getOutbox()
		);
	}

	/**
	 * The `replies` collection of a post, which a peer dereferences after
	 * reading `replies` on the note: a reply reaches only the instances
	 * holding its parent, so other readers find replies through this, as
	 * Mastodon publishes on every note.
	 */
	/**
	 * How many likes, or dislikes, a post has had, as the collection a peer
	 * reads the number from. The count alone: who liked a post is not
	 * published, which is also what Mastodon and PeerTube do.
	 *
	 * @param string $path Stream::LIKES_PATH or Stream::DISLIKES_PATH
	 */
	public function getRatesCollection(Stream $post, string $path): OrderedCollection {
		$collection = new OrderedCollection();
		$collection->setId($post->getId() . $path);
		$collection->setTotalItems(max(0, $post->getDetailInt(
			($path === Stream::DISLIKES_PATH) ? Details::DISLIKES : Details::LIKES
		)));

		return $collection;
	}

	public function getRepliesCollection(Stream $post): OrderedCollection {
		$id = $post->getId() . Stream::REPLIES_PATH;

		return OrderedCollection::paged(
			$id, $this->streamRequest->countPublicRepliesTo($post->getId()), $id
		);
	}

	/**
	 * One page of it: the ids of the public replies, oldest first. Ids only:
	 * a reply is its author's document, served by their instance.
	 */
	public function getRepliesPage(Stream $post, int $page, string $after = ''): OrderedCollectionPage {
		$id = $post->getId() . Stream::REPLIES_PATH;

		$numbered = ($after === '');
		// a cursor is a nid; anything else starts no page
		$replies = (!$numbered && !ctype_digit($after))
			? []
			: $this->streamRequest->getPublicRepliesTo(
				$post->getId(),
				OrderedCollection::PAGE_SIZE,
				$numbered ? ($page - 1) * OrderedCollection::PAGE_SIZE : 0,
				$after
			);

		$last = end($replies);
		$next = ($last === false) ? '' : (string)$last->getNid();
		$items = array_values(array_map(static fn (Stream $reply): string => $reply->getId(), $replies));

		return $numbered
			? OrderedCollectionPage::of($id, $id, $page, $items, $next, 'min_id')
			: OrderedCollectionPage::after($id, $id, 'min_id', $after, $items, $next);
	}

	/**
	 * @param Person $actor
	 */
	public function syncRemoteTimeline(Person $actor): int {
		return $this->syncRemoteTimelinePage($actor)['synced'];
	}

	/**
	 * Read one bounded page of a remote outbox and return its continuation.
	 *
	 * @return array{synced: int, next: array{url: string, offset: int}|null, success: bool}
	 */
	public function syncRemoteTimelinePage(Person $actor, string $pageUrl = '', int $offset = 0): array {
		$result = ['synced' => 0, 'next' => null, 'success' => false];
		$page = $this->remoteOutboxPageReader->read($actor, $pageUrl);
		if (!$page['success']) {
			return $result;
		}

		$result['success'] = true;
		$synced = 0;
		$items = $page['items'];
		$pageUrl = $page['pageUrl'];
		try {

			$this->logger->info('[syncRemoteTimeline] Processing items', ['actor' => $actor->getId(), 'count' => count($items)]);

			$offset = max(0, $offset);
			$batch = array_slice($items, $offset, self::SYNC_ITEM_LIMIT);
			$remainingFetches = 6;
			$consumed = 0;
			foreach ($batch as $itemData) {
				$resolved = null;
				try {
					$resolved = $this->remoteOutboxPageReader->resolveEntry($actor, $itemData, $remainingFetches);
					if ($resolved['deferred']) {
						break;
					}
					$consumed++;
					$itemData = $resolved['item'];
					if ($itemData === null) {
						continue;
					}

					// Extract the status or Event data from the activity item
					$itemType = $this->get('type', $itemData, '');
					if ($itemType === 'Update') {
						$activityActor = $itemData['actor'] ?? '';
						$activityActor = is_array($activityActor) ? ($activityActor['id'] ?? '') : $activityActor;
						if ($activityActor !== $actor->getId()) {
							continue;
						}
					}
					if ($itemType === 'Announce') {
						$activityActor = $itemData['actor'] ?? '';
						$activityActor = is_array($activityActor) ? ($activityActor['id'] ?? '') : $activityActor;
						if ($activityActor !== $actor->getId()) {
							continue;
						}
						// Never trust foreign post content embedded by the booster.
						// The Announce handler queues its object for origin verification.
						$object = $itemData['object'] ?? '';
						$itemData['object'] = is_array($object) ? ($object['id'] ?? '') : $object;
						if (!is_string($itemData['object']) || $itemData['object'] === '') {
							continue;
						}
						$announce = AP::instance()->getItemFromData($itemData, deferAttachmentDownloads: true);
						$announce->setOrigin(ConfigService::authorityOf($actor->getId()), SignatureService::ORIGIN_REQUEST, time());
						$announce->setActor($actor);
						AP::instance()->getInterfaceForItem($announce)->processIncomingRequest($announce);
						$synced++;
						continue;
					}
					if ($itemType === 'Delete') {
						// A fetched outbox Delete still goes through the ActivityPub handler,
						// which checks the stored author and cleans up every related row; only
						// deletes the outbox owner authored are accepted.
						$delete = AP::instance()->getItemFromData($itemData);
						if ($delete->getType() !== 'Delete' || $delete->getActorId() !== $actor->getId()) {
							continue;
						}
						$delete->setOrigin(ConfigService::authorityOf($actor->getId()), SignatureService::ORIGIN_REQUEST, time());
						AP::instance()->getInterfaceForItem($delete)->processIncomingRequest($delete);
						$synced++;
						continue;
					}
					if (in_array($itemType, ['Create', 'Update'], true) && isset($itemData['object']) && is_array($itemData['object'])) {
						$noteData = $itemData['object'];
					} elseif (in_array($itemType, array_merge(['Note', 'Question'], AP::NOTE_LIKE_TYPES), true)) {
						$noteData = $itemData;
					} else {
						continue;
					}
					$objectType = $noteData['type'] ?? '';
					if (!in_array($objectType, array_merge(['Note', 'Question'], AP::NOTE_LIKE_TYPES), true)) {
						continue;
					}

					$noteId = $noteData['id'] ?? '';
					if (!is_string($noteId) || $noteId === '') {
						continue;
					}

					// This path stores what a remote server said about itself, so it only
					// yields notes on that server by the actor whose outbox is read.
					$attributedTo = $noteData['attributedTo'] ?? $actor->getId();
					if (is_array($attributedTo)) {
						$values = array_is_list($attributedTo) ? $attributedTo : [$attributedTo];
						$first = $values[0] ?? '';
						$attributedTo = is_array($first) ? ($first['id'] ?? '') : $first;
					}
					if (!is_string($attributedTo) || $attributedTo === '') {
						continue;
					}
					$actorHost = strtolower((string)parse_url($actor->getId(), PHP_URL_HOST));
					$noteHost = strtolower((string)parse_url($noteId, PHP_URL_HOST));
					$authorHost = strtolower((string)parse_url($attributedTo, PHP_URL_HOST));
					if ($actorHost === '' || $noteHost !== $actorHost || $authorHost !== $actorHost) {
						$this->logger->debug(
							'[syncRemoteTimeline] Skipping foreign item',
							['actor' => $actor->getId(), 'note' => $noteId]
						);
						continue;
					}

					try {
						$this->streamRequest->getStreamById($noteId);
						if ($itemType === 'Update') {
							$update = AP::instance()->getItemFromData($itemData, deferAttachmentDownloads: true);
							if ($update->getType() !== 'Update' || $update->getActorId() !== $actor->getId()) {
								continue;
							}
							// The document was fetched from this actor's own outbox.
							// The inbox handler still checks the stored author and edit time.
							$update->setOrigin(ConfigService::authorityOf($actor->getId()), SignatureService::ORIGIN_REQUEST, time());
							AP::instance()->getInterfaceForItem($update)->processIncomingRequest($update);
							$synced++;
						}
						continue;
					} catch (StreamNotFoundException) {
					}

					// The inbox's object parser, which knows polls, article titles, Lemmy
					// links, PeerTube videos, event facts, quotes and media. Media metadata
					// is stored now and the files left to the media worker, so one slow CDN
					// cannot hold up the page.
					$source = $noteData;
					$noteData['attributedTo'] ??= $actor->getId();
					if ($objectType !== 'Video') {
						$noteData['attributedTo'] = $attributedTo;
					}
					$noteData['published'] ??= date('c');
					$note = AP::instance()->getItemFromData($noteData, deferAttachmentDownloads: true);
					if (!$note instanceof Note
						|| strtolower((string)parse_url($note->getAttributedTo(), PHP_URL_HOST)) !== $actorHost) {
						continue;
					}
					$note->setSource(json_encode($source, JSON_UNESCAPED_SLASHES));
					$note->setSummary($note->validate(ACore::AS_STRING, 'summary', $noteData, ''));
					$note->setLocal(false);

					// the audience the peer's `to`/`cc` give: the row must not export
					// as public while the timelines leave it out
					$note->setVisibility($this->visibilityOf($note) ?: Stream::TYPE_DIRECT);

					// Save without generating a new inbox delivery or forwarding it.
					$this->streamRequest->save($note);
					// and so without NoteInterface::updateDetails(), which tells the
					// parent it has one more reply
					$this->streamRequest->recountReplies($note->getInReplyTo());
					$this->streamRequest->recountQuotes($note->getQuote());
					$synced++;
					$this->logger->debug('[syncRemoteTimeline] Saved post', ['id' => $note->getId()]);
				} catch (Throwable $e) {
					// A failed reference still consumes this entry; the next profile
					// refresh retries the first page without blocking other posts.
					if (!isset($resolved)) {
						$consumed++;
					}
					$this->logger->warning('[syncRemoteTimeline] Failed to process item', [
						'error' => $e->getMessage(),
					]);
				}
			}

			$nextOffset = $offset + $consumed;
			if ($nextOffset < count($items)) {
				$result['next'] = ['url' => $pageUrl, 'offset' => $nextOffset];
			} elseif ($page['nextUrl'] !== '') {
				$result['next'] = ['url' => $page['nextUrl'], 'offset' => 0];
			}
		} catch (Exception $e) {
			$this->logger->warning('[syncRemoteTimeline] Failed to process outbox page', [
				'actor' => $actor->getId(),
				'error' => $e->getMessage()
			]);
		}

		if ($synced > 0) {
			$this->logger->info('[syncRemoteTimeline] Sync complete', ['actor' => $actor->getId(), 'synced' => $synced]);
		}

		$result['synced'] = $synced;

		return $result;
	}

}
