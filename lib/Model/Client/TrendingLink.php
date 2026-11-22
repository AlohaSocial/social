<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;
use OCA\Social\Model\StreamCard;

/**
 * Mastodon's Trends::Link entity: a link preview with the counts that put it
 * in the list. The card half is the app's own `StreamCard`, so a trending
 * link and the card on its post agree. `history` has a single bucket with
 * `accounts` always `0`, as in `HashtagService::tagEntity()`: uses are
 * counted, not distinct accounts.
 */
class TrendingLink implements JsonSerializable {
	public function __construct(
		private StreamCard $card,
		private int $shares,
	) {
	}

	public function getCard(): StreamCard {
		return $this->card;
	}

	public function getShares(): int {
		return $this->shares;
	}

	#[\Override]
	public function jsonSerialize(): array {
		return array_merge(
			$this->card->jsonSerialize(),
			[
				'history' => [
					[
						'day' => (string)strtotime('today midnight'),
						'accounts' => '0',
						'uses' => (string)$this->shares,
					]
				],
			]
		);
	}
}
