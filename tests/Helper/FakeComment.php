<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Helper;

use DateTime;
use OCP\Comments\IComment;

/**
 * A comment held in memory, for the code that reads and writes comments
 * through `ICommentsManager` without the server's own implementation.
 *
 * `getMentions()` follows the server's rule for the two forms the tests
 * write, `@uid` and `@"uid with spaces"`.
 */
final class FakeComment implements IComment {
	private string $id = '';
	private string $parentId = '0';
	private string $topmostParentId = '0';
	private int $childrenCount = 0;
	private string $message = '';
	private string $verb = '';
	private string $actorType = '';
	private string $actorId = '';
	private string $objectType = '';
	private string $objectId = '';
	private ?DateTime $creation = null;
	private ?DateTime $latestChild = null;
	private ?string $referenceId = null;
	private ?array $metaData = null;
	private array $reactions = [];
	private ?DateTime $expire = null;

	#[\Override]
	public function getId() {
		return $this->id;
	}

	#[\Override]
	public function setId($id) {
		$this->id = (string)$id;

		return $this;
	}

	#[\Override]
	public function getParentId() {
		return $this->parentId;
	}

	#[\Override]
	public function setParentId($parentId) {
		$this->parentId = (string)$parentId;

		return $this;
	}

	#[\Override]
	public function getTopmostParentId() {
		return $this->topmostParentId;
	}

	#[\Override]
	public function setTopmostParentId($id) {
		$this->topmostParentId = (string)$id;

		return $this;
	}

	#[\Override]
	public function getChildrenCount() {
		return $this->childrenCount;
	}

	#[\Override]
	public function setChildrenCount($count) {
		$this->childrenCount = (int)$count;

		return $this;
	}

	#[\Override]
	public function getMessage() {
		return $this->message;
	}

	#[\Override]
	public function setMessage($message, $maxLength = self::MAX_MESSAGE_LENGTH) {
		if (mb_strlen($message) > $maxLength) {
			throw new \OCP\Comments\MessageTooLongException('Comment message must not exceed ' . $maxLength . ' characters');
		}
		$this->message = $message;

		return $this;
	}

	#[\Override]
	public function getMentions(bool $supportMarkdown = true) {
		preg_match_all('/\B(?<![^a-z0-9_\-@\.\'\s])@("[a-z0-9_\-@\.\' ]+"|[a-z0-9_\-@\.\']+)/i', $this->message, $matches);

		return array_map(
			static fn (string $id): array => ['type' => 'user', 'id' => trim($id, '"')],
			array_values(array_unique($matches[1]))
		);
	}

	#[\Override]
	public function getVerb() {
		return $this->verb;
	}

	#[\Override]
	public function setVerb($verb) {
		$this->verb = $verb;

		return $this;
	}

	#[\Override]
	public function getActorType() {
		return $this->actorType;
	}

	#[\Override]
	public function getActorId() {
		return $this->actorId;
	}

	#[\Override]
	public function setActor($actorType, $actorId) {
		$this->actorType = $actorType;
		$this->actorId = $actorId;

		return $this;
	}

	#[\Override]
	public function getCreationDateTime() {
		return $this->creation ?? new DateTime();
	}

	#[\Override]
	public function setCreationDateTime(\DateTime $dateTime) {
		$this->creation = $dateTime;

		return $this;
	}

	#[\Override]
	public function getLatestChildDateTime() {
		return $this->latestChild;
	}

	#[\Override]
	public function setLatestChildDateTime(?\DateTime $dateTime = null) {
		$this->latestChild = $dateTime;

		return $this;
	}

	#[\Override]
	public function getObjectType() {
		return $this->objectType;
	}

	#[\Override]
	public function getObjectId() {
		return $this->objectId;
	}

	#[\Override]
	public function setObject($objectType, $objectId) {
		$this->objectType = $objectType;
		$this->objectId = (string)$objectId;

		return $this;
	}

	#[\Override]
	public function getReferenceId(): ?string {
		return $this->referenceId;
	}

	#[\Override]
	public function setReferenceId(?string $referenceId): IComment {
		$this->referenceId = $referenceId;

		return $this;
	}

	#[\Override]
	public function getMetaData(): ?array {
		return $this->metaData;
	}

	#[\Override]
	public function setMetaData(?array $metaData): IComment {
		$this->metaData = $metaData;

		return $this;
	}

	#[\Override]
	public function getReactions(): array {
		return $this->reactions;
	}

	#[\Override]
	public function setReactions(?array $reactions): IComment {
		$this->reactions = $reactions ?? [];

		return $this;
	}

	#[\Override]
	public function setExpireDate(?\DateTime $dateTime): IComment {
		$this->expire = $dateTime;

		return $this;
	}

	#[\Override]
	public function getExpireDate(): ?\DateTime {
		return $this->expire;
	}
}
