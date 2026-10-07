<?php
declare(strict_types=1);
namespace OCA\Social\Atproto\Firehose;
use Ratchet\Server\IoServer;
/** Install the bounded decorator before HttpServer initializes connection state. */
class BoundedIoServer extends IoServer {
	public function handleConnect($conn) {
		$decor = new BoundedIoConnection($conn); $decor->resourceId = (int)$conn->stream;
		$uri = $conn->getRemoteAddress();
		$decor->remoteAddress = trim((string)parse_url(str_contains($uri, '://') ? $uri : 'tcp://' . $uri, PHP_URL_HOST), '[]');
		$this->app->onOpen($decor);
		$conn->on('data', function ($data) use ($decor) {
			try { $this->app->onMessage($decor, $data); } catch (\Exception $e) { $this->app->onError($decor, $e); }
		});
		$conn->on('close', function () use ($decor) {
			try { $this->app->onClose($decor); } catch (\Exception $e) { $this->app->onError($decor, $e); }
		});
		$conn->on('error', function (\Exception $e) use ($decor) { $this->app->onError($decor, $e); });
	}
}
