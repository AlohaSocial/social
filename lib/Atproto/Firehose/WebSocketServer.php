<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Firehose;

use Closure;
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
 * a slow PDS, a request longer than REQUEST_LIMIT never upgrades, a
 * connection that has not finished its handshake after HANDSHAKE_TIMEOUT
 * seconds is closed, and past MAX_SUBSCRIBERS in all or MAX_PER_ADDRESS
 * from one address a connection is answered 503 and closed.
 *
 * Connections are plain TCP; the web server in front terminates TLS and
 * proxies the upgrade (contrib/webserver). The address a connection is
 * counted under is its peer's, or, when the peer is a proxy (the loopback
 * or one the trusted-proxy check names), the last address in its
 * `X-Forwarded-For` that is not a proxy. An IPv6 address is counted with
 * the rest of its /64, which one host can hold all of.
 */
class WebSocketServer {
	public const PATH = '/xrpc/com.atproto.sync.subscribeRepos';
	private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
	private const REQUEST_LIMIT = 8192;
	private const OUTPUT_LIMIT = 16 * 1024 * 1024;
	/** connections held at once, each with an output buffer of up to OUTPUT_LIMIT */
	public const MAX_SUBSCRIBERS = 256;
	/** connections held from one address: a relay opens one, a few on a retry */
	public const MAX_PER_ADDRESS = 8;
	/** seconds a connection may take to send its upgrade request */
	public const HANDSHAKE_TIMEOUT = 10;
	private const OPCODE_BINARY = 0x2;
	private const OPCODE_CLOSE = 0x8;
	private const OPCODE_PING = 0x9;
	private const OPCODE_PONG = 0xA;
	private const CLOSE_NORMAL = 1000;
	private const CLOSE_PROTOCOL_ERROR = 1002;
	private int $dataOpcode;

	/** @var resource */
	private $socket;
	/** @var array<int, Subscriber> */
	private array $subscribers = [];

	public function __construct(
		private readonly string $bind,
		private readonly int $maxSubscribers = self::MAX_SUBSCRIBERS,
		private readonly int $maxPerAddress = self::MAX_PER_ADDRESS,
		private readonly string $path = self::PATH,
		bool $binaryFrames = true,
		private readonly ?Closure $authorize = null,
		private readonly ?Closure $onMessage = null,
		private readonly bool $eventStreams = false,
		private readonly ?Closure $trustedProxy = null,
	) {
		$this->dataOpcode = $binaryFrames ? self::OPCODE_BINARY : 0x1;
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
		$this->closeUnfinishedHandshakes();
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
		$subscriber->output .= $subscriber->eventStream ? $payload : self::frame($this->dataOpcode, $payload);
		if (strlen($subscriber->output) > self::OUTPUT_LIMIT) {
			$this->close($subscriber, 'too slow');

			return;
		}
		$this->flush($subscriber);
	}

	/**
	 * Closes the WebSocket connections nothing was heard from for $seconds:
	 * a client answers a ping with a pong, so one that stays silent across
	 * several pings is gone, or holding its place without reading. An event
	 * stream cannot answer, and is left alone.
	 */
	public function closeIdle(int $seconds): void {
		$now = time();
		foreach ($this->subscribers as $subscriber) {
			if ($subscriber->upgraded && !$subscriber->eventStream && $now - $subscriber->lastSeen > $seconds) {
				$this->close($subscriber, 'idle');
			}
		}
	}

	public function ping(Subscriber $subscriber): void {
		if (!$subscriber->closed) {
			$subscriber->output .= $subscriber->eventStream ? ": thump\n\n" : self::frame(self::OPCODE_PING, '');
			$this->flush($subscriber);
		}
	}

	/**
	 * @return Subscriber[] the live, upgraded connections
	 */
	public function subscribers(): array {
		return array_values(array_filter($this->subscribers, static fn (Subscriber $s): bool => $s->upgraded && !$s->closed));
	}

	public function close(Subscriber $subscriber, string $reason = '', int $code = self::CLOSE_NORMAL): void {
		if ($subscriber->closed) {
			return;
		}
		$subscriber->closed = true;
		if ($subscriber->upgraded && !$subscriber->eventStream) {
			@fwrite($subscriber->socket, self::frame(self::OPCODE_CLOSE, pack('n', $code) . $reason));
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
		$subscriber->address = self::addressOf((string)stream_socket_get_name($socket, true));
		if (count($this->subscribers) >= $this->maxSubscribers) {
			$this->refuse($subscriber, 503, 'Service Unavailable');

			return;
		}
		$this->subscribers[$subscriber->id] = $subscriber;
	}

	/** How many connections are held from an address's bucket, upgraded or not. */
	private function heldFrom(string $address, int $except = 0): int {
		$bucket = self::bucket($address);
		$held = 0;
		foreach ($this->subscribers as $subscriber) {
			if ($subscriber->id !== $except && self::bucket($subscriber->address) === $bucket) {
				$held++;
			}
		}

		return $held;
	}

	/**
	 * What connections are counted under: an IPv4 address as it is, an IPv6
	 * address by its /64.
	 */
	public static function bucket(string $address): string {
		if (!str_contains($address, ':')) {
			return $address;
		}
		$packed = @inet_pton(trim($address, '[]'));
		if ($packed === false || strlen($packed) !== 16) {
			return $address;
		}
		if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
			return (string)inet_ntop(substr($packed, 12));
		}

		return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
	}

	/** Whether connections from $address come through a proxy whose `X-Forwarded-For` is believed. */
	private function isTrustedProxy(string $address): bool {
		$address = trim($address, '[]');
		if ($address === '::1' || (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($address, '127.'))) {
			return true;
		}

		return $this->trustedProxy !== null && filter_var($address, FILTER_VALIDATE_IP) !== false && ($this->trustedProxy)($address) === true;
	}

