<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\AppView;

use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Exceptions\AppViewNotFoundException;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Service\CurlService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the Bluesky AppView: the public one for anything anybody may see,
 * the authenticated one, as a local user, for what is theirs — their
 * notifications. Every call is one XRPC query; what comes back is the
 * decoded JSON, and a method the AppView does not have, a record it does
 * not know or a user it refuses are the exceptions below rather than a
 * silent empty answer.
 */
class AppViewClient {
	private const TIMEOUT = 20;

	public function __construct(
		private AtprotoConfig $config,
		private ServiceAuth $serviceAuth,
		private CurlService $curlService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * A public query, unauthenticated.
	 *
	 * @param array<string, string|int|string[]> $params
	 * @param array<string, string> $headers such as `atproto-accept-labelers`
	 * @return array the decoded answer
	 * @throws AppViewNotFoundException when the AppView says the thing is not there
	 * @throws AtprotoException for every other refusal, and when the AppView cannot be reached
	 */
	public function query(string $method, array $params = [], array $headers = []): array {
		return $this->get($this->config->appView(), $method, $params, $headers);
	}

	/**
	 * A query as a local user, with a service-auth token signed by their key.
	 *
	 * @param string $did the user's DID
	 * @param PrivateKey $key the user's signing key
	 * @param array<string, string|int|string[]> $params
	 * @throws AppViewNotFoundException
	 * @throws AtprotoException
	 */
	public function queryAs(string $did, PrivateKey $key, string $method, array $params = []): array {
		$token = $this->serviceAuth->token($key, $did, $this->config->appViewDid(), $method);

		return $this->get($this->config->appViewAuth(), $method, $params, ['Authorization' => 'Bearer ' . $token]);
	}

	/**
	 * A procedure as a local user, its input as JSON: what the AppView keeps
	 * for the user rather than in their repository, such as whose posts they
	 * want to be told about.
	 *
	 * @throws AppViewNotFoundException
	 * @throws AtprotoException
	 */
	public function procedureAs(string $did, PrivateKey $key, string $method, array $input): array {
		$token = $this->serviceAuth->token($key, $did, $this->config->appViewDid(), $method);

		return $this->get($this->config->appViewAuth(), $method, [], ['Authorization' => 'Bearer ' . $token], $input);
	}

	/**
	 * A query, or with an input a procedure.
	 *
	 * @param array<string, string|int|string[]> $params
	 * @param array<string, string> $headers
	 * @param array|null $input a procedure's input, sent as JSON
	 * @throws AppViewNotFoundException
	 * @throws AtprotoException
	 */
	private function get(string $base, string $method, array $params, array $headers, ?array $input = null): array {
		$url = $base . '/xrpc/' . $method;
		$query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
		if ($query !== '') {
			$url .= '?' . preg_replace('/%5B\d+%5D=/', '=', $query);
		}
		$status = 0;
		$contentType = '';
		try {
			$answer = $this->curlService->doRequest($input === null ? 'get' : 'post', $url, [
				'headers' => $headers + ['Accept' => 'application/json'] + ($input === null ? [] : ['Content-Type' => 'application/json']),
				'timeout' => self::TIMEOUT,
				'json_headers' => false,
				'accept_errors' => true,
				// the interop job runs its own AppView on this machine
				'allow_local_address' => !str_starts_with($base, 'https://'),
			] + ($input === null ? [] : ['body' => (string)json_encode($input === [] ? new \stdClass() : $input, JSON_UNESCAPED_SLASHES)]), $contentType, $status);
		} catch (Throwable $e) {
			throw new AtprotoException('AppView could not be reached for ' . $method . ': ' . $e->getMessage(), 0, $e);
		}
		$decoded = json_decode($answer, true);
		if ($status >= 200 && $status < 300) {
			if (!is_array($decoded)) {
				throw new AtprotoException('AppView did not answer JSON for ' . $method);
			}

			return $decoded;
		}
		$error = is_array($decoded) ? (string)($decoded['error'] ?? '') : '';
		$message = is_array($decoded) ? (string)($decoded['message'] ?? '') : substr(trim($answer), 0, 200);
		$notFound = $status === 404
			|| in_array($error, ['NotFound', 'AccountNotFound', 'ActorNotFound', 'ProfileNotFound', 'RecordNotFound', 'BlockedActor', 'BlockedByActor'], true)
			|| ($error === 'InvalidRequest' && str_contains(strtolower($message), 'not found'));
		if ($notFound) {
			throw new AppViewNotFoundException($method . ': ' . ($message !== '' ? $message : $error));
		}
		$this->logger->notice('AppView refused a query', ['method' => $method, 'status' => $status, 'error' => $error, 'message' => $message]);

		throw new AtprotoException('AppView answered ' . (int)$status . ' for ' . $method . ': ' . ($message !== '' ? $message : $error), (int)$status);
	}
}
