<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social;

use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Interfaces\Activity\AcceptInterface;
use OCA\Social\Interfaces\Activity\AddInterface;
use OCA\Social\Interfaces\Activity\ApproveReplyInterface;
use OCA\Social\Interfaces\Activity\BlockInterface;
use OCA\Social\Interfaces\Activity\CreateInterface;
use OCA\Social\Interfaces\Activity\DeleteInterface;
use OCA\Social\Interfaces\Activity\MoveInterface;
use OCA\Social\Interfaces\Activity\QuoteRequestInterface;
use OCA\Social\Interfaces\Activity\RejectInterface;
use OCA\Social\Interfaces\Activity\RemoveInterface;
use OCA\Social\Interfaces\Activity\StoryAnswerInterface;
use OCA\Social\Interfaces\Activity\UndoInterface;
use OCA\Social\Interfaces\Activity\UpdateInterface;
use OCA\Social\Interfaces\Actor\ApplicationInterface;
use OCA\Social\Interfaces\Actor\GroupInterface;
use OCA\Social\Interfaces\Actor\OrganizationInterface;
use OCA\Social\Interfaces\Actor\PersonInterface;
use OCA\Social\Interfaces\Actor\ServiceInterface;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Interfaces\Internal\SocialAppNotificationInterface;
use OCA\Social\Interfaces\Object\AnnounceInterface;
use OCA\Social\Interfaces\Object\DislikeInterface;
use OCA\Social\Interfaces\Object\DocumentInterface;
use OCA\Social\Interfaces\Object\EmojiReactInterface;
use OCA\Social\Interfaces\Object\FlagInterface;
use OCA\Social\Interfaces\Object\FollowInterface;
use OCA\Social\Interfaces\Object\ImageInterface;
use OCA\Social\Interfaces\Object\LikeInterface;
use OCA\Social\Interfaces\Object\NoteInterface;
use OCA\Social\Interfaces\Object\PlaylistInterface;
use OCA\Social\Interfaces\Object\StoryInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Accept;
use OCA\Social\Model\ActivityPub\Activity\Add;
use OCA\Social\Model\ActivityPub\Activity\ApproveReply;
use OCA\Social\Model\ActivityPub\Activity\Block;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Activity\Move;
use OCA\Social\Model\ActivityPub\Activity\QuoteRequest;
use OCA\Social\Model\ActivityPub\Activity\Reject;
use OCA\Social\Model\ActivityPub\Activity\Remove;
use OCA\Social\Model\ActivityPub\Activity\StoryReaction;
use OCA\Social\Model\ActivityPub\Activity\StoryReply;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Activity\Update;
use OCA\Social\Model\ActivityPub\Activity\View;
use OCA\Social\Model\ActivityPub\Actor\Application;
use OCA\Social\Model\ActivityPub\Actor\Group;
use OCA\Social\Model\ActivityPub\Actor\Organization;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Actor\Service;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Dislike;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\EmojiReact;
use OCA\Social\Model\ActivityPub\Object\Flag;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Image;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Playlist;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Object\Story;
use OCA\Social\Model\ActivityPub\Object\Tombstone;
use OCA\Social\Model\ActivityPub\OrderedCollection;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\PeerTubeService;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\Server;

class AP {
	use TArrayTools;

	public const REDUNDANCY_LIMIT = 10;

	/**
	 * Object types other servers `Create` into a timeline that this app has no
	 * model of its own for.
	 *
	 * PeerTube posts `Video`, Plume and WriteFreely post `Article`, Mobilizon
	 * posts `Event`, Lemmy posts `Page` and Funkwhale posts `Audio`. Every one
	 * of them arrives inside an ordinary `Create` addressed to an actor's
	 * followers, and Mastodon shows them as statuses.
	 *
	 * Without a mapping they were not merely unrendered, they were invisible:
	 * `getObjectFromData()` swallowed the `ItemUnknownException` and — because
	 * `object` had arrived as an array — set neither `object` nor `objectId`, so
	 * `CreateInterface` returned on `!hasObject()` and nothing was logged.
	 * Following a PeerTube channel or a Plume blog produced a permanently empty
	 * timeline.
	 *
	 * They are therefore modelled as a `Note`, which is the shape everything
	 * downstream (storage, the client API, the timeline queries) understands;
	 * the type as it arrived on the wire is kept in `subtype` so nothing is
	 * lost.
	 */
	public const NOTE_LIKE_TYPES = ['Video', 'Article', 'Page', 'Event', 'Audio'];

