<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Client;

use OCA\Social\Atproto\AppView\ServiceAuth;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Atproto\Xrpc\XrpcBytes;
use OCA\Social\Atproto\Xrpc\XrpcException;
use OCA\Social\Service\CurlService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What a Bluesky app asks of the AppView through this PDS (D17): its
 * timelines, threads, profiles, notifications, search — and of Bluesky's
 * chat service, its direct messages (`chat.bsky.*`, D15). Passed on as the
 * signed-in account, with a token its own key signs for the one method,
 * and answered as the service answered (§16.2). The app's own token never
 * leaves here. Only the AppView and the chat service this instance is
 * configured with can be the target: `atproto-proxy` cannot point the
 * account's signature anywhere else. A report an app files is no proxied call: it becomes a
 * report here (`WriteService`), passed on in this server's name.
 */
class AppViewProxy {
	private const TIMEOUT = 20;
	private const FORWARDED_HEADERS = [
		'atproto-accept-labelers' => 'atproto-accept-labelers',
		'accept-language' => 'accept-language',
		'content-type' => 'content-type',
		'x-atproto-device-id' => 'X-Atproto-Device-Id',
		'x-atproto-session-id' => 'X-Atproto-Session-Id',
		'x-atproto-bsky-topics' => 'X-Atproto-Bsky-Topics',
		'x-bsky-topics' => 'X-Bsky-Topics',
	];
	/** the most a request body may carry on its way through */
	public const MAX_BODY = 1048576;

	public function __construct(
		private AtprotoConfig $config,
		private IdentityService $identities,
		private ServiceAuth $serviceAuth,
		private CurlService $curlService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether a method is one the AppView answers rather than this PDS.
	 */
	public static function isProxied(string $method): bool {
		return str_starts_with($method, 'app.bsky.') && !in_array($method, Preferences::METHODS, true);
	}

	/**
	 * @param string $verb `get` or `post`
	 * @param string $query the raw query string, passed on as it came
	 * @param string $body the raw body of a procedure
	 * @param array<string, string> $headers the caller's proxy, labeler, device/session, Bsky topics, content type and language headers
	 * @throws XrpcException
	 */
	public function forward(ClientSession $session, string $method, string $verb, string $query, string $body, array $headers): XrpcBytes {
		[$audience, $endpoint] = $this->target($method, (string)($headers['atproto-proxy'] ?? ''));
		if (strlen($body) > self::MAX_BODY) {
			throw new XrpcException(413, 'PayloadTooLarge', 'Request body too large');
		}
		$token = $this->serviceAuth->token($this->identities->signingKey($session->identity), $session->identity->did, $audience, $method);
		$outgoing = ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
		foreach (self::FORWARDED_HEADERS as $name => $outgoingName) {
			$value = trim((string)($headers[$name] ?? ''));
			if ($value !== '' && !str_contains($value, "\r") && !str_contains($value, "\n")) {
				$outgoing[$outgoingName] = substr($value, 0, 2048);
			}
		}
		$url = $endpoint . '/xrpc/' . $method . ($query !== '' ? '?' . $query : '');
		$status = 0;
		$contentType = '';
		try {
			$send = function (string $authorization) use ($verb, $url, $outgoing, $body, $endpoint, &$contentType, &$status): string {
				$outgoing['Authorization'] = $authorization;

				return $this->curlService->doRequest($verb === 'post' ? 'post' : 'get', $url, [
					'headers' => $outgoing,
					'body' => $verb === 'post' ? $body : '',
					'timeout' => self::TIMEOUT,
					'json_headers' => false,
					'accept_errors' => true,
					'allow_local_address' => !str_starts_with($endpoint, 'https://'),
				], $contentType, $status);
			};
			$answer = $send('Bearer ' . $token);
			$decoded = json_decode($answer, true);
			if ($status === 401 && str_ends_with($audience, '#bsky_appview') && ($decoded['error'] ?? '') === 'BadJwtAudience') {
				$legacyAudience = substr($audience, 0, -strlen('#bsky_appview'));
				$legacyToken = $this->serviceAuth->token($this->identities->signingKey($session->identity), $session->identity->did, $legacyAudience, $method);
				$answer = $send('Bearer ' . $legacyToken);
			}
		} catch (Throwable $e) {
			$this->logger->notice('AppView not reached through the proxy', ['method' => $method, 'exception' => $e]);

			throw new XrpcException(502, 'UpstreamFailure', 'The AppView could not be reached');
		}

		return new XrpcBytes($answer, $contentType !== '' ? $contentType : 'application/json', $status > 0 ? $status : 502);
	}

	/**
	 * The audience and endpoint a call goes to.
	 *
	 * @return array{0: string, 1: string}
	 * @throws XrpcException
	 */
	private function target(string $method, string $proxy): array {
		if (str_starts_with($method, 'chat.bsky.')) {
			$chat = $this->config->chatDid();
			$service = $chat . '#bsky_chat';
			if ($chat === '') {
				throw new XrpcException(501, 'MethodNotImplemented', 'Direct messages are not offered by this server');
			}
			if ($proxy !== '' && $proxy !== $service && $proxy !== $chat) {
				throw new XrpcException(400, 'InvalidRequest', 'This server proxies direct messages to its configured chat service only');
			}

			return [$service, $this->config->chat()];
		}
		$appView = $this->config->appViewDid();
		$service = $appView . '#bsky_appview';
		if ($proxy === '') {
			return [$service, $this->config->appViewAuth()];
		}
		if ($proxy === $service || $proxy === $appView) {
			return [$service, $this->config->appViewAuth()];
		}

		throw new XrpcException(400, 'InvalidRequest', 'This server proxies to its configured AppView only');
	}
}
