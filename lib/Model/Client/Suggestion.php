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
 * Mastodon's Suggestion entity: an account to follow, and where the
 * suggestion came from. Both `sources` and the deprecated `source` are sent,
 * since clients read one or the other.
 *
 * Sources: `friends_of_friends` for an account followed by accounts the
 * viewer follows, `most_interactions` for one posting here, and `featured`
 * for an account named in the `fediverse` field of a Nextcloud profile on
 * this instance.
 */
class Suggestion implements JsonSerializable {
	/** Followed by somebody the viewer follows. */
	public const SOURCE_FRIENDS = 'friends_of_friends';
	/** Active on this instance and open to being found. */
	public const SOURCE_ACTIVE = 'most_interactions';
	/** Named in the `fediverse` field of a Nextcloud profile on this instance. */
	public const SOURCE_COLLEAGUES = 'featured';

	/** @var array<string, string> the deprecated `source` each `sources` maps to */
	private const LEGACY_SOURCE = [
		self::SOURCE_FRIENDS => 'past_interactions',
		self::SOURCE_ACTIVE => 'global',
		self::SOURCE_COLLEAGUES => 'staff',
	];

	public function __construct(
		private Person $account,
		private string $source = self::SOURCE_ACTIVE,
	) {
	}

	public function getAccount(): Person {
		return $this->account;
	}

	public function getSource(): string {
		return $this->source;
	}

	#[\Override]
	public function jsonSerialize(): array {
		$this->account->setExportFormat(ACore::FORMAT_LOCAL);

		return [
			'source' => self::LEGACY_SOURCE[$this->source] ?? 'global',
			'sources' => [$this->source],
			'account' => $this->account,
		];
	}
}
