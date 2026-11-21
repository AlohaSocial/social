<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub\Object;

use JsonSerializable;
use OCA\Social\Model\StreamAction;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Server;

/**
 * An ActivityPub poll, the way Mastodon federates it: options in `oneOf`
 * (single choice) or `anyOf` (multiple), each with its count in
 * `replies.totalItems`. Stored through the wire source; a remote
 * `Update{Question}` refreshes it and the counts.
 */
class Question extends Note implements JsonSerializable {
	public const TYPE = 'Question';

	/** @var array[] [['title' => string, 'votes_count' => int], …] */
	private array $options = [];
	private bool $multiple = false;
	private string $endTime = '';
	private string $closed = '';
	private int $votersCount = 0;

	public function __construct($parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
	}

	/**
	 * @return array[]
	 */
	public function getOptions(): array {
		return $this->options;
	}

	public function isMultiple(): bool {
		return $this->multiple;
	}

	public function getEndTime(): string {
		return $this->endTime;
	}

	/**
	 * When the poll ends, as a Unix time, or null for one with no end time
	 * or one that cannot be read — the form `social_stream.poll_ends_at`
	 * stores it in.
	 */
	public function getEndTimestamp(): ?int {
		return self::timestampOf($this->endTime);
	}

	/** An ActivityPub `endTime` as a Unix time, or null when there is none to read. */
	public static function timestampOf(string $endTime): ?int {
		if ($endTime === '') {
			return null;
		}

		$time = strtotime($endTime);

		return ($time === false || $time < 0) ? null : $time;
	}

	/**
	 * A time as the client API writes every one: UTC, with milliseconds and
	 * `Z`. The `endTime` a peer sent is kept as it was written, which may be
	 * any ISO 8601 spelling.
	 */
	private static function clientTime(?int $time): ?string {
		return ($time === null) ? null : gmdate('Y-m-d\TH:i:s', $time) . '.000Z';
	}

	/**
	 * Whether the poll is over at a moment (the server's clock when none is
	 * given). Over on its end time, as the closing sweep reads polls ending at
	 * or before now.
	 */
	public function isExpired(?int $now = null): bool {
		if ($this->closed !== '') {
			return true;
		}
		if ($this->endTime === '') {
			return false;
		}

		return strtotime($this->endTime) <= ($now ?? self::now());
	}

	public function getVotersCount(): int {
		return $this->votersCount;
	}

	/**
	 * Initialise a locally created poll: options start at zero votes.
	 *
	 * @param string[] $optionTitles
	 */
	public function setPollData(array $optionTitles, bool $multiple, int $expiresInSeconds, ?int $now = null): self {
		$this->options = array_map(
			static fn (string $title): array => ['title' => $title, 'votes_count' => 0],
			array_values($optionTitles)
		);
		$this->multiple = $multiple;
		$this->endTime = gmdate('Y-m-d\TH:i:s\Z', ($now ?? self::now()) + $expiresInSeconds);
		$this->votersCount = 0;

		return $this;
	}

	/**
	 * Counts an incoming vote (a first-time voter bumps votersCount too).
	 */
	public function countVote(int $option, bool $newVoter): void {
		if (!array_key_exists($option, $this->options)) {
			return;
		}
		$this->options[$option]['votes_count']++;
		if ($newVoter) {
			$this->votersCount++;
		}
	}

	public function findOption(string $title): ?int {
		foreach ($this->options as $index => $option) {
			if ($option['title'] === $title) {
				return $index;
			}
		}

		return null;
	}

	#[\Override]
	public function import(array $data): void {
		parent::import($data);

		$this->parsePollData($data);
	}

	#[\Override]
	public function importFromDatabase(array $data): void {
		parent::importFromDatabase($data);

		$source = json_decode($this->getSource(), true);
		if (is_array($source)) {
			$this->parsePollData($source);
		}
	}

	private function parsePollData(array $data): void {
		$options = $this->getArray('oneOf', $data, []);
		$this->multiple = false;
		if ($options === []) {
			$options = $this->getArray('anyOf', $data, []);
			$this->multiple = ($options !== []);
		}

		$this->options = [];
		foreach ($options as $option) {
			if (!is_array($option) || !is_scalar($option['name'] ?? null) || (string)$option['name'] === '') {
				continue;
			}
			$replies = $option['replies'] ?? null;
			$totalItems = is_array($replies) ? ($replies['totalItems'] ?? 0) : 0;
			$this->options[] = [
				'title' => (string)$option['name'],
				'votes_count' => is_scalar($totalItems) ? (int)$totalItems : 0,
			];
		}

		$this->endTime = $this->get('endTime', $data, '');
		$this->closed = $this->get('closed', $data, '');
		$this->votersCount = $this->getInt('votersCount', $data, 0);
	}

	/**
	 * The wire shape Mastodon understands: options in oneOf/anyOf with their
	 * counts in replies.totalItems, plus endTime and votersCount.
	 */
	#[\Override]
	public function jsonSerialize(): array {
		$result = parent::jsonSerialize();
		// The client format carries the poll as `poll` (exportAsLocal).
		if ($this->options === [] || $this->getExportFormat() === self::FORMAT_LOCAL) {
			return $result;
		}

		$key = $this->multiple ? 'anyOf' : 'oneOf';
		$result[$key] = array_map(static fn (array $option): array => [
			'type' => 'Note',
			'name' => $option['title'],
			'replies' => ['type' => 'Collection', 'totalItems' => $option['votes_count']],
		], $this->options);
		if ($this->endTime !== '') {
			$result['endTime'] = $this->endTime;
		}
		if ($this->closed !== '') {
			$result['closed'] = $this->closed;
		}
		$result['votersCount'] = $this->votersCount;

		return $result;
	}

	/**
	 * The Mastodon Poll entity, carried in the status as `poll`. The viewer's
	 * own votes come from the per-viewer stream action (`poll_votes`).
	 */
	#[\Override]
	public function exportAsLocal(): array {
		$result = parent::exportAsLocal();
		if ($this->options === []) {
			return $result;
		}

		$ownVotes = [];
		if ($this->hasAction()) {
			$stored = $this->getAction()->getValues()[StreamAction::POLL_VOTES] ?? '';
			$decoded = is_string($stored) ? json_decode($stored, true) : $stored;
			if (is_array($decoded)) {
				$ownVotes = array_values(array_map('intval', $decoded));
			}
		}

		$result['poll'] = [
			'id' => (string)$this->getNid(),
			'expires_at' => self::clientTime($this->getEndTimestamp()),
			'expired' => $this->isExpired(),
			'multiple' => $this->multiple,
			'votes_count' => array_sum(array_column($this->options, 'votes_count')),
			'voters_count' => $this->votersCount,
			'options' => $this->options,
			'emojis' => $this->getEmojis(),
			'voted' => ($ownVotes !== []),
			'own_votes' => $ownVotes,
		];

		return $result;
	}

	private static function now(): int {
		return Server::get(ITimeFactory::class)->getTime();
	}
}
