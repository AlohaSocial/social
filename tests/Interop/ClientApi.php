<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use RuntimeException;

/**
 * A client of a Mastodon-shaped REST API.
 *
 * Both ends of the interop suite speak it: the Mastodon on the other side,
 * and this app itself, whose client API is Mastodon's. Reading this side
 * through the same API a phone app reads it through is what makes "it
 * arrived here" mean what a reader would mean by it — a row that the client
 * API will not render has not arrived either.
 */
abstract class ClientApi extends RestClient {
	/**
	 * Account ids already resolved, by side and handle: an id does not change
	 * within a run, and a search is rate limited on both sides.
	 *
	 * @var array<string, string>
	 */
	private static array $resolved = [];

	// --- what both sides answer the same way --------------------------------

	/**
	 * The id this side gives an account, resolving it over the network if it
	 * has not heard of it yet.
	 *
	 * `resolve=true` is what makes a server fetch the actor, so on the far side
	 * this one call already proves the actor document is one it accepts.
	 */
	public function resolveAccount(string $handle): string {
		return self::$resolved[$this->name() . ' ' . strtolower(ltrim($handle, '@'))] ??= $this->searchAccount($handle);
	}

	private function searchAccount(string $handle): string {
		$found = $this->get('/api/v2/search', [
			'q' => $handle,
			'type' => 'accounts',
			'resolve' => 'true',
			'limit' => '5',
		]);

		$wanted = strtolower(ltrim($handle, '@'));
		$accounts = is_array($found['accounts'] ?? null) ? $found['accounts'] : [];
		foreach ($accounts as $account) {
			if (is_array($account) && strtolower((string)($account['acct'] ?? '')) === $wanted) {
				return (string)$account['id'];
			}
		}

		$first = $accounts[0] ?? null;
		if (!is_array($first) || ($first['id'] ?? '') === '') {
			throw new RuntimeException(
				$this->name() . ' could not resolve ' . $handle . ': ' . json_encode($found)
			);
		}

		return (string)$first['id'];
	}

	/**
	 * A status this side holds, found by its ActivityPub id, fetching it from
	 * its origin if needed. Null when it cannot be had.
	 *
	 * @return array<string, mixed>|null
	 */
	public function resolveStatus(string $uri): ?array {
		$found = $this->get('/api/v2/search', [
			'q' => $uri,
			'type' => 'statuses',
			'resolve' => 'true',
			'limit' => '5',
		]);

		foreach ((is_array($found['statuses'] ?? null) ? $found['statuses'] : []) as $status) {
			if (is_array($status) && (($status['uri'] ?? '') === $uri || ($status['url'] ?? '') === $uri)) {
				return $status;
			}
		}

		return null;
	}

	/** Waits for a status to become resolvable here, by its ActivityPub id. */
	public function awaitResolvedStatus(string $uri): ?array {
		return $this->await(fn (): ?array => $this->resolveStatus($uri));
	}

	/** @return array<string, mixed> the relationship now */
	public function follow(string $accountId): array {
		return $this->post('/api/v1/accounts/' . rawurlencode($accountId) . '/follow');
	}

	/** @return array<string, mixed> the relationship now */
	public function unfollow(string $accountId): array {
		return $this->post('/api/v1/accounts/' . rawurlencode($accountId) . '/unfollow');
	}

	/**
	 * How the token's account stands to another.
	 *
	 * @return array<string, mixed>
	 */
	public function relationship(string $accountId): array {
		$relationships = $this->get('/api/v1/accounts/relationships', ['id[]' => $accountId]);

		return (is_array($relationships[0] ?? null) ? $relationships[0] : [])
			+ ['following' => false, 'followed_by' => false, 'requested' => false];
	}

	/**
	 * Waits until one field of the relationship has the value wanted.
	 *
	 * @return bool whether it got there
	 */
	public function awaitRelationship(string $accountId, string $field, bool $wanted, int $seconds = 0): bool {
		return $this->await(
			fn (): ?bool => ((bool)($this->relationship($accountId)[$field] ?? false) === $wanted) ? true : null,
			$seconds
		) === true;
	}

	/**
	 * @return array<string, mixed> the account as this side describes it
	 */
	public function account(string $accountId): array {
		return $this->get('/api/v1/accounts/' . rawurlencode($accountId));
	}

