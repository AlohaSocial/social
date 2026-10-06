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
 * A Mastodon-shaped client API on the far side of an HTTP connection.
 *
 * Every call fails loudly with what came back: a harness that swallows an
 * error and reports "not found" sends whoever is debugging it to the wrong end
 * of the wire. The only answer that is not an error is a 404 read through
 * `find()`, because "it is not there (any more)" is what a deletion test asks.
 */
abstract class ApiClient {
	/** How long an `await()` keeps asking, unless told otherwise. */
	public const WAIT_SECONDS = 90;
	private const POLL_MICROSECONDS = 750_000;

	public function __construct(
		protected string $baseUrl,
	) {
		$this->baseUrl = rtrim($baseUrl, '/');
	}

	/**
	 * The headers that say who is asking.
	 *
	 * @return string[]
	 */
	abstract protected function authHeaders(): array;

	/**
	 * @param array<string, mixed> $query
	 * @return array<mixed>
	 */
	public function get(string $path, array $query = []): array {
		return $this->request('GET', $this->url($path, $query)) ?? [];
	}

	/**
	 * The same as `get()`, but null where the server answers 404.
	 *
	 * @param array<string, mixed> $query
	 * @return array<mixed>|null
	 */
	public function find(string $path, array $query = []): ?array {
		return $this->request('GET', $this->url($path, $query), null, true);
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<mixed>
	 */
	public function post(string $path, array $body = []): array {
		return $this->request('POST', $this->baseUrl . $path, $body) ?? [];
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<mixed>
	 */
	public function patch(string $path, array $body = []): array {
		return $this->request('PATCH', $this->baseUrl . $path, $body) ?? [];
	}

	/** @return array<mixed> */
	public function delete(string $path): array {
		return $this->request('DELETE', $this->baseUrl . $path) ?? [];
	}

	/**
	 * A multipart upload, the way a client sends a picture.
	 *
	 * @param array<string, string> $fields
	 * @return array<mixed>
	 */
	public function upload(string $path, string $file, string $mime, array $fields = []): array {
		$body = $fields + ['file' => new CURLFile($file, $mime, basename($file))];

		return $this->request('POST', $this->baseUrl . $path, $body, false, true) ?? [];
	}

	/**
	 * Waits for a condition the other side satisfies in its own time, and
	 * answers what the probe answered, or null when it never did.
	 *
	 * @template T
	 * @param callable(): ?T $probe
	 * @return ?T
	 */
	public function await(callable $probe, int $seconds = self::WAIT_SECONDS) {
		$until = microtime(true) + $seconds;
		do {
			$answer = $probe();
			if ($answer !== null) {
				return $answer;
			}
			usleep(self::POLL_MICROSECONDS);
		} while (microtime(true) < $until);

		return null;
	}

	/** @param array<string, mixed> $query */
	private function url(string $path, array $query): string {
		$url = $this->baseUrl . $path;
		if ($query !== []) {
			// `id[]=1&id[]=2`, which is what Mastodon-shaped servers read
			$url .= '?' . preg_replace('/%5B\d+%5D=/', '%5B%5D=', http_build_query($query));
		}

		return $url;
	}

	/**
	 * @param array<string, mixed>|null $body
	 * @return array<mixed>|null
	 */
	private function request(
		string $method,
		string $url,
		?array $body = null,
		bool $missingIsNull = false,
		bool $multipart = false,
	): ?array {
		$handle = curl_init($url);
		$headers = array_merge($this->authHeaders(), ['Accept: application/json']);

		curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
		curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($handle, CURLOPT_TIMEOUT, 60);
		if ($multipart) {
			curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
		} elseif ($body !== null) {
			$headers[] = 'Content-Type: application/json';
			curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
		}
		curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);

		$answer = curl_exec($handle);
		$status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
		$error = curl_error($handle);
		curl_close($handle);

		if ($answer === false) {
			throw new RuntimeException($method . ' ' . $url . ' failed: ' . $error);
		}

		if ($missingIsNull && ($status === 404 || $status === 410)) {
			return null;
		}

		if ($status >= 300) {
			throw new RuntimeException(
				$method . ' ' . $url . ' answered ' . $status . ': ' . substr((string)$answer, 0, 600)
			);
		}

		$decoded = json_decode((string)$answer, true);

		return is_array($decoded) ? $decoded : [];
	}
}
