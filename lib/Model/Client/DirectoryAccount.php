<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;

/**
 * Somebody another server's directory said exists.
 *
 * Deliberately **not** a Mastodon `Account`. An `Account` carries an `id` that
 * the client hands straight back to this instance's own API, and the id in a
 * remote directory's answer is a row number in somebody else's database — the
 * same string means a different person here. Giving these entities the same
 * shape would make that mistake invisible; giving them their own makes it
 * unspellable.
 *
 * The one durable reference to a person on another server is their handle, so
 * that is what this carries and what every action the client can take from a
 * result is keyed by. `url` is offered for the link out and for nothing else.
 *
 * Everything here was written by a stranger's server. It is length-capped, and
 * `note` is delivered as text with its markup removed rather than as HTML: a
 * bio arrives as HTML on Mastodon and as plain text on Misskey, and the one
 * thing the two must not be is "sometimes markup, rendered".
 */
class DirectoryAccount implements JsonSerializable {
	public const MAX_NAME = 120;
	public const MAX_NOTE = 400;

	private string $displayName = '';
	private string $note = '';
	private string $avatar = '';
	private string $url = '';
	private int $followersCount = -1;
	private int $statusesCount = -1;
	private bool $bot = false;
	private bool $known = false;

	/**
	 * @param string $acct the full handle, `user@host`, never bare
	 * @param string $source the host of the directory that answered
	 * @param string $kind which API that host speaks
	 */
	public function __construct(
		private string $acct,
		private string $source,
		private string $kind,
	) {
	}

	public function getAcct(): string {
		return $this->acct;
	}

	/** The name before the `@`, which is all a row needs when there is no display name. */
	public function getUsername(): string {
		return explode('@', $this->acct)[0];
	}

	/**
	 * The server the account is on: after the `@`, or, for a Bluesky
	 * handle, which has none, the handle's own domain.
	 */
	public function getHost(): string {
		$parts = explode('@', $this->acct);
		if (isset($parts[1])) {
			return $parts[1];
		}
		$dot = strpos($this->acct, '.');

		return $dot === false ? '' : substr($this->acct, $dot + 1);
	}

	public function getSource(): string {
		return $this->source;
	}

	public function getKind(): string {
		return $this->kind;
	}

	public function getDisplayName(): string {
		return $this->displayName;
	}

	public function setDisplayName(string $displayName): self {
		$this->displayName = mb_substr(trim($displayName), 0, self::MAX_NAME);

		return $this;
	}

	public function getNote(): string {
		return $this->note;
	}

	/**
	 * The bio, as text. Tags are stripped rather than escaped, and the entities
	 * behind them decoded, because half of these arrive as HTML and half as
	 * plain text and a row has to draw both the same way.
	 */
	public function setNote(string $note): self {
		$text = html_entity_decode(
			strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], ' ', $note)),
			ENT_QUOTES | ENT_HTML5,
			'UTF-8'
		);

		$this->note = mb_substr(trim(preg_replace('/\s+/u', ' ', $text) ?? $text), 0, self::MAX_NOTE);

		return $this;
	}

	public function getAvatar(): string {
		return $this->avatar;
	}

	/**
	 * Only a plain http(s) URL ever reaches an `<img src>`; anything else is
	 * dropped and the row draws its initial instead.
	 */
	public function setAvatar(string $avatar): self {
		$scheme = strtolower((string)parse_url($avatar, PHP_URL_SCHEME));
		if (in_array($scheme, ['http', 'https'], true) && (string)parse_url($avatar, PHP_URL_HOST) !== '') {
			$this->avatar = $avatar;
		}

		return $this;
	}

	public function getUrl(): string {
		return $this->url;
	}

	public function setUrl(string $url): self {
		$scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
		if (in_array($scheme, ['http', 'https'], true)) {
			$this->url = $url;
		}

		return $this;
	}

	public function getFollowersCount(): int {
		return $this->followersCount;
	}

	/** `-1` is "the directory did not say", which is not the same as none. */
	public function setFollowersCount(int $count): self {
		$this->followersCount = max(-1, $count);

		return $this;
	}

	public function getStatusesCount(): int {
		return $this->statusesCount;
	}

	public function setStatusesCount(int $count): self {
		$this->statusesCount = max(-1, $count);

		return $this;
	}

	public function isBot(): bool {
		return $this->bot;
	}

	public function setBot(bool $bot): self {
		$this->bot = $bot;

		return $this;
	}

	public function isKnown(): bool {
		return $this->known;
	}

	/**
	 * Whether this instance already holds the actor — so the row can offer the
	 * profile here rather than sending the reader to somebody else's website
	 * for a person their own server knows.
	 */
	public function setKnown(bool $known): self {
		$this->known = $known;

		return $this;
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'acct' => $this->acct,
			'username' => $this->getUsername(),
			'host' => $this->getHost(),
			'display_name' => $this->displayName,
			'note' => $this->note,
			'avatar' => $this->avatar,
			'url' => $this->url,
			'followers_count' => $this->followersCount,
			'statuses_count' => $this->statusesCount,
			'bot' => $this->bot,
			'known' => $this->known,
			'source' => $this->source,
			'source_kind' => $this->kind,
		];
	}
}
