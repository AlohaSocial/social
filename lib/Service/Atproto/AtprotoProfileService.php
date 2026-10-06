<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\Atproto;

use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Details;

/** Resolves a Bluesky handle and imports its author feed into shared rows. */
class AtprotoProfileService {
	public function __construct(
		private AtprotoClient $client,
		private AtprotoIdentity $identity,
		private AtprotoIngress $ingress,
		private AtprotoEngagementService $engagement,
	) {
	}

	/** @return array{profile: array<string, mixed>, account: array<string, mixed>, statuses: list<Stream>, nextCursor: string} */
	public function read(string $handle, int $limit = 20, ?string $viewerId = null, string $cursor = ''): array {
		$resolved = $this->identity->resolve($handle);
		$profile = [
			'did' => $resolved['did'],
			'handle' => $resolved['handle'],
		];
		try {
			$profile = array_merge($profile, $this->client->get('app.bsky.actor.getProfile', [
				'actor' => $resolved['did'],
			]));
		} catch (AtprotoException) {
			// A PDS can be valid while the public appview is temporarily behind.
		}

		try {
			$repoProfile = $this->identity->profile($resolved['did'], $resolved['pds']);
			foreach (['displayName', 'description', 'avatar', 'banner'] as $field) {
				if ((!isset($profile[$field]) || $profile[$field] === '') && isset($repoProfile[$field]) && $repoProfile[$field] !== '') {
					$profile[$field] = $repoProfile[$field];
				}
			}
		} catch (\Throwable) {
		}

		if (isset($profile['avatar'])) {
			$profile['avatar'] = $this->identity->blobUrl($resolved['did'], $profile['avatar'], $resolved['pds']);
		}
		if (isset($profile['banner'])) {
			$profile['banner'] = $this->identity->blobUrl($resolved['did'], $profile['banner'], $resolved['pds']);
		}

		$actor = $this->identity->actor($resolved['did'], $resolved['handle'], $profile, $resolved['pds']);
		$actor->setExportFormat(ACore::FORMAT_LOCAL);

		$params = [
			'actor' => $resolved['did'],
			'limit' => min(max($limit, 1), 50),
		];
		if ($cursor !== '') {
			$params['cursor'] = $cursor;
		}
		$answer = $this->client->get('app.bsky.feed.getAuthorFeed', $params);
		$statuses = [];
		foreach ((array)($answer['feed'] ?? []) as $entry) {
			$post = is_array($entry['post'] ?? null) ? $entry['post'] : [];
			$uri = (string)($post['uri'] ?? '');
			if (!str_starts_with($uri, 'at://')) {
				continue;
			}
			try {
				$status = $this->ingress->fetch($this->localId($uri), 0);
				$this->applyCounts($status, $post);
				$this->applyLabels($status, $post);
				if ($viewerId !== null) {
					try {
						$state = $this->engagement->viewerState($viewerId, $status);
						$status->setViewerEngagement($state['liked'], $state['reposted']);
					} catch (AtprotoException) {
						// A temporary PDS failure must not hide a public profile.
					}
				}
				$status->setExportFormat(ACore::FORMAT_LOCAL);
				$statuses[] = $status;
			} catch (\Throwable) {
				// One deleted or malformed record must not hide the rest of a profile.
			}
		}

		return [
			'profile' => $profile,
			'account' => $actor->exportAsLocal(),
			'statuses' => $statuses,
			'nextCursor' => (string)($answer['cursor'] ?? ''),
		];
	}

	/** @return array{users: list<array<string, mixed>>, nextCursor: string} */
	public function followers(string $handle, int $limit = 20, ?string $viewerId = null, string $cursor = ''): array {
		$resolved = $this->identity->resolve($handle);
		$params = [
			'actor' => $resolved['did'],
			'limit' => min(max($limit, 1), 50),
		];
		if ($cursor !== '') {
			$params['cursor'] = $cursor;
		}

		$answer = $this->client->get('app.bsky.graph.getFollowers', $params);
		$users = [];
		foreach ((array)($answer['followers'] ?? []) as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			$users[] = $this->formatUser($entry, $resolved['pds'], $viewerId);
		}

		return [
			'users' => $users,
			'nextCursor' => (string)($answer['cursor'] ?? ''),
		];
	}

