<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\AppView;

use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Reader\AccountStatus;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Exceptions\AppViewNotFoundException;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Service\CurlService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the Bluesky AppView: publicly, or as a local user for what is theirs,
 * such as notifications. A missing method, an unknown record or a refused
 * user is thrown as the exceptions below, never answered empty.
 */
class AppViewClient {
	private const TIMEOUT = 20;
	public const INTERACTIVE_TIMEOUT = 3;

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
	public function query(string $method, array $params = [], array $headers = [], ?int $timeout = null): array {
		return $this->get($this->config->appView(), $method, $params, $headers, timeout: $timeout);
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
	public function queryAs(string $did, PrivateKey $key, string $method, array $params = [], ?int $timeout = null, array $headers = []): array {
		$serviceDid = $this->config->appViewDid();
		$token = $this->serviceAuth->token($key, $did, $serviceDid . '#bsky_appview', $method);
		$legacyToken = fn (): string => $this->serviceAuth->token($key, $did, $serviceDid, $method);

		return $this->get($this->config->appViewAuth(), $method, $params, array_merge($headers, ['Authorization' => 'Bearer ' . $token]), null, $legacyToken, $timeout);
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
		$serviceDid = $this->config->appViewDid();
		$token = $this->serviceAuth->token($key, $did, $serviceDid . '#bsky_appview', $method);
		$legacyToken = fn (): string => $this->serviceAuth->token($key, $did, $serviceDid, $method);

		return $this->get($this->config->appViewAuth(), $method, [], ['Authorization' => 'Bearer ' . $token], $input, $legacyToken);
	}

	/**
	 * A call to the chat service as a local user (`chat.bsky.*`), a query or,
	 * with an input, a procedure: the direct messages are kept there, not
	 * at the AppView.
	 *
	 * @param array<string, string|int|string[]> $params
	 * @throws AppViewNotFoundException
	 * @throws AtprotoException also when no chat service is configured
	 */
	public function chatAs(string $did, PrivateKey $key, string $method, array $params = [], ?array $input = null): array {
		if ($this->config->chat() === '') {
			throw new AtprotoException('No chat service is configured');
		}
		$token = $this->serviceAuth->token($key, $did, $this->config->chatDid() . '#bsky_chat', $method);

		return $this->get($this->config->chat(), $method, $params, ['Authorization' => 'Bearer ' . $token], $input);
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
	private function get(string $base, string $method, array $params, #[\SensitiveParameter] array $headers, ?array $input = null, ?callable $legacyToken = null, ?int $timeout = null): array {
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
				'timeout' => $timeout ?? self::TIMEOUT,
				'json_headers' => false,
				'accept_errors' => true,
				// the interop job runs its own AppView on this machine
				'allow_local_address' => !str_starts_with($base, 'https://'),
			] + ($input === null ? [] : ['body' => (string)json_encode($input === [] ? new \stdClass() : $input, JSON_UNESCAPED_SLASHES)]), $contentType, $status);
		} catch (Throwable $e) {
			// the message reaches the person; what went wrong on the way is logged
			$this->logger->info('AppView not reached', ['method' => $method, 'exception' => $e]);
			throw new AtprotoException('AppView could not be reached for ' . $method, 0, $e);
		}
		$decoded = json_decode($answer, true);
		if ($status === 401 && $legacyToken !== null && ($decoded['error'] ?? '') === 'BadJwtAudience') {
			$headers['Authorization'] = 'Bearer ' . $legacyToken();

			return $this->get($base, $method, $params, $headers, $input, timeout: $timeout);
		}
		if ($status >= 200 && $status < 300) {
			// a procedure without output (`muteActor`, `muteActorList`,
			// `createBookmark`) answers an empty body
			if ($input !== null && trim($answer) === '') {
				return [];
			}
			if (!is_array($decoded)) {
				throw new AtprotoException('AppView did not answer JSON for ' . $method);
			}

			return $decoded;
		}
		$error = is_array($decoded) ? (string)($decoded['error'] ?? '') : '';
		$message = is_array($decoded) ? (string)($decoded['message'] ?? '') : substr(trim($answer), 0, 200);
		$notFound = $status === 404
			|| in_array($error, ['NotFound', 'AccountNotFound', 'ActorNotFound', 'ProfileNotFound', 'RecordNotFound', 'BlockedActor', 'BlockedByActor'], true)
			|| ($error === 'InvalidRequest' && str_contains(strtolower($message), 'not found'))
			// an account the AppView knows and no longer serves, named so the
			// caller can tell a deactivation from a takedown (AccountStatus)
			|| isset(AccountStatus::ERRORS[$error]);
		if ($notFound) {
			throw new AppViewNotFoundException($method . ': ' . ($message !== '' ? $message : $error), $error);
		}
		$this->logger->notice('AppView refused a query', ['method' => $method, 'status' => $status, 'error' => $error, 'message' => $message]);

		throw new AtprotoException('AppView answered ' . (int)$status . ' for ' . $method . ': ' . ($message !== '' ? $message : $error), (int)$status);
	}
}
