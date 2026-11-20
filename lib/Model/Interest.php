<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model;

/**
 * One hashtag a reader is interested in, as `social_interest` stores it. The
 * score is what reading earned it as of `scoredAt`; it decays from there, as
 * `InterestScorer` works out when read. A position is the rank the reader
 * pinned it to; null floats on its score.
 */
class Interest {
	public function __construct(
		private string $hashtag,
		private float $score = 0.0,
		private int $scoredAt = 0,
		private bool $manual = false,
		private ?int $position = null,
		private ?float $scoreWeek = null,
	) {
	}

	public function getHashtag(): string {
		return $this->hashtag;
	}

	public function getScore(): float {
		return $this->score;
	}

	public function setScore(float $score): self {
		$this->score = $score;

		return $this;
	}

	/** Unix time the score was last worked out at. */
	public function getScoredAt(): int {
		return $this->scoredAt;
	}

	public function setScoredAt(int $scoredAt): self {
		$this->scoredAt = $scoredAt;

		return $this;
	}

	/** Added by the reader rather than learned. */
	public function isManual(): bool {
		return $this->manual;
	}

	public function setManual(bool $manual): self {
		$this->manual = $manual;

		return $this;
	}

	public function getPosition(): ?int {
		return $this->position;
	}

	public function setPosition(?int $position): self {
		$this->position = $position;

		return $this;
	}

	public function isPinned(): bool {
		return $this->position !== null;
	}

	/** The score as the current week of activity began, for the trend arrow. */
	public function getScoreWeek(): ?float {
		return $this->scoreWeek;
	}

	public function setScoreWeek(?float $scoreWeek): self {
		$this->scoreWeek = $scoreWeek;

		return $this;
	}

	/**
	 * Whether the row still says anything: a learned, unpinned tag whose score
	 * has faded to nothing is the same as no row at all.
	 */
	public function isEmpty(): bool {
		return !$this->manual && $this->position === null && abs($this->score) < 0.01;
	}
}