	/**
	 * Resolved on first use by instance(), not at autoload time.
	 *
	 * This used to be a public mutable static filled in by an `AP::init();`
	 * statement at the bottom of this file, so merely autoloading the class
	 * built all 24 interface services out of the container -- on every request
	 * that touched ActivityPub, needed or not -- and anything anywhere could
	 * overwrite the registry.
	 */
	private static ?AP $instance = null;

	public function __construct(
		public AcceptInterface $acceptInterface,
		public AddInterface $addInterface,
		public StoryAnswerInterface $storyAnswerInterface,
		public AnnounceInterface $announceInterface,
		public BlockInterface $blockInterface,
		public CreateInterface $createInterface,
		public DeleteInterface $deleteInterface,
		public DocumentInterface $documentInterface,
		public FlagInterface $flagInterface,
		public FollowInterface $followInterface,
		public ImageInterface $imageInterface,
		public LikeInterface $likeInterface,
		public EmojiReactInterface $emojiReactInterface,
		public StoryInterface $storyInterface,
		public MoveInterface $moveInterface,
		public NoteInterface $noteInterface,
		public SocialAppNotificationInterface $notificationInterface,
		public PersonInterface $personInterface,
		public ServiceInterface $serviceInterface,
		public GroupInterface $groupInterface,
		public OrganizationInterface $organizationInterface,
		public ApplicationInterface $applicationInterface,
		public RejectInterface $rejectInterface,
		public RemoveInterface $removeInterface,
		public UndoInterface $undoInterface,
		public UpdateInterface $updateInterface,
		public QuoteRequestInterface $quoteRequestInterface,
		public ConfigService $configService,
		public ApproveReplyInterface $approveReplyInterface,
		public DislikeInterface $dislikeInterface,
		public PlaylistInterface $playlistInterface,
		public PeerTubeService $peerTubeService,
	) {
	}

	/**
	 * The registry, built on first use.
	 *
	 * A failure here used to be logged and swallowed, which left the static
	 * null and turned every call site into "call to a member function on null"
	 * somewhere else entirely. Letting the container exception out says what
	 * actually could not be built.
	 */
	public static function instance(): self {
		return self::$instance ??= Server::get(self::class);
	}

	/**
	 * Replace the registry, or clear it with null so the next instance() rebuilds.
	 *
	 * The unit suite has no server to resolve the real thing from, so it
	 * installs a double here. This is the one way in: the property behind it is
	 * private, which is the difference from the public static it replaced --
	 * that could be reassigned from anywhere, including by accident.
	 */
	public static function set(?self $instance): void {
		self::$instance = $instance;
	}

	/**
	 * Builds an item from a document somebody else wrote.
	 *
	 * `actor_info` is deliberately not read here. It is this app's own
	 * `FORMAT_LOCAL` field, holding a whole serialised actor, and `getActorId()`
	 * answers from it whenever it is set — so a peer that put one in its
	 * payload handed over the acting account itself, with the followers
	 * collection a boost is then addressed to and the account a notification
	 * names, none of it fetched and none of it checked. Who acted is the
	 * `actor` id and what the actor cache says about it.
	 */
	public function getItemFromData(array $data, ?ACore $parent = null, int $level = 0): ACore {
		if (++$level > self::REDUNDANCY_LIMIT) {
			throw new RedundancyLimitException((string)$level);
		}

		$item = $this->getSimpleItemFromData($data);
		if ($parent !== null) {
			$item->setParent($parent);
		}

		$this->getObjectFromData($data, $item, $level);

		return $item;
	}

