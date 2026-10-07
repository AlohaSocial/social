<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\Db\MediaTagsRequest;
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

class StreamService {
	use TArrayTools;

	/** Who is reading, once somebody has said; null for an anonymous read. */
	private ?Person $viewer = null;

	/** How many rows the last `getTimeline()` read; see `lastTimelineRowCount()`. */
	private int $lastTimelineRows = 0;

	/** How far up a thread one context request walks; Mastodon's cap. */
	private const ANCESTOR_LIMIT = 40;

	/**
	 * How many entries of a remote outbox page a single sync walks.
	 *
	 * The page comes from a server the caller names and is bounded only by the
	 * body size cap, so a page of minimal Notes carries tens of thousands of
	 * entries — each one a lookup and an INSERT. `AccountApiController::fetchRemoteCollection`
	 * bounds its own walk over a remote page the same way.
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
		private PlaceService $placeService,
		private ReactionSummaryService $reactionSummaryService,
		private MediaTagsRequest $mediaTagsRequest,
		private AccountService $accountService,
		private ChannelService $channelService,
	) {
	}

	/**
	 * @param Person $viewer
	 */
	public function setViewer(Person $viewer) {
		// kept here as well as handed down: the reaction bars this service
		// attaches have to say whether the reader is one of the people in them
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
				// Fail closed. `public` is spelled out above, so anything
				// arriving here is a visibility this app does not know, and
				// addressing it to the public collection — which is what used
				// to happen — publishes a post its author never meant to make
				// public. Mastodon's `private` is translated to `followers`
				// long before this point; see `Stream::visibilityFromClient()`.
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
	 * Who can see a post, read off what it addresses.
	 *
	 * Public and unlisted are decided by the collection the post names.
	 * Telling followers-only from direct needs the author's followers
	 * collection, so an author this instance has never cached cannot be
	 * judged — that is the empty answer, and a caller that has to have one
	 * treats it as direct, which is the narrower audience.
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
	 * Addresses an activity about a video post to the followers of the
	 * channel it is filed under, as well as to the author's.
	 *
	 * A PeerTube follows a channel, not the person behind it, so a video
	 * delivered to the author's followers alone never reached it. Only a
	 * public or unlisted post, and only one published as a `Video`: somebody
	 * who follows a channel has not asked for the author's followers-only
	 * posts, nor for posts that are not videos.
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
	 * followers.
	 *
	 * The followers instance path expands to the shared inbox of every
	 * instance that has one, so adding it to an activity about a direct
	 * message posts that activity to servers the post was never addressed to.
	 * A row written before the `visibility` column existed has none, and is
	 * judged by what it addresses — which is where the column came from.
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

		// A mention is addressed the way Mastodon addresses one
		// (`Account#preferred_inbox_url`): the instance's shared inbox where it
		// publishes one, the personal inbox otherwise. Who the activity is for
		// is carried by `to`/`cc`, not by which inbox it was posted to, so
		// three people mentioned on one server are one delivery instead of
		// three — and a mention of somebody who also follows the author is the
		// same inbox the follower fan-out already uses, which is what lets the
		// queue recognise it as one.
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

		$stream->addTag(
			[
				'type' => 'Mention',
				'href' => $actor->getId(),
				'name' => '@' . $account
			]
		);

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
	 * The `Emoji` tags a post needs for the shortcodes written in it.
	 *
	 * The shortcode stays in the content as text; the tag beside it says where
	 * the picture is. That is how every fediverse server does it, and it is
	 * why an instance that has never heard of `:blobcat:` still renders the
	 * post — it dereferences the icon out of the tag rather than looking the
	 * name up anywhere. A shortcode this instance has no picture for is left
	 * as the text it already was, here and everywhere it is delivered.
	 *
	 * The spoiler text is scanned too: it is written by the same person in the
	 * same composer, and a content warning whose emoji did not render was the
	 * one place the shortcode showed through.
	 */
	public function addCustomEmojis(Stream $stream, string ...$texts): void {
		// rebuilt rather than appended to: an edit that takes a shortcode out
		// would otherwise leave the tag behind, and one that puts a new
		// shortcode in has to gain a tag or the emoji renders nowhere
		$stream->setTags(array_values(array_filter(
			$stream->getTags(),
			static fn (array $tag): bool => ($tag['type'] ?? '') !== 'Emoji'
		)));

		foreach ($this->emojiService->tagsFor(implode(' ', $texts)) as $tag) {
			$stream->addTag($tag);
		}
	}

	/**
	 * The href is the hashtag timeline this instance actually serves — the URL
	 * `UnifiedSearchProvider` hands out for a hashtag. It used to be
	 * `<social url>tag/<tag>`, a path no route answers, so following a tag on a
	 * post from here led every reader, local or remote, to a 404.
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

		$parent ??= $this->streamRequest->getStreamById($replyTo);

		// what the author said may be done with their post. Sending a reply
		// their server states it will not take means telling the person who
		// wrote it that it went out — and it did, and nothing will ever show
		// it. Refused here, before anything is written or addressed.
		if (!$parent->allowsInteraction(Stream::INTERACTION_REPLY)) {
			throw new InvalidActionException(
				'the author of this post does not allow replies to it'
			);
		}

		$author = $this->cacheActorService->getFromId($parent->getAttributedTo());
		$note->setInReplyTo($replyTo);

		// Named in the reply as well as sent to: a server that hands what its
		// shared inbox receives to the accounts the activity addresses (Loops)
		// has nobody to give a reply that names only the public and our
		// followers. Mastodon addresses the author of the parent the same way.
		if ($author->getId() !== $note->getAttributedTo()) {
			if ($note->getVisibility() === Stream::TYPE_DIRECT) {
				$note->addToArray($author->getId());
			} else {
				$note->addCc($author->getId());
			}
		}

		// A PeerTube video whose comments need approving takes a reply in and
		// shows it to nobody until a human has looked. Saying so here is what
		// stops the reply looking posted when it is waiting: the state moves to
		// `approved` when the `ApproveReply` arrives (FEP-5624). Read off the
		// parent that was just fetched, rather than fetching it again.
		if ($parent->repliesNeedApproval()) {
			$note->setReplyState(Stream::REPLY_PENDING);
		}

		// The author of the post being replied to is the one recipient that
		// matters most, and `endpoints.sharedInbox` is optional — without the
		// fallback, a reply to anyone on an instance that publishes only a
		// personal inbox was addressed to host '' and never arrived.
		$inbox = $author->getSharedInbox() !== '' ? $author->getSharedInbox() : $author->getInbox();
		if ($inbox === '') {
			$this->logger->notice(
				'cannot deliver a reply: the author has neither a shared inbox nor an inbox',
				['author' => $author->getId(), 'inReplyTo' => $replyTo]
			);

			return;
		}

		// Addressed whatever the reply's audience is, and first: the person
		// being answered is the one recipient every reply has, and the audience
		// decides who else it reaches rather than whether it reaches them.
		$note->addInstancePath(
			new InstancePath($inbox, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_HIGH)
		);
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
			// only where the post itself reached them: a Delete addressed to
			// the followers path is posted to the shared inbox of every
			// instance with a follower, and for a direct message that tells
			// servers that were never recipients the post existed
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
		// their profile has one post fewer. Nothing moved this counter down at
		// all, so an account that wrote and deleted a post all day climbed
		// until the cron's recount put it back; the condition is the recount's
		// own — a public status, which is what it counts.
		if ($item->addressesPublic()
			&& in_array($item->getType(), [Note::TYPE, Question::TYPE], true)) {
			$this->accountService->bumpActorCount($item->getAttributedTo(), 'count_posts', -1);
		}
		// the post it answered counts one reply fewer now. Deleting your own
		// reply comes through here rather than through `NoteInterface`, so
		// without this the parent went on claiming a reply that no page could
		// show — and the recount has to happen after the row has gone.
		$this->streamRequest->recountReplies($item->getInReplyTo());

		// after the row has gone and the Delete is queued, with the post as it
		// last was: anything that copied it somewhere else needs the id to
		// remove its own copy, and nothing can hand that over afterwards
		$this->eventDispatcher->dispatchTyped(new PostDeletedEvent($item));
	}

	/**
	 * A post travels further than the author's followers and the instances it
	 * was addressed to: every boost carried it to the booster's followers, and
	 * every reply to the replier's. Mastodon sends the Delete to the inboxes of
	 * everyone who boosted or replied as well; without that, the post lingers on
	 * every instance that only ever saw it through a boost. The shared inbox is
	 * used where the actor has one — a server with ten boosters is one delivery.
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

	/**
	 * @param int|string $nid
	 *
	 * @return array
	 */
	public function getContextByNid(int|string $nid): array {
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

		$context = [
			'ancestors' => array_reverse($ancestors),
			'descendants' => $this->streamRequest->getDescendants($post->getId())
		];
		// the post itself as well: it is drawn with the thread — the open
		// direct message is rendered from it — and leaving it out of the batch
		// meant the one post the reader actually opened was the only one
		// without its link preview
		$this->attachCardsToPosts(array_merge($context['ancestors'], [$post], $context['descendants']));

		return $context;
	}

	/**
	 * Attach saved website preview cards to a batch of locally rendered statuses.
	 *
	 * @param Stream[] $posts
	 */
	public function attachCardsToPosts(array $posts): void {
		$this->linkPreviewService->attachCards($posts);
	}

	/**
	 * @return Stream
	 * @throws StreamNotFoundException
	 */
	public function getStreamByNid(int|string $nid): Stream {
		return $this->streamRequest->getStreamByNid($nid);
	}

	public function updateStream(Stream $stream, bool $generateDest = false): void {
		$this->streamRequest->update($stream, $generateDest);
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
		$posts = $this->withoutRepeatsOfPostsAlreadyInThePage($rows, $options);
		if ($options->getFormat() === ACore::FORMAT_LOCAL) {
			// one query each for the whole page, and only for pages a client
			// reads -- none of them is part of the wire object
			$this->linkPreviewService->attachCards($posts);
			$this->placeService->attachPlaces($posts);
			$this->reactionSummaryService->attachReactions($posts, $this->viewer?->getId() ?? '');
			$this->attachTaggedPeople($posts);
		}

		return $posts;
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
		$this->linkPreviewService->attachCards($list);
		$this->placeService->attachPlaces($list);
		$this->reactionSummaryService->attachReactions($list, $this->viewer?->getId() ?? '');
		$this->attachTaggedPeople($list);

		return $posts;
	}

	/**
	 * The people named in each post's pictures.
	 *
	 * Read here rather than in `MediaTagService`, which owns the writing side:
	 * that service names people *through* this one — it addresses them and
	 * re-sends the post — and a service that both of them depended on would be
	 * a circle. This is the same read every other page attachment above is: one
	 * query for the whole page, and only for a page a client reads, because a
	 * name under a photograph is not part of the wire object. On the wire the
	 * names are `Mention` tags, which is where a peer looks for them.
	 *
	 * @param Stream[] $posts
	 */
	public function attachTaggedPeople(array $posts): void {
		$nids = [];
		foreach ($posts as $post) {
			if ($post->getNid() > 0) {
				$nids[] = (string)$post->getNid();
			}
		}

		$tags = $this->mediaTagsRequest->forStreams($nids);
		if ($tags === []) {
			return;
		}

		$people = [];
		foreach ($posts as $post) {
			$named = [];
			foreach ($tags[$post->getNid()] ?? [] as $actorId) {
				if (!array_key_exists($actorId, $people)) {
					$people[$actorId] = $this->taggedPerson($actorId);
				}
				if ($people[$actorId] !== null) {
					$named[] = $people[$actorId];
				}
			}
			$post->setTaggedPeople($named);
		}
	}

	private function taggedPerson(string $actorId): ?Person {
		try {
			$person = $this->cacheActorService->getFromId($actorId);
			$person->setExportFormat(ACore::FORMAT_LOCAL);

			return $person;
		} catch (Exception $e) {
			// somebody this server cannot name any more is left out rather
			// than drawn as a blank
			return null;
		}
	}

	/**
	 * A page shows a post once.
	 *
	 * A boost is a row of its own that points at the post it repeats, so
	 * following both an author and somebody who boosts them put the same post
	 * in the timeline twice: once on its own and once again under "X boosted",
	 * with the same text, the same picture and the same actions. The boost is
	 * the one that goes, because the post is already there in the place its own
	 * time gives it — which is what Mastodon does with a reblog of something
	 * already in the feed. Two boosts of the same post by different people
	 * collapse to the first as well.
	 *
	 * Notifications are left alone: a favourite and a boost of the same post
	 * are two different things that happened to you, and a page of them is not
	 * a page of posts.
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
	 * How many rows the last `getTimeline()` read, before the page lost any
	 * boost of a post already in it.
	 *
	 * Whether a further page exists is a question about what the query
	 * answered, not about what survived deduplication: a page of twenty that
	 * dropped one boost is still a full page, and a `rel="next"` decided from
	 * the nineteen left is no `rel="next"` at all — every client that pages on
	 * the Link header stops at that page.
	 */
	public function lastTimelineRowCount(): int {
		return $this->lastTimelineRows;
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
		// `last` used to be advertised as `?page=1&min_id=0`, which is not a
		// page the controller serves; `paged()` derives both links from the
		// page size the outbox actually pages at.
		return OrderedCollection::paged(
			$actor->getOutbox(),
			$this->getInt('post', $actor->getDetails(Details::COUNT)),
			$actor->getOutbox()
		);
	}

	/**
	 * The `replies` collection of a post: what a peer dereferences after
	 * reading `replies` on the note itself.
	 *
	 * A reply reaches the instances that hold the post it answers and nowhere
	 * else, so without this a reader on a third instance sees a post with no
	 * replies. Mastodon publishes one on every note and walks one on every note
	 * it fetches.
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
	 * One page of it: the ids of the public replies, oldest first.
	 *
	 * Ids and not the replies themselves. A reply is its author's document,
	 * held and served by their instance, and handing out a copy of it from here
	 * would publish this instance's idea of a post somebody else may since have
	 * edited or deleted. It is also what keeps the page small: a thread of
	 * forty replies is forty URIs.
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
		if ($actor->isLocal()) {
			return 0;
		}

		$synced = 0;
		try {
			$outboxUrl = $actor->getOutbox();
			if (empty($outboxUrl)) {
				$this->logger->info('[syncRemoteTimeline] No outbox URL for actor', ['actor' => $actor->getId()]);
				return 0;
			}

			$this->logger->debug('[syncRemoteTimeline] Fetching outbox', ['url' => $outboxUrl, 'actor' => $actor->getId()]);
			$outboxData = $this->curlService->retrieveObject($outboxUrl);

			// Follow 'first' to get the first page
			$pageData = $outboxData;
			if (isset($outboxData['first'])) {
				if (is_array($outboxData['first'])) {
					$pageData = $outboxData['first'];
				} elseif (is_string($outboxData['first'])) {
					$this->logger->debug('[syncRemoteTimeline] Following first page', ['url' => $outboxData['first']]);
					$pageData = $this->curlService->retrieveObject($outboxData['first']);
				}
			}

			$items = $pageData['orderedItems'] ?? $pageData['items'] ?? [];
			if (!is_array($items)) {
				$this->logger->debug('[syncRemoteTimeline] No items in page', ['actor' => $actor->getId()]);
				return 0;
			}

			$this->logger->info('[syncRemoteTimeline] Processing items', ['actor' => $actor->getId(), 'count' => count($items)]);

			foreach (array_slice($items, 0, self::SYNC_ITEM_LIMIT) as $itemData) {
				try {
					// An OrderedCollection may list bare ids as well as objects,
					// and everything below reads the entry as an array.
					if (!is_array($itemData)) {
						continue;
					}

					// Extract the Note data from the activity item
					$itemType = $this->get('type', $itemData, '');
					if ($itemType === 'Create' && isset($itemData['object']) && is_array($itemData['object'])) {
						$noteData = $itemData['object'];
					} elseif ($itemType === 'Note') {
						$noteData = $itemData;
					} else {
						continue;
					}

					$noteId = $this->get('id', $noteData, '');
					if ($noteId === '') {
						continue;
					}

					// This path stores what a remote server said about itself, so it may
					// only yield notes that live on that server and belong to the actor
					// whose outbox is being read. Anything else is that server speaking
					// for someone it does not host.
					$attributedTo = $this->get('attributedTo', $noteData, $actor->getId());
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

					// Check if we already have it
					try {
						$this->streamRequest->getStreamById($noteId);
						continue;
					} catch (StreamNotFoundException $e) {
					}

					// Manually create a Note with only the fields we need (no attachment
					// processing). Storing without NoteInterface::save() also means
					// storing without the validation ACore::import() does on the inbox
					// path, so every field a remote server controls is put through the
					// same helpers here.
					$note = new Note();
					$note->setId($noteId);
					$note->setType('Note');
					$note->setUrl($note->validate(ACore::AS_URL, 'url', $noteData, ''));
					$note->setAttributedTo($attributedTo);
					$note->setPublished($this->get('published', $noteData, date('c')));
					$note->setContent($note->validate(ACore::AS_CONTENT, 'content', $noteData, ''));
					$note->setSummary($note->validate(ACore::AS_STRING, 'summary', $noteData, ''));
					$note->setSensitive(!empty($noteData['sensitive']));
					$note->setSource(json_encode($noteData, JSON_UNESCAPED_SLASHES));
					$note->setLocal(false);

					// Set conversation / context
					$conversation = $note->validate(ACore::AS_ID, 'conversation', $noteData, '');
					$context = $note->validate(ACore::AS_ID, 'context', $noteData, '');
					if ($context !== '') {
						$conversation = $context;
					}
					if ($conversation !== '') {
						$note->setConversation($conversation);
					}
					$note->setInReplyTo($note->validate(ACore::AS_ID, 'inReplyTo', $noteData, ''));

					// Set to/cc arrays
					$note->setToArray($this->recipientList($noteData['to'] ?? []));
					$note->setCcArray($this->recipientList($noteData['cc'] ?? []));

					// Process tags (hashtags, mentions) without triggering downloads.
					// A hashtag is the one stored string with no read-time filter behind
					// it — it reaches the composer's autocomplete as it was stored — so
					// the tag names are sanitised here, exactly as `ACore::import()` and
					// `Note::fillHashtags()` do for anything arriving over the inbox.
					$note->setTags($note->validateArray(ACore::AS_TAGS, 'tag', $noteData, []));
					$note->fillHashtags();
					// the people the post names, resolved to the accounts a
					// client reads out of `mentions`; without it every post
					// reached this way mentioned nobody
					$note->fillMentions();

					// what a peer's `to`/`cc` say the audience is. Stored
					// blank, the row exported as public to every client — so a
					// followers-only post fetched this way offered a Boost
					// button — while the timelines that compare the column
					// left it out.
					$note->setVisibility($this->visibilityOf($note) ?: Stream::TYPE_DIRECT);

					// Attachments are not processed during sync to avoid
					// memory-exhausting remote file downloads. They will be
					// fetched lazily from the source JSON when needed.

					// Convert published time
					try {
						$note->convertPublished();
					} catch (Exception $e) {
					}

					// what the origin says about its own post, where what it
					// says is a believable count; see Stream::statedCount()
					$remoteLikes = Stream::statedCount($noteData, 'likes');
					if ($remoteLikes !== null) {
						$note->setDetailInt(Details::LIKES, $remoteLikes);
						$note->setDetailInt(Details::REMOTE_LIKES, $remoteLikes);
					}
					$remoteBoosts = Stream::statedCount($noteData, 'shares');
					if ($remoteBoosts !== null) {
						$note->setDetailInt(Details::BOOSTS, $remoteBoosts);
						$note->setDetailInt(Details::REMOTE_BOOSTS, $remoteBoosts);
					}
					$remoteReplies = Stream::statedCount($noteData, 'replies');
					if ($remoteReplies !== null) {
						$note->setDetailInt(Details::REPLIES, $remoteReplies);
					}

					// Save the Note directly without going through NoteInterface
					// (which would trigger attachment downloads)
					$this->streamRequest->save($note);
					// and so without NoteInterface::updateDetails(), which is the
					// only thing that tells the post this one answered that it has
					// one more reply. Saved here, a reply to a post already stored
					// left the parent's count where it was — which, when the origin
					// publishes no `replies.totalItems`, was "no replies at all"
					$this->streamRequest->recountReplies($note->getInReplyTo());
					$synced++;
					$this->logger->debug('[syncRemoteTimeline] Saved post', ['id' => $note->getId()]);
				} catch (Exception $e) {
					$this->logger->warning('[syncRemoteTimeline] Failed to process item', [
						'error' => $e->getMessage(),
					]);
				}
			}
		} catch (Exception $e) {
			$this->logger->warning('[syncRemoteTimeline] Failed to fetch outbox', [
				'actor' => $actor->getId(),
				'error' => $e->getMessage()
			]);
		}

		if ($synced > 0) {
			$this->logger->info('[syncRemoteTimeline] Sync complete', ['actor' => $actor->getId(), 'synced' => $synced]);
		}

		return $synced;
	}

	/**
	 * The `to`/`cc` of a remote object: a single recipient may be sent as a bare
	 * string instead of a list, and anything in the list that is not a string is
	 * not an address this app can store.
	 */
	private function recipientList(mixed $value): array {
		return array_values(array_filter(is_array($value) ? $value : [$value], 'is_string'));
	}
}
