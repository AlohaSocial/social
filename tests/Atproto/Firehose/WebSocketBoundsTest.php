<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Firehose;

use OCA\Social\Atproto\Firehose\WebSocketServer;
use PHPUnit\Framework\TestCase;

/**
 * The bounds the firehose socket keeps against what connects to it
 * (§16.4): a slow consumer is dropped once its backlog passes the limit,
 * an oversized or malformed request never upgrades, and a client frame
 * whose length cannot be real is treated as too long rather than read.
 */
class WebSocketBoundsTest extends TestCase {
	private ?WebSocketServer $server = null;
	private string $bind = '';

	protected function setUp(): void {
		$this->bind = '127.0.0.1:' . random_int(20000, 60000);
		$this->server = new WebSocketServer($this->bind);
	}

	protected function tearDown(): void {
		$this->server?->shutdown();
	}

	public function testASubscriberThatDoesNotReadIsDropped(): void {
		$client = $this->connect(WebSocketServer::PATH);
		$ready = $this->pump();
		$this->assertCount(1, $ready);

		$chunk = str_repeat('x', 4 * 1024 * 1024);
		for ($i = 0; $i < 6 && !$ready[0]->closed; $i++) {
			$this->server->send($ready[0], $chunk);
		}

		$this->assertTrue($ready[0]->closed, 'past sixteen megabytes of backlog');
		$this->assertSame([], $this->server->subscribers());
		fclose($client);
	}

	public function testARequestThatNeverEndsIsRefused(): void {
		$client = stream_socket_client('tcp://' . $this->bind, $errno, $error, 2);
		fwrite($client, 'GET ' . WebSocketServer::PATH . " HTTP/1.1\r\nX-Padding: " . str_repeat('a', 9000));
		$this->pump();

		$this->assertStringStartsWith('HTTP/1.1 431', $this->read($client));
		$this->assertSame([], $this->server->subscribers());
	}

	public function testOnlyAGetUpgrades(): void {
		$client = stream_socket_client('tcp://' . $this->bind, $errno, $error, 2);
		fwrite($client, 'POST ' . WebSocketServer::PATH . " HTTP/1.1\r\nUpgrade: websocket\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n\r\n");
		$this->pump();

		$this->assertStringStartsWith('HTTP/1.1 405', $this->read($client));
	}

	public function testAKeyThatIsNotSixteenBytesDoesNotUpgrade(): void {
		$client = stream_socket_client('tcp://' . $this->bind, $errno, $error, 2);
		fwrite($client, 'GET ' . WebSocketServer::PATH . " HTTP/1.1\r\nUpgrade: websocket\r\nSec-WebSocket-Key: c2hvcnQ=\r\n\r\n");
		$this->pump();

		$this->assertStringStartsWith('HTTP/1.1 426', $this->read($client));
	}

	/**
	 * A cursor that is not a number is no cursor: the subscriber is live.
	 */
	public function testACursorThatIsNotANumberIsIgnored(): void {
		$this->connect(WebSocketServer::PATH . '?cursor=-5');
		$this->connect(WebSocketServer::PATH . '?cursor=12abc');
		$this->connect(WebSocketServer::PATH . '?cursor[]=1');

		$ready = $this->pump();
		$this->assertCount(3, $ready);
		foreach ($ready as $subscriber) {
			$this->assertNull($subscriber->cursor);
		}
	}

	public function testASubscriberThatSendsDataIsClosed(): void {
		$client = $this->connect(WebSocketServer::PATH);
		$this->pump();
		$this->assertCount(1, $this->server->subscribers());

		fwrite($client, chr(0x82) . chr(0x80 | 126) . pack('n', 9000) . "\0\0\0\0" . str_repeat('a', 9000));
		$this->pump();

		$this->assertSame([], $this->server->subscribers());
	}

	/**
	 * RFC 6455 §5.1: a client masks every frame it sends, and a server that
	 * receives one unmasked closes the connection; the close says 1002,
	 * protocol error, and the ping it carried is not answered.
	 */
	public function testAnUnmaskedClientFrameClosesWithAProtocolError(): void {
		$client = $this->connect(WebSocketServer::PATH);
		$this->pump();
		$this->read($client);
		$this->assertCount(1, $this->server->subscribers());

		fwrite($client, "\x89\x02hi");
		$this->pump();

		$this->assertSame([], $this->server->subscribers());
		$close = $this->read($client);
		$this->assertSame(0x88, ord($close[0]), 'a close frame, not a pong');
		$this->assertSame(1002, unpack('n', substr($close, 2, 2))[1]);
	}

	/**
	 * RFC 6455 §5.2: the most significant bit of a 64-bit length is 0. A
	 * length with it set is not a frame to read but one too long, as any
	 * other length past the limit is.
	 */
	/**
	 * Past the ceiling on connections a new one is answered 503 and closed
	 * before anything of it is read; the ones already held go on.
	 */
	public function testPastTheSubscriberCapAConnectionIsRefused(): void {
		$this->server?->shutdown();
		$this->server = new WebSocketServer($this->bind, 2, 8);
		$first = $this->connect(WebSocketServer::PATH);
		$second = $this->connect(WebSocketServer::PATH);
		$this->assertCount(2, $this->pump());

		$third = stream_socket_client('tcp://' . $this->bind, $errno, $error, 2);
		$this->pump();

		$this->assertStringStartsWith('HTTP/1.1 503', $this->read($third));
		$this->assertCount(2, $this->server->subscribers(), 'the two already there are kept');
		fclose($first);
		fclose($second);
	}