	public function getObjectFromData(array $data, ACore &$item, int $level) {
		$objectData = $this->getArray('object', $data, []);
		if ($objectData === []) {
			$objectId = $this->get('object', $data, '');
			if ($objectId !== '') {
				$item->setObjectId($objectId);
			}

			return;
		}

		try {
			$item->setObject($this->getItemFromData($objectData, $item, $level));
		} catch (ItemUnknownException $e) {
			// A type this app has no model for. The activity used to be left
			// referring to nothing at all — neither `object` nor `objectId` —
			// so it was discarded with no trace of what had arrived. Keeping
			// the id names it, and lets the inbox log it and a later pass
			// resolve it.
			$objectId = $this->get('id', $objectData, '');
			if ($objectId !== '') {
				$item->setObjectId($objectId);
			}
		}
	}

	public function getSimpleItemFromData(array $data): Acore {
		$type = $this->get('type', $data, '');
		$item = $this->getItemFromType($type);
		$item->import($data);

		if (in_array($type, self::NOTE_LIKE_TYPES, true)) {
			$item->setSubType($type);
			$item->setType(Note::TYPE);

			if ($type === PeerTubeService::TYPE) {
				$this->fillVideo($item, $data);
			} else {
				$this->fillNoteLikeContent($item, $data);
			}
		}

		$item->setSource(json_encode($data, JSON_UNESCAPED_SLASHES));

		return $item;
	}

	/**
	 * A `Video`, `Audio` or `Event` usually carries its title in `name` and
	 * nothing at all in `content`. Rendered as-is that is an empty post, so the
	 * title (and the link to the thing itself, when it is not the object id
	 * again) becomes the content — the same substitution Mastodon makes.
	 *
	 * The title is not also copied into the note's `name`: on a Note that field
	 * means one thing here, the option a poll vote chose (see
	 * PollService::handleIncomingVote), and giving it a second meaning is how a
	 * post would end up counted as a vote.
	 */
	private function fillNoteLikeContent(ACore $item, array $data): void {
		if (!$item instanceof Note) {
			return;
		}

		$this->fillTitle($item, $data);
		$this->appendLinkAttachment($item, $data);
	}

	private function fillTitle(Note $item, array $data): void {
		if ($item->getContent() !== '') {
			return;
		}

		$name = trim($this->get('name', $data, ''));
		if ($name === '') {
			return;
		}

		$content = '<p>' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';

		$url = $item->getUrl();
		if ($url !== '' && $url !== $item->getId()) {
			$href = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
			$content .= '<p><a href="' . $href . '">' . $href . '</a></p>';
		}

		$item->setContent($content);
	}

	/**
	 * The link a link post is about, which Lemmy sends as the only
	 * `attachment` of a `Page` — `{type: "Link", href: …}` — and nowhere else:
	 * no `url`, nothing in the content. There is no model for a `Link`
	 * attachment, so it used to be dropped with the rest of what
	 * `importAttachments()` cannot store, and the one thing a link post is
	 * went with it. It is appended to the content as a link, which is also
	 * what the link preview is made from.
	 */
	private function appendLinkAttachment(Note $item, array $data): void {
		foreach (ACore::listOf('attachment', $data) as $attachment) {
			if (!is_array($attachment) || ($attachment['type'] ?? '') !== 'Link') {
				continue;
			}

			$href = is_string($attachment['href'] ?? null) ? $attachment['href'] : '';
			$scheme = strtolower((string)parse_url($href, PHP_URL_SCHEME));
			if ($scheme !== 'https' && $scheme !== 'http') {
				continue;
			}

			$escaped = htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
			if (!str_contains($item->getContent(), 'href="' . $escaped . '"')) {
				$item->setContent(
					$item->getContent() . '<p><a href="' . $escaped . '">' . $escaped . '</a></p>'
				);
			}

			return;
		}
	}

