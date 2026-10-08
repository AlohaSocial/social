<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Client;

use OCA\Social\Atproto\OAuth\AuthorizationServer;
use OCA\Social\Atproto\OAuth\OAuthException;
use OCA\Social\Atproto\Publisher\VideoBlobService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Atproto\Xrpc\XrpcBytes;
use OCA\Social\Atproto\Xrpc\XrpcException;
use OCA\Social\Service\ModerationService;

/**
 * The part of the XRPC surface a Bluesky app signed in here uses (§6.2,
 * §6.3): sessions, its own account, writes and uploads, service-auth
 * tokens for Bluesky's video service, preferences, and
 * everything of `app.bsky.*` passed on to the AppView. What is not a
 * signed-in call is the public surface's (`XrpcService`), and is answered
 * there.
 */
class ClientXrpc {
	public const UPLOAD = 'com.atproto.repo.uploadBlob';
	/** what a picture upload may weigh: Bluesky takes pictures up to two megabytes, and a larger one is made to fit */
	public const MAX_BLOB = 5242880;
	/** what any upload may weigh: a video */
	public const MAX_UPLOAD = VideoBlobService::MAX_BYTES;

	public function __construct(
		private AtprotoConfig $config,
		private SessionService $sessions,
		private AppViewProxy $proxy,
		private Preferences $preferences,
		private WriteService $writes,
		private ServiceAuthGrant $grants,
		private AuthorizationServer $oauth,
		private ModerationService $moderation,
	) {
	}

	/**
	 * A query a signed-in app makes, or null when it is not one.
	 *
	 * @param array<string, string> $headers lower-cased names
	 * @throws XrpcException
	 */
	public function query(string $method, string $rawQuery, array $headers): array|XrpcBytes|null {
		if (!$this->config->isEnabled() || !$this->isClientMethod($method)) {
			return null;
		}
		$session = $this->signedIn($headers, $method, 'GET');

		parse_str($rawQuery, $params);

		return match (true) {
			$method === 'com.atproto.server.getSession' => $this->sessions->describe($session),
			$method === 'com.atproto.server.getServiceAuth' => $this->grants->grant(
				$session, self::param($params, 'aud'), self::param($params, 'lxm'), (int)self::param($params, 'exp')
			),
			$method === 'app.bsky.actor.getPreferences' => $this->preferences->get($session),
			default => $this->proxy->forward($session, $method, 'get', $rawQuery, '', $headers),
		};
	}

	/**
	 * A procedure an app calls, or null when it is not one.
	 *
	 * @param array<string, string> $headers lower-cased names
	 * @throws XrpcException
	 */
	public function procedure(string $method, string $rawBody, array $headers, string $ip): array|XrpcBytes|null {
		if (!$this->config->isEnabled()) {
			return null;
		}
		switch ($method) {
			case 'com.atproto.server.createSession':
				$body = self::json($rawBody);

				return $this->sessions->create((string)($body['identifier'] ?? ''), (string)($body['password'] ?? ''), $ip);
			case 'com.atproto.server.refreshSession':
				return $this->sessions->refresh((string)($headers['authorization'] ?? ''));
			case 'com.atproto.server.deleteSession':
				$this->sessions->delete((string)($headers['authorization'] ?? ''));

				return [];
		}
		if (!$this->isClientMethod($method)) {
			return null;
		}
		$session = $this->signedIn($headers, $method, 'POST');

		return match ($method) {
			'app.bsky.actor.putPreferences' => $this->preferences->put($session, self::json($rawBody)),
			'com.atproto.repo.createRecord' => $this->writes->create($session, self::json($rawBody)),
			'com.atproto.repo.putRecord' => $this->writes->put($session, self::json($rawBody)),
			'com.atproto.repo.deleteRecord' => $this->writes->delete($session, self::json($rawBody)),
			'com.atproto.repo.applyWrites' => $this->writes->apply($session, self::json($rawBody)),
			'com.atproto.moderation.createReport' => $this->writes->report($session, self::json($rawBody)),
			default => $this->proxy->forward($session, $method, 'post', '', $rawBody, $headers),
		};
	}

