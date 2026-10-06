<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use CURLFile;
use RuntimeException;

/**
 * A REST client over the network, with the waiting every interop test needs.
 *
 * Every call fails loudly with what came back: a harness that swallows an
 * error and reports "not found" sends whoever is debugging it to the wrong
 * end of the wire.
 */
abstract class RestClient {
	/**
	 * How long to keep asking for something that arrives asynchronously.
	 *
	 * Delivery is queued on both sides: a post exists on the other side a
	 * second or two after it is sent, not at once.
	 */
	protected const WAIT_SECONDS = 30;
	protected const POLL_SECONDS = 1;

	/** How often a request answered 429 is tried again before it fails. */
	private const RATE_LIMIT_RETRIES = 5;

	public function __construct(
		protected string $baseUrl,
	) {
		$this->baseUrl = rtrim($baseUrl, '/');
	}

	/** What goes into the `Authorization` header, '' for nothing. */
	abstract protected function authorization(): string;

	/** A short name of this side, for failure messages. */
	abstract public function name(): string;

	// --- waiting ----------------------------------------------------------

	/**
	 * Waits for a condition the other side satisfies in its own time.
	 *
	 * @template T
	 * @param callable(): ?T $probe
	 * @param int $seconds 0 for this side's default
	 * @return ?T
	 */
	public function await(callable $probe, int $seconds = 0) {
		$until = time() + (($seconds > 0) ? $seconds : static::WAIT_SECONDS);
		do {
			$answer = $probe();
			if ($answer !== null) {
				return $answer;
			}
			sleep(static::POLL_SECONDS);
		} while (time() < $until);

		return null;
	}

	// --- HTTP -------------------------------------------------------------

	/**
	 * @param array<string, string> $query
	 * @return array<mixed>
	 */
	public function get(string $path, array $query = []): array {
		return $this->request('GET', $this->url($path, $query));
	}

	/**
	 * The answer to a GET, or null when it is 404 or 410 — for asking whether
	 * something is still there.
	 *
	 * @param array<string, string> $query
	 * @return array<mixed>|null
	 */
	public function getOrNull(string $path, array $query = []): ?array {
		[$status, $answer] = $this->send('GET', $this->url($path, $query), null, $this->headers());
		if ($status === 404 || $status === 410) {
			return null;
		}

		return $this->decoded('GET', $this->url($path, $query), $status, $answer);
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<mixed>
	 */
	public function post(string $path, array $body = []): array {
		return $this->request('POST', $this->url($path), $body);
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<mixed>
	 */
	public function put(string $path, array $body = []): array {
		return $this->request('PUT', $this->url($path), $body);
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<mixed>
	 */
	public function patch(string $path, array $body = []): array {
		return $this->request('PATCH', $this->url($path), $body);
	}

	/** @return array<mixed> */
	public function delete(string $path): array {
		return $this->request('DELETE', $this->url($path));
	}

	/**
	 * A multipart request: form fields, and files as `name => [path, mime type]`.
	 *
	 * @param array<string, string> $fields
	 * @param array<string, array{0: string, 1: string}> $files
	 * @return array<mixed>
	 */
	public function upload(string $path, array $fields, array $files, string $method = 'POST'): array {
		$body = $fields;
		foreach ($files as $name => [$file, $mimeType]) {
			$body[$name] = new CURLFile($file, $mimeType, basename($file));
		}

		return $this->exchange($method, $this->url($path), $body, $this->headers());
	}

	/**
	 * @param array<string, mixed>|null $body
	 * @return array<mixed>
	 */
	protected function request(string $method, string $url, ?array $body = null): array {
		$headers = $this->headers();
		$payload = null;
		if ($body !== null) {
			$headers[] = 'Content-Type: application/json';
			$payload = (string)json_encode($body, JSON_UNESCAPED_SLASHES);
		}

		return $this->exchange($method, $url, $payload, $headers);
	}

	/** @return string[] */
	protected function headers(): array {
		$headers = ['Accept: application/json'];
		$authorization = $this->authorization();
		if ($authorization !== '') {
			$headers[] = 'Authorization: ' . $authorization;
		}

		return $headers;
	}

	/** @param array<string, string> $query */
	protected function url(string $path, array $query = []): string {
		$url = $this->baseUrl . $path;
		if ($query !== []) {
			$url .= '?' . http_build_query($query);
		}

		return $url;
	}

	/**
	 * Sends one request; a 429 is waited out and sent again, a few times.
	 *
	 * @param string|array<string, mixed>|null $payload
	 * @param string[] $headers
	 * @return array{0: int, 1: string}
	 */
	protected function send(string $method, string $url, string|array|null $payload, array $headers): array {
		for ($attempt = 0; ; $attempt++) {
			$handle = curl_init($url);
			curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
			curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($handle, CURLOPT_TIMEOUT, 60);
			curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
			if ($payload !== null) {
				curl_setopt($handle, CURLOPT_POSTFIELDS, $payload);
			}

			$answer = curl_exec($handle);
			$status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
			$error = curl_error($handle);
			curl_close($handle);

			if ($answer === false) {
				throw new RuntimeException($method . ' ' . $url . ' failed: ' . $error);
			}

			if ($status !== 429 || $attempt >= self::RATE_LIMIT_RETRIES) {
				return [$status, (string)$answer];
			}

			sleep(5 * ($attempt + 1));
		}
	}

	/**
	 * Sends one request and answers its decoded body, failing on an error.
	 *
	 * @param string|array<string, mixed>|null $payload
	 * @param string[] $headers
	 * @return array<mixed>
	 */
	protected function exchange(string $method, string $url, string|array|null $payload, array $headers): array {
		[$status, $answer] = $this->send($method, $url, $payload, $headers);

		return $this->decoded($method, $url, $status, $answer);
	}

	/** @return array<mixed> */
	private function decoded(string $method, string $url, int $status, string $answer): array {
		if ($status >= 400 || $status === 0) {
			throw new RuntimeException(
				$method . ' ' . $url . ' answered ' . $status . ': ' . substr($answer, 0, 500)
			);
		}

		if ($status >= 300) {
			// a redirect read as an empty answer reads as "nothing there"
			throw new RuntimeException($method . ' ' . $url . ' was redirected (' . $status . ')');
		}

		$decoded = json_decode($answer, true);

		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * @param array<mixed> $answer
	 * @return array<int, array<string, mixed>>
	 */
	protected function listOf(array $answer): array {
		return array_values(array_filter($answer, 'is_array'));
	}
}
