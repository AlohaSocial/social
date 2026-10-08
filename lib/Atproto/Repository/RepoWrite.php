<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Repository;

use InvalidArgumentException;
use OCA\Social\Atproto\Protocol\Mst;
use OCA\Social\Atproto\Protocol\Tid;

/**
 * One change to a repository: a record created, replaced or deleted.
 */
final class RepoWrite {
	public const CREATE = 'create';
	public const UPDATE = 'update';
	public const DELETE = 'delete';

	/**
	 * @param array<string, mixed>|null $record the value, null for a delete
	 * @param string $localId the Social object this record is of, '' for none
	 */
	private function __construct(
		public readonly string $action,
		public readonly string $collection,
		public readonly string $rkey,
		public readonly ?array $record,
		public readonly string $localId,
	) {
		if (!Mst::isValidKey($collection . '/' . $rkey)) {
			throw new InvalidArgumentException('Not a record path: ' . $collection . '/' . $rkey);
		}
	}

	/**
	 * @param string $rkey '' for a fresh TID
	 */
	public static function create(string $collection, array $record, string $localId = '', string $rkey = ''): self {
		return new self(self::CREATE, $collection, $rkey === '' ? Tid::next() : $rkey, $record, $localId);
	}

	public static function update(string $collection, string $rkey, array $record, string $localId = ''): self {
		return new self(self::UPDATE, $collection, $rkey, $record, $localId);
	}

	public static function delete(string $collection, string $rkey): self {
		return new self(self::DELETE, $collection, $rkey, null, '');
	}

	public function path(): string {
		return $this->collection . '/' . $this->rkey;
	}
}
