<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model;

use JsonSerializable;
use OCA\Social\Tools\DbTime;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * A post that was accepted, stored and not published, because a rule said a
 * person should look at it first. It is the request, not the post: nothing
 * is written to `social_stream` until it is approved. The account is carried
 * so the row can be checked against the caller in SQL.
 *
 * `reason` is which rule held it, from a small closed set a moderator can
 * agree or disagree with.
 */
class HeldPost implements JsonSerializable, StatusParams {
	use TArrayTools;
	use TStatusParams;

	/** The account has published nothing here yet. */
	public const REASON_FIRST_POST = 'first_post';

	/** More links than a post this length carries for any other reason. */
	public const REASON_LINKS = 'links';

	/** Mentions of accounts that have nothing to do with this one. */
	public const REASON_MENTIONS = 'mentions';

	/** The same text this account has already been held for. */
	public const REASON_REPEAT = 'repeat';

	/**
	 * There is a video on it, and this instance looks at videos first. About
	 * the video, not the account, so trusted accounts are held too.
	 */
	public const REASON_VIDEO = 'video';

	public const REASONS = [
		self::REASON_FIRST_POST,
		self::REASON_LINKS,
		self::REASON_MENTIONS,
		self::REASON_REPEAT,
		self::REASON_VIDEO,
	];

	private int $id = 0;
	private string $actorId = '';
	private string $reason = '';
	/** Not a column: the handle, hydrated for the screens that show a row. */
	private string $handle = '';
	private string $digest = '';
	private int $creation = 0;

	public function setId(int $id): self {
		$this->id = $id;

		return $this;
	}

	public function getId(): int {
		return $this->id;
	}

	public function setActorId(string $actorId): self {
		$this->actorId = $actorId;

		return $this;
	}

	public function getActorId(): string {
		return $this->actorId;
	}

	/** @param array<string, mixed> $params */
	public function setParams(array $params): self {
		$this->params = $params;

		return $this;
	}

	public function setReason(string $reason): self {
		$this->reason = $reason;

		return $this;
	}

	public function getReason(): string {
		return $this->reason;
	}

	public function setHandle(string $handle): self {
		$this->handle = $handle;

		return $this;
	}

	public function getHandle(): string {
		return $this->handle;
	}

	/**
	 * The md5 of the account and the text, which the unique index is on;
	 * computed here so the duplicate check and the write agree.
	 */
	public function digest(): string {
		if ($this->digest !== '') {
			return $this->digest;
		}

		return md5($this->getActorId() . "\n" . $this->paramText());
	}

	public function setDigest(string $digest): self {
		$this->digest = $digest;

		return $this;
	}

	public function setCreation(int $creation): self {
		$this->creation = $creation;

		return $this;
	}

	public function getCreation(): int {
		return $this->creation;
	}

	/** @param array<string, mixed> $data a row of `social_post_hold` */
	public function importFromDatabase(array $data): self {
		$creation = $this->get('creation', $data);
		$params = json_decode($this->get('params', $data), true);

		$this->setId($this->getInt('id', $data))
			->setActorId($this->get('actor_id', $data))
			->setParams(is_array($params) ? $params : [])
			->setReason($this->get('reason', $data))
			->setDigest($this->get('digest', $data))
			->setCreation(DbTime::toTimestamp($creation));

		return $this;
	}

	/**
	 * What the author and the moderator are both shown: the text, so it can be
	 * judged and recognised, and the account as a handle and id, not an Account
	 * entity.
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => (string)$this->getId(),
			'account_id' => $this->getActorId(),
			'username' => ($this->handle !== '') ? $this->handle : $this->getActorId(),
			'reason' => $this->getReason(),
			'text' => $this->paramText(),
			'spoiler_text' => $this->paramString('spoiler_text'),
			'visibility' => $this->paramString('visibility'),
			'media_count' => count($this->paramMediaIds()),
			'created_at' => gmdate('Y-m-d\TH:i:s', $this->getCreation()) . '.000Z',
		];
	}
}
