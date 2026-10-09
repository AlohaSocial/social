<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader\Jetstream;

use OCA\Social\Atproto\Reader\Jetstream\JetstreamClient;
use OCA\Social\Exceptions\AtprotoException;
use PHPUnit\Framework\TestCase;

class JetstreamClientTest extends TestCase {
	/** A server frame, never masked. */
	private static function serverFrame(int $opcode, string $payload, bool $fin = true): string {
		$length = strlen($payload);
		$head = chr(($fin ? 0x80 : 0) | $opcode);
		if ($length < 126) {
			$head .= chr($length);
		} elseif ($length < 65536) {
			$head .= chr(126) . pack('n', $length);
		} else {
			$head .= chr(127) . pack('J', $length);
		}

		return $head . $payload;
	}

	/**
	 * @return array{0: JetstreamClient, 1: resource} the client on one end of a pair, and the other end
	 */
	private function connected(): array {
		[$ours, $theirs] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		stream_set_blocking($ours, false);
		$client = new JetstreamClient();
		(new \ReflectionProperty(JetstreamClient::class, 'socket'))->setValue($client, $ours);

		return [$client, $theirs];
	}

	public function testAClientFrameIsMaskedAndReadsBackAsSent(): void {
		$long = str_repeat('jetstream ', 7000);
		foreach (['{}', str_repeat('x', 300), $long] as $payload) {
			$frame = JetstreamClient::frame(0x1, $payload);
			$this->assertSame(0x80, ord($frame[1]) & 0x80, 'masked');
			$this->assertStringNotContainsString($payload, $frame);

			$this->assertSame([true, 0x1, $payload], JetstreamClient::readFrame($frame));
			$this->assertSame('', $frame, 'the frame is taken off the buffer');
		}
		$partial = substr(JetstreamClient::frame(0x1, 'hello'), 0, 5);
		$this->assertNull(JetstreamClient::readFrame($partial), 'not all there yet');
	}

	public function testMessagesArriveWholePingsAreAnsweredAndACloseEndsIt(): void {
		[$client, $theirs] = $this->connected();
		fwrite($theirs, self::serverFrame(0x1, '{"kind":"comm', false) . self::serverFrame(0x9, 'are you there')
			. self::serverFrame(0x0, 'it"}') . self::serverFrame(0x1, '{"n":2}'));

		$this->assertSame(['{"kind":"commit"}', '{"n":2}'], $client->read(1.0));
		$answer = fread($theirs, 1024);
		$this->assertSame([true, 0xA, 'are you there'], JetstreamClient::readFrame($answer), 'the ping is answered');

		fwrite($theirs, self::serverFrame(0x8, ''));
		$this->expectException(AtprotoException::class);
		$client->read(1.0);
	}

	public function testOnlyAWebSocketAddressIsConnectedTo(): void {
		$this->expectException(AtprotoException::class);
		(new JetstreamClient())->connect('https://jetstream.example');
	}
}
