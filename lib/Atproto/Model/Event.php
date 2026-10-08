<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Model;

use OCA\Social\Atproto\Protocol\DagCbor;

/**
 * One firehose event: the sequence number the database gave it, its kind
 * and the DAG-CBOR body of the frame, without the `seq` the row carries.
 */
final class Event {
	public const KIND_COMMIT = '#commit';
	public const KIND_IDENTITY = '#identity';
	public const KIND_ACCOUNT = '#account';
	public const KIND_SYNC = '#sync';

	public function __construct(
		public readonly int $seq,
		public readonly string $did,
		public readonly string $kind,
		public readonly string $body,
		public readonly int $time,
	) {
	}

	/**
	 * The binary WebSocket frame: the header and the body with `seq` in it,
	 * concatenated.
	 */
	public function frame(): string {
		$body = DagCbor::decode($this->body);
		if (!is_array($body)) {
			$body = [];
		}
		$body['seq'] = $this->seq;

		return DagCbor::encode(['op' => 1, 't' => $this->kind]) . DagCbor::encode($body);
	}
}
