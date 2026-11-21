<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;

/**
 * One word or phrase a filter matches on, as Mastodon's `FilterKeyword`
 * entity. `wholeWord` is applied by `FilterService::keywordRegex()`.
 */
class FilterKeyword implements JsonSerializable {
	/** The width of `social_filter_kw.keyword`. */
	public const MAX_KEYWORD = 255;

	private int $id = 0;
	private int $filterId = 0;
	private string $keyword = '';
	private bool $wholeWord = false;
	private int $creation = 0;

	public function setId(int $id): self {
		$this->id = $id;

		return $this;
	}

	public function getId(): int {
		return $this->id;
	}

	public function setFilterId(int $filterId): self {
		$this->filterId = $filterId;

		return $this;
	}

	public function getFilterId(): int {
		return $this->filterId;
	}

	public function setKeyword(string $keyword): self {
		$this->keyword = $keyword;

		return $this;
	}

	public function getKeyword(): string {
		return $this->keyword;
	}

	public function setWholeWord(bool $wholeWord): self {
		$this->wholeWord = $wholeWord;

		return $this;
	}

	public function isWholeWord(): bool {
		return $this->wholeWord;
	}

	public function setCreation(int $creation): self {
		$this->creation = $creation;

		return $this;
	}

	public function getCreation(): int {
		return $this->creation;
	}

	/**
	 * The keyword as it is stored: trimmed, and cut to the column's width in
	 * characters rather than bytes. Returns '' for something that is not a
	 * keyword at all, which the caller answers 422 for — a filter with an
	 * empty keyword would match every status there is.
	 */
	public static function normalise(string $raw): string {
		return mb_substr(trim($raw), 0, self::MAX_KEYWORD, 'UTF-8');
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => (string)$this->id,
			'keyword' => $this->keyword,
			'whole_word' => $this->wholeWord,
		];
	}
}
