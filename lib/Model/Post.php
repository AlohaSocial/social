<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Class Post
 *
 * @package OCA\Social\Model
 */
class Post implements JsonSerializable {
	use TArrayTools;

	private Person $actor;
	private array $to = [];
	private string $replyTo = '';
	/**
	 * The post this one quotes, as whoever asked for it named it: the numeric
	 * status id a Mastodon client sends as `quote_id`, or an ActivityPub URI.
	 * `PostService::createPost()` resolves the one into the other.
	 */
	private string $quotedId = '';
	/** who may quote this post: '', 'public', 'followers' or 'nobody' */
	private string $quotePolicy = '';
	/** what the composer said about the video: its title, category and licence */
	private array $videoMeta = [];
	private string $content = '';
	private string $publishTarget = '';
	public function setPublishTarget(string $target): void {
		$this->publishTarget = $target;
	}
	public function getPublishTarget(): string {
		return $this->publishTarget;
	}
	private string $type = '';
	private array $hashtags = [];
	private ?array $poll = null;

	/** the content warning, hiding the body until the reader asks for it */
	private string $spoilerText = '';

	/** whether the attachments are shown blurred until the reader asks for them */
	private bool $sensitive = false;

	/** BCP 47; empty means the poster's default, decided by PostService */
	private string $language = '';
	private int $placeId = 0;

	/** @var string[] */
	private array $attachments = [];
	/** @var MediaAttachment[] */
	private array $medias = [];

	/** @var Document[] */
	private array $documents = [];

	public function __construct(Person $actor) {
		$this->actor = $actor;
	}

	/**
	 * @return Person
	 */
	public function getActor(): Person {
		return $this->actor;
	}

	/**
	 * @param string $to
	 *
	 * @return Post
	 */
	public function addTo(string $to): Post {
		$to = trim($to);
		if ($to !== '' && !in_array($to, $this->to, true)) {
			$this->to[] = $to;
		}

		return $this;
	}

	/**
	 * @return array
	 */
	public function getTo(): array {
		return $this->to;
	}

	/**
	 * @param array $to
	 *
	 * @return Post
	 */
	public function setTo(array $to): Post {
		$this->to = $to;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getReplyTo(): string {
		return $this->replyTo;
	}

	/**
	 * @param string $replyTo
	 *
	 * @return Post
	 */
	public function setReplyTo(string $replyTo): Post {
		$this->replyTo = $replyTo;

		return $this;
	}

	public function getQuotedId(): string {
		return $this->quotedId;
	}

	public function setQuotedId(string $quotedId): Post {
		$this->quotedId = trim($quotedId);

		return $this;
	}

	public function getQuotePolicy(): string {
		return $this->quotePolicy;
	}

	public function setQuotePolicy(string $quotePolicy): Post {
		$this->quotePolicy = $quotePolicy;

		return $this;
	}

	/**
	 * @return array<string, string>
	 */
	public function getVideoMeta(): array {
		return $this->videoMeta;
	}

	/**
	 * @param array<string, string> $videoMeta
	 */
	public function setVideoMeta(array $videoMeta): Post {
		$this->videoMeta = $videoMeta;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getType(): string {
		return $this->type;
	}

	/**
	 * The post's visibility, normalised into this app's own vocabulary. Every
	 * creation path funnels through here — the web UI already speaks it, while
	 * a Mastodon client says `private` for followers-only — so this is the one
	 * place that has to get it right. An unrecognised value becomes `direct`
	 * rather than public; see `Stream::visibilityFromClient()`.
	 *
	 * @param string $type
	 *
	 * @return Post
	 */
	public function setType(string $type): Post {
		$this->type = Stream::visibilityFromClient($type);

		return $this;
	}

	/**
	 * @return array
	 */
	public function getHashtags(): array {
		return $this->hashtags;
	}

	/**
	 * @param array $hashtags
	 *
	 * @return Post
	 */
	public function setHashtags(array $hashtags): Post {
		$this->hashtags = $hashtags;

		return $this;
	}

	public function addHashtag(string $hashtag): Post {
		$hashtag = trim($hashtag);
		if ($hashtag !== '' && !in_array($hashtag, $this->hashtags, true)) {
			$this->hashtags[] = $hashtag;
		}

		return $this;
	}

	/**
	 * @return string[]
	 */
	public function getAttachments(): array {
		return $this->attachments;
	}

	/**
	 * @param string[] $attachments
	 *
	 * @return self
	 */
	public function setAttachments(array $attachments): self {
		$this->attachments = $attachments;

		return $this;
	}

	/**
	 * @param MediaAttachment[] $medias
	 */
	public function setMedias(array $medias): self {
		$this->medias = $medias;

		return $this;
	}

	/**
	 * @return MediaAttachment[]
	 */
	/**
	 * @param ?array $poll ['options' => string[], 'expires_in' => int, 'multiple' => bool]
	 */
	public function setPoll(?array $poll): self {
		$this->poll = $poll;

		return $this;
	}

	public function isSensitive(): bool {
		return $this->sensitive;
	}

	public function setSensitive(bool $sensitive): self {
		$this->sensitive = $sensitive;

		return $this;
	}

	public function getSpoilerText(): string {
		return $this->spoilerText;
	}

	public function setSpoilerText(string $spoilerText): self {
		$this->spoilerText = trim($spoilerText);

		return $this;
	}

	public function getPoll(): ?array {
		return $this->poll;
	}

	public function getLanguage(): string {
		return $this->language;
	}

	/**
	 * Normalised on the way in like the visibility is, so an unusable value
	 * means "no language" rather than a nonsense `contentMap` key on every
	 * other server.
	 */
	public function setLanguage(string $language): self {
		$this->language = Stream::normalizeLanguage($language);

		return $this;
	}

	/** Where the post was taken, when its author said so; 0 is "nowhere". */
	public function getPlaceId(): int {
		return $this->placeId;
	}

	public function setPlaceId(int $placeId): self {
		$this->placeId = max(0, $placeId);

		return $this;
	}

	public function hasPoll(): bool {
		return $this->poll !== null && ($this->poll['options'] ?? []) !== [];
	}

	public function getMedias(): array {
		return $this->medias;
	}

	/**
	 * @return Document[]
	 */
	public function getDocuments(): array {
		return $this->documents;
	}

	/**
	 * @param Document[] $documents
	 *
	 * @return Post
	 */
	public function setDocuments(array $documents): Post {
		$this->documents = $documents;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getContent(): string {
		return $this->content;
	}

	/**
	 * @param string $content
	 */
	public function setContent(string $content) {
		$this->content = $content;
	}

	/**
	 * @return array
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'actor' => $this->getActor(),
			'to' => $this->getTo(),
			'replyTo' => $this->getReplyTo(),
			'content' => $this->getContent(),
			'attachments' => $this->getAttachments(),
			'hashtags' => $this->getHashtags(),
			'type' => $this->getType(),
			'language' => $this->getLanguage()
		];
	}
}
