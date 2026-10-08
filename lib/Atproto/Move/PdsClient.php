<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Move;

use OCA\Social\Exceptions\AtprotoException;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Throwable;

/**
 * XRPC calls to another PDS, the one an account moves to or from: JSON in
 * and out, or bytes in (a repository, a blob). The other PDS is the person's
 * choice and gets the same care as any address a user types: https, and
 * Nextcloud's refusal of local addresses — unless the server is one set up
 * to talk to local ones, as a development network is.
 */
class PdsClient {
	public function __construct(
		private IClientService $clientService,
		private IConfig $systemConfig,
	) {
	}

	/**
	 * A PDS address as typed, made its origin.
	 *
	 * @throws AtprotoException when it is not one this server will talk to
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
			throw new AtprotoException('That is not the https address of a PDS');
		}

		return $scheme . '://' . $host . (isset($url['port']) ? ':' . $url['port'] : '');
	}

	/**
	 * @param array<string, scalar> $query
	 * @param array|null $json the JSON body of a procedure
	 * @param resource|string|null $bytes a raw body, with its type
	 * @return array{status: int, body: array}
	 * @throws AtprotoException when the PDS cannot be reached
	 */
	public function call(string $pds, string $method, string $verb = 'GET', array $query = [], ?array $json = null, $bytes = null, string $type = '', string $token = ''): array {
		$headers = ['Accept' => 'application/json'];
		if ($token !== '') {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		$options = ['headers' => $headers, 'timeout' => 600, 'http_errors' => false];
		if ($json !== null) {
			$options['headers']['Content-Type'] = 'application/json';
			$options['body'] = (string)json_encode($json, JSON_UNESCAPED_SLASHES);
		} elseif ($bytes !== null) {
			$options['headers']['Content-Type'] = $type;
			$options['body'] = $bytes;
		}
		$url = $pds . '/xrpc/' . $method . ($query === [] ? '' : '?' . http_build_query($query));
		try {
			$client = $this->clientService->newClient();
			$response = $verb === 'GET' ? $client->get($url, $options) : $client->post($url, $options);
			$body = json_decode((string)$response->getBody(), true);
		} catch (Throwable $e) {
			throw new AtprotoException('The PDS at ' . $pds . ' could not be reached: ' . $e->getMessage(), 0, $e);
		}

		return ['status' => $response->getStatusCode(), 'body' => is_array($body) ? $body : []];
	}

	/**
	 * A query whose answer is bytes — a repository, a blob — with its type.
	 *
	 * @return array{status: int, bytes: string, type: string}
	 * @throws AtprotoException when the PDS cannot be reached
	 */
	public function bytes(string $pds, string $method, array $query, string $token = ''): array {
		$headers = $token === '' ? [] : ['Authorization' => 'Bearer ' . $token];
		try {
			$response = $this->clientService->newClient()->get($pds . '/xrpc/' . $method . '?' . http_build_query($query), [
				'headers' => $headers,
				'timeout' => 600,
				'http_errors' => false,
			]);
		} catch (Throwable $e) {
			throw new AtprotoException('The PDS at ' . $pds . ' could not be reached: ' . $e->getMessage(), 0, $e);
		}

		return ['status' => $response->getStatusCode(), 'bytes' => (string)$response->getBody(), 'type' => $response->getHeader('Content-Type')];
	}

	/**
	 * The same, for a call that has to succeed.
	 *
	 * @return array the answer
	 * @throws AtprotoException with the PDS's own error
	 */
	public function expect(string $pds, string $method, string $verb = 'GET', array $query = [], ?array $json = null, $bytes = null, string $type = '', string $token = ''): array {
		$answer = $this->call($pds, $method, $verb, $query, $json, $bytes, $type, $token);
		if ($answer['status'] !== 200) {
			$error = trim((string)($answer['body']['error'] ?? '') . ': ' . (string)($answer['body']['message'] ?? ''), ': ');
			throw new AtprotoException($method . ' answered ' . $answer['status'] . ($error !== '' ? ' (' . $error . ')' : ''));
		}

		return $answer['body'];
	}
}
