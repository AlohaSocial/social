<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub\Object;

use JsonSerializable;
use OCA\Social\AP;
use OCA\Social\Db\RenditionsRequest;
use OCA\Social\Db\VideoViewsRequest;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Details;
use OCA\Social\Service\ChannelService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\PeerTubeService;
use OCA\Social\Tools\ActivityPubEvent;
use OCP\IURLGenerator;
use OCP\Server;
use Throwable;

class Note extends Stream implements JsonSerializable {
	private string $name = '';
	public const TYPE = 'Note';

	private array $hashtags = [];

	public function __construct(?ACore $parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
	}

	#[\Override]
	public function getHashtags(): array {
		return $this->hashtags;
	}

	public function setHashtags(array $hashtags): Note {
		$this->hashtags = $hashtags;

		return $this;
	}

	public function fillMentions(): void {
		$personInterface = AP::instance()->getInterfaceFromType(Person::TYPE);
		$mentions = [];

		foreach ($this->getTags('Mention') as $item) {
			$username = ltrim($this->get('name', $item), '@');
			$mention = [
				'id' => 0,
				'username' => $username,
				'url' => $this->get('href', $item),
				'acct' => $username,
			];

			try {
				/** @var Person $actor */
				$actor = $personInterface->getItemById($mention['url']);
				$mention = self::mentionOf($actor, $mention['url']);
			} catch (ItemNotFoundException $e) {
			}

			$mentions[] = $mention;
		}

		$this->setDetailArray(Details::MENTIONS, $mentions);
	}

	/**
	 * Adds a known account to the client's `mentions`, once. What a post
	 * written here is given as each Mention tag is added to it.
	 */
	public function addMention(Person $actor, string $url): void {
		$mentions = $this->getDetails(Details::MENTIONS);
		foreach ($mentions as $mention) {
			if (is_array($mention) && ($mention['url'] ?? '') === $url) {
				return;
			}
		}

		$mentions[] = self::mentionOf($actor, $url);
		$this->setDetailArray(Details::MENTIONS, $mentions);
	}

	/**
	 * One entry of Mastodon's `Status.mentions` for a known account: bare
	 * `acct` for an account of this instance, as `Account.acct` is.
	 *
	 * @return array{id: string, username: string, url: string, acct: string}
	 */
	public static function mentionOf(Person $actor, string $url): array {
		return [
			'id' => (string)$actor->getNid(),
			'username' => $actor->getPreferredUsername(),
			'url' => $url,
			'acct' => $actor->isLocal() ? $actor->getPreferredUsername() : $actor->getAccount(),
		];
	}

	public function fillHashtags(): void {
		$tags = $this->getTags('Hashtag');
		$hashtags = [];
		foreach ($tags as $tag) {
			$hashtag = $tag['name'];
			if (substr($hashtag, 0, 1) === '#') {
				$hashtag = substr($hashtag, 1);
			}
			$hashtags[] = trim($hashtag);
		}

		$this->setHashtags($hashtags);
	}

	/**
	 * @throws ItemAlreadyExistsException
	 */
	#[\Override]
	public function import(array $data): void {
		parent::import($data);
		if (($data['type'] ?? '') === 'Event') {
			$this->setSubType('Event');
			$event = ActivityPubEvent::metadata($data);
			$this->setDetailArray(Details::EVENT, $event);
		}

		$this->fillHashtags();
		$this->fillMentions();
		$this->setName($this->voteOption($data));
	}

	/**
	 * The option a poll vote chose: Mastodon votes with a `Note` that has a
	 * `name`, an `inReplyTo` and no `content`. Anything else with a `name` — a
	 * PeerTube video's title, say — is not a vote and keeps none here.
	 */
	private function voteOption(array $data): string {
		if (($data['type'] ?? '') !== self::TYPE
			|| ($data['content'] ?? '') !== ''
			|| ($data['inReplyTo'] ?? '') === '') {
			return '';
		}

		$name = $data['name'] ?? '';

		return is_string($name) ? trim($name) : '';
	}

	#[\Override]
	public function importFromDatabase(array $data): void {
		parent::importFromDatabase($data);

		$this->setHashtags($this->getArray('hashtags', $data, []));
	}

