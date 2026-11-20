<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Mastodon's StatusEdit entity: one version a status has been through, with
 * its text, content warning, sensitivity and attachments. Not a Status:
 * clients diff it against the status they have.
 *
 * `account` is attached on read, so it shows the author as they are now.
 * `media_attachments` are stored per revision since descriptions and focal
 * points are edited in place; older revisions without them read as `[]`.
 * `poll` and `emojis` are always `null`/`[]` but emitted, as some clients
 * require them.
 */
class StatusRevision implements JsonSerializable {
	use TArrayTools;

	private int $id = 0;
	private string $streamId = '';
	private string $content = '';
	private string $spoilerText = '';
	private bool $sensitive = false;
	private string $published = '';
	private ?Person $account = null;
	/** @var list<MediaAttachment> */
	private array $mediaAttachments = [];

	/**
	 * The version a status is in right now. `published` is the edit stamp, or
	 * the creation stamp if never edited, so the first revision carries the
	 * posting time.
	 */
	public static function fromStream(Stream $stream): self {
		$updated = $stream->getUpdated();

		return (new self())
			->setStreamId($stream->getId())
			->setContent($stream->getContent())
			->setSpoilerText($stream->getSpoilerText())
			->setSensitive($stream->isSensitive())
			->setMediaAttachments(array_values(array_filter(
				$stream->getAttachments(),
				static fn (mixed $attachment): bool => $attachment instanceof MediaAttachment
			)))
			->setPublished(($updated === '') ? $stream->getPublished() : $updated);
	}

	public function setId(int $id): self {
		$this->id = $id;

		return $this;
	}

	public function getId(): int {
		return $this->id;
	}

	public function setStreamId(string $streamId): self {
		$this->streamId = $streamId;

		return $this;
	}

	public function getStreamId(): string {
		return $this->streamId;
	}

	public function setContent(string $content): self {
		$this->content = $content;

		return $this;
	}

	public function getContent(): string {
		return $this->content;
	}

	public function setSpoilerText(string $spoilerText): self {
		$this->spoilerText = $spoilerText;

		return $this;
	}

	public function getSpoilerText(): string {
		return $this->spoilerText;
	}

	public function setSensitive(bool $sensitive): self {
		$this->sensitive = $sensitive;

		return $this;
	}

	public function isSensitive(): bool {
		return $this->sensitive;
	}

	public function setPublished(string $published): self {
		$this->published = $published;

		return $this;
	}

	public function getPublished(): string {
		return $this->published;
	}

	/** @param list<MediaAttachment> $mediaAttachments */
	public function setMediaAttachments(array $mediaAttachments): self {
		$this->mediaAttachments = $mediaAttachments;

		return $this;
	}

	/** @return list<MediaAttachment> */
	public function getMediaAttachments(): array {
		return $this->mediaAttachments;
	}

	/**
	 * The attachments in the form the row stores them, the same one a status
	 * row stores its own in.
	 */
	public function getMediaAttachmentsAsJson(): string {
		return (string)json_encode(
			array_map(static fn (MediaAttachment $attachment): array => $attachment->asLocal(), $this->mediaAttachments),
			JSON_UNESCAPED_SLASHES
		);
	}

	public function setAccount(?Person $account): self {
		$this->account = $account;

		return $this;
	}

	public function getAccount(): ?Person {
		return $this->account;
	}

	/** @param array<string, mixed> $data a row of `social_stream_rev` */
	public function importFromDatabase(array $data): self {
		$this->setId($this->getInt('id', $data))
			->setStreamId($this->get('stream_id_prim', $data))
			->setContent($this->get('content', $data))
			->setSpoilerText($this->get('spoiler_text', $data))
			->setSensitive($this->getInt('sensitive', $data) === 1)
			->setPublished($this->get('published', $data));

		// null on a row written before the column existed
		$stored = json_decode((string)($data['media'] ?? ''), true);
		$attachments = [];
		foreach (is_array($stored) ? $stored : [] as $attachment) {
			if (is_array($attachment)) {
				$attachments[] = (new MediaAttachment())->import($attachment);
			}
		}
		$this->setMediaAttachments($attachments);

		return $this;
	}

	/**
	 * `account` in the client format, as only Mastodon clients read a
	 * StatusEdit.
	 */
	#[\Override]
	public function jsonSerialize(): array {
		$account = $this->getAccount();
		if ($account !== null) {
			$account->setExportFormat(ACore::FORMAT_LOCAL);
		}

		return [
			'content' => $this->getContent(),
			'spoiler_text' => $this->getSpoilerText(),
			'sensitive' => $this->isSensitive(),
			'created_at' => self::clientTime($this->getPublished()),
			'account' => $account,
			'poll' => null,
			'media_attachments' => array_map(
				static fn (MediaAttachment $attachment): MediaAttachment => $attachment->setExportFormat(ACore::FORMAT_LOCAL),
				$this->getMediaAttachments()
			),
			'emojis' => [],
		];
	}

	/**
	 * When this version was written, in the client API's form (UTC with
	 * milliseconds and `Z`); the stored ActivityPub time may use any ISO 8601
	 * spelling, which some clients fail to parse.
	 */
	private static function clientTime(string $stored): string {
		$time = strtotime($stored);
		if ($stored === '' || $time === false) {
			return $stored;
		}

		return gmdate('Y-m-d\TH:i:s', $time) . '.000Z';
	}
}