	/** @return array{users: list<array<string, mixed>>, nextCursor: string} */
	public function following(string $handle, int $limit = 20, ?string $viewerId = null, string $cursor = ''): array {
		$resolved = $this->identity->resolve($handle);
		$params = [
			'actor' => $resolved['did'],
			'limit' => min(max($limit, 1), 50),
		];
		if ($cursor !== '') {
			$params['cursor'] = $cursor;
		}

		$answer = $this->client->get('app.bsky.graph.getFollows', $params);
		$users = [];
		foreach ((array)($answer['follows'] ?? []) as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			$users[] = $this->formatUser($entry, $resolved['pds'], $viewerId);
		}

		return [
			'users' => $users,
			'nextCursor' => (string)($answer['cursor'] ?? ''),
		];
	}

	/**
	 * Feed generators on Bluesky / ATProto.
	 *
	 * @return array{statuses: list<array<string, mixed>>, nextCursor: string, feed: array<string, string>}
	 */
	public function feed(string $feedKey, int $limit = 20, ?string $viewerId = null, string $cursor = ''): array {
		$generators = [
			'discover' => [
				'uri' => 'at://did:plc:z72i7hdynmk6r22z27h6tvur/app.bsky.feed.generator/whats-hot',
				'title' => 'Discover',
			],
			'popular-with-friends' => [
				'uri' => 'at://did:plc:z72i7hdynmk6r22z27h6tvur/app.bsky.feed.generator/with-friends',
				'title' => 'Popular with friends',
			],
			'mutuals' => [
				'uri' => 'at://did:plc:z72i7hdynmk6r22z27h6tvur/app.bsky.feed.generator/mutuals',
				'title' => 'Mutuals',
			],
			'science' => [
				'uri' => 'at://did:plc:vpkhqbfspuxqn2q8tfpnqkyr/app.bsky.feed.generator/science',
				'title' => 'Science',
			],
		];

		$feedInfo = $generators[$feedKey] ?? [
			'uri' => str_starts_with($feedKey, 'at://') ? $feedKey : 'at://did:plc:z72i7hdynmk6r22z27h6tvur/app.bsky.feed.generator/whats-hot',
			'title' => ucfirst(str_replace('-', ' ', $feedKey)),
		];

		$params = [
			'feed' => $feedInfo['uri'],
			'limit' => min(max($limit, 1), 50),
		];
		if ($cursor !== '') {
			$params['cursor'] = $cursor;
		}

		$answer = $this->client->get('app.bsky.feed.getFeed', $params);
		$statuses = [];
		foreach ((array)($answer['feed'] ?? []) as $entry) {
			$post = is_array($entry['post'] ?? null) ? $entry['post'] : [];
			$uri = (string)($post['uri'] ?? '');
			if (!str_starts_with($uri, 'at://')) {
				continue;
			}
			try {
				$status = $this->ingress->fetch($this->localId($uri), 0);
				$this->applyCounts($status, $post);
				$this->applyLabels($status, $post);
				if ($viewerId !== null) {
					try {
						$state = $this->engagement->viewerState($viewerId, $status);
						$status->setViewerEngagement($state['liked'], $state['reposted']);
					} catch (AtprotoException) {
					}
				}
				$status->setExportFormat(ACore::FORMAT_LOCAL);
				$statuses[] = $status->exportAsLocal();
			} catch (\Throwable) {
			}
		}

		return [
			'statuses' => $statuses,
			'nextCursor' => (string)($answer['cursor'] ?? ''),
			'feed' => $feedInfo,
		];
	}