	/**
	 * A `Video` is the one note-like type this app understands in detail,
	 * because it is the one whose whole point is a file to play. PeerTube --
	 * which is what publishes them -- writes four things somewhere an ordinary
	 * `Note` does not look:
	 *
	 * - `url` is a *list*: the watch page, one link per transcoded resolution,
	 *   the HLS playlist, a torrent and a magnet URI. `Item::setUrl()` asked
	 *   for a string and got none of them.
	 * - `attributedTo` is a list of two actors, the channel and the account
	 *   behind it, where every other server sends one id.
	 * - the title is in `name`, and `content` is the description in *markdown*
	 *   (the object says so in its own `mediaType`).
	 * - the thumbnail is in `icon`.
	 *
	 * So a federated video used to arrive as a post attributed to nobody,
	 * pointing nowhere, with a wall of unrendered markdown for a body and no
	 * picture. What it becomes here is a post with a title that links to the
	 * video, its description under it, and one attachment: the video itself,
	 * streamed from the instance that holds it rather than copied here.
	 *
	 * @see PeerTubeService
	 */
	private function fillVideo(ACore $item, array $data): void {
		if (!$item instanceof Note) {
			return;
		}

		$attributedTo = $this->peerTubeService->attributedTo($data);
		if ($attributedTo !== '') {
			$item->setAttributedTo($attributedTo);
		}

		$watch = $this->peerTubeService->watchUrl($data);
		if ($watch !== '') {
			$item->setUrl($watch);
		}

		$item->setContent($this->peerTubeService->content($data));

		// whether a reply to this will be shown to anybody before a human has
		// looked at it: a reply written here to a moderated video is held on
		// the other side, and this is what lets the client say so
		if ($this->peerTubeService->repliesNeedApproval($data)) {
			$item->setReplyPolicy(Stream::REPLY_POLICY_APPROVAL);
		}

		// everything else the object says that a `Note` has nowhere to put —
		// the category, the licence, the chapters, the captions, the counters.
		// A video is not a post with a rectangle in it.
		$meta = $this->peerTubeService->videoMeta($data);
		if ($meta !== []) {
			$item->setVideoMeta($meta);
		}

		$attachments = $this->peerTubeService->attachments($data, $item);
		if ($attachments !== []) {
			$item->setAttachments($attachments);
		}
	}

	public function getItemFromType(string $type): ACore {
		switch ($type) {
			case Accept::TYPE:
				$item = new Accept();
				break;

			case Add::TYPE:
				$item = new Add();
				break;

			case View::TYPE:
				$item = new View();
				break;

			case ApproveReply::TYPE:
				$item = new ApproveReply();
				break;

			case Dislike::TYPE:
				$item = new Dislike();
				break;

			case Playlist::TYPE:
				$item = new Playlist();
				break;

			case StoryReaction::TYPE:
				$item = new StoryReaction();
				break;

			case StoryReply::TYPE:
				$item = new StoryReply();
				break;

			case Announce::TYPE:
				$item = new Announce();
				$item->setFilterDuplicate(true);
				break;

			case Block::TYPE:
				$item = new Block();
				break;

			case Move::TYPE:
				$item = new Move();
				break;

			case Create::TYPE:
				$item = new Create();
				break;

			case Delete::TYPE:
				$item = new Delete();
				break;

			case Document::TYPE:
				$item = new Document();
				break;

			case Flag::TYPE:
				$item = new Flag();
				break;

			case Follow::TYPE:
				$item = new Follow();
				break;

			case Image::TYPE:
				$item = new Image();
				break;

			case Like::TYPE:
				$item = new Like();
				break;

			case EmojiReact::TYPE:
				$item = new EmojiReact();
				break;

			case Note::TYPE:
				$item = new Note();
				break;

			case Story::TYPE:
				$item = new Story();
				break;

			case Question::TYPE:
				$item = new Question();
				break;

			case QuoteRequest::TYPE:
				$item = new QuoteRequest();
				break;

			case OrderedCollection::TYPE:
				$item = new OrderedCollection();
				break;

			case SocialAppNotification::TYPE:
				$item = new SocialAppNotification();
				break;

			case Stream::TYPE:
				$item = new Stream();
				break;

			case Person::TYPE:
				$item = new Person();
				break;

			case Reject::TYPE:
				$item = new Reject();
				break;

			case Remove::TYPE:
				$item = new Remove();
				break;

			case Service::TYPE:
				$item = new Service();
				break;

			case Group::TYPE:
				$item = new Group();
				break;

			case Organization::TYPE:
				$item = new Organization();
				break;

			case Application::TYPE:
				$item = new Application();
				break;

			case Tombstone::TYPE:
				$item = new Tombstone();
				break;

			case Undo::TYPE:
				$item = new Undo();
				break;

			case Update::TYPE:
				$item = new Update();
				break;

			default:
				if (!in_array($type, self::NOTE_LIKE_TYPES, true)) {
					throw new ItemUnknownException();
				}

				// see self::NOTE_LIKE_TYPES; getSimpleItemFromData() moves the
				// wire type into `subtype` once the data has been imported
				$item = new Note();
				break;
		}

		$item->setUrlCloud($this->configService->getCloudUrl());

		return $item;
	}