	/**
	 * The client behind a chain of proxies: walking `X-Forwarded-For` from
	 * the right, the first address that is not a proxy. Null when the
	 * header is malformed before such an address, or names only proxies.
	 */
	private function forwardedClient(string $forwardedFor): ?string {
		$addresses = array_reverse(array_map('trim', explode(',', $forwardedFor)));
		foreach ($addresses as $address) {
			if (filter_var($address, FILTER_VALIDATE_IP) === false) {
				return null;
			}
			if (!$this->isTrustedProxy($address)) {
				return $address;
			}
		}

		return null;
	}

	/** Closes the connections that did not finish their upgrade request in time. */
	private function closeUnfinishedHandshakes(): void {
		$now = time();
		foreach ($this->subscribers as $subscriber) {
			if (!$subscriber->upgraded && $now - $subscriber->connectedAt > self::HANDSHAKE_TIMEOUT) {
				$this->refuse($subscriber, 408, 'Request Timeout');
			}
		}
	}

	/** The address of a peer name as `stream_socket_get_name()` gives it: `ip:port` or `[ipv6]:port`. */
	private static function addressOf(string $peer): string {
		$colon = strrpos($peer, ':');

		return $colon === false ? $peer : substr($peer, 0, $colon);
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
		$subscriber->lastSeen = time();
		if (!$subscriber->upgraded) {
			return $this->handshake($subscriber);
		}
		if ($subscriber->eventStream) {
			return true;
		}
		while (($frame = self::readFrame($subscriber->input)) !== null) {
			[$opcode, $payload, $masked] = $frame;
			if (!$masked) {
				// RFC 6455 §5.1: every frame a client sends is masked
				$this->close($subscriber, 'unmasked frame', self::CLOSE_PROTOCOL_ERROR);

				return false;
			}
			if ($opcode === self::OPCODE_CLOSE) {
				$this->close($subscriber);

				return false;
			}
			if ($opcode === self::OPCODE_PING) {
				$subscriber->output .= self::frame(self::OPCODE_PONG, $payload);
				$this->flush($subscriber);
			} elseif ($this->onMessage !== null && in_array($opcode, [0x1, self::OPCODE_BINARY], true)) {
				$response = ($this->onMessage)($subscriber, $opcode, $payload);
				if (is_string($response)) {
					$this->send($subscriber, $response);
				}
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
		$path = $path === $this->path . '/' ? $this->path : $path;
		if ($path === $this->path . '/health') {
			$this->healthy($subscriber);

			return false;
		}
		$isEventStream = $this->eventStreams && in_array($path, [
			$this->path . '/user',
			$this->path . '/user/notification',
			$this->path . '/direct',
			$this->path . '/list',
			$this->path . '/public',
			$this->path . '/public/local',
			$this->path . '/public/remote',
			$this->path . '/hashtag',
			$this->path . '/hashtag/local',
		], true);
		if ($path !== $this->path && !$isEventStream) {
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
		$forwardedFor = $headers['x-forwarded-for'] ?? '';
		if ($forwardedFor !== '' && $this->isTrustedProxy($subscriber->address)) {
			$subscriber->address = $this->forwardedClient($forwardedFor) ?? $subscriber->address;
		}
		if ($this->heldFrom($subscriber->address, $subscriber->id) >= $this->maxPerAddress) {
			$this->refuse($subscriber, 503, 'Service Unavailable');

			return false;
		}
		$key = $headers['sec-websocket-key'] ?? '';
		if (!$isEventStream && (strtolower($headers['upgrade'] ?? '') !== 'websocket' || $key === '' || strlen(base64_decode($key, true) ?: '') !== 16)) {
			$this->refuse($subscriber, 426, 'Upgrade Required');

			return false;
		}
		parse_str(is_array($url) ? ($url['query'] ?? '') : '', $subscriber->query);
		$subscriber->headers = $headers;
		$subscriber->requestPath = $path;
		$subscriber->eventStream = $isEventStream;
		if ($isEventStream && ($headers['accept'] ?? '') !== '' && !str_contains($headers['accept'], 'text/event-stream')) {
			$this->refuse($subscriber, 406, 'Not Acceptable');

			return false;
		}
		if ($this->authorize !== null && !($this->authorize)($subscriber)) {
			$this->refuse($subscriber, 401, 'Unauthorized');

			return false;
		}
		$cursor = $subscriber->query['cursor'] ?? null;
		$subscriber->cursor = is_string($cursor) && ctype_digit($cursor) ? (int)$cursor : null;
		if ($isEventStream) {
			$subscriber->output .= "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nCache-Control: no-cache\r\nConnection: keep-alive\r\nX-Accel-Buffering: no\r\n\r\n: connected\n\n";
		} else {
			$accept = base64_encode(sha1($key . self::GUID, true));
			$protocol = $subscriber->responseSubprotocol === '' ? '' : 'Sec-WebSocket-Protocol: ' . $subscriber->responseSubprotocol . "\r\n";
			$subscriber->output .= "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: " . $accept . "\r\n" . $protocol . "\r\n";
		}
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

	private function healthy(Subscriber $subscriber): void {
		$body = "OK\n";
		@fwrite($subscriber->socket, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
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
	 * One frame off the front of $buffer, or null when it is not all there
	 * yet; whether it was masked is for the caller to judge.
	 *
	 * @return array{0: int, 1: string, 2: bool}|null opcode, payload, and whether it was masked
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
		if ($length < 0 || $length > self::REQUEST_LIMIT) {
			// a subscriber never sends anything that long, and a length with its top bit set is none at all; the caller closes
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

		return [$opcode, $payload, $masked];
	}
}
