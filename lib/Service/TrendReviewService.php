<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use InvalidArgumentException;
use OCA\Social\Db\TrendReviewRequest;

/**
 * Keeping something out of what is trending, for Mastodon's trend admin
 * routes.
 *
 * Rejected items are hidden and everything else trends; approval is recorded
 * but grants nothing, so Explore is not emptied until somebody approves. The
 * filter applies when trends are read, not counted, so a lifted rejection
 * returns with its full count.
 */
class TrendReviewService {
	public function __construct(
		private TrendReviewRequest $trendReviewRequest,
	) {
	}

	/**
	 * @throws InvalidArgumentException a kind this app does not review
	 */
	public function decide(string $kind, string $ref, bool $approved, string $moderator): void {
		$kind = $this->assertKind($kind);
		$ref = $this->normalise($kind, $ref);
		if ($ref === '') {
			throw new InvalidArgumentException('there is nothing named here to decide about');
		}

		$this->trendReviewRequest->decide($kind, $ref, $approved, $moderator);
	}

	/**
	 * @throws InvalidArgumentException
	 */
	public function forget(string $kind, string $ref): bool {
		$kind = $this->assertKind($kind);

		return $this->trendReviewRequest->forget($kind, $this->normalise($kind, $ref));
	}

	/**
	 * @return array<int, array{ref: string, approved: bool, moderator: string, creation: string}>
	 * @throws InvalidArgumentException
	 */
	public function decisions(string $kind): array {
		return $this->trendReviewRequest->decisions($this->assertKind($kind));
	}

	/**
	 * One hashtag in Mastodon's `Admin::Tag` shape. `usable` and `listable`
	 * are always true: hashtags here cannot be disabled for posting or search.
	 *
	 * @return array{id: string, name: string, trendable: bool, usable: bool, listable: bool}
	 */
	public function tagEntity(string $tag): array {
		$name = $this->normalise(TrendReviewRequest::KIND_TAG, $tag);

		return [
			'id' => $name,
			'name' => $name,
			'trendable' => !isset($this->rejectedSet(TrendReviewRequest::KIND_TAG)[strtolower($name)]),
			'usable' => true,
			'listable' => true,
		];
	}

	/**
	 * Takes the rejected ones out of a page of trending hashtags.
	 *
	 * @param array<int, array<string, mixed>> $hashtags rows as `getTrending()` returns them
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function filterTags(array $hashtags): array {
		$rejected = $this->rejectedSet(TrendReviewRequest::KIND_TAG);
		if ($rejected === []) {
			return $hashtags;
		}

		return array_values(array_filter(
			$hashtags,
			static fn (array $row): bool
				=> !isset($rejected[strtolower((string)($row['hashtag'] ?? ''))])
		));
	}

	/**
	 * The same for links, which are named by their URL.
	 *
	 * @param array<int, array<string, mixed>> $links
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function filterLinks(array $links): array {
		$rejected = $this->rejectedSet(TrendReviewRequest::KIND_LINK);
		if ($rejected === []) {
			return $links;
		}

		return array_values(array_filter(
			$links,
			static fn (array $row): bool => !isset($rejected[strtolower((string)($row['url'] ?? ''))])
		));
	}

	/** Whether one status has been kept out of the trending list. */
	public function statusIsRejected(string $statusId): bool {
		return isset($this->rejectedSet(TrendReviewRequest::KIND_STATUS)[strtolower($statusId)]);
	}

	/**
	 * The rejected references of one kind, as a set for O(1) lookup.
	 *
	 * @return array<string, true>
	 */
	private function rejectedSet(string $kind): array {
		$set = [];
		foreach ($this->trendReviewRequest->rejected($kind) as $ref) {
			$set[strtolower($ref)] = true;
		}

		return $set;
	}

	/**
	 * @throws InvalidArgumentException
	 */
	private function assertKind(string $kind): string {
		$kind = strtolower(trim($kind));
		if (!in_array($kind, TrendReviewRequest::KINDS, true)) {
			throw new InvalidArgumentException(
				'kind must be one of ' . implode(', ', TrendReviewRequest::KINDS)
			);
		}

		return $kind;
	}

	/**
	 * Normalises a reference: a hashtag loses its leading `#` (the counters
	 * store the bare word); a status id is a URL and is left as it is.
	 */
	private function normalise(string $kind, string $ref): string {
		$ref = trim($ref);

		return ($kind === TrendReviewRequest::KIND_TAG) ? ltrim($ref, '#') : $ref;
	}
}