	public function getName(): string {
		return $this->name;
	}

	public function setName(string $name): self {
		$this->name = $name;

		return $this;
	}

	#[\Override]
	public function jsonSerialize(): array {
		$result = parent::jsonSerialize();

		if ($this->getName() !== '') {
			// a poll vote: the chosen option travels in `name`
			$result['name'] = $this->getName();
		}

		// `hashtags` is this app's own shape and predates the client API.
		// Mastodon's `tags: [{name, url}]` carries the same data and is what a
		// client reads, so the bare-string version stays out of that format.
		if ($this->isCompleteDetails() && $this->getExportFormat() !== self::FORMAT_LOCAL) {
			$result['hashtags'] = $this->getHashtags();
		}

		$result = $this->asVideoIfItIsOne($result);
		$result = $this->asEventIfItIsOne($result);

		$this->cleanArray($result);

		return $result;
	}

	/** Publish locally authored event details using ActivityStreams' Event shape. */
	private function asEventIfItIsOne(array $result): array {
		if ($this->getExportFormat() === self::FORMAT_LOCAL
			|| !$this->isLocal()
			|| $this->getSubType() !== 'Event') {
			return $result;
		}

		$event = ActivityPubEvent::fromStream($this);
		if ($event === null || $event['start_time'] === null) {
			return $result;
		}

		$result['type'] = 'Event';
		// Mastodon converts Event objects into statuses using `content`, or
		// `name` only when content is absent. Keep the title in `name` for
		// Event-aware servers and append readable details for status clients.
		$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
		$details = [];
		if ($event['name'] !== '') {
			$details[] = '<strong>' . $escape($event['name']) . '</strong>';
		}
		$details[] = 'Starts: ' . substr($event['start_time'], 0, 10) . ' ' . substr($event['start_time'], 11, 5) . ' UTC';
		if ($event['end_time'] !== null) {
			$details[] = 'Ends: ' . substr($event['end_time'], 0, 10) . ' ' . substr($event['end_time'], 11, 5) . ' UTC';
		}
		if ($event['location'] !== '') {
			$details[] = 'Location: ' . $escape($event['location']);
		}
		$result['content'] = (string)($result['content'] ?? '') . '<p>' . implode('<br>', $details) . '</p>';
		$language = $this->getLanguage();
		if ($language !== '' && isset($result['contentMap'][$language])) {
			$result['contentMap'][$language] = $result['content'];
		}
		$result['name'] = $event['name'];
		$result['startTime'] = $event['start_time'];
		if (isset($event['status'])) {
			$result['ical:status'] = strtoupper($event['status']);
		}
		if ($event['end_time'] !== null) {
			$result['endTime'] = $event['end_time'];
		}
		if ($event['location'] !== '') {
			$result['location'] = ['type' => 'Place', 'name' => $event['location']];
		}

		return $result;
	}

