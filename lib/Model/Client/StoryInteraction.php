<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Tools\DbTime;
use OCA\Social\Tools\IQueryRow;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Something said back to a short (a Pixelfed story): an emoji or a sentence,
 * one model for both as in Pixelfed. Neither is a post: there is no
 * `social_stream` row, so it never appears in a timeline, profile or outbox,
 * and it goes when the story goes.
 */
class StoryInteraction implements IQueryRow, JsonSerializable {
	use TArrayTools;

	public const TYPE_REACTION = 'reaction';
	public const TYPE_REPLY = 'reply';

	/**
	 * How many times one account may answer one story: Pixelfed's own number,
	 * so a story is not an unclosable private channel to its poster.
	 */
	public const MAX_PER_ACTOR = 5;

	/** A reaction is an emoji or two, and a reply is a sentence, not an essay. */
	public const MAX_REACTION_LENGTH = 20;
	public const MAX_REPLY_LENGTH = 500;

	private int $id = 0;
	private int $storyId = 0;
	private string $actorId = '';
	private string $type = self::TYPE_REACTION;
	private string $content = '';
	private string $sourceId = '';
	private int $creation = 0;

	/** Filled in when it is read for a client. */
	private ?Person $author = null;

	public function getId(): int {
		return $this->id;
	}

	public function setId(int $id): self {
		$this->id = $id;

		return $this;
	}

	public function getStoryId(): int {
		return $this->storyId;
	}

	public function setStoryId(int $storyId): self {
		$this->storyId = $storyId;

		return $this;
	}

	public function getActorId(): string {
		return $this->actorId;
	}

	public function setActorId(string $actorId): self {
		$this->actorId = $actorId;

		return $this;
	}

	public function getType(): string {
		return $this->type;
	}

	public function setType(string $type): self {
		$this->type = ($type === self::TYPE_REPLY) ? self::TYPE_REPLY : self::TYPE_REACTION;

		return $this;
	}

	public function getContent(): string {
		return $this->content;
	}

	public function setContent(string $content): self {
		$this->content = $content;

		return $this;
	}

	public function getSourceId(): string {
		return $this->sourceId;
	}

	public function setSourceId(string $sourceId): self {
		$this->sourceId = $sourceId;

		return $this;
	}

	public function getCreation(): int {
		return $this->creation;
	}

	public function setCreation(int $creation): self {
		$this->creation = $creation;

		return $this;
	}

	public function getAuthor(): ?Person {
		return $this->author;
	}

	public function setAuthor(?Person $author): self {
		$this->author = $author;

		return $this;
	}

	/** The longest this kind of answer may be. */
	public static function maxLengthOf(string $type): int {
		return ($type === self::TYPE_REPLY) ? self::MAX_REPLY_LENGTH : self::MAX_REACTION_LENGTH;
	}

	#[\Override]
	public function importFromDatabase(array $data): void {
		$this->setId($this->getInt('id', $data));
		$this->setStoryId($this->getInt('story_id', $data));
		$this->setActorId($this->get('actor_id', $data));
		$this->setType($this->get('type', $data));
		$this->setContent($this->get('content', $data));
		$this->setSourceId($this->get('source_id', $data));

		$creation = $this->get('creation', $data);
		$this->setCreation(DbTime::toTimestamp($creation));
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => (string)$this->getId(),
			'story_id' => (string)$this->getStoryId(),
			'type' => $this->getType(),
			'content' => $this->getContent(),
			'created_at' => date('c', $this->getCreation()),
			'account' => $this->getAuthor(),
		];
	}
}
