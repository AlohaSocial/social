<?php

declare(strict_types=1);

namespace OCA\Social\Service\Atproto;

use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Model\ActivityPub\Stream;

/** Resolves a Bluesky handle and imports its author feed into shared rows. */
class AtprotoProfileService {
	public function __construct(
		private AtprotoClient $client,
		private AtprotoIdentity $identity,
		private AtprotoIngress $ingress,
	) {
	}

	/** @return array{profile: array<string, mixed>, statuses: list<Stream>} */
	public function read(string $handle, int $limit = 20): array {
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

		$answer = $this->client->get('app.bsky.feed.getAuthorFeed', [
			'actor' => $resolved['did'],
			'limit' => min(max($limit, 1), 50),
		]);
		$statuses = [];
		foreach ((array)($answer['feed'] ?? []) as $entry) {
			$post = is_array($entry['post'] ?? null) ? $entry['post'] : [];
			$uri = (string)($post['uri'] ?? '');
			if (!str_starts_with($uri, 'at://')) {
				continue;
			}
			try {
				$statuses[] = $this->ingress->fetch($this->localId($uri), 0);
			} catch (\Throwable) {
				// One deleted or malformed record must not hide the rest of a profile.
			}
		}

		return ['profile' => $profile, 'statuses' => $statuses];
	}

	private function localId(string $uri): string {
		$parts = explode('/', substr($uri, 5));
		if (count($parts) !== 3 || !str_starts_with($parts[0], 'did:')) {
			return '';
		}

		return $this->identity->recordId($parts[0], $parts[1], $parts[2]);
	}
}
