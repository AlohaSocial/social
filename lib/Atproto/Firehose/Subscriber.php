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
	public bool $eventStream = false;
	public bool $handshakeDoneNow = false;
	public bool $closed = false;
	/** the peer's address, without the port */
	public string $address = '';
	/** the cursor the subscriber connected with; null for live only */
	public ?int $cursor = null;
	/** query parameters from the upgrade request */
	public array $query = [];
	/** lower-cased HTTP headers from the upgrade request */
	public array $headers = [];
	/** normalized request path */
	public string $requestPath = '';
	/** protocol token echoed when the client supplied it as an auth token */
	public string $responseSubprotocol = '';
	/** protocol-specific state associated with an authorized connection */
	public array $context = [];
	/** the last sequence number sent, so the next is the one after */
	public int $sent = 0;
	public int $connectedAt;
	/** when anything was last read from the connection */
	public int $lastSeen;

	/**
	 * @param resource $socket
	 */
	public function __construct(
		public readonly int $id,
		public $socket,
	) {
		$this->connectedAt = time();
		$this->lastSeen = $this->connectedAt;
	}
}
