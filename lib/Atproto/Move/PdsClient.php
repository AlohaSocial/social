<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Move;

use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\DurableCache;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * XRPC calls to another PDS, the one an account moves to or from: JSON in
 * and out, or bytes in (a repository, a blob). The other PDS is the person's
 * choice and gets the same care as any address a user types: https, and
 * every request goes out through `CurlService`, with its refusal of local
 * addresses — unless the server is one set up to talk to local ones, as a
 * development network is.
 */
class PdsClient {
	/** what a JSON call is given to answer, in seconds: some are waited for in a request */
	public const TIMEOUT = 20;
	/** what a repository or a blob is given, in seconds: those only move in a background job */
	public const LONG_TIMEOUT = 600;
	/** how many password sign-ins this server sends one PDS in a window */
	public const SIGN_INS = 20;
	/** that window, in seconds */
	public const SIGN_IN_WINDOW = 600;
	private const SIGN_IN = 'com.atproto.server.createSession';

	public function __construct(
		private CurlService $curl,
		private IConfig $systemConfig,
		private DurableCache $cache,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * A PDS address as typed, made its origin.
	 *
	 * @throws MoveFailure when it is not one this server will talk to
	 */
	public function origin(string $typed): string {
		$typed = trim($typed);
		if (!preg_match('#^[a-z]+://#i', $typed)) {
			$typed = 'https://' . $typed;
		}
		$url = parse_url($typed);
		$scheme = strtolower($url['scheme'] ?? '');
		$host = strtolower($url['host'] ?? '');
		$local = $this->systemConfig->getSystemValueBool('allow_local_remote_servers', false);
		if ($host === '' || isset($url['user']) || !($scheme === 'https' || ($scheme === 'http' && $local))) {
			throw new MoveFailure('pds-address-invalid', 'That is not the https address of a PDS');
		}

		return $scheme . '://' . $host . (isset($url['port']) ? ':' . $url['port'] : '');
	}

	/**
	 * @param array<string, scalar> $query
	 * @param array|null $json the JSON body of a procedure
	 * @param resource|string|null $bytes a raw body, with its type
	 * @return array{status: int, body: array}
	 * @throws MoveFailure when the PDS cannot be reached, or this server sent it too many sign-ins of late
	 */
	public function call(string $pds, string $method, string $verb = 'GET', array $query = [], ?array $json = null, $bytes = null, string $type = '', string $token = ''): array {
		if ($method === self::SIGN_IN) {
			$this->countSignIn($pds);
		}
		$headers = ['Accept' => 'application/json'];
		if ($token !== '') {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		$options = [
			'headers' => $headers,
			'timeout' => $bytes !== null ? self::LONG_TIMEOUT : self::TIMEOUT,
			'json_headers' => false,
			'accept_errors' => true,
		];
		if ($json !== null) {
			$options['headers']['Content-Type'] = 'application/json';
			// a procedure's input is an object, and an empty PHP array encodes as []
			$options['body'] = (string)json_encode($json === [] ? (object)[] : $json, JSON_UNESCAPED_SLASHES);
		} elseif ($bytes !== null) {
			$options['headers']['Content-Type'] = $type;
			$options['body'] = $bytes;
		}
		$url = $pds . '/xrpc/' . $method . ($query === [] ? '' : '?' . http_build_query($query));
		$status = 0;
		try {
			$body = json_decode($this->curl->doRequest($verb === 'GET' ? 'get' : 'post', $url, $options, $contentType, $status), true);
		} catch (Throwable $e) {
			throw $this->unreachable($pds, $e);
		}

		return ['status' => (int)$status, 'body' => is_array($body) ? $body : []];
	}

	/**
	 * A query whose answer is bytes — a repository, a blob — copied to a
	 * temporary file as it arrives, which the caller removes; none is made
	 * for an error status.
	 *
	 * @param int $max the most bytes taken
	 * @return array{status: int, path: string, size: int, type: string}
	 * @throws MoveFailure when the PDS cannot be reached, or the answer is larger than $max (`repo-too-large`, `blob-too-large`)
	 */
	public function bytes(string $pds, string $method, array $query, int $max, string $token = ''): array {
		$headers = $token === '' ? [] : ['Authorization' => 'Bearer ' . $token];
		try {
			// a stream rather than a document: a repository is not held to the
			// download ceiling, only to $max
			$answer = $this->curl->openStream($pds . '/xrpc/' . $method . '?' . http_build_query($query), $headers, '', self::LONG_TIMEOUT, $max);
		} catch (RequestContentException $e) {
			return ['status' => $e->getCode(), 'path' => '', 'size' => 0, 'type' => ''];
		} catch (RequestResultSizeException $e) {
			throw new MoveFailure($method === 'com.atproto.sync.getRepo' ? 'repo-too-large' : 'blob-too-large', $method . ' answered more than ' . $max . ' bytes');
		} catch (Throwable $e) {
			throw $this->unreachable($pds, $e);
		}
		$type = '';
		$length = -1;
		foreach ($answer['headers'] as $name => $values) {
			if (strtolower($name) === 'content-type') {
				$type = (string)($values[0] ?? '');
			} elseif (strtolower($name) === 'content-length') {
				$length = (int)($values[0] ?? -1);
			}
		}
		$path = (string)tempnam(sys_get_temp_dir(), 'social-atproto-pds-');
		$out = fopen($path, 'wb');
		$size = 0;
		try {
			if ($out === false) {
				throw new MoveFailure(MoveFailure::FAILED, 'No temporary file for what ' . $method . ' answered');
			}
			while ($length <= $max && !feof($answer['stream'])) {
				$chunk = fread($answer['stream'], 65536);
				if ($chunk === false) {
					break;
				}
				$size += strlen($chunk);
				if ($size > $max) {
					break;
				}
				fwrite($out, $chunk);
			}
			if ($length > $max || $size > $max) {
				throw new MoveFailure($method === 'com.atproto.sync.getRepo' ? 'repo-too-large' : 'blob-too-large', $method . ' answered more than ' . $max . ' bytes');
			}
		} catch (MoveFailure $e) {
			@unlink($path);
			throw $e;
		} finally {
			if ($out !== false) {
				fclose($out);
			}
			fclose($answer['stream']);
		}

		return ['status' => $answer['status'], 'path' => $path, 'size' => $size, 'type' => $type];
	}

	/**
	 * Counts a password sign-in at a PDS, for the whole instance: a PDS
	 * limits them per address, so the people of one server share that
	 * limit, and one person's tries must not use it up for all.
	 *
	 * @throws MoveFailure `rate-limited` past `SIGN_INS` in `SIGN_IN_WINDOW`
	 */
	private function countSignIn(string $pds): void {
		$host = strtolower((string)parse_url($pds, PHP_URL_HOST));
		if ($this->cache->inc('social.atproto.pds-sign-ins', $host, self::SIGN_IN_WINDOW) > self::SIGN_INS) {
			throw new MoveFailure('rate-limited', 'This server signed in to ' . $host . ' too often of late; try again in a few minutes');
		}
	}

	/**
	 * Why a PDS was not reached: `pds-blocked` for an instance this server
	 * does not federate with, `pds-unreachable` for anything else. An
	 * address this server does not send requests to is not reached either,
	 * and said no differently: the person must not learn which hosts
	 * resolve to local addresses. What went wrong is logged, not said.
	 */
	private function unreachable(string $pds, Throwable $e): MoveFailure {
		$this->logger->info('PDS not reached', ['pds' => $pds, 'exception' => $e]);
		if ($e instanceof UnauthorizedFediverseException) {
			return new MoveFailure('pds-blocked', 'This server does not talk to the PDS at ' . $pds, $e);
		}

		return new MoveFailure('pds-unreachable', 'The PDS at ' . $pds . ' could not be reached', $e);
	}

	/**
	 * The same, for a call that has to succeed.
	 *
	 * @return array the answer
	 * @throws MoveFailure with the PDS's own error
	 */
	public function expect(string $pds, string $method, string $verb = 'GET', array $query = [], ?array $json = null, $bytes = null, string $type = '', string $token = ''): array {
		$answer = $this->call($pds, $method, $verb, $query, $json, $bytes, $type, $token);
		if ($answer['status'] !== 200) {
			throw self::refused($method, $answer);
		}

		return $answer['body'];
	}

	/**
	 * A call the PDS answered with an error, with its own error in the
	 * message: `rate-limited` when it asks to slow down, `pds-refused`
	 * otherwise.
	 *
	 * @param array{status: int, body: array} $answer
	 */
	public static function refused(string $method, array $answer): MoveFailure {
		$error = trim((string)($answer['body']['error'] ?? '') . ': ' . (string)($answer['body']['message'] ?? ''), ': ');
		$slug = $answer['status'] === 429 || ($answer['body']['error'] ?? '') === 'RateLimitExceeded' ? 'rate-limited' : 'pds-refused';

		return new MoveFailure($slug, $method . ' answered ' . $answer['status'] . ($error !== '' ? ' (' . $error . ')' : ''));
	}
}