	/** @param array<string, mixed> $entry */
	private function formatUser(array $entry, string $pds, ?string $viewerId = null): array {
		$did = (string)($entry['did'] ?? '');
		$handle = (string)($entry['handle'] ?? $did);
		$avatar = $entry['avatar'] ?? null;
		$avatarUrl = is_string($avatar) ? $avatar : $this->identity->blobUrl($did, $avatar, $pds);
		$banner = $entry['banner'] ?? null;
		$bannerUrl = is_string($banner) ? $banner : $this->identity->blobUrl($did, $banner, $pds);

		$following = false;
		if (isset($entry['viewer']['following'])) {
			$following = true;
		} elseif ($viewerId !== null && $did !== '') {
			try {
				$following = $this->engagement->isFollowing($viewerId, $did);
			} catch (\Throwable) {
			}
		}

		return [
			'id' => $did,
			'username' => $handle,
			'acct' => $handle,
			'display_name' => (string)($entry['displayName'] ?? $handle),
			'avatar' => $avatarUrl,
			'header' => $bannerUrl,
			'note' => (string)($entry['description'] ?? ''),
			'followers_count' => (int)($entry['followersCount'] ?? 0),
			'follows_count' => (int)($entry['followsCount'] ?? 0),
			'following' => $following,
		];
	}

	/** @return list<array<string, mixed>> */
	public function thread(string $localId, ?string $viewerId = null): array {
		$did = $this->identity->didOf($localId);
		$collection = $this->identity->collectionOf($localId);
		$rkey = $this->identity->rkeyOf($localId);
		if ($did === '' || $collection !== AtprotoIngress::COLLECTION || $rkey === '') {
			throw new AtprotoException('not an ATProto post id', 400);
		}

		$answer = $this->client->get('app.bsky.feed.getPostThread', [
			'uri' => 'at://' . $did . '/' . $collection . '/' . $rkey,
			'depth' => 1,
		]);
		$descendants = [];
		$walk = function (mixed $node) use (&$walk, &$descendants, $viewerId): void {
			if (!is_array($node)) {
				return;
			}
			$post = is_array($node['post'] ?? null) ? $node['post'] : [];
			$uri = (string)($post['uri'] ?? '');
			if ($uri !== '') {
				try {
					$status = $this->ingress->fetch($this->localId($uri), 0);
					$this->applyCounts($status, $post);
					$this->applyLabels($status, $post);
					if ($viewerId !== null) {
						$state = $this->engagement->viewerState($viewerId, $status);
						$status->setViewerEngagement($state['liked'], $state['reposted']);
					}
					$status->setExportFormat(ACore::FORMAT_LOCAL);
					$descendants[] = $status->exportAsLocal();
				} catch (\Throwable) {
					// A deleted or not-yet-imported reply does not hide its siblings.
				}
			}
			foreach ((array)($node['replies'] ?? []) as $reply) {
				$walk($reply);
			}
		};
		foreach ((array)($answer['thread']['replies'] ?? []) as $reply) {
			$walk($reply);
		}

		return $descendants;
	}

	/** Copy the public AppView counters into the shared Social status model. */
	private function applyCounts(Stream $status, array $post): void {
		$counts = [
			Details::LIKES => $post['likeCount'] ?? null,
			Details::BOOSTS => $post['repostCount'] ?? null,
			Details::REPLIES => $post['replyCount'] ?? null,
		];
		foreach ($counts as $detail => $count) {
			if (is_int($count) || (is_string($count) && ctype_digit($count))) {
				$status->setDetailInt($detail, max(0, (int)$count));
			}
		}
	}

	/** Keep AppView moderation labels visible without changing Fediverse policy. */
	private function applyLabels(Stream $status, array $post): void {
		$labels = $post['labels'] ?? [];
		if (!is_array($labels)) {
			return;
		}
		$values = [];
		foreach ((array)($labels['labels'] ?? $labels) as $label) {
			$value = is_array($label) ? trim((string)($label['val'] ?? '')) : '';
			if ($value !== '' && !in_array($value, $values, true)) {
				$values[] = $value;
			}
		}
		if ($values === []) {
			return;
		}

		$status->setSensitive(true);
		if ($status->getSpoilerText() === '') {
			$status->setSpoilerText('Bluesky: ' . implode(', ', array_slice($values, 0, 3)));
		}
	}

	private function localId(string $uri): string {
		$parts = explode('/', substr($uri, 5));
		if (count($parts) !== 3 || !str_starts_with($parts[0], 'did:')) {
			return '';
		}

		return $this->identity->recordId($parts[0], $parts[1], $parts[2]);
	}
}
