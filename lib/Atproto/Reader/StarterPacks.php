<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use InvalidArgumentException;
use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\FollowService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Bluesky starter packs (`app.bsky.graph.starterpack`), opened here: who is
 * in one and which feeds come with it, read from the AppView as the person
 * where they are on Bluesky — so whom they follow already is marked — and
 * its members followed from here, some or all, a batch at a time, as any
 * Bluesky account is followed. The feeds are kept as a Bluesky app keeps
 * them (`BlueskyFeeds`).
 */
class StarterPacks {
	public const COLLECTION = 'app.bsky.graph.starterpack';
	/** a starter pack holds at most this many accounts */
	public const MAX_MEMBERS = 150;
	/** how many members one request follows */
	public const FOLLOW_BATCH = 25;

	public function __construct(
		private AppViewClient $appView,
		private IdentityService $identities,
		private CacheActorService $cacheActors,
		private FollowService $follows,
		private BlueskyFeeds $feeds,
		private LoggerInterface $logger,
		private ?BlueskyBlockedBy $blockedBy = null,
	) {
	}

	/**
	 * A starter pack, by its bsky.app address or its `at://` URI.
	 *
	 * @return array{uri: string, url: string, name: string, description: string, joined: int,
	 *     creator: array{did: string, handle: string, name: string, avatar: string},
	 *     members: list<array{did: string, handle: string, name: string, avatar: string, description: string, following: bool}>,
	 *     feeds: list<array{uri: string, type: string, name: string, description: string, avatar: string, creator: string}>}
	 * @throws InvalidArgumentException with what to tell the person
	 */
	public function read(Person $viewer, string $typed): array {
		$uri = $this->uriOf($typed);
		$pack = $this->query($viewer, 'app.bsky.graph.getStarterPack', ['starterPack' => $uri])['starterPack'] ?? null;
		if (!is_array($pack) || ($pack['uri'] ?? '') !== $uri) {
			throw new InvalidArgumentException('Bluesky does not know that starter pack');
		}
		$creator = is_array($pack['creator'] ?? null) ? $pack['creator'] : [];
		$parsed = Syntax::parseAtUri($uri);

		return [
			'uri' => $uri,
			'url' => 'https://bsky.app/starter-pack/' . (string)($creator['handle'] ?? $parsed['authority'] ?? '') . '/' . (string)($parsed['rkey'] ?? ''),
			'name' => (string)($pack['record']['name'] ?? ''),
			'description' => (string)($pack['record']['description'] ?? ''),
			'joined' => (int)($pack['joinedAllTimeCount'] ?? 0),
			'creator' => self::account($creator),
			'members' => $this->members($viewer, (string)($pack['list']['uri'] ?? '')),
			'feeds' => array_values(array_filter(array_map(
				static fn ($view): ?array => is_array($view) ? BlueskyFeeds::describeFeed($view) : null,
				is_array($pack['feeds'] ?? null) ? $pack['feeds'] : [],
			))),
		];
	}

	/**
	 * Follows members of a starter pack — the ones named, which must be in
	 * it — and, asked to, keeps its feeds.
	 *
	 * @param string[] $dids at most FOLLOW_BATCH
	 * @return array{followed: list<string>, failed: list<string>}
	 * @throws InvalidArgumentException
	 */
	public function follow(Person $viewer, string $typed, array $dids, bool $keepFeeds): array {
		if (count($dids) > self::FOLLOW_BATCH) {
			throw new InvalidArgumentException('At most ' . self::FOLLOW_BATCH . ' accounts at a time');
		}
		$pack = $this->read($viewer, $typed);
		$members = array_column($pack['members'], 'did');
		$followed = [];
		$failed = [];
		foreach (array_unique($dids) as $did) {
			if (!in_array($did, $members, true)) {
				$failed[] = $did;
				continue;
			}
			try {
				$this->follows->followActor($viewer, $this->cacheActors->getFromId(BlueskyIds::actorId($did)));
				$followed[] = $did;
			} catch (Throwable $e) {
				$this->logger->info('A starter pack member was not followed', ['did' => $did, 'exception' => $e]);
				$failed[] = $did;
			}
		}
		if ($keepFeeds) {
			foreach ($pack['feeds'] as $feed) {
				try {
					$this->feeds->add($viewer, $feed['uri']);
				} catch (Throwable $e) {
					$this->logger->info('A starter pack feed was not kept', ['feed' => $feed['uri'], 'exception' => $e]);
				}
			}
		}

		return ['followed' => $followed, 'failed' => $failed];
	}

