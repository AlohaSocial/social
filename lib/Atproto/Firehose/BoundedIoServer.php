<?php
declare(strict_types=1);
namespace OCA\Social\Atproto\Firehose;
use Ratchet\Server\{IoServer, IoConnection};
/** Bounds transport buffering for slow relay consumers without blocking the event loop. */
class BoundedIoServer extends IoServer {
	public function handleConnect($conn) {
		parent::handleConnect($conn);
		$decor = new BoundedIoConnection($conn); $decor->resourceId = $conn->decor->resourceId; $conn->decor = $decor;
	}
}