	/**
	 * Whether a method is answered for a signed-in app: its session, its
	 * writes, and what goes to the AppView.
	 */
	private function isClientMethod(string $method): bool {
		return str_starts_with($method, 'app.bsky.')
			|| str_starts_with($method, 'chat.bsky.')
			|| in_array($method, [
				'com.atproto.server.getSession', 'com.atproto.server.getServiceAuth',
				'com.atproto.repo.createRecord', 'com.atproto.repo.putRecord', 'com.atproto.repo.deleteRecord',
				'com.atproto.repo.applyWrites', 'com.atproto.moderation.createReport',
			], true);
	}

	/**
	 * `com.atproto.repo.uploadBlob`, from a file the body was copied to: by a
	 * signed-in app, or by Bluesky's video service with a token the account
	 * signed for it (`getServiceAuth`). A video may weigh up to Bluesky's
	 * limit for one; anything else is held to a picture's.
	 *
	 * @param array<string, string> $headers lower-cased names
	 * @throws XrpcException
	 */
	public function upload(string $path, array $headers): array {
		if (!$this->config->isEnabled()) {
			throw XrpcException::notImplemented(self::UPLOAD);
		}
		$session = $this->grants->uploader((string)($headers['authorization'] ?? ''));
		if ($session === null) {
			$session = $this->signedIn($headers, self::UPLOAD, 'POST');
		} else {
			$this->assertNotSuspended($session);
		}
		$size = (int)filesize($path);
		$sniffed = (string)mime_content_type($path);
		if ($size > (str_starts_with($sniffed, 'video/') ? self::MAX_UPLOAD : self::MAX_BLOB)) {
			throw new XrpcException(413, 'BlobTooLarge', 'This file is too large');
		}

		return $this->writes->upload($session, $path, (string)($headers['content-type'] ?? ''));
	}

	/**
	 * The signed-in account: by an app password session's bearer token, or
	 * an OAuth session's DPoP-bound one. Anything but `getSession` needs the
	 * account-wide scope an app password has and an OAuth app may be given.
	 *
	 * @throws XrpcException
	 */
	private function signedIn(array $headers, string $method, string $verb): ClientSession {
		$authorization = (string)($headers['authorization'] ?? '');
		if (str_starts_with($authorization, 'DPoP ')) {
			try {
				$session = $this->oauth->authenticate($authorization, (string)($headers['dpop'] ?? ''), $verb, $this->oauth->issuer() . '/xrpc/' . $method);
			} catch (OAuthException $e) {
				throw new XrpcException($e->status, $e->error === 'use_dpop_nonce' ? 'UseDpopNonce' : 'InvalidToken', $e->getMessage(), $e->headers);
			}
		} else {
			$session = $this->sessions->authenticate($authorization);
		}
		if ($session === null) {
			throw XrpcException::authenticationRequired();
		}
		$this->assertNotSuspended($session);
		if ($method !== 'com.atproto.server.getSession' && !$session->may(ClientSession::GENERIC)) {
			throw new XrpcException(403, 'InsufficientScope', 'This app was not allowed to act for the account');
		}

		return $session;
	}

	/**
	 * @throws XrpcException
	 */
	private function assertNotSuspended(ClientSession $session): void {
		if ($this->moderation->isSuspended($session->identity->actorId)) {
			throw new XrpcException(400, 'AccountTakedown', 'This account is suspended');
		}
	}

	private static function param(array $params, string $name): string {
		return is_string($params[$name] ?? null) ? $params[$name] : '';
	}

	/**
	 * @throws XrpcException
	 */
	private static function json(string $raw): array {
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			throw XrpcException::invalidRequest('A JSON body is expected');
		}

		return $decoded;
	}
}
