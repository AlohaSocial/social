<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Firehose;

use RuntimeException;

/**
 * The WebSocket side of the firehose, in plain PHP sockets.
 *
 * A relay connects, the handshake is answered, and from then on the server
 * only ever writes binary frames and reads the one thing a subscriber may
 * send: pings and a close. There is no library between the socket and the
 * frames because what a firehose needs of RFC 6455 is a page of it, and a
 * page this app owns is one it can bound: a slow consumer whose output
 * buffer fills past OUTPUT_LIMIT is dropped, as the reference relay drops
 * a slow PDS, and a request longer than REQUEST_LIMIT never upgrades.
 *
 * Connections are plain TCP; the web server in front terminates TLS and
 * proxies the upgrade (contrib/webserver).
 */
class WebSocketServer {
	public const PATH = '/xrpc/com.atproto.sync.subscribeRepos';
	private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
	private const REQUEST_LIMIT = 8192;
	private const OUTPUT_LIMIT = 16 * 1024 * 1024;
	private const OPCODE_BINARY = 0x2;
	private const OPCODE_CLOSE = 0x8;
	private const OPCODE_PING = 0x9;
	private const OPCODE_PONG = 0xA;

	/** @var resource */
	private $socket;
	/** @var array<int, Subscriber> */
	private array $subscribers = [];

	public function __construct(
		private readonly string $bind,
	) {
		$socket = @stream_socket_server('tcp://' . $bind, $errno, $error);
		if ($socket === false) {
			throw new RuntimeException('Cannot listen on ' . $bind . ': ' . $error);
		}
		stream_set_blocking($socket, false);
		$this->socket = $socket;
	}

	/**
	 * Accepts connections and reads what subscribers send, for up to
	 * $timeout seconds of nothing happening. Returns the subscribers that
	 * finished their handshake during this call, with the cursor each asked.
	 *
	 * @return Subscriber[]
	 */
	public function serve(float $timeout): array {
		$read = [$this->socket];
		foreach ($this->subscribers as $subscriber) {
			$read[] = $subscriber->socket;
		}
		$write = [];
		foreach ($this->subscribers as $subscriber) {
			if ($subscriber->output !== '') {
				$write[] = $subscriber->socket;
			}
		}
		$except = null;
		$seconds = (int)floor($timeout);
		$micro = (int)round(($timeout - (float)$seconds) * 1000000.0);
		if (@stream_select($read, $write, $except, $seconds, $micro) === false) {
			return [];
		}

		$ready = [];
		foreach ($read as $socket) {
			if ($socket === $this->socket) {
				$this->accept();
				continue;
			}
			$subscriber = $this->subscribers[(int)$socket] ?? null;
			if ($subscriber === null) {
				continue;
			}
			if ($this->read($subscriber) && $subscriber->handshakeDoneNow) {
				$subscriber->handshakeDoneNow = false;
				$ready[] = $subscriber;
			}
		}
		foreach ($write as $socket) {
			$subscriber = $this->subscribers[(int)$socket] ?? null;
			if ($subscriber !== null) {
				$this->flush($subscriber);
			}
		}

		return $ready;
	}

	/**
	 * Queues a frame to every subscriber that is caught up, i.e. whose
	 * cursor is just below $seq; the caller replays the rest itself.
	 */
	public function send(Subscriber $subscriber, string $payload): void {
		if ($subscriber->closed) {
			return;
		}
		$subscriber->output .= self::frame(self::OPCODE_BINARY, $payload);
		if (strlen($subscriber->output) > self::OUTPUT_LIMIT) {
			$this->close($subscriber, 'too slow');

			return;
		}
		$this->flush($subscriber);
	}

	/**
	 * @return Subscriber[] the live, upgraded connections
	 */
	public function subscribers(): array {
		return array_values(array_filter($this->subscribers, static fn (Subscriber $s): bool => $s->upgraded && !$s->closed));
	}

	public function close(Subscriber $subscriber, string $reason = ''): void {
		if ($subscriber->closed) {
			return;
		}
		$subscriber->closed = true;
		if ($subscriber->upgraded) {
			@fwrite($subscriber->socket, self::frame(self::OPCODE_CLOSE, pack('n', 1000) . $reason));
		}
		$socket = $subscriber->socket;
		@fclose($socket);
		unset($this->subscribers[$subscriber->id]);
	}

	public function shutdown(): void {
		foreach ($this->subscribers as $subscriber) {
			$this->close($subscriber, 'server shutting down');
		}
		$socket = $this->socket;
		@fclose($socket);
	}

	private function accept(): void {
		$socket = @stream_socket_accept($this->socket, 0);
		if ($socket === false) {
			return;
		}
		stream_set_blocking($socket, false);
		$subscriber = new Subscriber((int)$socket, $socket);
		$this->subscribers[$subscriber->id] = $subscriber;
	}

