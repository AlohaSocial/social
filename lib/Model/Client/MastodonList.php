<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Mastodon's List entity: a user-made group of accounts they follow, with a
 * timeline of its own.
 *
 * Named MastodonList and not List because `list` is a reserved word in PHP and
 * `class List` does not parse.
 *
 * `id` is a string in the JSON and an integer in the row, as every other id a
 * Mastodon client is handed is: `social_list.id` is the cursor and the path
 * segment, and a client compares it as an opaque string.
 *
 * `ownerId` is not part of the entity a client sees. It is carried so that the
 * one row a request names can be checked against the account that asked before
 * anything is done with it — see ListsRequest, where the check is a SQL
 * predicate rather than a comparison made after the row was read.
 */
class MastodonList implements JsonSerializable {
	use TArrayTools;

	/** Replies to accounts the list owner follows are shown. */
	public const REPLIES_FOLLOWED = 'followed';
	/** Replies to other members of this list are shown. */
	public const REPLIES_LIST = 'list';
	/** No replies are shown. */
	public const REPLIES_NONE = 'none';

	/** @var string[] the only three values Mastodon's enum admits */
	public const REPLIES_POLICIES = [self::REPLIES_FOLLOWED, self::REPLIES_LIST, self::REPLIES_NONE];

	/**
	 * What a list gets when the client does not say, matching Mastodon's
	 * column default — a client that never sends the field must not end up
	 * with a different list here than it would get there.
	 */
	public const DEFAULT_REPLIES_POLICY = self::REPLIES_LIST;

	/** Its owner's alone, as every Mastodon list is. */
	public const VISIBILITY_PRIVATE = 'private';
	/**
	 * Anyone may see it and who is on it: published to Bluesky as a curate
	 * list (`BlueskyLists`).
	 */
	public const VISIBILITY_PUBLIC = 'public';

	private int $id = 0;
	private string $ownerId = '';
	private string $title = '';
	private string $repliesPolicy = self::DEFAULT_REPLIES_POLICY;
	private bool $exclusive = false;
	/** the Nextcloud group this list follows, or '' for one made by hand */
	private string $groupId = '';
	private string $visibility = self::VISIBILITY_PRIVATE;
	private int $creation = 0;

	public function setId(int $id): self {
		$this->id = $id;

		return $this;
	}

	public function getId(): int {
		return $this->id;
	}

	public function setOwnerId(string $ownerId): self {
		$this->ownerId = $ownerId;

		return $this;
	}

	public function getOwnerId(): string {
		return $this->ownerId;
	}

	public function setTitle(string $title): self {
		$this->title = $title;

		return $this;
	}

	public function getTitle(): string {
		return $this->title;
	}

	/**
	 * Anything that is not one of the three is stored as the default rather
	 * than as itself: the column would otherwise hold a value that no client
	 * and no query here knows how to read. Callers that must refuse an unknown
	 * policy — the two write routes do, with a 422 — ask isRepliesPolicy()
	 * first.
	 */
	public function setRepliesPolicy(string $repliesPolicy): self {
		$this->repliesPolicy = self::isRepliesPolicy($repliesPolicy)
			? $repliesPolicy
			: self::DEFAULT_REPLIES_POLICY;

		return $this;
	}

	public function getRepliesPolicy(): string {
		return $this->repliesPolicy;
	}

	public static function isRepliesPolicy(string $repliesPolicy): bool {
		return in_array($repliesPolicy, self::REPLIES_POLICIES, true);
	}

	public function setExclusive(bool $exclusive): self {
		$this->exclusive = $exclusive;

		return $this;
	}

	public function isExclusive(): bool {
		return $this->exclusive;
	}

	public function setGroupId(string $groupId): self {
		$this->groupId = $groupId;

		return $this;
	}

	public function getGroupId(): string {
		return $this->groupId;
	}

	/** Anything but public is private. */
	public function setVisibility(string $visibility): self {
		$this->visibility = $visibility === self::VISIBILITY_PUBLIC ? self::VISIBILITY_PUBLIC : self::VISIBILITY_PRIVATE;

		return $this;
	}

	public function getVisibility(): string {
		return $this->visibility;
	}

	public function setPublic(bool $public): self {
		return $this->setVisibility($public ? self::VISIBILITY_PUBLIC : self::VISIBILITY_PRIVATE);
	}

	public function isPublic(): bool {
		return $this->visibility === self::VISIBILITY_PUBLIC;
	}

	public function setCreation(int $creation): self {
		$this->creation = $creation;

		return $this;
	}

	public function getCreation(): int {
		return $this->creation;
	}

	/** @param array<string, mixed> $data a row of `social_list` */
	public function importFromDatabase(array $data): self {
		$creation = $this->get('creation', $data);

		$this->setId($this->getInt('id', $data))
			->setOwnerId($this->get('actor_id', $data))
			->setTitle($this->get('title', $data))
			->setRepliesPolicy($this->get('replies_policy', $data))
			->setExclusive($this->getBool('exclusive', $data))
			->setGroupId($this->get('group_id', $data))
			->setVisibility($this->get('visibility', $data))
			->setCreation(($creation === '') ? 0 : (int)strtotime($creation));

		return $this;
	}

	/**
	 * Mastodon's four keys, and two of this app's own: a client reads
	 * `replies_policy` and `exclusive` off this to draw the list's settings,
	 * and the owner is not among them. `nextcloud_group` is the group a list
	 * follows, or null -- what tells this app's own client to draw the group
	 * icon and not offer to edit the members; `public` whether anyone may
	 * see the list and who is on it. A Mastodon client ignores both.
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => (string)$this->getId(),
			'title' => $this->getTitle(),
			'replies_policy' => $this->getRepliesPolicy(),
			'exclusive' => $this->isExclusive(),
			'nextcloud_group' => ($this->groupId === '') ? null : $this->groupId,
			'public' => $this->isPublic(),
		];
	}
}
