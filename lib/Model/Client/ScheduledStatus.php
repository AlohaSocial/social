<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;
use OCA\Social\Model\StatusParams;
use OCA\Social\Model\TStatusParams;
use OCA\Social\Tools\DbTime;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Mastodon's ScheduledStatus entity: a post accepted but not yet published,
 * and the request to replay when it is. `POST /api/v1/statuses` answers with
 * it for `scheduled_at`; clients tell it from a Status by its keys.
 *
 * `params` is the client's own request, read only by `paramText()` and
 * friends. `actorId` is not shown to clients; ScheduledStatusesRequest checks
 * it as a SQL predicate.
 */
class ScheduledStatus implements JsonSerializable, StatusParams {
	use TArrayTools;
	use TStatusParams;

	/**
	 * The keys `params` carries, in Mastodon's order, each sent every time
	 * (`null` when unset) since clients may declare them non-optional.
	 */
	private const PARAM_KEYS = [
		'text', 'media_ids', 'poll', 'in_reply_to_id', 'quoted_status_id',
		'sensitive', 'spoiler_text', 'visibility', 'language', 'event',
		'application_id', 'idempotency', 'scheduled_at', 'with_rate_limit',
	];

	private int $id = 0;
	private string $actorId = '';
	private int $scheduledAt = 0;
	/** @var MediaAttachment[] */
	private array $mediaAttachments = [];
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

	public function setScheduledAt(int $scheduledAt): self {
		$this->scheduledAt = $scheduledAt;

		return $this;
	}

	public function getScheduledAt(): int {
		return $this->scheduledAt;
	}

	/**
	 * Anything outside Mastodon's set and this app's `event` extension is
	 * dropped: `params` is echoed back, and must not hold arbitrary content under
	 * the account's name.
	 *
	 * @param array<string, mixed> $params
	 */
	public function setParams(array $params): self {
		$kept = [];
		foreach (self::PARAM_KEYS as $key) {
			$kept[$key] = $params[$key] ?? null;
		}

		$this->params = $kept;

		return $this;
	}

	/** @param MediaAttachment[] $mediaAttachments */
	public function setMediaAttachments(array $mediaAttachments): self {
		$this->mediaAttachments = $mediaAttachments;

		return $this;
	}

	/** @return MediaAttachment[] */
	public function getMediaAttachments(): array {
		return $this->mediaAttachments;
	}

	public function setCreation(int $creation): self {
		$this->creation = $creation;

		return $this;
	}

	public function getCreation(): int {
		return $this->creation;
	}

	/** @param array<string, mixed> $data a row of `social_scheduled` */
	public function importFromDatabase(array $data): self {
		$scheduledAt = $this->get('scheduled_at', $data);
		$creation = $this->get('creation', $data);
		$params = json_decode($this->get('params', $data), true);

		$this->setId($this->getInt('id', $data))
			->setActorId($this->get('actor_id', $data))
			->setScheduledAt(DbTime::toTimestamp($scheduledAt))
			->setParams(is_array($params) ? $params : [])
			->setCreation(DbTime::toTimestamp($creation));

		return $this;
	}

	/**
	 * Exactly Mastodon's four keys. `media_attachments` holds the
	 * `MediaAttachment` entities, so clients can draw thumbnails without fetching
	 * each of `params.media_ids`.
	 */
	#[\Override]
	public function jsonSerialize(): array {
		$params = $this->getParams();
		// an ISO 639 code or null, as on a status: the post is stored with no
		// language as an empty string, which is neither
		if (($params['language'] ?? null) === '') {
			$params['language'] = null;
		}

		return [
			'id' => (string)$this->getId(),
			'scheduled_at' => gmdate('Y-m-d\TH:i:s', $this->getScheduledAt()) . '.000Z',
			'params' => $params,
			'media_attachments' => $this->getMediaAttachments(),
		];
	}
}