	public function testOneAddressIsHeldToItsOwnShareOfConnections(): void {
		$this->server?->shutdown();
		$this->server = new WebSocketServer($this->bind, 256, 1);
		$first = $this->connect(WebSocketServer::PATH);
		$this->assertCount(1, $this->pump());

		$second = $this->connect(WebSocketServer::PATH);
		$this->pump();

		$this->assertStringStartsWith('HTTP/1.1 503', $this->read($second));
		$this->assertCount(1, $this->server->subscribers());
		$this->assertSame('127.0.0.1', $this->server->subscribers()[0]->address);
		fclose($first);
	}

	/** A connection that ended frees its place. */
	public function testAClosedConnectionFreesItsPlace(): void {
		$this->server?->shutdown();
		$this->server = new WebSocketServer($this->bind, 1, 8);
		$first = $this->connect(WebSocketServer::PATH);
		$this->assertCount(1, $this->pump());
		fclose($first);
		$this->pump();
		$this->assertSame([], $this->server->subscribers());

		$second = $this->connect(WebSocketServer::PATH);
		$this->assertCount(1, $this->pump());
		fclose($second);
	}

	/**
	 * Behind the web server every connection comes from the loopback: each
	 * is counted under the client its proxy names, the last address in
	 * `X-Forwarded-For` that is not a proxy, whatever a client wrote in
	 * front of it.
	 */
	public function testAConnectionThroughAProxyIsCountedUnderTheClientItNames(): void {
		$this->server?->shutdown();
		$this->server = new WebSocketServer($this->bind, 256, 1, trustedProxy: static fn (string $address): bool => $address === '192.0.2.1');
		$first = $this->connect(WebSocketServer::PATH, '203.0.113.5');
		$second = $this->connect(WebSocketServer::PATH, '203.0.113.6, 192.0.2.1');
		$this->assertCount(2, $this->pump());
		$spoofed = $this->connect(WebSocketServer::PATH, '198.51.100.9, 203.0.113.5');
		$this->pump();

		$this->assertStringStartsWith('HTTP/1.1 503', $this->read($spoofed), 'a client cannot choose its address by writing one in front');
		$this->assertSame(['203.0.113.5', '203.0.113.6'], array_map(static fn ($s): string => $s->address, $this->server->subscribers()));
		fclose($first);
		fclose($second);
	}

	public function testAnIpv6AddressIsCountedWithItsSlash64(): void {
		$this->assertSame('2001:db8:1:2::/64', WebSocketServer::bucket('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));
		$this->assertSame(WebSocketServer::bucket('2001:db8:1:2::1'), WebSocketServer::bucket('[2001:db8:1:2:ffff::9]'));
		$this->assertNotSame(WebSocketServer::bucket('2001:db8:1:2::1'), WebSocketServer::bucket('2001:db8:1:3::1'));
		$this->assertSame('203.0.113.5', WebSocketServer::bucket('::ffff:203.0.113.5'));
		$this->assertSame('203.0.113.5', WebSocketServer::bucket('203.0.113.5'));
	}

	public function testAConnectionNothingIsHeardFromIsClosed(): void {
		$client = $this->connect(WebSocketServer::PATH);
		$this->assertCount(1, $this->pump());

		$this->server->closeIdle(-1);

		$this->assertSame([], $this->server->subscribers());
		fclose($client);
	}

	public function testALengthWithItsTopBitSetIsTooLong(): void {
		$buffer = "\x89\x7f" . str_repeat("\xff", 8) . 'abcdefghij';

		$this->assertNull(WebSocketServer::readFrame($buffer));
		$this->assertGreaterThan(8192, strlen($buffer), 'the caller sees more than a subscriber may send, and closes');
	}

	/**
	 * @return resource
	 */
	private function connect(string $path, string $forwardedFor = '') {
		$client = stream_socket_client('tcp://' . $this->bind, $errno, $error, 2);
		$this->assertNotFalse($client, (string)$error);
		$forwarded = $forwardedFor === '' ? '' : 'X-Forwarded-For: ' . $forwardedFor . "\r\n";
		fwrite($client, 'GET ' . $path . " HTTP/1.1\r\nHost: social.example.com\r\n" . $forwarded . "Upgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n");

		return $client;
	}

	private function pump(): array {
		$ready = [];
		for ($i = 0; $i < 6; $i++) {
			$ready = array_merge($ready, $this->server->serve(0.05));
		}

		return $ready;
	}

	/**
	 * @param resource $client
	 */
	private function read($client): string {
		stream_set_timeout($client, 2);

		return (string)fread($client, 4096);
	}
}
