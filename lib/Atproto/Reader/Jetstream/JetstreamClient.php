<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader\Jetstream;

use OCA\Social\Exceptions\AtprotoException;

/**
 * One WebSocket to a Jetstream instance, as a client: the opening handshake,
 * text messages in (fragments joined, pings answered), text messages out —
 * masked, as a client's must be. No compression is asked for, so every
 * message is JSON.
 */
class JetstreamClient {
	/** the longest message accepted; Jetstream's are a record and its envelope */
	public const MAX_MESSAGE = 4 * 1024 * 1024;
	private const OP_CONTINUATION = 0x0;
	private const OP_TEXT = 0x1;
	private const OP_CLOSE = 0x8;
	private const OP_PING = 0x9;
	private const OP_PONG = 0xA;

	/** @var resource|null */
	private $socket = null;
	private string $buffer = '';
	private string $fragments = '';

	/**
	 * @throws AtprotoException the server could not be reached or refused the upgrade
	 */
	public function connect(string $url, float $timeout = 10.0): void {
		$parts = parse_url($url);
		$scheme = strtolower($parts['scheme'] ?? '');
		$host = $parts['host'] ?? '';
		if (!in_array($scheme, ['ws', 'wss'], true) || $host === '') {
			throw new AtprotoException('Not a WebSocket address: ' . $url);
		}
		$port = $parts['port'] ?? ($scheme === 'wss' ? 443 : 80);
		$target = ($scheme === 'wss' ? 'tls://' : 'tcp://') . $host . ':' . $port;
		$context = stream_context_create(['ssl' => ['peer_name' => $host, 'SNI_enabled' => true]]);
		$socket = @stream_socket_client($target, $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);
		if ($socket === false) {
			throw new AtprotoException('Jetstream not reached at ' . $target . ': ' . $error);
		}
		$path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
		$key = base64_encode(random_bytes(16));
		$hostHeader = $host . (isset($parts['port']) ? ':' . $port : '');
		fwrite($socket, "GET $path HTTP/1.1\r\nHost: $hostHeader\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
			. "Sec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\nUser-Agent: aloha-social\r\n\r\n");
		stream_set_timeout($socket, (int)ceil($timeout));
		$response = '';
		while (!str_contains($response, "\r\n\r\n")) {
			$line = fgets($socket);
			if ($line === false) {
				fclose($socket);
				throw new AtprotoException('Jetstream closed the connection during the handshake');
			}
			$response .= $line;
		}
		$accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
		if (!preg_match('#^HTTP/1\.1 101#', $response) || stripos($response, 'Sec-WebSocket-Accept: ' . $accept) === false) {
			fclose($socket);
			throw new AtprotoException('Jetstream refused the upgrade: ' . strtok($response, "\r\n"));
		}
		stream_set_blocking($socket, false);
		$this->socket = $socket;
		$this->buffer = '';
		$this->fragments = '';
	}

	public function isConnected(): bool {
		return is_resource($this->socket) && !feof($this->socket);
	}

	/**
	 * The text messages that arrived within the timeout, in order.
	 *
	 * @return list<string>
	 * @throws AtprotoException the connection is gone
	 */
	public function read(float $timeout): array {
		if (!is_resource($this->socket)) {
			throw new AtprotoException('Not connected');
		}
		$read = [$this->socket];
		$write = $except = null;
		$seconds = (int)$timeout;
		$micro = (int)(($timeout - (float)$seconds) * 1000000.0);
		if (@stream_select($read, $write, $except, $seconds, $micro) > 0) {
			$chunk = fread($this->socket, 65536);
			if ($chunk === false || ($chunk === '' && feof($this->socket))) {
				$this->close();
				throw new AtprotoException('Jetstream closed the connection');
			}
			$this->buffer .= $chunk;
		}

		$messages = [];
		while (($frame = self::readFrame($this->buffer)) !== null) {
			[$fin, $opcode, $payload] = $frame;
			switch ($opcode) {
				case self::OP_PING:
					$this->sendFrame(self::OP_PONG, $payload);
					break;
				case self::OP_CLOSE:
					$this->close();
					throw new AtprotoException('Jetstream closed the connection');
				case self::OP_TEXT:
				case self::OP_CONTINUATION:
					$this->fragments .= $payload;
					if (strlen($this->fragments) > self::MAX_MESSAGE) {
						$this->close();
						throw new AtprotoException('A Jetstream message too long');
					}
					if ($fin) {
						$messages[] = $this->fragments;
						$this->fragments = '';
					}
					break;
			}
		}

		return $messages;
	}

	public function send(string $text): void {
		$this->sendFrame(self::OP_TEXT, $text);
	}

	public function close(): void {
		$socket = $this->socket;
		$this->socket = null;
		if (is_resource($socket)) {
			@fwrite($socket, self::frame(self::OP_CLOSE, ''));
			fclose($socket);
		}
	}

	private function sendFrame(int $opcode, string $payload): void {
		if (!is_resource($this->socket)) {
			return;
		}
		$frame = self::frame($opcode, $payload);
		stream_set_blocking($this->socket, true);
		for ($written = 0; $written < strlen($frame);) {
			$sent = fwrite($this->socket, substr($frame, $written));
			if ($sent === false || $sent === 0) {
				break;
			}
			$written += $sent;
		}
		stream_set_blocking($this->socket, false);
	}

	/**
	 * A client frame: always masked.
	 */
	public static function frame(int $opcode, string $payload): string {
		$length = strlen($payload);
		$head = chr(0x80 | $opcode);
		if ($length < 126) {
			$head .= chr(0x80 | $length);
		} elseif ($length < 65536) {
			$head .= chr(0x80 | 126) . pack('n', $length);
		} else {
			$head .= chr(0x80 | 127) . pack('J', $length);
		}
		$mask = random_bytes(4);
		$masked = '';
		for ($i = 0; $i < $length; $i++) {
			$masked .= $payload[$i] ^ $mask[$i % 4];
		}

		return $head . $mask . $masked;
	}

	/**
	 * One server frame off the front of $buffer, or null when it is not all
	 * there yet.
	 *
	 * @return array{0: bool, 1: int, 2: string}|null whether it is the last fragment, its opcode, its payload
	 * @throws AtprotoException a frame longer than any message
	 */
	public static function readFrame(string &$buffer): ?array {
		if (strlen($buffer) < 2) {
			return null;
		}
		$first = ord($buffer[0]);
		$second = ord($buffer[1]);
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
		if ($length > self::MAX_MESSAGE) {
			throw new AtprotoException('A Jetstream frame too long');
		}
		$masked = ($second & 0x80) !== 0;
		$maskLength = $masked ? 4 : 0;
		if (strlen($buffer) < $offset + $maskLength + $length) {
			return null;
		}
		$payload = substr($buffer, $offset + $maskLength, $length);
		if ($masked) {
			$mask = substr($buffer, $offset, 4);
			for ($i = 0; $i < $length; $i++) {
				$payload[$i] = $payload[$i] ^ $mask[$i % 4];
			}
		}
		$buffer = substr($buffer, $offset + $maskLength + $length);

		return [($first & 0x80) !== 0, $first & 0x0f, $payload];
	}
}