	/**
	 * @return bool whether the connection is still open
	 */
	private function read(Subscriber $subscriber): bool {
		$chunk = @fread($subscriber->socket, 65536);
		if ($chunk === false || ($chunk === '' && feof($subscriber->socket))) {
			$this->close($subscriber);

			return false;
		}
		$subscriber->input .= $chunk;
		if (!$subscriber->upgraded) {
			return $this->handshake($subscriber);
		}
		while (($frame = self::readFrame($subscriber->input)) !== null) {
			[$opcode, $payload] = $frame;
			if ($opcode === self::OPCODE_CLOSE) {
				$this->close($subscriber);

				return false;
			}
			if ($opcode === self::OPCODE_PING) {
				$subscriber->output .= self::frame(self::OPCODE_PONG, $payload);
				$this->flush($subscriber);
			}
			// a subscriber has nothing else to say; data frames are ignored
		}
		if (strlen($subscriber->input) > self::REQUEST_LIMIT) {
			$this->close($subscriber, 'unexpected data');

			return false;
		}

		return true;
	}

	private function handshake(Subscriber $subscriber): bool {
		$end = strpos($subscriber->input, "\r\n\r\n");
		if ($end === false) {
			if (strlen($subscriber->input) > self::REQUEST_LIMIT) {
				$this->refuse($subscriber, 431, 'Request Header Fields Too Large');

				return false;
			}

			return true;
		}
		$request = substr($subscriber->input, 0, $end);
		$subscriber->input = substr($subscriber->input, $end + 4);
		$lines = explode("\r\n", $request);
		$requestLine = array_shift($lines);
		if (preg_match('#^GET (\S+) HTTP/1\.1$#', (string)$requestLine, $m) !== 1) {
			$this->refuse($subscriber, 405, 'Method Not Allowed');

			return false;
		}
		$url = parse_url($m[1]);
		$path = is_array($url) ? ($url['path'] ?? '') : '';
		$path = (string)preg_replace('#^/index\.php/apps/social#', '', $path);
		if ($path !== self::PATH) {
			$this->refuse($subscriber, 404, 'Not Found');

			return false;
		}
		$headers = [];
		foreach ($lines as $line) {
			$parts = explode(':', $line, 2);
			if (count($parts) === 2) {
				$headers[strtolower(trim($parts[0]))] = trim($parts[1]);
			}
		}
		$key = $headers['sec-websocket-key'] ?? '';
		if (strtolower($headers['upgrade'] ?? '') !== 'websocket' || $key === '' || strlen(base64_decode($key, true) ?: '') !== 16) {
			$this->refuse($subscriber, 426, 'Upgrade Required');

			return false;
		}
		parse_str(is_array($url) ? ($url['query'] ?? '') : '', $query);
		$cursor = $query['cursor'] ?? null;
		$subscriber->cursor = is_string($cursor) && ctype_digit($cursor) ? (int)$cursor : null;
		$accept = base64_encode(sha1($key . self::GUID, true));
		$subscriber->output .= "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: " . $accept . "\r\n\r\n";
		$subscriber->upgraded = true;
		$subscriber->handshakeDoneNow = true;
		$this->flush($subscriber);

		return true;
	}

	private function refuse(Subscriber $subscriber, int $status, string $reason): void {
		$body = $reason . "\n";
		@fwrite($subscriber->socket, 'HTTP/1.1 ' . $status . ' ' . $reason . "\r\nContent-Type: text/plain\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
		$subscriber->closed = true;
		$socket = $subscriber->socket;
		@fclose($socket);
		unset($this->subscribers[$subscriber->id]);
	}

	private function flush(Subscriber $subscriber): void {
		if ($subscriber->output === '' || $subscriber->closed) {
			return;
		}
		$written = @fwrite($subscriber->socket, $subscriber->output);
		if ($written === false) {
			$this->close($subscriber);

			return;
		}
		$subscriber->output = substr($subscriber->output, $written);
	}

	/**
	 * A server frame: never masked.
	 */
	public static function frame(int $opcode, string $payload): string {
		$length = strlen($payload);
		$head = chr(0x80 | $opcode);
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
	 * One masked client frame off the front of $buffer, or null when it is
	 * not all there yet.
	 *
	 * @return array{0: int, 1: string}|null opcode and payload
	 */
	public static function readFrame(string &$buffer): ?array {
		if (strlen($buffer) < 2) {
			return null;
		}
		$opcode = ord($buffer[0]) & 0x0f;
		$second = ord($buffer[1]);
		$masked = ($second & 0x80) !== 0;
		$length = $second & 0x7f;
		$offset = 2;
		if ($length === 126) {
			if (strlen($buffer) < 4) {
				return null;
			}
			$length = unpack('n', substr($buffer, 2, 2))[1];
			$offset = 4;
		} elseif ($length === 127) {
			if (strlen($buffer) < 10) {
				return null;
			}
			$length = unpack('J', substr($buffer, 2, 8))[1];
			$offset = 10;
		}
		if ($length > self::REQUEST_LIMIT) {
			// a subscriber never sends anything that long; the caller closes
			$buffer = str_repeat('x', self::REQUEST_LIMIT + 1);

			return null;
		}
		$maskLength = $masked ? 4 : 0;
		if (strlen($buffer) < $offset + $maskLength + $length) {
			return null;
		}
		$payload = substr($buffer, $offset + $maskLength, $length);
		if ($masked) {
			$mask = substr($buffer, $offset, 4);
			$unmasked = '';
			for ($i = 0; $i < $length; $i++) {
				$unmasked .= $payload[$i] ^ $mask[$i % 4];
			}
			$payload = $unmasked;
		}
		$buffer = substr($buffer, $offset + $maskLength + $length);

		return [$opcode, $payload];
	}
}
