<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Client;

use OCA\Social\Atproto\Move\InboundMoveService;
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
 * there. An account moving here (`createAccount`, and the session that
 * makes) is `InboundMoveService`'s.
 */
class ClientXrpc {
	public const UPLOAD = 'com.atproto.repo.uploadBlob';
	public const IMPORT = 'com.atproto.repo.importRepo';
	private const WRITES = ['com.atproto.repo.createRecord', 'com.atproto.repo.putRecord', 'com.atproto.repo.deleteRecord', 'com.atproto.repo.applyWrites'];
	/** what the session of an account moving here asks, beside what any session may */
	private const MOVE_QUERIES = [
		'com.atproto.server.checkAccountStatus', 'com.atproto.identity.getRecommendedDidCredentials', 'com.atproto.repo.listMissingBlobs',
	];
	private const MOVE_PROCEDURES = [
		'com.atproto.server.activateAccount', 'com.atproto.identity.submitPlcOperation',
	];
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
		private InboundMoveService $inbound,
	) {
	}

	/**
	 * A query a signed-in app makes, or null when it is not one.
	 *
	 * @param array<string, string> $headers lower-cased names
	 * @throws XrpcException
	 */
	public function query(string $method, string $rawQuery, array $headers): array|XrpcBytes|null {
		if (!$this->config->isEnabled()) {
			return null;
		}
		$authorization = (string)($headers['authorization'] ?? '');
		parse_str($rawQuery, $params);
		if ($this->inbound->owns($authorization)) {
			return $this->isClientMethod($method) || in_array($method, self::MOVE_QUERIES, true)
				? $this->inbound->query($method, $params, $authorization)
				: null;
		}
		if (!$this->isClientMethod($method)) {
			return null;
		}
		$session = $this->signedIn($headers, $method, 'GET');
		$permissions = $session->permissions();
		$allowed = match ($method) {
			'com.atproto.server.getSession' => true,
			'com.atproto.server.getServiceAuth' => $permissions->mayCall(self::param($params, 'lxm'), self::param($params, 'aud')),
			default => $permissions->mayCall($method, $this->audience($headers)),
		};
		self::assertAllowed($allowed, 'call ' . $method);

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
		$authorization = (string)($headers['authorization'] ?? '');
		if ($method === 'com.atproto.server.createAccount') {
			return $this->inbound->createAccount($authorization, self::json($rawBody), $ip);
		}
		if ($this->inbound->owns($authorization)) {
			return $this->isClientMethod($method) || in_array($method, self::MOVE_PROCEDURES, true)
				|| in_array($method, ['com.atproto.server.refreshSession', 'com.atproto.server.deleteSession'], true)
				? $this->inbound->procedure($method, $rawBody === '' ? [] : self::json($rawBody), $authorization)
				: null;
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
		$writes = in_array($method, self::WRITES, true) ? self::writesOf($method, self::json($rawBody)) : null;
		if ($writes !== null) {
			foreach ($writes as [$collection, $actions]) {
				$allowed = array_filter($actions, static fn (string $action): bool => $session->permissions()->mayWrite($collection, $action)) !== [];
				self::assertAllowed($allowed, implode(' or ', $actions) . ' records of ' . $collection);
			}
		} else {
			self::assertAllowed($session->permissions()->mayCall($method, $this->audience($headers)), 'call ' . $method);
		}

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
		if ($this->inbound->owns((string)($headers['authorization'] ?? ''))) {
			return $this->inbound->upload($path, (string)$headers['authorization'], (string)($headers['content-type'] ?? ''));
		}
		$session = $this->grants->uploader((string)($headers['authorization'] ?? ''));
		if ($session === null) {
			$session = $this->signedIn($headers, self::UPLOAD, 'POST');
			self::assertAllowed($session->permissions()->mayUpload((string)($headers['content-type'] ?? '')), 'upload files of this type');
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
	 * `com.atproto.repo.importRepo`, from a file the CAR was copied to: only
	 * for an account moving here.
	 *
	 * @param array<string, string> $headers lower-cased names
	 * @throws XrpcException
	 */
	public function importRepo(string $path, array $headers): array {
		if (!$this->config->isEnabled()) {
			throw XrpcException::notImplemented(self::IMPORT);
		}
		$authorization = (string)($headers['authorization'] ?? '');
		if ($this->inbound->owns($authorization)) {
			return $this->inbound->importRepo($path, $authorization);
		}
		$this->signedIn($headers, self::IMPORT, 'POST');

		throw XrpcException::invalidRequest('A repository is imported here only when an account moves here');
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

		return $session;
	}

	/**
	 * The service a call is for: what `atproto-proxy` names, else the
	 * AppView's.
	 */
	private function audience(array $headers): string {
		$proxy = trim((string)($headers['atproto-proxy'] ?? ''));

		return $proxy !== '' ? $proxy : $this->config->appViewDid() . '#bsky_appview';
	}

	/**
	 * The collections a write touches, each with the actions any one of which
	 * allows it: `putRecord` both makes and replaces.
	 *
	 * @return list<array{0: string, 1: list<string>}>
	 */
	private static function writesOf(string $method, array $body): array {
		$collection = (string)($body['collection'] ?? '');

		switch ($method) {
			case 'com.atproto.repo.createRecord':
				return [[$collection, ['create']]];
			case 'com.atproto.repo.putRecord':
				return [[$collection, ['update', 'create']]];
			case 'com.atproto.repo.deleteRecord':
				return [[$collection, ['delete']]];
		}
		$writes = [];
		foreach (is_array($body['writes'] ?? null) ? $body['writes'] : [] as $write) {
			$write = is_array($write) ? $write : [];
			$action = match ((string)($write['$type'] ?? '')) {
				'com.atproto.repo.applyWrites#create' => 'create',
				'com.atproto.repo.applyWrites#update' => 'update',
				default => 'delete',
			};
			$writes[] = [(string)($write['collection'] ?? ''), [$action]];
		}

		return $writes;
	}

	/**
	 * @throws XrpcException
	 */
	private static function assertAllowed(bool $allowed, string $what): void {
		if (!$allowed) {
			throw new XrpcException(403, 'InsufficientScope', 'This app was not allowed to ' . $what);
		}
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