	/**
	 * A post that is a video goes onto the wire as a `Video`, not a `Note`.
	 *
	 * This is the outbound half of what `PeerTubeService` reads. PeerTube --
	 * and every other video-native server -- ingests `Video` objects and
	 * nothing else, so a Social instance publishing `Note`s had, from their
	 * side, no videos at all: not badly-formatted ones, none. Only the
	 * serialisation changes; the row stays a `Note`, exactly as an incoming
	 * `Video` is stored as one.
	 *
	 * Three guards, in cost order so the cheap ones decide first:
	 *
	 * - only for the wire. The client API is Mastodon's and has no `Video`.
	 * - only for **local** posts. A remote note is somebody else's document and
	 *   is re-serialised as it arrived.
	 * - only when the post *is* a video: one attachment, and it a video. See
	 *   `PeerTubeService::soleVideo()`.
	 *
	 * The app value is last because it costs a container lookup, and it is
	 * **off by default**. The question this comment used to leave open —
	 * whether a Mastodon-family server renders a `Video` as well as it
	 * rendered the `Note` — has an answer from Pixelfed's own source: its
	 * inbox handles a `Create` only when the object is a `Note` with a parent
	 * or an attachment, and drops everything else without a word
	 * (`Inbox/HandlesCreates.php`). With this on, no video posted here ever
	 * reached a Pixelfed follower. Mastodon draws both shapes; PeerTube draws
	 * only this one; Pixelfed only the `Note`. `attachment` is published either
	 * way, so a `Video` still carries its file for the servers that read it.
	 * An instance whose audience is on PeerTube turns it on with
	 * `occ config:app:set social publish_video_objects --value 1`.
	 */
	private function asVideoIfItIsOne(array $result): array {
		if ($this->getExportFormat() === self::FORMAT_LOCAL || !$this->isLocal()) {
			return $result;
		}
		if ($this->getSubType() === 'Event') {
			return $result;
		}

		$video = PeerTubeService::soleVideo($this->getAttachments());
		if ($video === null) {
			return $result;
		}

		try {
			if (!Server::get(ConfigService::class)->getAppValueBool(ConfigService::SOCIAL_PUBLISH_VIDEO)) {
				return $result;
			}

			// Which channel it is filed under. PeerTube resolves a video's
			// channel by looking for a `Group` here and refuses the video
			// outright when there is none, so this is not decoration: without
			// it every `Video` this app published was thrown away on arrival.
			// Read, never created — the channel is made when the post is
			// written (`PostService::createPost()`), because making an actor
			// inside a serialisation is a write on a read path.
			$attributedTo = Server::get(ChannelService::class)->attributionOf($this->getAttributedTo());
		} catch (Throwable $e) {
			// nothing to resolve it from: publish the post as it was rather
			// than lose it over a setting
			return $result;
		}

		$asVideo = PeerTubeService::asVideo(
			$result,
			$video,
			$this->getId(),
			$attributedTo,
			// how many times it was watched here, which is what PeerTube shows
			// under a video; one query, on the same single-post path as below
			$this->getLocalVideoViews() ?? self::viewsOf($this->getId()),
			// what the author said it is, where they said anything: a title
			// taken out of the first line is a guess, and a guess is only worth
			// making when nobody has answered
			$this->getVideoMeta(),
			// where the video has been laddered, the sizes it also exists at.
			// One query, on the wire-export path of a single post that has a
			// single video on it -- never on a timeline read.
			self::rungsOf($video)
		);

		// a post that cannot make a `Video` PeerTube would accept stays the
		// `Note` it was; see `PeerTubeService::asVideo()`
		return $asVideo ?? $result;
	}

	/**
	 * The rungs of a laddered video, addressed absolutely.
	 *
	 * Empty for a video with no ladder, which is every video on an instance
	 * that has not turned the setting on — and the reason this asks the
	 * attachment first: `hls_url` is set only where the row says there is one,
	 * so the ordinary case costs no query at all.
	 *
	 * @return array<array{height: int, size: int, bandwidth: int, href: string}>
	 */
	/** How many times a video posted here was watched; 0 when that cannot be read. */
	private static function viewsOf(string $id): int {
		try {
			return Server::get(VideoViewsRequest::class)->countsFor([$id])[md5($id)] ?? 0;
		} catch (Throwable $e) {
			return 0;
		}
	}

	private static function rungsOf(MediaAttachment $video): array {
		if ($video->getHlsUrl() === '') {
			return [];
		}

		try {
			$renditions = Server::get(RenditionsRequest::class)->forDocument((int)$video->getId());
			$urlGenerator = Server::get(IURLGenerator::class);
		} catch (Throwable $e) {
			return [];
		}

		$rungs = [];
		foreach ($renditions as $rendition) {
			$rungs[] = [
				'height' => $rendition->getHeight(),
				'size' => $rendition->getSize(),
				'bandwidth' => $rendition->getBandwidth(),
				'href' => $urlGenerator->linkToRouteAbsolute('social.MediaApi.mediaLadderFile', [
					'uuid' => self::ladderUuid($video),
					'height' => $rendition->getHeight(),
				]),
			];
		}

		return $rungs;
	}

	/**
	 * The uuid the ladder routes are keyed on, read back out of the master
	 * url the attachment already carries — rather than fetched again from the
	 * document row it came from.
	 */
	private static function ladderUuid(MediaAttachment $video): string {
		$path = parse_url($video->getHlsUrl(), PHP_URL_PATH);

		return ($path === false || $path === null) ? '' : basename($path);
	}
}
