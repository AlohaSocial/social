<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model;

use JsonSerializable;
use OCA\Social\Tools\Traits\TArrayTools;
use OCA\Social\Tools\Traits\TStringTools;

/**
 * Class StreamAction
 *
 * @package OCA\Social\Model
 */
class StreamAction implements JsonSerializable {
	use TArrayTools;
	use TStringTools;

	public const LIKED = 'liked';
	public const BOOSTED = 'boosted';
	public const REPLIED = 'replied';
	public const BOOKMARKED = 'bookmarked';
	/** PeerTube's other counter; only a video can carry one. */
	public const DISLIKED = 'disliked';
	public const POLL_VOTES = 'poll_votes';

	private int $id = 0;
	private string $actorId = '';
	private string $streamId = '';
	private array $values = [];
	private array $affected = [];
	private array $accepted = [
		self::LIKED,
		self::BOOSTED,
		self::REPLIED,
		self::BOOKMARKED,
		self::DISLIKED,
		self::POLL_VOTES
	];

	public function __construct(string $actorId = '', string $streamId = '') {
		$this->actorId = $actorId;
		$this->streamId = $streamId;
	}

	public function getId(): int {
		return $this->id;
	}

	public function setId(int $id): StreamAction {
		$this->id = $id;

		return $this;
	}

	public function getActorId(): string {
		return $this->actorId;
	}

	public function setActorId(string $actorId): StreamAction {
		$this->actorId = $actorId;

		return $this;
	}

	public function getStreamId(): string {
		return $this->streamId;
	}

	public function setStreamId(string $streamId): StreamAction {
		$this->streamId = $streamId;

		return $this;
	}

	public function updateValue(string $key, string $value): void {
		$this->values[$key] = $value;
		if (in_array($key, $this->accepted, true) && !in_array($key, $this->affected, true)) {
			$this->affected[] = $key;
		}
	}

	public function updateValueInt(string $key, int $value): void {
		$this->values[$key] = $value;
		if (in_array($key, $this->accepted, true) && !in_array($key, $this->affected, true)) {
			$this->affected[] = $key;
		}
	}

	public function updateValueBool(string $key, bool $value): void {
		$this->values[$key] = $value;
		if (in_array($key, $this->accepted, true) && !in_array($key, $this->affected, true)) {
			$this->affected[] = $key;
		}
	}

	public function hasValue(string $key): bool {
		return (array_key_exists($key, $this->values));
	}

	public function getValue(string $key): string {
		return $this->values[$key];
	}

	public function getValueInt(string $key): int {
		return $this->values[$key];
	}

	public function getValueBool(string $key): bool {
		return $this->values[$key];
	}

	public function getValues(): array {
		return $this->values;
	}

	public function getAffected(): array {
		return $this->affected;
	}

	public function setDefaultValues(array $default): StreamAction {
		$keys = array_keys($default);
		foreach ($keys as $k) {
			if (!array_key_exists($k, $this->values)) {
				$this->values[$k] = $default[$k];
			}
		}

		return $this;
	}

	public function importFromDatabase(array $data): void {
		$this->setId($this->getInt('id', $data, 0));
		$this->setActorId($this->get('actor_id', $data, ''));
		$this->setStreamId($this->get('stream_id', $data, ''));
		$stored = json_decode($this->get('values', $data, '[]'), true);
		$this->values = array_merge(
			is_array($stored) ? $stored : [],
			[
				self::LIKED => $this->getBool('liked', $data),
				self::BOOSTED => $this->getBool('boosted', $data),
				self::REPLIED => $this->getBool('replied', $data),
				self::BOOKMARKED => $this->getBool('bookmarked', $data),
				self::DISLIKED => $this->getBool('disliked', $data),
			]
		);
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'actorId' => $this->getActorId(),
			'streamId' => $this->getStreamId(),
			'values' => $this->getValues(),
		];
	}
}
