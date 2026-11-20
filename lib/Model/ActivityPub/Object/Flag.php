<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub\Object;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\ACore;

/**
 * A federated report. Mastodon sends `object` as a list of ids — the reported
 * account plus, optionally, the reported statuses — and the reporter's comment
 * in `content`. A single-id `object` (plain ActivityPub) is accepted too.
 */
class Flag extends ACore implements JsonSerializable {
	public const TYPE = 'Flag';

	/**
	 * How many ids an incoming report may name: Mastodon's carry the account and
	 * a handful of statuses, and the rest of a longer list is dropped.
	 */
	public const MAX_OBJECT_IDS = 50;

	/** @var string[] every id from the wire `object`, in order */
	private array $objectIds = [];
	private string $content = '';

	public function __construct($parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
	}

	/**
	 * @return string[]
	 */
	public function getObjectIds(): array {
		return $this->objectIds;
	}

	/**
	 * @param string[] $objectIds
	 */
	public function setObjectIds(array $objectIds): self {
		$this->objectIds = array_values(
			array_filter($objectIds, fn ($id): bool => is_string($id) && $id !== '')
		);

		return $this;
	}

	public function getContent(): string {
		return $this->content;
	}

	public function setContent(string $content): self {
		$this->content = $content;

		return $this;
	}

	#[\Override]
	public function import(array $data) {
		parent::import($data);

		$object = $data['object'] ?? [];
		if (is_string($object)) {
			$object = [$object];
		}
		$this->setObjectIds(is_array($object) ? $object : []);
		$this->objectIds = array_slice($this->objectIds, 0, self::MAX_OBJECT_IDS);
		if ($this->getObjectIds() !== [] && $this->getObjectId() === '') {
			$this->setObjectId($this->getObjectIds()[0]);
		}

		$this->setContent($this->get('content', $data, ''));
	}

	#[\Override]
	public function jsonSerialize(): array {
		$result = array_merge(
			parent::jsonSerialize(),
			[
				'object' => $this->getObjectIds(),
				'content' => $this->getContent(),
			]
		);

		return $result;
	}
}
