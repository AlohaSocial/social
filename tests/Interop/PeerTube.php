<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use RuntimeException;

/**
 * The PeerTube on the other side, read through its own REST API.
 *
 * PeerTube is the implementation this app most needed to be checked against
 * and the one it had never been checked against at all. It refuses a video it
 * cannot make sense of **silently and on its own side** — "Cannot find
 * associated video channel" goes into *their* log, and the delivery here
 * answers 204 and looks like a success — which is the exact shape of bug no
 * test on this side can see.
 *
 * Its REST API rather than its database: `/api/v1/videos` is the same answer a
 * PeerTube user would get, and a row its serialiser will not render has not
 * arrived either.
 */
class PeerTube extends RestClient {
	/**
	 * Ingest here is a queue and a job runner, and a video that is going to
	 * arrive arrives within a few seconds of the delivery.
	 */
	protected const WAIT_SECONDS = 60;
	protected const POLL_SECONDS = 2;

	private string $token = '';

	public function __construct(
		string $baseUrl,
		private string $username,
		private string $password,
	) {
		parent::__construct($baseUrl);
	}

	/** Whether this suite has a PeerTube to talk to at all. */
	public static function fromEnvironment(): ?self {
		$base = (string)getenv('PEERTUBE_BASE_URL');
		$user = (string)getenv('PEERTUBE_USER');
		$password = (string)getenv('PEERTUBE_PASSWORD');
		if ($base === '' || $user === '' || $password === '') {
			return null;
		}

		return new self($base, $user, $password);
	}

	/**
	 * The handle PeerTube knows this instance's account by.
	 *
	 * Resolving it through PeerTube's own search with `search-target=search-index`
	 * off — the local resolver — is what makes PeerTube fetch our actor, so
	 * this one call already proves the actor document we serve is one it will
	 * accept.
	 *
	 * @return array<string, mixed> the account as PeerTube describes it
	 */
	public function resolveAccount(string $handle): array {
		$found = $this->get('/api/v1/search/video-channels', ['search' => $handle, 'count' => '1'])
			+ ['data' => []];

		$account = $found['data'][0] ?? null;
		if (!is_array($account)) {
			// not every PeerTube resolves a channel through search; asking for
			// the actor directly is the other way in and the one that proves
			// the same thing
			$account = $this->get('/api/v1/video-channels/' . rawurlencode(ltrim($handle, '@')));
		}

		if (($account['name'] ?? '') === '') {
			throw new RuntimeException(
				'PeerTube could not resolve ' . $handle . ': ' . json_encode($found)
			);
		}

		return $account;
	}

	/** Makes PeerTube follow one of our channels, which is how a video reaches it. */
	public function follow(string $handle): void {
		$this->post('/api/v1/users/me/subscriptions', ['uri' => ltrim($handle, '@')]);
	}

	/**
	 * Every video PeerTube holds for a channel.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function videosOf(string $handle): array {
		$answer = $this->get(
			'/api/v1/video-channels/' . rawurlencode(ltrim($handle, '@')) . '/videos',
			['count' => '50', 'nsfw' => 'both']
		);

		$videos = $answer['data'] ?? [];

		return is_array($videos) ? $videos : [];
	}

	/**
	 * Waits for a video whose `url` is `$uri`, or null when it never arrives.
	 *
	 * @return array<string, mixed>|null
	 */
	public function awaitVideo(string $handle, string $uri): ?array {
		return $this->await(function () use ($handle, $uri): ?array {
			foreach ($this->videosOf($handle) as $video) {
				if (($video['url'] ?? '') === $uri) {
					// the list entry is a summary; the whole of it is what says
					// whether the description, the duration and the file
					// survived
					return $this->get('/api/v1/videos/' . rawurlencode((string)$video['uuid']));
				}
			}

			return null;
		});
	}

	/** Waits for a video to stop being there — what a `Delete` has to achieve. */
	public function awaitVideoGone(string $handle, string $uri): bool {
		return $this->await(function () use ($handle, $uri): ?bool {
			foreach ($this->videosOf($handle) as $video) {
				if (($video['url'] ?? '') === $uri) {
					return null;
				}
			}

			return true;
		}) === true;
	}

	/** Stops PeerTube's account following one of our channels or accounts. */
	public function unfollow(string $handle): void {
		$this->delete('/api/v1/users/me/subscriptions/' . rawurlencode(ltrim($handle, '@')));
	}

	/** Whether PeerTube's account follows a channel or account, by handle. */
	public function follows(string $handle): bool {
		$handle = ltrim($handle, '@');
		$answer = $this->get('/api/v1/users/me/subscriptions/exist', ['uris' => $handle]);

		return (bool)($answer[$handle] ?? false);
	}

	/**
	 * One of PeerTube's own channels.
	 *
	 * @return array<string, mixed>
	 */
	public function channel(string $name): array {
		return $this->get('/api/v1/video-channels/' . rawurlencode($name));
	}