	/**
	 * The `at://` URI of a starter pack a person typed or pasted: one
	 * already, or its bsky.app address.
	 *
	 * @throws InvalidArgumentException
	 */
	public function uriOf(string $typed): string {
		$typed = trim($typed);
		$parsed = Syntax::parseAtUri($typed);
		if ($parsed !== null && ($parsed['collection'] ?? '') === self::COLLECTION && Syntax::isDid((string)$parsed['authority']) && ($parsed['rkey'] ?? '') !== '') {
			return $typed;
		}
		if (preg_match('#^https://bsky\.app/starter-pack/([^/?\#]+)/([^/?\#]+)#', $typed, $m) !== 1) {
			throw new InvalidArgumentException('That is not the address of a Bluesky starter pack');
		}
		$actor = strtolower(rawurldecode($m[1]));
		if (!Syntax::isDid($actor)) {
			try {
				$actor = (string)($this->appView->query('com.atproto.identity.resolveHandle', ['handle' => $actor])['did'] ?? '');
			} catch (Throwable) {
				$actor = '';
			}
		}
		if (!Syntax::isDid($actor)) {
			throw new InvalidArgumentException('Bluesky does not know whose starter pack that is');
		}

		return 'at://' . $actor . '/' . self::COLLECTION . '/' . rawurldecode($m[2]);
	}

	/**
	 * The accounts on the pack's list, as many as a pack holds.
	 *
	 * @return list<array{did: string, handle: string, name: string, avatar: string, description: string, following: bool}>
	 */
	private function members(Person $viewer, string $list): array {
		$members = [];
		$cursor = '';
		while ($list !== '' && count($members) < self::MAX_MEMBERS) {
			$answer = $this->query($viewer, 'app.bsky.graph.getList', ['list' => $list, 'limit' => 100] + ($cursor !== '' ? ['cursor' => $cursor] : []));
			foreach (is_array($answer['items'] ?? null) ? $answer['items'] : [] as $item) {
				$subject = is_array($item['subject'] ?? null) ? $item['subject'] : [];
				if (($subject['did'] ?? '') !== '' && count($members) < self::MAX_MEMBERS) {
					$members[] = self::account($subject) + [
						'description' => (string)($subject['description'] ?? ''),
						'following' => is_string($subject['viewer']['following'] ?? null) && $subject['viewer']['following'] !== '',
					];
				}
			}
			$cursor = is_string($answer['cursor'] ?? null) ? $answer['cursor'] : '';
			if ($cursor === '') {
				break;
			}
		}

		return $members;
	}

	/**
	 * @return array{did: string, handle: string, name: string, avatar: string}
	 */
	private static function account(array $view): array {
		return [
			'did' => (string)($view['did'] ?? ''),
			'handle' => (string)($view['handle'] ?? ''),
			'name' => (string)($view['displayName'] ?? ''),
			'avatar' => (string)($view['avatar'] ?? ''),
		];
	}

	/**
	 * The AppView's answer, as the person where they are on Bluesky.
	 */
	private function query(Person $viewer, string $method, array $params): array {
		$identity = $this->identities->forActor($viewer, false);
		if ($identity === null) {
			return $this->appView->query($method, $params);
		}
		$answer = $this->appView->queryAs($identity->did, $this->identities->signingKey($identity), $method, $params);
		$this->blockedBy?->learn($viewer, $answer);

		return $answer;
	}
}
