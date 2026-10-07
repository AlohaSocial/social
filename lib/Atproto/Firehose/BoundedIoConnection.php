<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Firehose;

use Ratchet\Server\IoConnection;
use React\Socket\ConnectionInterface;

#[\AllowDynamicProperties]
class BoundedIoConnection extends IoConnection {
	public int $resourceId;
	public string $remoteAddress;
	private bool $blocked = false;
	private int $buffered = 0;
	private float $blockedAt = 0;
	public function __construct(ConnectionInterface $conn) {
		parent::__construct($conn);
		$conn->on('drain', function () {
			$this->blocked = false;
			$this->buffered = 0;
		});
	}
	#[\Override]
	public function send($data) {
		if ($this->blocked) {
			$this->buffered += strlen($data);
		}
		if (strlen($data) + $this->buffered > 1024 * 1024 || ($this->blocked && microtime(true) - $this->blockedAt > 5)) {
			$this->conn->close();
			return $this;
		}
		if (!$this->conn->write($data) && !$this->blocked) {
			$this->blocked = true;
			$this->blockedAt = microtime(true);
			$this->buffered = strlen($data);
		}
		return $this;
	}
}
