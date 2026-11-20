<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;

/**
 * Mastodon's Translation entity: what one status says, in another language.
 * Not a Status, which a client could store in a timeline in place of the
 * original. `detected_source_language` is the language the post declared,
 * where it declared one.
 */
class Translation implements JsonSerializable {
	private string $content = '';
	private string $spoilerText = '';
	private string $detectedSourceLanguage = '';
	private string $provider = '';
	/** @var array<int, array{id: string, description: string}> */
	private array $mediaAttachments = [];
	/** @var array<int, array{title: string}> */
	private array $pollOptions = [];
	private string $pollId = '';

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

	public function setDetectedSourceLanguage(string $language): self {
		$this->detectedSourceLanguage = $language;

		return $this;
	}

	public function getDetectedSourceLanguage(): string {
		return $this->detectedSourceLanguage;
	}

	public function setProvider(string $provider): self {
		$this->provider = $provider;

		return $this;
	}

	public function getProvider(): string {
		return $this->provider;
	}

	/**
	 * @param array<int, array{id: string, description: string}> $mediaAttachments
	 */
	public function setMediaAttachments(array $mediaAttachments): self {
		$this->mediaAttachments = $mediaAttachments;

		return $this;
	}

	/**
	 * @param array<int, array{title: string}> $options
	 */
	public function setPoll(string $id, array $options): self {
		$this->pollId = $id;
		$this->pollOptions = $options;

		return $this;
	}

	/**
	 * The keys Mastodon defines, all of them, every time: `media_attachments`
	 * is always a list, and `poll` is null when there is nothing to translate.
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'content' => $this->content,
			'spoiler_text' => $this->spoilerText,
			'media_attachments' => $this->mediaAttachments,
			'poll' => ($this->pollId === '')
				? null
				: ['id' => $this->pollId, 'options' => $this->pollOptions],
			'detected_source_language' => $this->detectedSourceLanguage,
			'provider' => $this->provider,
		];
	}
}