	/**
	 * The actor ids following one of PeerTube's own channels; only the
	 * channel's owner may ask.
	 *
	 * @return string[]
	 */
	public function channelFollowers(string $name): array {
		$answer = $this->get('/api/v1/video-channels/' . rawurlencode($name) . '/followers', ['count' => '100']);

		$followers = [];
		foreach ((is_array($answer['data'] ?? null) ? $answer['data'] : []) as $follow) {
			$url = (string)($follow['follower']['url'] ?? '');
			if ($url !== '') {
				$followers[] = $url;
			}
		}

		return $followers;
	}

	/**
	 * Uploads a video to one of PeerTube's own channels, public at once.
	 *
	 * @return array{id: int, uuid: string, shortUUID: string}
	 */
	public function uploadVideo(int $channelId, string $file, string $name, string $description): array {
		$answer = $this->upload('/api/v1/videos/upload', [
			'channelId' => (string)$channelId,
			'name' => $name,
			'description' => $description,
			'privacy' => '1',
			'waitTranscoding' => 'false',
		], ['videofile' => [$file, 'video/mp4']]);

		$video = $answer['video'] ?? [];
		if (!is_array($video) || ($video['uuid'] ?? '') === '') {
			throw new RuntimeException('PeerTube did not take the upload: ' . json_encode($answer));
		}

		return [
			'id' => (int)$video['id'],
			'uuid' => (string)$video['uuid'],
			'shortUUID' => (string)($video['shortUUID'] ?? ''),
		];
	}

	/**
	 * One video, as a PeerTube user sees it.
	 *
	 * @return array<string, mixed>
	 */
	public function video(string $uuid): array {
		return $this->get('/api/v1/videos/' . rawurlencode($uuid));
	}

	/**
	 * Every comment on a video, threads and their replies, flattened.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function comments(string $uuid): array {
		$threads = $this->get('/api/v1/videos/' . rawurlencode($uuid) . '/comment-threads', ['count' => '100']);

		$comments = [];
		foreach ((is_array($threads['data'] ?? null) ? $threads['data'] : []) as $thread) {
			if (!is_array($thread)) {
				continue;
			}
			$comments[] = $thread;
			if ((int)($thread['totalReplies'] ?? 0) === 0) {
				continue;
			}

			$tree = $this->get('/api/v1/videos/' . rawurlencode($uuid) . '/comment-threads/' . (int)$thread['id']);
			$pending = is_array($tree['children'] ?? null) ? $tree['children'] : [];
			while ($pending !== []) {
				$node = array_shift($pending);
				if (is_array($node['comment'] ?? null)) {
					$comments[] = $node['comment'];
				}
				foreach ((is_array($node['children'] ?? null) ? $node['children'] : []) as $child) {
					$pending[] = $child;
				}
			}
		}

		return $comments;
	}

	/**
	 * Waits for a comment on a video whose text contains `$words`.
	 *
	 * @return array<string, mixed>|null
	 */
	public function awaitComment(string $uuid, string $words): ?array {
		return $this->await(function () use ($uuid, $words): ?array {
			foreach ($this->comments($uuid) as $comment) {
				if (str_contains((string)($comment['text'] ?? ''), $words)) {
					return $comment;
				}
			}

			return null;
		});
	}

	/**
	 * Replies to a comment on a video, as PeerTube's account.
	 *
	 * @return array<string, mixed> the new comment
	 */
	public function replyToComment(string $uuid, int $commentId, string $text): array {
		$answer = $this->post('/api/v1/videos/' . rawurlencode($uuid) . '/comments/' . $commentId, ['text' => $text]);

		return is_array($answer['comment'] ?? null) ? $answer['comment'] : [];
	}

	#[\Override]
	public function name(): string {
		return 'PeerTube';
	}

	#[\Override]
	protected function authorization(): string {
		return 'Bearer ' . $this->token();
	}

	/**
	 * A user token, fetched once.
	 *
	 * PeerTube's OAuth needs the instance's own client id and secret first,
	 * which it hands to anybody at `/api/v1/oauth-clients/local` — that is the
	 * documented way a client signs in and what its own apps do.
	 */
	private function token(): string {
		if ($this->token !== '') {
			return $this->token;
		}

		// unauthenticated: headers() asks for this very token
		$client = $this->exchange('GET', $this->baseUrl . '/api/v1/oauth-clients/local', null, [
			'Accept: application/json',
		]);
		$answer = $this->form('/api/v1/users/token', [
			'client_id' => (string)($client['client_id'] ?? ''),
			'client_secret' => (string)($client['client_secret'] ?? ''),
			'grant_type' => 'password',
			'response_type' => 'code',
			'username' => $this->username,
			'password' => $this->password,
		]);

		$token = (string)($answer['access_token'] ?? '');
		if ($token === '') {
			throw new RuntimeException('PeerTube would not issue a token: ' . json_encode($answer));
		}

		return $this->token = $token;
	}

	/**
	 * @param array<string, string> $fields
	 * @return array<mixed>
	 */
	private function form(string $path, array $fields): array {
		return $this->exchange('POST', $this->baseUrl . $path, http_build_query($fields), [
			'Content-Type: application/x-www-form-urlencoded',
		]);
	}
}