	public function getInterfaceForItem(Acore $activity): IActivityPubInterface {
		return $this->getInterfaceFromType($activity->getType());
	}

	public function getInterfaceFromType(string $type): IActivityPubInterface {
		switch ($type) {
			case Accept::TYPE:
				return $this->acceptInterface;
			case Add::TYPE:
				return $this->addInterface;
			case View::TYPE:
			case StoryReaction::TYPE:
			case StoryReply::TYPE:
				return $this->storyAnswerInterface;
			case ApproveReply::TYPE:
				return $this->approveReplyInterface;
			case Dislike::TYPE:
				return $this->dislikeInterface;
			case Playlist::TYPE:
				return $this->playlistInterface;
			case Announce::TYPE:
				return $this->announceInterface;
			case Block::TYPE:
				return $this->blockInterface;
			case Create::TYPE:
				return $this->createInterface;
			case Delete::TYPE:
				return $this->deleteInterface;
			case Document::TYPE:
				return $this->documentInterface;
			case Flag::TYPE:
				return $this->flagInterface;
			case Follow::TYPE:
				return $this->followInterface;
			case Image::TYPE:
				return $this->imageInterface;
			case Like::TYPE:
				return $this->likeInterface;
			case EmojiReact::TYPE:
				return $this->emojiReactInterface;
			case Move::TYPE:
				return $this->moveInterface;
			case Note::TYPE:
			case Question::TYPE:
				return $this->noteInterface;
			case Story::TYPE:
				return $this->storyInterface;
			case SocialAppNotification::TYPE:
				return $this->notificationInterface;
			case Person::TYPE:
				return $this->personInterface;
			case Reject::TYPE:
				return $this->rejectInterface;
			case Remove::TYPE:
				return $this->removeInterface;
			case Service::TYPE:
				return $this->serviceInterface;
				// Actor types that are not Person: parsed and modelled all along, but
				// absent here they raised ItemUnknownException, which CacheActorService
				// swallows — so a Lemmy community, an a.gup.pe/Friendica group or a
				// Mastodon instance or relay actor was never written to the actor cache.
				// One signature check passed on the in-memory copy and every later
				// request re-fetched over HTTP; following one was impossible.
			case Group::TYPE:
				return $this->groupInterface;
			case Organization::TYPE:
				return $this->organizationInterface;
			case Application::TYPE:
				return $this->applicationInterface;
			case Undo::TYPE:
				return $this->undoInterface;
			case Update::TYPE:
				return $this->updateInterface;
			case QuoteRequest::TYPE:
				return $this->quoteRequestInterface;
			default:
				// an item built elsewhere than getSimpleItemFromData() can still
				// carry the wire type; it is handled as the Note it was modelled as
				if (in_array($type, self::NOTE_LIKE_TYPES, true)) {
					return $this->noteInterface;
				}

				throw new ItemUnknownException($type);
		}
	}

	public function isActor(ACore $item): bool {
		$types = [
			Person::TYPE,
			Service::TYPE,
			Group::TYPE,
			Organization::TYPE,
			Application::TYPE
		];

		return (in_array($item->getType(), $types, true));
	}
}
