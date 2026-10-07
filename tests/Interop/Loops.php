<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use CURLFile;
use RuntimeException;

/**
 * One account on the Loops on the other side, driven through Loops' own API.
 *
 * Loops' REST API is its own rather than Mastodon's (`/api/v1/studio/upload`,
 * `/api/v1/video/like/{id}`, `/api/v1/account/follow/{id}`), and every route
 * the suite needs takes a Passport bearer token, which the workflow mints for
 * each account. Its API rather than its database for the same reason as the
 * Mastodon helper: what a Loops user would see is what "it arrived" means.
 *
 * Every call fails loudly with what came back, and everything that happens on
 * the other side in its own time is waited for with a bound.
 */
class Loops {
	/**
	 * How long to keep asking. A video crosses two queues and an ffmpeg run on
	 * the way in, which is seconds on a runner, not milliseconds.
	 */
	public const WAIT_SECONDS = 90;
	private const POLL_SECONDS = 2;

	public function __construct(
		private string $baseUrl,
		private string $token,
	) {
		$this->baseUrl = rtrim($baseUrl, '/');
	}

	/**
	 * The account behind one token variable, or null when the suite has no
	 * Loops to talk to.
	 */
	public static function fromEnvironment(string $tokenVariable = 'LOOPS_TOKEN'): ?self {
		$base = (string)getenv('LOOPS_BASE_URL');
		$token = (string)getenv($tokenVariable);
		if ($base === '' || $token === '') {
			return null;
		}

		return new self($base, $token);
	}

	/** The host this Loops answers on, which is the domain of its handles. */
	public function host(): string {
		return (string)parse_url($this->baseUrl, PHP_URL_HOST);
	}

	/**
	 * This token's own account.
	 *
	 * @return array<string, mixed>
	 */
	public function self(): array {
		return $this->data($this->get('/api/v1/account/info/self'));
	}

	/** This token's own handle, as another server addresses it. */
	public function handle(): string {
		return '@' . $this->self()['username'] . '@' . $this->host();
	}

	/**
	 * Loops' profile id for an account of ours, resolving it over webfinger if
	 * Loops has not met it yet.
	 *
	 * The lookup is what makes Loops fetch our actor document, so this already
	 * proves Loops accepts the actor we serve.
	 */
	public function resolve(string $handle): string {
		$found = $this->post('/api/v1/search/remote', ['q' => '@' . ltrim($handle, '@')]);
		$user = $found['data']['users'][0] ?? null;
		if (!is_array($user) || ($user['id'] ?? '') === '') {
			throw new RuntimeException('Loops could not resolve ' . $handle . ': ' . json_encode($found));
		}

		return (string)$user['id'];
	}

	/** @return array<string, mixed> */
	public function account(string $profileId): array {
		return $this->data($this->get('/api/v1/account/info/' . $profileId));
	}

	public function follow(string $profileId): void {
		$this->post('/api/v1/account/follow/' . $profileId);
	}

	public function unfollow(string $profileId): void {
		$this->post('/api/v1/account/unfollow/' . $profileId);
	}

	/**
	 * How this account stands to another: `following` (accepted),
	 * `pending_follow_request` and `followed_by`.
	 *
	 * @return array<string, mixed>
	 */
	public function relationship(string $profileId): array {
		return $this->data($this->get('/api/v1/account/state/' . $profileId));
	}

	/**
	 * The ids of the accounts following one account.
	 *
	 * @return string[]
	 */
	public function followerIds(string $profileId): array {
		return array_map(
			static fn (array $account): string => (string)($account['id'] ?? ''),
			array_filter($this->list($this->get('/api/v1/account/followers/' . $profileId)), 'is_array')
		);
	}

	/**
	 * Uploads a video as this account and waits for it to be published, which
	 * is when Loops federates it.
	 *
	 * The upload answers before the video exists as a post: a thumbnail job,
	 * an optimize job and a completion job follow on its queue, and the
	 * `Create` goes out from the last one. So the post is found afterwards, on
	 * the account's own feed, by its caption.
	 *
	 * @return array<string, mixed> the published video, as Loops' API shows it
	 */
	public function uploadVideo(string $path, string $caption, bool $comments = true): array {
		$this->request('POST', $this->baseUrl . '/api/v1/studio/upload', [
			'video' => new CURLFile($path, 'video/mp4', 'interop.mp4'),
			'description' => $caption,
			'comment_state' => $comments ? '4' : '0',
		], true);

		$profileId = (string)$this->self()['id'];
		$video = $this->await(function () use ($profileId, $caption): ?array {
			foreach ($this->videosOf($profileId) as $video) {
				if (($video['caption'] ?? '') === $caption) {
					return $video;
				}
			}

			return null;
		});
		if ($video === null) {
			throw new RuntimeException('Loops never published the video "' . $caption . '"');
		}

		return $video;
	}

	/**
	 * The videos one account has published, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function videosOf(string $profileId): array {
		return array_values(array_filter(
			$this->list($this->get('/api/v1/feed/account/' . $profileId)),
			'is_array'
		));
	}

	/**
	 * Waits for a video of `$profileId` whose caption contains `$words`.
	 *
	 * @return array<string, mixed>|null
	 */
	public function awaitVideoOf(string $profileId, string $words): ?array {
		return $this->await(function () use ($profileId, $words): ?array {
			foreach ($this->videosOf($profileId) as $video) {
				if (str_contains((string)($video['caption'] ?? ''), $words)) {
					return $video;
				}
			}

			return null;
		});
	}

