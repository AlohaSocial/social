<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Client;

use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Atproto\Xrpc\XrpcBytes;
use OCA\Social\Atproto\Xrpc\XrpcException;
use OCA\Social\Service\ModerationService;

/**
 * The part of the XRPC surface a Bluesky app signed in here uses (§6.2,
 * §6.3): sessions, its own account, writes and uploads, preferences, and
 * everything of `app.bsky.*` passed on to the AppView. What is not a
 * signed-in call is the public surface's (`XrpcService`), and is answered
 * there.
 */
class ClientXrpc {
	/** what one upload may weigh: Bluesky takes pictures up to a megabyte */
	public const MAX_BLOB = 5242880;

	public function __construct(
		private AtprotoConfig $config,
		private SessionService $sessions,
		private AppViewProxy $proxy,
		private Preferences $preferences,
		private WriteService $writes,
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
		$session = $this->signedIn($headers);

		return match (true) {
			$method === 'com.atproto.server.getSession' => $this->sessions->describe($session),
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
		$session = $this->signedIn($headers);

		return match ($method) {
			'app.bsky.actor.putPreferences' => $this->preferences->put($session, self::json($rawBody)),
			'com.atproto.repo.createRecord' => $this->writes->create($session, self::json($rawBody)),
			'com.atproto.repo.putRecord' => $this->writes->put($session, self::json($rawBody)),
			'com.atproto.repo.deleteRecord' => $this->writes->delete($session, self::json($rawBody)),
			'com.atproto.repo.applyWrites' => $this->writes->apply($session, self::json($rawBody)),
			'com.atproto.repo.uploadBlob' => $this->upload($session, $rawBody, (string)($headers['content-type'] ?? '')),
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
				'com.atproto.server.getSession',
				'com.atproto.repo.createRecord', 'com.atproto.repo.putRecord', 'com.atproto.repo.deleteRecord',
				'com.atproto.repo.applyWrites', 'com.atproto.repo.uploadBlob', 'com.atproto.moderation.createReport',
			], true);
	}

	/**
	 * @throws XrpcException
	 */
	private function signedIn(array $headers): ClientSession {
		$session = $this->sessions->authenticate((string)($headers['authorization'] ?? ''));
		if ($session === null) {
			throw XrpcException::authenticationRequired();
		}
		if ($this->moderation->isSuspended($session->identity->actorId)) {
			throw new XrpcException(400, 'AccountTakedown', 'This account is suspended');
		}

		return $session;
	}

	/**
	 * @throws XrpcException
	 */
	private function upload(ClientSession $session, string $bytes, string $mime): array {
		if (strlen($bytes) > self::MAX_BLOB) {
			throw new XrpcException(413, 'BlobTooLarge', 'This file is too large');
		}

		return $this->writes->upload($session, $bytes, $mime);
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