	/**
	 * The statuses this side shows for one account.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function statusesOf(string $accountId): array {
		return $this->listOf($this->get('/api/v1/accounts/' . rawurlencode($accountId) . '/statuses', ['limit' => '40']));
	}

	/**
	 * Waits for a status of one account whose ActivityPub id is `$uri`.
	 *
	 * @return array<string, mixed>|null
	 */
	public function awaitStatus(string $accountId, string $uri): ?array {
		return $this->await(fn (): ?array => self::findByUri($this->statusesOf($accountId), $uri));
	}

	/** Waits for a status to stop being there — what a `Delete` has to achieve. */
	public function awaitStatusGone(string $accountId, string $uri): bool {
		return $this->await(
			fn (): ?bool => (self::findByUri($this->statusesOf($accountId), $uri) === null) ? true : null
		) === true;
	}

	/**
	 * One status by this side's id, or null when it answers 404.
	 *
	 * @return array<string, mixed>|null
	 */
	public function status(string $id): ?array {
		return $this->getOrNull('/api/v1/statuses/' . rawurlencode($id));
	}

	/**
	 * Waits for a status to satisfy a condition, read afresh each time.
	 *
	 * @param callable(array<string, mixed>): bool $condition
	 * @return array<string, mixed>|null the status once it does
	 */
	public function awaitStatusThat(string $id, callable $condition, int $seconds = 0): ?array {
		return $this->await(function () use ($id, $condition): ?array {
			$status = $this->status($id);

			return ($status !== null && $condition($status)) ? $status : null;
		}, $seconds);
	}

	/**
	 * A timeline: `home`, `public`, or `tag/<name>`.
	 *
	 * @param array<string, string> $query
	 * @return array<int, array<string, mixed>>
	 */
	public function timeline(string $which, array $query = []): array {
		return $this->listOf($this->get('/api/v1/timelines/' . $which, $query + ['limit' => '40']));
	}

	/**
	 * Waits for a status with ActivityPub id `$uri` on a timeline.
	 *
	 * @param array<string, string> $query
	 * @return array<string, mixed>|null
	 */
	public function awaitOnTimeline(string $which, string $uri, array $query = []): ?array {
		return $this->await(fn (): ?array => self::findByUri($this->timeline($which, $query), $uri));
	}

	/**
	 * The token account's notifications, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function notifications(): array {
		return $this->listOf($this->get('/api/v1/notifications', ['limit' => '40']));
	}

	/**
	 * Waits for a notification of one type that satisfies a condition.
	 *
	 * @param callable(array<string, mixed>): bool $condition
	 * @return array<string, mixed>|null
	 */
	public function awaitNotification(string $type, callable $condition): ?array {
		return $this->await(function () use ($type, $condition): ?array {
			foreach ($this->notifications() as $notification) {
				if (($notification['type'] ?? '') === $type && $condition($notification)) {
					return $notification;
				}
			}

			return null;
		});
	}

	/**
	 * Publishes a status as the token's account.
	 *
	 * @param array<string, mixed> $fields anything besides the text
	 * @return array<string, mixed> the status as written
	 */
	public function publish(string $text, array $fields = []): array {
		$status = $this->post('/api/v1/statuses', ['status' => $text] + $fields);
		if (($status['id'] ?? '') === '') {
			throw new RuntimeException($this->name() . ' did not write the status: ' . json_encode($status));
		}

		return $status;
	}

	/**
	 * Uploads one file as a media attachment and waits until it is processed.
	 *
	 * @return string the attachment id to put in `media_ids`
	 */
	public function uploadMedia(string $path, string $mimeType, string $description): string {
		$media = $this->upload('/api/v2/media', ['description' => $description], ['file' => [$path, $mimeType]]);
		$id = (string)($media['id'] ?? '');
		if ($id === '') {
			throw new RuntimeException($this->name() . ' did not take the upload: ' . json_encode($media));
		}

		// an attachment still being processed answers 206 without a url
		$this->await(fn (): ?bool => (($this->getOrNull('/api/v1/media/' . $id)['url'] ?? null) !== null) ? true : null);

		return $id;
	}

	/**
	 * The first entry of `$statuses` whose ActivityPub id is `$uri`.
	 *
	 * @param array<int, array<string, mixed>> $statuses
	 * @return array<string, mixed>|null
	 */
	public static function findByUri(array $statuses, string $uri): ?array {
		foreach ($statuses as $status) {
			if (($status['uri'] ?? '') === $uri) {
				return $status;
			}
		}

		return null;
	}
}
