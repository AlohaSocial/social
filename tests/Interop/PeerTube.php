<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use RuntimeException;

/**
 * The PeerTube on the other side, read through its own REST API.
 *
 * PeerTube is the implementation this app most needed to be checked against
 * and the one it had never been checked against at all. It refuses a video it
 * cannot make sense of **silently and on its own side** — "Cannot find
 * associated video channel" goes into *their* log, and the delivery here
 * answers 204 and looks like a success — which is the exact shape of bug no
 * test on this side can see.
 *
 * Its REST API rather than its database: `/api/v1/videos` is the same answer a
 * PeerTube user would get, and a row its serialiser will not render has not
 * arrived either.
 */
class PeerTube {
	/**
	 * Ingest here is a queue and a job runner, and a video that is going to
	 * arrive arrives within a few seconds of the delivery.
	 */
	private const WAIT_SECONDS = 60;
	private const POLL_SECONDS = 2;

	private string $token = '';

	public function __construct(
		private string $baseUrl,
		private string $username,
		private string $password,
	) {
		$this->baseUrl = rtrim($baseUrl, '/');
	}

	/** Whether this suite has a PeerTube to talk to at all. */
	public static function fromEnvironment(): ?self {
		$base = (string)getenv('PEERTUBE_BASE_URL');
		$user = (string)getenv('PEERTUBE_USER');
		$password = (string)getenv('PEERTUBE_PASSWORD');
		if ($base === '' || $user === '' || $password === '') {
			return null;
		}

		return new self($base, $user, $password);
	}

	/**
	 * The handle PeerTube knows this instance's account by.
	 *
	 * Resolving it through PeerTube's own search with `search-target=search-index`
	 * off — the local resolver — is what makes PeerTube fetch our actor, so
	 * this one call already proves the actor document we serve is one it will
	 * accept.
	 *
	 * @return array<string, mixed> the account as PeerTube describes it
	 */
	public function resolveAccount(string $handle): array {
		$found = $this->get('/api/v1/search/video-channels', ['search' => $handle, 'count' => '1'])
			+ ['data' => []];

		$account = $found['data'][0] ?? null;
		if (!is_array($account)) {
			// not every PeerTube resolves a channel through search; asking for
			// the actor directly is the other way in and the one that proves
			// the same thing
			$account = $this->get('/api/v1/video-channels/' . rawurlencode(ltrim($handle, '@')));
		}

		if (($account['name'] ?? '') === '') {
			throw new RuntimeException(
				'PeerTube could not resolve ' . $handle . ': ' . json_encode($found)
			);
		}

		return $account;
	}

	/** Makes PeerTube follow one of our channels, which is how a video reaches it. */
	public function follow(string $handle): void {
		$this->post('/api/v1/users/me/subscriptions', ['uri' => ltrim($handle, '@')]);
	}

	/**
	 * Every video PeerTube holds for a channel.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function videosOf(string $handle): array {
		$answer = $this->get(
			'/api/v1/video-channels/' . rawurlencode(ltrim($handle, '@')) . '/videos',
			['count' => '50', 'nsfw' => 'both']
		);

		$videos = $answer['data'] ?? [];

		return is_array($videos) ? $videos : [];
	}

	/**
	 * Waits for a video whose `url` is `$uri`, or null when it never arrives.
	 *
	 * @return array<string, mixed>|null
	 */
	public function awaitVideo(string $handle, string $uri): ?array {
		return $this->await(function () use ($handle, $uri): ?array {
			foreach ($this->videosOf($handle) as $video) {
				if (($video['url'] ?? '') === $uri) {
					// the list entry is a summary; the whole of it is what says
					// whether the description, the duration and the file
					// survived
					return $this->get('/api/v1/videos/' . rawurlencode((string)$video['uuid']));
				}
			}

			return null;
		});
	}

	/** Waits for a video to stop being there — what a `Delete` has to achieve. */
	public function awaitVideoGone(string $handle, string $uri): bool {
		return $this->await(function () use ($handle, $uri): ?bool {
			foreach ($this->videosOf($handle) as $video) {
				if (($video['url'] ?? '') === $uri) {
					return null;
				}
			}

			return true;
		}) === true;
	}

	/**
	 * @template T
	 * @param callable(): ?T $probe
	 * @return ?T
	 */
	public function await(callable $probe) {
		$until = time() + self::WAIT_SECONDS;
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
	 * A user token, fetched once.
	 *
	 * PeerTube's OAuth needs the instance's own client id and secret first,
	 * which it hands to anybody at `/api/v1/oauth-clients/local` — that is the
	 * documented way a client signs in and what its own apps do.
	 */
	private function token(): string {
		if ($this->token !== '') {
			return $this->token;
		}

		// unauthenticated: request() asks for this very token
		$client = $this->send('GET', $this->baseUrl . '/api/v1/oauth-clients/local', null, [
			'Accept: application/json',
		]);
		$answer = $this->form('/api/v1/users/token', [
			'client_id' => (string)($client['client_id'] ?? ''),
			'client_secret' => (string)($client['client_secret'] ?? ''),
			'grant_type' => 'password',
			'response_type' => 'code',
			'username' => $this->username,
			'password' => $this->password,
		]);

		$token = (string)($answer['access_token'] ?? '');
		if ($token === '') {
			throw new RuntimeException('PeerTube would not issue a token: ' . json_encode($answer));
		}

		return $this->token = $token;
	}

	/**
	 * @param array<string, string> $fields
	 * @return array<mixed>
	 */
	private function form(string $path, array $fields): array {
		return $this->send('POST', $this->baseUrl . $path, http_build_query($fields), [
			'Content-Type: application/x-www-form-urlencoded',
		]);
	}

	/**
	 * @param array<string, mixed>|null $body
	 * @return array<mixed>
	 */
	private function request(string $method, string $url, ?array $body = null): array {
		$headers = ['Authorization: Bearer ' . $this->token(), 'Accept: application/json'];
		$payload = null;
		if ($body !== null) {
			$headers[] = 'Content-Type: application/json';
			$payload = json_encode($body, JSON_UNESCAPED_SLASHES);
		}

		return $this->send($method, $url, $payload, $headers);
	}

	/**
	 * @param string[] $headers
	 * @return array<mixed>
	 */
	private function send(string $method, string $url, ?string $payload, array $headers): array {
		$handle = curl_init($url);
		curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
		curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($handle, CURLOPT_TIMEOUT, 30);
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

		if ($status >= 400) {
			throw new RuntimeException(
				$method . ' ' . $url . ' answered ' . $status . ': ' . substr((string)$answer, 0, 500)
			);
		}

		$decoded = json_decode((string)$answer, true);

		return is_array($decoded) ? $decoded : [];
	}
}
