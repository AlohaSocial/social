<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub\Object;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\ACore;

/**
 * A story on the wire: one picture that is gone after a day.
 *
 * Pixelfed's object and verbs, field names included, since only Pixelfed
 * reads stories: an `Add` to the author's followers, withdrawn with a
 * `Delete`. Not a `Note`, so a server that does not know the type ignores it.
 * `expiresAt` is required and in the future, and nothing is held past a day.
 *
 * Two shapes exist only for Pixelfed: `object` is a bearcap
 * (`bear:?t=…&u=…`, FEP-d8c2) that its `StoryFetch` follows, so an `Add`
 * without one is dropped there; and `attachment` is one object of type
 * `Image` or `Video`, not a list, as Pixelfed validates.
 */
class Story extends ACore implements JsonSerializable {
	public const TYPE = 'Story';

	/** No story is held longer than this, whatever `expiresAt` claims. */
	public const MAX_LIFETIME = 24 * 3600;

	/** The only two attachment types Pixelfed will accept on a story. */
	private const ATTACHMENT_VIDEO = 'Video';

	private string $attributedTo = '';
	private string $caption = '';
	private int $duration = 5;
	private int $expiresAt = 0;
	private string $bearcap = '';
	private ?Document $attachment = null;

	public function __construct($parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
	}

	/** Whose story it is. On `ACore` this lives only on a Stream, and a story is not one. */
	public function getAttributedTo(): string {
		return $this->attributedTo;
	}

	public function setAttributedTo(string $attributedTo): self {
		$this->attributedTo = $attributedTo;

		return $this;
	}

	public function getCaption(): string {
		return $this->caption;
	}

	public function setCaption(string $caption): self {
		$this->caption = $caption;

		return $this;
	}

	public function getDuration(): int {
		return $this->duration;
	}

	public function setDuration(int $duration): self {
		$this->duration = $duration;

		return $this;
	}

	public function getExpiresAt(): int {
		return $this->expiresAt;
	}

	public function setExpiresAt(int $expiresAt): self {
		$this->expiresAt = $expiresAt;

		return $this;
	}

	/**
	 * The capability that fetches this story: `bear:?t=<token>&u=<url>`. Empty
	 * on a story that arrived: this app reads the fields beside it instead.
	 */
	public function getBearcap(): string {
		return $this->bearcap;
	}

	public function setBearcap(string $bearcap): self {
		$this->bearcap = $bearcap;

		return $this;
	}

	public function getAttachment(): ?Document {
		return $this->attachment;
	}

	public function setAttachment(?Document $attachment): self {
		$this->attachment = $attachment;

		return $this;
	}

	#[\Override]
	public function import(array $data): void {
		parent::import($data);

		$this->setAttributedTo($this->validate(ACore::AS_URL, 'attributedTo', $data, ''));
		$this->setCaption($this->validate(ACore::AS_CONTENT, 'content', $data, ''));
		$this->setDuration($this->getInt('duration', $data, 5));

		$expires = strtotime($this->get('expiresAt', $data, ''));
		$this->setExpiresAt(($expires === false) ? 0 : $expires);

		// one picture: a story is one thing shown for a few seconds
		$attachments = $this->getArray('attachment', $data, []);
		$first = $attachments[0] ?? ($attachments === [] ? null : $attachments);
		if (is_array($first) && ($first['url'] ?? '') !== '') {
			$document = new Document();
			// a remote file is identified by its url: Pixelfed's attachment has no id,
			// and one minted here would name a document that is not on this server
			$document->setId($this->validate(ACore::AS_URL, 'id', $first, '')
				?: $this->validate(ACore::AS_URL, 'url', $first, ''));
			$document->setUrl($this->validate(ACore::AS_URL, 'url', $first, ''));
			$document->setMediaType($this->validate(ACore::AS_STRING, 'mediaType', $first, ''));
			$document->setMimeType($document->getMediaType());
			$document->setDescription($this->validate(ACore::AS_STRING, 'name', $first, ''));
			$document->setParentId($this->getId());
			$this->setAttachment($document);
		}
	}

	/**
	 * `Image` or `Video`, the only pair Pixelfed accepts, worked out from the
	 * file, since every attachment here is a `Document`.
	 */
	private function attachmentType(Document $attachment): string {
		return str_starts_with(strtolower($attachment->getMediaType()), 'video/')
			? self::ATTACHMENT_VIDEO : Image::TYPE;
	}

	#[\Override]
	public function jsonSerialize(): array {
		$attachment = $this->getAttachment();

		return array_filter(
			array_merge(
				parent::jsonSerialize(),
				[
					'attributedTo' => $this->getAttributedTo(),
					'content' => $this->getCaption(),
					'duration' => $this->getDuration(),
					'expiresAt' => ($this->getExpiresAt() > 0)
						? gmdate('Y-m-d\TH:i:s\Z', $this->getExpiresAt()) : '',
					// the capability that fetches this story, for the one
					// network that reads a story that way; see the class comment
					'object' => $this->getBearcap(),
					// neither is possible here, and both are stated, since a reader assuming
					// the default would get it wrong
					'can_reply' => false,
					'can_react' => false,
					// one object, not a list — see the class comment
					'attachment' => ($attachment === null) ? [] : [
						'type' => $this->attachmentType($attachment),
						'mediaType' => $attachment->getMediaType(),
						'url' => $attachment->getUrl(),
						'name' => ($attachment->getDescription() === '') ? null : $attachment->getDescription(),
					],
				]
			),
			static fn ($value): bool => $value !== '' && $value !== []
		);
	}
}
