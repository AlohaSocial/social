<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use RuntimeException;

/**
 * This app's own client API, asked over HTTP as a signed-in Nextcloud user.
 *
 * The same routes the web page and a Mastodon client use, so a post written
 * here takes the path a real one does — the upload, the Exif strip, the
 * `Create` built for the wire — and what is read back is what the reader's
 * timeline would show. Basic auth with the `OCS-APIRequest` header is how a
 * script signs in to Nextcloud without a browser session.
 */
class AlohaApi extends ApiClient {
	public function __construct(
		string $cloudUrl,
		private string $user,
		private string $password,
	) {
		parent::__construct(rtrim($cloudUrl, '/') . '/index.php/apps/social');
	}

	/** Whether this suite knows whom to sign in as. */
	public static function fromEnvironment(): ?self {
		$url = (string)getenv('NEXTCLOUD_URL');
		$user = (string)getenv('NEXTCLOUD_USER');
		$password = (string)getenv('NEXTCLOUD_PASSWORD');
		if ($url === '' || $user === '' || $password === '') {
			return null;
		}

		return new self($url, $user, $password);
	}

	public function user(): string {
		return $this->user;
	}

	protected function authHeaders(): array {
		return [
			'Authorization: Basic ' . base64_encode($this->user . ':' . $this->password),
			'OCS-APIRequest: true',
		];
	}

	/** Our id for a remote account, fetching it if this is the first time. */
	public function resolveAccount(string $handle): string {
		$found = $this->get('/api/v2/search', [
			'q' => '@' . ltrim($handle, '@'),
			'type' => 'accounts',
			'resolve' => 'true',
		]);

		$id = (string)($found['accounts'][0]['id'] ?? '');
		if ($id === '') {
			throw new RuntimeException('this side could not resolve ' . $handle . ': ' . json_encode($found));
		}

		return $id;
	}

	/** @return array<string, mixed> */
	public function relationship(string $accountId): array {
		$relationships = $this->get('/api/v1/accounts/relationships', ['id' => [$accountId]]);

		return ($relationships[0] ?? []) + ['following' => false, 'followed_by' => false, 'requested' => false];
	}

	/**
	 * Uploads one picture with its description.
	 */
	public function uploadPhoto(string $file, string $description): string {
		$media = $this->upload('/api/v2/media', $file, 'image/jpeg', ['description' => $description]);
		$id = (string)($media['id'] ?? '');
		if ($id === '') {
			throw new RuntimeException('the upload was refused: ' . json_encode($media));
		}

		return $id;
	}

	/**
	 * Writes a status through `POST /api/v1/statuses`.
	 *
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	public function publish(array $fields): array {
		$status = $this->post('/api/v1/statuses', $fields);
		if (($status['id'] ?? '') === '' || ($status['uri'] ?? '') === '') {
			throw new RuntimeException('the status was not written: ' . json_encode($status));
		}

		return $status;
	}

	/** @return array<string, mixed>|null */
	public function status(string $id): ?array {
		return $this->find('/api/v1/statuses/' . $id);
	}

	/**
	 * A timeline, as the page asks for it.
	 *
	 * @param array<string, mixed> $query
	 * @return array<int, array<string, mixed>>
	 */
	public function timeline(string $timeline, array $query = []): array {
		return $this->get('/api/v1/timelines/' . $timeline, $query + ['limit' => '40']);
	}

	/**
	 * The Photos page: the home timeline narrowed to posts with a picture.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function photos(): array {
		return $this->timeline('home', ['only_media' => 'true', 'media_type' => 'image']);
	}

	/**
	 * Waits for a status whose ActivityPub id is `$uri` to be on a list.
	 *
	 * @param callable(): array<int, array<string, mixed>> $list
	 * @return array<string, mixed>|null
	 */
	public function awaitOn(callable $list, string $uri, int $seconds = self::WAIT_SECONDS): ?array {
		return $this->await(static function () use ($list, $uri): ?array {
			foreach ($list() as $status) {
				if (($status['uri'] ?? '') === $uri) {
					return $status;
				}
			}

			return null;
		}, $seconds);
	}

	/**
	 * Replies under a status, as this side threads them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function descendants(string $id): array {
		return $this->get('/api/v1/statuses/' . $id . '/context')['descendants'] ?? [];
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
