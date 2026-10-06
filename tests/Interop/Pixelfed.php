<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use RuntimeException;

/**
 * The Pixelfed on the other side, read through its own client API: the
 * Mastodon-compatible `/api/v1` and, for what only Pixelfed has, `/api/v1.1`.
 *
 * Its API rather than its database, because that is the answer a Pixelfed
 * user would get — a status its transformer will not render has not arrived.
 * Pixelfed caches what it renders, so a value that changes (a count, a
 * profile) is read with `await()` until it settles rather than once.
 */
class Pixelfed extends ApiClient {
	private string $selfId = '';

	public function __construct(
		string $baseUrl,
		private string $token,
	) {
		parent::__construct($baseUrl);
	}

	/** Whether this suite has a Pixelfed to talk to at all. */
	public static function fromEnvironment(): ?self {
		$base = (string)getenv('PIXELFED_BASE_URL');
		$token = (string)getenv('PIXELFED_TOKEN');
		if ($base === '' || $token === '') {
			return null;
		}

		return new self($base, $token);
	}

	protected function authHeaders(): array {
		return ['Authorization: Bearer ' . $this->token];
	}

	/** The host Pixelfed's own accounts are named under. */
	public function host(): string {
		return (string)parse_url($this->baseUrl, PHP_URL_HOST);
	}

	/** Pixelfed's id for the account the token belongs to. */
	public function selfId(): string {
		if ($this->selfId === '') {
			$this->selfId = (string)($this->get('/api/v1/accounts/verify_credentials')['id'] ?? '');
		}

		return $this->selfId;
	}

	/** `username@host` of the account the token belongs to. */
	public function selfHandle(): string {
		return (string)($this->get('/api/v1/accounts/verify_credentials')['username'] ?? '') . '@' . $this->host();
	}

	/**
	 * Pixelfed's id for one of our accounts, resolving it over the network —
	 * which already proves our webfinger and actor document are ones Pixelfed
	 * will fetch and store.
	 */
	public function resolveAccount(string $handle): string {
		$found = $this->get('/api/v2/search', [
			'q' => '@' . ltrim($handle, '@'),
			'type' => 'accounts',
			'resolve' => 'true',
		]);

		$id = (string)($found['accounts'][0]['id'] ?? '');
		if ($id === '') {
			throw new RuntimeException('Pixelfed could not resolve ' . $handle . ': ' . json_encode($found));
		}

		return $id;
	}

	/** @return array<string, mixed> Pixelfed's Account entity */
	public function account(string $id): array {
		return $this->get('/api/v1/accounts/' . $id);
	}

	public function follow(string $accountId): void {
		$this->post('/api/v1/accounts/' . $accountId . '/follow');
	}

	public function unfollow(string $accountId): void {
		$this->post('/api/v1/accounts/' . $accountId . '/unfollow');
	}

	/** @return array<string, mixed> */
	public function relationship(string $accountId): array {
		$relationships = $this->get('/api/v1/accounts/relationships', ['id' => [$accountId]]);

		return ($relationships[0] ?? []) + ['following' => false, 'followed_by' => false, 'requested' => false];
	}

	/**
	 * The top-level posts Pixelfed holds for one account.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function statusesOf(string $accountId): array {
		return $this->get('/api/v1/accounts/' . $accountId . '/statuses', ['limit' => '40']);
	}

	/**
	 * Waits for a post of `$accountId` whose ActivityPub id is `$uri`.
	 *
	 * @return array<string, mixed>|null
	 */
	public function awaitStatusOf(string $accountId, string $uri, int $seconds = self::WAIT_SECONDS): ?array {
		return $this->await(function () use ($accountId, $uri): ?array {
			foreach ($this->statusesOf($accountId) as $status) {
				if (($status['uri'] ?? '') === $uri) {
					return $status;
				}
			}

			return null;
		}, $seconds);
	}

	/** @return array<string, mixed>|null the status, or null when Pixelfed answers 404 */
	public function status(string $id): ?array {
		return $this->find('/api/v1/statuses/' . $id);
	}

	/**
	 * Waits for a status to be one Pixelfed no longer serves.
	 */
	public function awaitStatusGone(string $accountId, string $id, string $uri): bool {
		return $this->await(function () use ($accountId, $id, $uri): ?bool {
			if ($this->status($id) !== null) {
				return null;
			}
			foreach ($this->statusesOf($accountId) as $status) {
				if (($status['uri'] ?? '') === $uri) {
					return null;
				}
			}

			return true;
		}) === true;
	}

	/**
	 * Replies under a status, as Pixelfed threads them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function descendants(string $id): array {
		return $this->get('/api/v1/statuses/' . $id . '/context')['descendants'] ?? [];
	}

	/**
	 * Uploads one picture with its description, the way the app does.
	 */
	public function uploadPhoto(string $file, string $description): string {
		$media = $this->upload('/api/v1/media', $file, 'image/jpeg', ['description' => $description]);
		$id = (string)($media['id'] ?? '');
		if ($id === '') {
			throw new RuntimeException('Pixelfed refused the upload: ' . json_encode($media));
		}

		return $id;
	}

	/**
	 * Writes a status.
	 *
	 * @param array<string, mixed> $fields Mastodon's `POST /api/v1/statuses`
	 * @return array<string, mixed>
	 */
	public function publish(array $fields): array {
		$status = $this->post('/api/v1/statuses', $fields);
		if (($status['id'] ?? '') === '') {
			throw new RuntimeException('Pixelfed did not write the status: ' . json_encode($status));
		}

		return $status;
	}

	public function favourite(string $id): void {
		$this->post('/api/v1/statuses/' . $id . '/favourite');
	}

	public function deleteStatus(string $id): void {
		$this->delete('/api/v1/statuses/' . $id);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function notifications(): array {
		return $this->get('/api/v1/notifications', ['limit' => '40']);
	}

	/**
	 * Waits for a notification `$match` accepts.
	 *
	 * @param callable(array<string, mixed>): bool $match
	 * @return array<string, mixed>|null
	 */
	public function awaitNotification(callable $match): ?array {
		return $this->await(function () use ($match): ?array {
			foreach ($this->notifications() as $notification) {
				if ($match($notification)) {
					return $notification;
				}
			}

			return null;
		});
	}
}
