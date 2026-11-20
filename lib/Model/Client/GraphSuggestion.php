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

/**
 * An account the people you follow follow, and how many of them do. Mastodon's
 * Suggestion has only a `source`, so this carries the count and the handles
 * behind it, for the reader to judge the suggestion.
 */
class GraphSuggestion implements JsonSerializable {
	/**
	 * @param string[] $via handles of accounts that follow this one, at most a
	 *                      few: enough to recognise, not a list of everyone
	 */
	public function __construct(
		private Person $account,
		private int $followedBy,
		private array $via = [],
	) {
	}

	public function getAccount(): Person {
		return $this->account;
	}

	public function getFollowedBy(): int {
		return $this->followedBy;
	}

	/** @return string[] */
	public function getVia(): array {
		return $this->via;
	}

	#[\Override]
	public function jsonSerialize(): array {
		$this->account->setExportFormat(ACore::FORMAT_LOCAL);

		return [
			'account' => $this->account->jsonSerialize(),
			'followed_by' => $this->followedBy,
			'via' => $this->via,
		];
	}

	/**
	 * The other direction, for an answer that was kept: the account comes back
	 * as the entity it was sent as, since that is what the page reads.
	 *
	 * @param array<string, mixed> $row
	 */
	public static function fromArray(array $row): ?self {
		$data = $row['account'] ?? null;
		if (!is_array($data) || ($data['acct'] ?? '') === '') {
			return null;
		}

		$account = new Person();
		$account->importFromLocal($data);

		return new self(
			$account,
			(int)($row['followed_by'] ?? 0),
			array_values(array_filter(array_map(
				static fn ($handle): string => is_string($handle) ? $handle : '',
				is_array($row['via'] ?? null) ? $row['via'] : []
			)))
		);
	}
}
