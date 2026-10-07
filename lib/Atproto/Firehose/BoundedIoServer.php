<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Firehose;

use Ratchet\Server\IoServer;

/** Install the bounded decorator before HttpServer initializes connection state. */
class BoundedIoServer extends IoServer {
	#[\Override]
	public function handleConnect($conn) {
		$decor = new BoundedIoConnection($conn);
		$decor->resourceId = spl_object_id($conn);
		$uri = $conn->getRemoteAddress();
		$decor->remoteAddress = trim((string)parse_url(str_contains($uri, '://') ? $uri : 'tcp://' . $uri, PHP_URL_HOST), '[]');
		$this->app->onOpen($decor);
		$conn->on('data', function ($data) use ($decor) {
			try {
				$this->app->onMessage($decor, $data);
			} catch (\Exception $e) {
				$this->app->onError($decor, $e);
			}
		});
		$conn->on('close', function () use ($decor) {
			try {
				$this->app->onClose($decor);
			} catch (\Exception $e) {
				$this->app->onError($decor, $e);
			}
		});
		$conn->on('error', function (\Exception $e) use ($decor) {
			$this->app->onError($decor, $e);
		});
	}
}
