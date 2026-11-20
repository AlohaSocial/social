<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;
use OCA\Social\Tools\DbTime;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Mastodon's List entity: a user-made group of accounts they follow, with a
 * timeline of its own. Not `List`, a reserved word in PHP.
 *
 * `ownerId` is not shown to clients; it lets ListsRequest check ownership in
 * SQL before anything is done with the row.
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
	 * Anything but the three policies is stored as the default; the write
	 * routes check isRepliesPolicy() first to answer 422.
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
			->setCreation(DbTime::toTimestamp($creation));

		return $this;
	}

	/**
	 * Mastodon's four keys plus two of this app's own, which Mastodon clients
	 * ignore: `nextcloud_group`, the group a list follows (members not
	 * editable), and `public`.
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
