<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Firehose;

/**
 * One connection to the firehose: its socket, its buffers, and where in
 * the stream it is.
 */
final class Subscriber {
	public string $input = '';
	public string $output = '';
	public bool $upgraded = false;
	public bool $handshakeDoneNow = false;
	public bool $closed = false;
	/** the cursor the subscriber connected with; null for live only */
	public ?int $cursor = null;
	/** the last sequence number sent, so the next is the one after */
	public int $sent = 0;
	public int $connectedAt;

	/**
	 * @param resource $socket
	 */
	public function __construct(
		public readonly int $id,
		public $socket,
	) {
		$this->connectedAt = time();
	}
}
