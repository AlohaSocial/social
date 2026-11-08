<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use UnexpectedValueException;

/** A descending feed's committed timestamp and an unfinished walk back to it. */
final class PollCursor {
	public readonly string $since;
	public readonly string $newest;
	public readonly string $page;
	/** how many pages the unfinished walk has read */
	public readonly int $pages;

	public function __construct(string $stored) {
		if (!str_starts_with($stored, '{')) {
			$this->since = $stored;
			$this->newest = $stored;
			$this->page = '';
			$this->pages = 0;

			return;
		}

		$data = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($data) || !is_string($data['since'] ?? null)
			|| !is_string($data['newest'] ?? null) || !is_string($data['page'] ?? null) || $data['page'] === '') {
			throw new UnexpectedValueException('Invalid Bluesky polling checkpoint');
		}
		$this->since = $data['since'];
		$this->newest = $data['newest'];
		$this->page = $data['page'];
		$this->pages = is_int($data['pages'] ?? null) ? $data['pages'] : 1;
	}

	/** Whether a walk back to the committed timestamp is unfinished. */
	public function walking(): bool {
		return $this->page !== '';
	}

	/** @return array<string, string> the next page's query parameter */
	public function parameters(): array {
		return $this->page === '' ? [] : ['cursor' => $this->page];
	}

	/**
	 * Commit the newest timestamp only after reaching the previous one or the
	 * end. A first feed read may walk to the end; other readers take a snapshot.
	 * A cursor that does not advance ends the walk, as does reaching
	 * `$maxPages` pages (0 for no limit).
	 */
	public function after(string $newest, mixed $next, bool $reached, bool $walkInitial = false, int $maxPages = 0): string {
		if (!is_string($next)) {
			throw new UnexpectedValueException('Invalid Bluesky pagination cursor');
		}
		$newest = max($this->newest, $newest);
		$pages = $this->pages + 1;
		if (($this->since === '' && (!$walkInitial || $next === '')) || $reached || $next === ''
			|| $next === $this->page || ($maxPages > 0 && $pages >= $maxPages)) {
			return $newest;
		}

		return json_encode(['since' => $this->since, 'newest' => $newest, 'page' => $next, 'pages' => $pages], JSON_THROW_ON_ERROR);
	}
}
