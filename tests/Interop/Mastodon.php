<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use RuntimeException;

/**
 * The Mastodon on the other side, read through its own client API.
 *
 * Its API rather than its database, for two reasons. It is the same answer a
 * Mastodon *user* would get, which is what "did it arrive" means — a row in
 * `statuses` that its own serialiser refuses to render has not arrived. And it
 * works over a network, so the same suite runs against the container in CI and
 * against a Mastodon somebody has standing.
 *
 * Every call fails loudly with what came back: a harness that swallows an error
 * and reports "not found" sends whoever is debugging it to the wrong end of the
 * wire.
 */
class Mastodon {
	/**
	 * How long to keep asking for something that arrives asynchronously.
	 *
	 * Delivery here is queued, and Mastodon's own ingest is queued too: a post
	 * exists on the other side a second or two after it is sent, not at once.
	 */
	private const WAIT_SECONDS = 30;
	private const POLL_SECONDS = 1;

	public function __construct(
		private string $baseUrl,
		private string $token,
	) {
		$this->baseUrl = rtrim($baseUrl, '/');
	}

	/** Whether this suite has a Mastodon to talk to at all. */
	public static function fromEnvironment(): ?self {
		$base = (string)getenv('MASTODON_BASE_URL');
		$token = (string)getenv('MASTODON_TOKEN');
		if ($base === '' || $token === '') {
			return null;
		}

		return new self($base, $token);
	}

	/**
	 * Mastodon's own account id for an account of ours, resolving it over the
	 * network if this is the first time it has heard of it.
	 *
	 * `resolve=true` is what makes Mastodon fetch our actor document, so this
	 * one call already proves the half of interop nothing else does: that what
	 * we serve at the actor URL is a document Mastodon will accept.
	 */
	public function resolveAccount(string $handle): string {
		$found = $this->get('/api/v2/search', [
			'q' => $handle,
			'type' => 'accounts',
			'resolve' => 'true',
			'limit' => '1',
		]);

		$account = $found['accounts'][0] ?? null;
		if (!is_array($account) || ($account['id'] ?? '') === '') {
			throw new RuntimeException(
				'Mastodon could not resolve ' . $handle . ': ' . json_encode($found)
			);
		}

		return (string)$account['id'];
	}

	/** Makes Mastodon follow one of our accounts, which is how a post reaches it. */
	public function follow(string $accountId): void {
		$this->post('/api/v1/accounts/' . $accountId . '/follow');
	}

	/** Mastodon's id for one of its own accounts, by username. */
	public function accountId(string $username): string {
		$account = $this->get('/api/v1/accounts/lookup', ['acct' => $username]);
		$id = (string)($account['id'] ?? '');
		if ($id === '') {
			throw new RuntimeException('Mastodon has no account ' . $username . ': ' . json_encode($account));
		}

		return $id;
	}

	/**
	 * How the token's account stands to another: Mastodon's Relationship
	 * entity, which is what a `Move` changes.
	 *
	 * @return array<string, mixed>
	 */
	public function relationship(string $accountId): array {
		$relationships = $this->get('/api/v1/accounts/relationships', ['id[]' => $accountId]);

		return (is_array($relationships) ? ($relationships[0] ?? []) : []) + ['following' => false];
	}

	/**
	 * The statuses Mastodon holds for one account.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function statusesOf(string $accountId): array {
		$statuses = $this->get('/api/v1/accounts/' . $accountId . '/statuses', ['limit' => '40']);

		return is_array($statuses) ? $statuses : [];
	}

	/**
	 * Waits for a status whose ActivityPub id is `$uri` and answers it, or null
	 * when it never arrives.
	 *
	 * @return array<string, mixed>|null
	 */
	public function awaitStatus(string $accountId, string $uri): ?array {
		return $this->await(function () use ($accountId, $uri): ?array {
			foreach ($this->statusesOf($accountId) as $status) {
				if (($status['uri'] ?? '') === $uri) {
					return $status;
				}
			}

			return null;
		});
	}

	/** Waits for a status to stop being there — what a `Delete` has to achieve. */
	public function awaitStatusGone(string $accountId, string $uri): bool {
		return $this->await(function () use ($accountId, $uri): ?bool {
			foreach ($this->statusesOf($accountId) as $status) {
				if (($status['uri'] ?? '') === $uri) {
					return null;
				}
			}

			return true;
		}) === true;
	}

	/**
	 * Waits for a condition the other side satisfies in its own time.
	 *
	 * @template T
	 * @param callable(): ?T $probe
	 * @return ?T
	 */
	public function await(callable $probe, int $seconds = self::WAIT_SECONDS) {
		$until = time() + $seconds;
		do {
			$answer = $probe();
			if ($answer !== null) {
				return $answer;
			}
			sleep(self::POLL_SECONDS);
		} while (time() < $until);

		return null;
	}

	/**
	 * @param array<string, string> $query
	 * @return array<mixed>
	 */
	public function get(string $path, array $query = []): array {
		$url = $this->baseUrl . $path;
		if ($query !== []) {
			$url .= '?' . http_build_query($query);
		}

		return $this->request('GET', $url);
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<mixed>
	 */
	public function post(string $path, array $body = []): array {
		return $this->request('POST', $this->baseUrl . $path, $body);
	}

	/**
	 * @param array<string, mixed>|null $body
	 * @return array<mixed>
	 */
	private function request(string $method, string $url, ?array $body = null): array {
		$handle = curl_init($url);
		$headers = ['Authorization: Bearer ' . $this->token, 'Accept: application/json'];

		curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
		curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($handle, CURLOPT_TIMEOUT, 30);
		if ($body !== null) {
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

		if ($status >= 400) {
			throw new RuntimeException(
				$method . ' ' . $url . ' answered ' . $status . ': ' . substr((string)$answer, 0, 500)
			);
		}

		$decoded = json_decode((string)$answer, true);

		return is_array($decoded) ? $decoded : [];
	}
}
