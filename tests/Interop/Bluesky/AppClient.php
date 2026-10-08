<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop\Bluesky;

/**
 * A Bluesky app, as far as the tests need one: it talks XRPC to this
 * instance as its PDS, with the session it signed in for.
 */
final class AppClient {
	public string $pds = '';
	public string $accessJwt = '';
	public string $refreshJwt = '';
	public string $did = '';

	public static function at(string $pds): self {
		$client = new self();
		$client->pds = rtrim($pds, '/');

		return $client;
	}

	/**
	 * @return array{0: int, 1: array} the status and the decoded answer
	 */
	public function signIn(string $identifier, string $password): array {
		[$status, $answer] = $this->call('POST', 'com.atproto.server.createSession', ['identifier' => $identifier, 'password' => $password], '');
		if ($status === 200) {
			$this->accessJwt = (string)$answer['accessJwt'];
			$this->refreshJwt = (string)$answer['refreshJwt'];
			$this->did = (string)$answer['did'];
		}

		return [$status, $answer];
	}

	/**
	 * @return array{0: int, 1: array}
	 */
	public function query(string $method, array $params = []): array {
		return $this->call('GET', $method . ($params === [] ? '' : '?' . http_build_query($params)), null, $this->accessJwt);
	}

	/**
	 * @return array{0: int, 1: array}
	 */
	public function procedure(string $method, array $body, ?string $token = null): array {
		return $this->call('POST', $method, $body, $token ?? $this->accessJwt);
	}

	/**
	 * @return array{0: int, 1: array}
	 */
	public function upload(string $bytes, string $mime): array {
		return $this->send('com.atproto.repo.uploadBlob', $bytes, $mime);
	}

	/**
	 * A procedure whose input is bytes: a blob, a repository.
	 *
	 * @return array{0: int, 1: array}
	 */
	public function send(string $method, string $bytes, string $mime): array {
		return $this->call('POST', $method, $bytes, $this->accessJwt, $mime);
	}

	/**
	 * The bytes of a blob this server serves, as anyone reads them.
	 */
	public function blob(string $did, string $cid): string {
		$handle = curl_init($this->pds . '/xrpc/com.atproto.sync.getBlob?' . http_build_query(['did' => $did, 'cid' => $cid]));
		curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($handle, CURLOPT_TIMEOUT, 60);
		$answer = curl_exec($handle);

		return (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE) === 200 && is_string($answer) ? $answer : '';
	}

	/**
	 * @return array{0: int, 1: array}
	 */
	private function call(string $verb, string $method, array|string|null $body, string $token, string $mime = 'application/json'): array {
		$handle = curl_init($this->pds . '/xrpc/' . $method);
		$headers = ['Accept: application/json'];
		if ($token !== '') {
			$headers[] = 'Authorization: Bearer ' . $token;
		}
		curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $verb);
		curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($handle, CURLOPT_TIMEOUT, 60);
		if ($body !== null) {
			$headers[] = 'Content-Type: ' . $mime;
			curl_setopt($handle, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_SLASHES));
		}
		curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
		$answer = curl_exec($handle);
		$status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
		$decoded = json_decode(is_string($answer) ? $answer : '', true);

		return [$status, is_array($decoded) ? $decoded : []];
	}
}
