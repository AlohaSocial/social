<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop\Bluesky;

use RuntimeException;

/**
 * The official Bluesky development network the interop job starts
 * (tests/Interop/atproto/network.mjs): its PLC directory, its PDS with a
 * user on it, its AppView, and the relay beside them. Plain XRPC over
 * curl, nothing of this app.
 */
final class DevNetwork {
	public const WAIT_SECONDS = 90;
	public const POLL_SECONDS = 2;

	private string $accessJwt = '';
	private string $did = '';
	public string $plc = '';
	public string $pds = '';
	public string $appView = '';
	public string $relay = '';

	/** null when the job did not start the network */
	public static function fromEnvironment(): ?self {
		$file = (string)getenv('ATPROTO_NETWORK_FILE');
		if ($file === '' || !is_file($file)) {
			return null;
		}
		$addresses = json_decode((string)file_get_contents($file), true);
		if (!is_array($addresses)) {
			return null;
		}

		$network = new self();
		$network->plc = rtrim((string)$addresses['plc'], '/');
		$network->pds = rtrim((string)$addresses['pds'], '/');
		$network->appView = rtrim((string)$addresses['bsky'], '/');
		$network->relay = rtrim((string)getenv('ATPROTO_RELAY_URL'), '/');

		return $network;
	}

	/**
	 * A user on the development PDS, signed in.
	 *
	 * @return string the user's DID
	 */
	public function createUser(string $name): string {
		$answer = $this->post($this->pds, 'com.atproto.server.createAccount', [
			'handle' => $name . '.test',
			'email' => $name . '@example.test',
			'password' => 'dev-pass-' . $name,
		]);
		$this->accessJwt = (string)($answer['accessJwt'] ?? '');
		$this->did = (string)($answer['did'] ?? '');
		if ($this->accessJwt === '' || $this->did === '') {
			throw new RuntimeException('could not create a user on the dev PDS: ' . json_encode($answer));
		}

		return $this->did;
	}

	public function userDid(): string {
		return $this->did;
	}

	/**
	 * The DID a handle resolves to on the development AppView, '' for none.
	 *
	 * The AppView answers from its index, which it fills by verifying each
	 * handle it meets on the firehose against its host. The dev PDS is not
	 * asked: it treats every `.test` handle as one of its own and refuses the
	 * ones it does not have, by configuration, before resolving anything.
	 */
	public function resolveHandle(string $handle): string {
		$answer = $this->get($this->appView, 'com.atproto.identity.resolveHandle', ['handle' => $handle]);

		return (string)($answer['did'] ?? '');
	}

	/** The DID document the development directory serves, or null. */
	public function didDocument(string $did): ?array {
		[$status, $body] = $this->request('GET', $this->plc . '/' . rawurlencode($did), null, []);
		if ($status !== 200) {
			return null;
		}
		$document = json_decode($body, true);

		return is_array($document) ? $document : null;
	}

	/** @return array|null the profile view the AppView has, or null when it has none yet */
	public function profile(string $actor): ?array {
		$answer = $this->get($this->appView, 'app.bsky.actor.getProfile', ['actor' => $actor]);

		return isset($answer['did']) ? $answer : null;
	}

	/** @return array<int, array> the posts of the author feed, newest first */
	public function authorFeed(string $actor): array {
		$answer = $this->get($this->appView, 'app.bsky.feed.getAuthorFeed', ['actor' => $actor, 'limit' => '30']);

		return is_array($answer['feed'] ?? null) ? $answer['feed'] : [];
	}

	/** @return string[] the DIDs that follow $actor, as the AppView sees it */
	public function followers(string $actor): array {
		$answer = $this->get($this->appView, 'app.bsky.graph.getFollowers', ['actor' => $actor, 'limit' => '50']);

		return array_map(static fn (array $f): string => (string)($f['did'] ?? ''), is_array($answer['followers'] ?? null) ? $answer['followers'] : []);
	}

	/** The signed-in user follows $did, as a Bluesky app would. */
	public function follow(string $did): array {
		return $this->post($this->pds, 'com.atproto.repo.createRecord', [
			'repo' => $this->did,
			'collection' => 'app.bsky.graph.follow',
			'record' => ['$type' => 'app.bsky.graph.follow', 'subject' => $did, 'createdAt' => gmdate('Y-m-d\TH:i:s.000\Z')],
		], true);
	}

	/** The signed-in user likes a post. */
	public function like(string $uri, string $cid): array {
		return $this->post($this->pds, 'com.atproto.repo.createRecord', [
			'repo' => $this->did,
			'collection' => 'app.bsky.feed.like',
			'record' => ['$type' => 'app.bsky.feed.like', 'subject' => ['uri' => $uri, 'cid' => $cid], 'createdAt' => gmdate('Y-m-d\TH:i:s.000\Z')],
		], true);
	}

	/** What the relay knows of a repository, or null when it has not seen it. */
	public function relayRepoStatus(string $did): ?array {
		if ($this->relay === '') {
			return null;
		}
		$answer = $this->get($this->relay, 'com.atproto.sync.getRepoStatus', ['did' => $did]);

		return isset($answer['did']) ? $answer : null;
	}

	/**
	 * Waits for a condition the network satisfies in its own time.
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
	 */
	private function get(string $service, string $method, array $query): array {
		[, $body] = $this->request('GET', $service . '/xrpc/' . $method . '?' . http_build_query($query), null, ['Accept: application/json']);
		$decoded = json_decode($body, true);

		return is_array($decoded) ? $decoded : [];
	}

	private function post(string $service, string $method, array $payload, bool $signedIn = false): array {
		$headers = ['Content-Type: application/json', 'Accept: application/json'];
		if ($signedIn) {
			$headers[] = 'Authorization: Bearer ' . $this->accessJwt;
		}
		[$status, $body] = $this->request('POST', $service . '/xrpc/' . $method, (string)json_encode($payload), $headers);
		$decoded = json_decode($body, true);
		if ($status >= 400) {
			throw new RuntimeException($method . ' answered ' . $status . ': ' . $body);
		}

		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * @param string[] $headers
	 * @return array{0: int, 1: string}
	 */
	private function request(string $method, string $url, ?string $payload, array $headers): array {
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

		return [$status, (string)$answer];
	}
}
