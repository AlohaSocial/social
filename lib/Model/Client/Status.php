<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Tools\Traits\TArrayTools;

class Status implements \JsonSerializable {
	use TArrayTools;

	private bool $sensitive = false;
	private string $visibility = '';
	private string $spoilerText = '';
	private array $mediaIds = [];
	private ?array $poll = null;
	private int $inReplyToId = 0;
	/**
	 * The post this one quotes, as the client named it: the numeric status id
	 * a Mastodon client sends, or an ActivityPub URI. Kept as a string for
	 * exactly that reason — `(int)'https://…'` is 0, which is a quote of
	 * whatever post happens to have that id.
	 */
	private string $quotedId = '';
	/** who may quote the post being written: '', 'public', 'followers' or 'nobody' */
	private string $quotePolicy = '';

	/**
	 * What the composer said about the video: its title, category and licence.
	 *
	 * @var array<string, string>
	 */
	private array $videoMeta = [];
	/** the handle of a team account this post is written as, or '' */
	private string $postAs = '';
	private string $status = '';
	/** BCP 47 as the client sent it, normalised; empty for "whatever the poster's default is" */
	private string $language = '';

	//"media_ids": [],

	public function __construct() {
	}

	/**
	 * @param bool $sensitive
	 *
	 * @return Status
	 */
	public function setSensitive(bool $sensitive): self {
		$this->sensitive = $sensitive;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isSensitive(): bool {
		return $this->sensitive;
	}

	/**
	 * @param string $visibility
	 *
	 * @return Status
	 */
	public function setVisibility(string $visibility): self {
		$this->visibility = $visibility;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getVisibility(): string {
		return $this->visibility;
	}

	/**
	 * @param string $spoilerText
	 *
	 * @return Status
	 */
	public function setSpoilerText(string $spoilerText): self {
		$this->spoilerText = $spoilerText;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getSpoilerText(): string {
		return $this->spoilerText;
	}

	public function setMediaIds(array $mediaIds): self {
		$this->mediaIds = array_map(function (string $id): int {
			return (int)$id;
		}, $mediaIds);

		return $this;
	}

	public function getMediaIds(): array {
		return $this->mediaIds;
	}

	public function setInReplyToId(int $inReplyToId): self {
		$this->inReplyToId = $inReplyToId;

		return $this;
	}

	public function getInReplyToId(): int {
		return $this->inReplyToId;
	}

	public function setQuotedId(string $quotedId): self {
		$this->quotedId = trim($quotedId);

		return $this;
	}

	public function setQuotePolicy(string $quotePolicy): self {
		$this->quotePolicy = in_array($quotePolicy, Stream::QUOTE_POLICIES, true) ? $quotePolicy : '';

		return $this;
	}

	public function getQuotePolicy(): string {
		return $this->quotePolicy;
	}

	/**
	 * @return array<string, string>
	 */
	public function getVideoMeta(): array {
		return $this->videoMeta;
	}

	public function getQuotedId(): string {
		return $this->quotedId;
	}

	/**
	 * The team account this post is written as, when it is one.
	 *
	 * This app's own, not Mastodon's: Mastodon has no team accounts and a
	 * client that has never heard of them sends nothing, which is what an
	 * ordinary post is.
	 */
	public function setPostAs(string $postAs): self {
		$this->postAs = ltrim(trim($postAs), '@');

		return $this;
	}

	public function getPostAs(): string {
		return $this->postAs;
	}

	/**
	 * Validated loosely rather than against a list: an unusable value is
	 * dropped so the poster's default language applies, see
	 * `Stream::normalizeLanguage()`.
	 */
	public function setLanguage(string $language): self {
		$this->language = Stream::normalizeLanguage($language);

		return $this;
	}

	public function getLanguage(): string {
		return $this->language;
	}

	/**
	 * @param string $status
	 *
	 * @return Status
	 */
	public function setStatus(string $status): self {
		$this->status = $status;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getStatus(): string {
		return $this->status;
	}

	public function import(array $data): self {
		$this->setSensitive($this->getBool('sensitive', $data));
		$this->setVisibility($this->get('visibility', $data));
		$this->setSpoilerText($this->get('spoiler_text', $data));
		$this->setMediaIds($this->getArray('media_ids', $data));
		$this->setInReplyToId($this->getInt('in_reply_to_id', $data));
		// `quote_id` is what a Mastodon 4.5 client sends to quote a post; a
		// client that sends something that is not a scalar quotes nothing
		$quotedId = $data['quote_id'] ?? '';
		$this->setQuotedId(is_scalar($quotedId) ? (string)$quotedId : '');

		// who may quote the post being written; Mastodon 4.5's composer sends
		// it on every post and its three values are the whole vocabulary
		$policy = $data['quote_approval_policy'] ?? '';
		$this->setQuotePolicy(is_scalar($policy) ? (string)$policy : '');

		// what a video is called, and what it is. A `Note` has none of this and
		// the composer never asked, so a video posted from here was published
		// with its title guessed out of the first line of the post — which is
		// right for somebody who wrote one and wrong for somebody who did not.
		foreach (['video_title' => 'title', 'video_category' => 'category', 'video_licence' => 'licence'] as $field => $key) {
			$value = $data[$field] ?? '';
			if (is_scalar($value) && trim((string)$value) !== '') {
				$this->videoMeta[$key] = mb_substr(trim((string)$value), 0, 255);
			}
		}
		$this->setStatus($this->get('status', $data));
		$this->setLanguage($this->get('language', $data));
		$this->setPostAs($this->get('post_as', $data));

		// Where the post was taken, if the client said. Either an id it got
		// from /api/v1/places/search, or a name it already had.
		$this->setPlaceId($this->getInt('place_id', $data));
		$this->setPlaceName($this->get('place_name', $data));
		$this->setPlaceCountry($this->get('place_country', $data));
		$this->setPlaceLat($this->get('place_lat', $data));
		$this->setPlaceLon($this->get('place_long', $data));
		$poll = $this->getArray('poll', $data);
		$this->setPoll($poll === [] ? null : $poll);

		return $this;
	}

	public function setPoll(?array $poll): self {
		$this->poll = $poll;

		return $this;
	}

	public function getPoll(): ?array {
		return $this->poll;
	}

	private int $placeId = 0;
	private string $placeName = '';
	private string $placeCountry = '';
	private string $placeLat = '';
	private string $placeLon = '';

	public function getPlaceId(): int {
		return $this->placeId;
	}

	public function setPlaceId(int $placeId): self {
		$this->placeId = $placeId;

		return $this;
	}

	public function getPlaceName(): string {
		return $this->placeName;
	}

	public function setPlaceName(string $placeName): self {
		$this->placeName = $placeName;

		return $this;
	}

	public function getPlaceCountry(): string {
		return $this->placeCountry;
	}

	public function setPlaceCountry(string $placeCountry): self {
		$this->placeCountry = $placeCountry;

		return $this;
	}

	public function getPlaceLat(): string {
		return $this->placeLat;
	}

	public function setPlaceLat(string $placeLat): self {
		$this->placeLat = $placeLat;

		return $this;
	}

	public function getPlaceLon(): string {
		return $this->placeLon;
	}

	public function setPlaceLon(string $placeLon): self {
		$this->placeLon = $placeLon;

		return $this;
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'sensitive' => $this->isSensitive(),
			'mediaIds' => $this->getMediaIds(),
			'visibility' => $this->getVisibility(),
			'spoilerText' => $this->getSpoilerText(),
			'language' => $this->getLanguage(),
			'status' => $this->getStatus()
		];
	}
}