	/**
	 * One video, or null where Loops answers that it has none.
	 *
	 * @return array<string, mixed>|null
	 */
	public function video(string $videoId): ?array {
		try {
			$video = $this->data($this->get('/api/v1/video/' . $videoId));
		} catch (RuntimeException $e) {
			if ($e->getCode() === 404) {
				return null;
			}

			throw $e;
		}

		return ($video === []) ? null : $video;
	}

	/** Edits a video's caption, keeping comments open. */
	public function editVideo(string $videoId, string $caption): void {
		$this->post('/api/v1/video/edit/' . $videoId, ['caption' => $caption, 'can_comment' => true]);
	}

	public function deleteVideo(string $videoId): void {
		$this->post('/api/v1/video/delete/' . $videoId);
	}

	public function like(string $videoId): void {
		$this->post('/api/v1/video/like/' . $videoId);
	}

	public function unlike(string $videoId): void {
		$this->post('/api/v1/video/unlike/' . $videoId);
	}

	/**
	 * Comments on a video, or answers a comment on it when `$parentId` is set.
	 *
	 * @return array<string, mixed> the comment as Loops stored it
	 */
	public function comment(string $videoId, string $words, ?string $parentId = null): array {
		$body = ['comment' => $words];
		if ($parentId !== null) {
			$body['parent_id'] = $parentId;
		}

		$made = $this->list($this->post('/api/v1/video/comments/' . $videoId, $body));
		if (!is_array($made[0] ?? null)) {
			throw new RuntimeException('Loops made no comment: ' . json_encode($made));
		}

		return $made[0];
	}

	/**
	 * The top-level comments on a video.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function comments(string $videoId): array {
		return array_values(array_filter($this->list($this->get('/api/v1/video/comments/' . $videoId)), 'is_array'));
	}

	/**
	 * The answers to one comment.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function replies(string $videoId, string $commentId): array {
		return array_values(array_filter(
			$this->list($this->get('/api/v1/video/comments/' . $videoId . '/replies', ['cr' => $commentId])),
			'is_array'
		));
	}

	/**
	 * This account's notifications, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function notifications(): array {
		return array_values(array_filter($this->list($this->get('/api/v1/account/notifications')), 'is_array'));
	}

	/**
	 * Waits for a notification of `$type` from `$actorId` and answers it.
	 *
	 * @return array<string, mixed>|null
	 */
	public function awaitNotification(string $type, string $actorId): ?array {
		return $this->await(function () use ($type, $actorId): ?array {
			foreach ($this->notifications() as $notification) {
				if (($notification['type'] ?? '') === $type
					&& (string)($notification['actor']['id'] ?? '') === $actorId) {
					return $notification;
				}
			}

			return null;
		});
	}

	/** Changes this account's display name and bio. */
	public function updateProfile(string $name, string $bio): void {
		$this->post('/api/v1/account/settings/bio', ['name' => $name, 'bio' => $bio]);
	}

	/**
	 * Waits for a condition the other side satisfies in its own time.
	 *
	 * @template T
	 * @param callable(): ?T $probe
	 * @return ?T
	 */
	public function await(callable $probe, int $seconds = self::WAIT_SECONDS) {
		$until = time() + $seconds;
		do {
			$answer = $probe();
			if ($answer !== null) {
				return $answer;
			}
			sleep(self::POLL_SECONDS);
		} while (time() < $until);

		return null;
	}

	/**
	 * Loops wraps most answers in `data`; a few routes answer bare.
	 *
	 * @param array<mixed> $answer
	 * @return array<mixed>
	 */
	private function data(array $answer): array {
		$data = $answer['data'] ?? $answer;

		return is_array($data) ? $data : [];
	}

	/**
	 * A list answer, wrapped in `data` or not.
	 *
	 * @param array<mixed> $answer
	 * @return array<mixed>
	 */
	private function list(array $answer): array {
		return array_is_list($answer) ? $answer : $this->data($answer);
	}

	/**
	 * @param array<string, string> $query
	 * @return array<mixed>
	 */
	public function get(string $path, array $query = []): array {
		$url = $this->baseUrl . $path;
		if ($query !== []) {
			$url .= '?' . http_build_query($query);
		}

		return $this->request('GET', $url);
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<mixed>
	 */
	public function post(string $path, array $body = []): array {
		return $this->request('POST', $this->baseUrl . $path, $body);
	}

	/**
	 * @param array<string, mixed>|null $body
	 * @return array<mixed>
	 */
	private function request(string $method, string $url, ?array $body = null, bool $multipart = false): array {
		$handle = curl_init($url);
		$headers = ['Authorization: Bearer ' . $this->token, 'Accept: application/json'];

		curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
		curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($handle, CURLOPT_TIMEOUT, 120);
		if ($body !== null) {
			if ($multipart) {
				curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
			} else {
				$headers[] = 'Content-Type: application/json';
				curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
			}
		}
		curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);

		$answer = curl_exec($handle);
		$status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
		$error = curl_error($handle);
		curl_close($handle);

		if ($answer === false) {
			throw new RuntimeException('Loops: ' . $method . ' ' . $url . ' failed: ' . $error);
		}

		if ($status >= 400) {
			throw new RuntimeException(
				'Loops: ' . $method . ' ' . $url . ' answered ' . $status . ': ' . substr((string)$answer, 0, 500),
				$status
			);
		}

		$decoded = json_decode((string)$answer, true);

		return is_array($decoded) ? $decoded : [];
	}
}
