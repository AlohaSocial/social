<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Firehose;

use OCA\Social\Atproto\Firehose\WebSocketServer;
use OCA\Social\Atproto\Protocol\DagCbor;
use PHPUnit\Framework\TestCase;

/**
 * The firehose's WebSocket, driven by a raw client over the loopback: the
 * handshake, the cursor, a frame delivered, a ping answered, a close.
 */
class WebSocketServerTest extends TestCase {
	private ?WebSocketServer $server = null;
	private string $bind = '';

	protected function setUp(): void {
		$port = random_int(20000, 60000);
		$this->bind = '127.0.0.1:' . $port;
		$this->server = new WebSocketServer($this->bind);
	}

	protected function tearDown(): void {
		$this->server?->shutdown();
	}

	public function testAClientUpgradesAndReceivesFrames(): void {
		$client = $this->connect('/xrpc/com.atproto.sync.subscribeRepos?cursor=41');
		$ready = $this->pump();
		$this->assertCount(1, $ready);
		$subscriber = $ready[0];
		$this->assertSame(41, $subscriber->cursor);

		$response = $this->readUntil($client, "\r\n\r\n");
		$this->assertStringStartsWith('HTTP/1.1 101', $response);
		$this->assertStringContainsString('Sec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', $response);

		$payload = DagCbor::encode(['op' => 1, 't' => '#commit']) . DagCbor::encode(['seq' => 42]);
		$this->server->send($subscriber, $payload);
		$this->pump();
		$frame = $this->readBytes($client, 2 + strlen($payload));
		$this->assertSame(0x82, ord($frame[0]), 'a final binary frame');
		$this->assertSame(strlen($payload), ord($frame[1]));
		$this->assertSame($payload, substr($frame, 2));
	}

	public function testAPingIsAnsweredAndACloseEndsIt(): void {
		$client = $this->connect('/xrpc/com.atproto.sync.subscribeRepos');
		$this->pump();
		$this->readUntil($client, "\r\n\r\n");
		$this->assertCount(1, $this->server->subscribers());

		fwrite($client, self::masked(0x9, 'hi'));
		$this->pump();
		$pong = $this->readBytes($client, 4);
		$this->assertSame("\x8a\x02hi", $pong);

		fwrite($client, self::masked(0x8, pack('n', 1000)));
		$this->pump();
		$this->assertCount(0, $this->server->subscribers());
	}

	public function testAnotherPathOrAPlainRequestIsRefused(): void {
		$client = $this->connect('/somewhere/else');
		$this->pump();
		$this->assertStringStartsWith('HTTP/1.1 404', $this->readUntil($client, "\r\n\r\n"));

		$plain = stream_socket_client('tcp://' . $this->bind, $errno, $error, 2);
		fwrite($plain, "GET /xrpc/com.atproto.sync.subscribeRepos HTTP/1.1\r\nHost: x\r\n\r\n");
		$this->pump();
		$this->assertStringStartsWith('HTTP/1.1 426', $this->readUntil($plain, "\r\n\r\n"));
	}

	public function testFramesAreEncodedForEveryLength(): void {
		$this->assertSame("\x82\x05hello", WebSocketServer::frame(0x2, 'hello'));
		$long = str_repeat('x', 300);
		$this->assertSame("\x82\x7e" . pack('n', 300) . $long, WebSocketServer::frame(0x2, $long));
		$huge = str_repeat('y', 70000);
		$this->assertSame("\x82\x7f" . pack('J', 70000) . $huge, WebSocketServer::frame(0x2, $huge));
	}

	public function testAMaskedClientFrameIsRead(): void {
		$buffer = self::masked(0x9, 'ping') . 'rest';
		$frame = WebSocketServer::readFrame($buffer);
		$this->assertSame([0x9, 'ping'], $frame);
		$this->assertSame('rest', $buffer);
		$partial = "\x89";
		$this->assertNull(WebSocketServer::readFrame($partial));
	}

	/**
	 * @return resource
	 */
	private function connect(string $path) {
		$client = stream_socket_client('tcp://' . $this->bind, $errno, $error, 2);
		$this->assertNotFalse($client, (string)$error);
		fwrite($client, 'GET ' . $path . " HTTP/1.1\r\nHost: social.test\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n");

		return $client;
	}

	private function pump(): array {
		$ready = [];
		for ($i = 0; $i < 5; $i++) {
			$ready = array_merge($ready, $this->server->serve(0.05));
		}

		return $ready;
	}

	/**
	 * @param resource $client
	 */
	private function readUntil($client, string $marker): string {
		stream_set_timeout($client, 2);
		$buffer = '';
		while (!str_contains($buffer, $marker)) {
			$chunk = fread($client, 4096);
			if ($chunk === false || $chunk === '') {
				break;
			}
			$buffer .= $chunk;
		}

		return $buffer;
	}

	/**
	 * @param resource $client
	 */
	private function readBytes($client, int $length): string {
		stream_set_timeout($client, 2);
		$buffer = '';
		while (strlen($buffer) < $length) {
			$chunk = fread($client, $length - strlen($buffer));
			if ($chunk === false || $chunk === '') {
				break;
			}
			$buffer .= $chunk;
		}

		return $buffer;
	}

	private static function masked(int $opcode, string $payload): string {
		$mask = "\x01\x02\x03\x04";
		$out = '';
		for ($i = 0, $n = strlen($payload); $i < $n; $i++) {
			$out .= $payload[$i] ^ $mask[$i % 4];
		}

		return chr(0x80 | $opcode) . chr(0x80 | strlen($payload)) . $mask . $out;
	}
}
