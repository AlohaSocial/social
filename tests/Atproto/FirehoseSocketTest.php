<?php

declare(strict_types=1);

namespace OCA\Social\Tests\Atproto;

use OCA\Social\Atproto\Firehose\BoundedIoServer;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServer;
use Ratchet\MessageComponentInterface;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\WebSocket\WsServer;

class FirehoseSocketTest extends TestCase {
	// Ratchet 0.4 emits known vendor-only PHP 8.5 deprecations during the real handshake.
	#[\PHPUnit\Framework\Attributes\IgnoreDeprecations('Implicitly marking parameter|Non-canonical cast|SplObjectStorage::attach') ]
	public function testRealUpgradeKeepsInitializedConnectionAndBinaryTransport(): void {
		$application = new class implements MessageComponentInterface {
			public bool $opened = false;
			public string $path = '';
			public function onOpen(ConnectionInterface $conn): void {
				$this->opened = true;
				$this->path = $conn->httpRequest->getUri()->getPath();
				$conn->send(new Frame('native-test-frame', true, Frame::OP_BINARY));
			}
			public function onClose(ConnectionInterface $conn): void {
			}
			public function onError(ConnectionInterface $conn, \Exception $e): void {
				throw $e;
			}
			public function onMessage(ConnectionInterface $conn, $msg): void {
			}
		};
		$server = BoundedIoServer::factory(new HttpServer(new WsServer($application)), 0, '127.0.0.1');
		$socket = stream_socket_client($server->socket->getAddress(), $errorCode, $errorMessage, 1);
		self::assertIsResource($socket);
		stream_set_blocking($socket, false);
		$key = base64_encode(random_bytes(16));
		fwrite($socket, "GET /xrpc/com.atproto.sync.subscribeRepos HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n");
		$wire = '';
		$server->loop->addPeriodicTimer(0.01, function () use ($socket, &$wire, $server) {
			$wire .= fread($socket, 4096);
			if (str_contains($wire, 'native-test-frame')) {
				$server->loop->stop();
			}
		});
		$server->loop->addTimer(2, static function () use ($server) {
			$server->loop->stop();
		});
		try {
			$server->run();
		} finally {
			fclose($socket);
			$server->socket->close();
		}
		self::assertTrue($application->opened);
		self::assertSame('/xrpc/com.atproto.sync.subscribeRepos', $application->path);
		self::assertStringContainsString('101 Switching Protocols', $wire);
		[, $frame] = explode("\r\n\r\n", $wire, 2);
		self::assertSame(0x82, ord($frame[0]));
		self::assertStringContainsString('native-test-frame', $frame);
	}
}
