<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What Bluesky has to discover, for Discover here: the topics trending on
 * Bluesky, each a custom feed to read here or a search, and the accounts
 * Bluesky suggests to the person, read as them where they are on Bluesky.
 * Trends are the same for everybody and kept a few minutes; an AppView that
 * offers neither answers nothing rather than an error.
 */
class BlueskyDiscovery {
	/** how long the trending topics are kept */
	private const TRENDS_KEPT = 600;
	private const TRENDS_LIMIT = 10;
	private const SUGGESTIONS_LIMIT = 20;

	private ?ICache $cache = null;

	public function __construct(
		private AppViewClient $appView,
		private IdentityService $identities,
		private ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The topics trending on Bluesky: each with what it is called and either
	 * the `at://` URI of the custom feed it opens or the text to search for.
	 *
	 * @return list<array{topic: string, label: string, feed: string, search: string}>
	 */
	public function trends(): array {
		$kept = $this->cache()->get('trends');
		if (is_array($kept)) {
			return $kept;
		}
		try {
			$answer = $this->appView->query('app.bsky.unspecced.getTrendingTopics', ['limit' => self::TRENDS_LIMIT]);
		} catch (Throwable $e) {
			// an AppView without trends is asked again only when they are due
			$this->logger->info('Bluesky trends not read', ['exception' => $e]);
			$this->cache()->set('trends', [], self::TRENDS_KEPT);

			return [];
		}
		$trends = [];
		foreach (is_array($answer['topics'] ?? null) ? $answer['topics'] : [] as $topic) {
			$trend = is_array($topic) ? $this->trend($topic) : null;
			if ($trend !== null) {
				$trends[] = $trend;
			}
		}
		$this->cache()->set('trends', $trends, self::TRENDS_KEPT);

		return $trends;
	}

	/**
	 * The accounts Bluesky suggests to the person, as a client shows an
	 * account: `acct` is the handle, which is how one is followed here.
	 *
	 * @return list<array{id: string, acct: string, username: string, display_name: string, avatar: string, note: string, url: string}>
	 */
	public function suggestions(Person $viewer): array {
		try {
			$identity = $this->identities->forActor($viewer, false);
			$params = ['limit' => self::SUGGESTIONS_LIMIT];
			$answer = $identity !== null
				? $this->appView->queryAs($identity->did, $this->identities->signingKey($identity), 'app.bsky.actor.getSuggestions', $params)
				: $this->appView->query('app.bsky.actor.getSuggestions', $params);
		} catch (Throwable $e) {
			$this->logger->info('Bluesky suggestions not read', ['exception' => $e]);

			return [];
		}
		$accounts = [];
		foreach (is_array($answer['actors'] ?? null) ? $answer['actors'] : [] as $actor) {
			$handle = is_array($actor) ? strtolower((string)($actor['handle'] ?? '')) : '';
			if ($handle === '' || $handle === 'handle.invalid' || is_string($actor['viewer']['following'] ?? null)) {
				continue;
			}
			$accounts[] = [
				'id' => (string)($actor['did'] ?? ''),
				'acct' => $handle,
				'username' => $handle,
				'display_name' => (string)($actor['displayName'] ?? ''),
				'avatar' => (string)($actor['avatar'] ?? ''),
				'note' => htmlspecialchars((string)($actor['description'] ?? ''), ENT_QUOTES | ENT_HTML5),
				'url' => 'https://bsky.app/profile/' . $handle,
			];
		}

		return $accounts;
	}

	/**
	 * One trending topic: its link is a path on bsky.app, a feed's or a
	 * search's; anything else is not one this app can open.
	 *
	 * @return array{topic: string, label: string, feed: string, search: string}|null
	 */
	private function trend(array $topic): ?array {
		$name = trim((string)($topic['topic'] ?? ''));
		$label = trim((string)($topic['displayName'] ?? '')) ?: $name;
		$link = (string)($topic['link'] ?? '');
		if ($name === '') {
			return null;
		}
		if (preg_match('#^/profile/([^/?\#]+)/feed/([^/?\#]+)$#', $link, $m) === 1) {
			$did = $this->didOf(rawurldecode($m[1]));

			return $did === '' ? null : ['topic' => $name, 'label' => $label, 'feed' => 'at://' . $did . '/app.bsky.feed.generator/' . rawurldecode($m[2]), 'search' => ''];
		}
		$query = [];
		parse_str((string)parse_url($link, PHP_URL_QUERY), $query);
		$search = is_string($query['q'] ?? null) ? trim($query['q']) : '';

		return ['topic' => $name, 'label' => $label, 'feed' => '', 'search' => $search !== '' ? $search : $name];
	}

	private function didOf(string $actor): string {
		if (Syntax::isDid($actor)) {
			return $actor;
		}
		try {
			$did = (string)($this->appView->query('com.atproto.identity.resolveHandle', ['handle' => $actor])['did'] ?? '');
		} catch (Throwable) {
			return '';
		}

		return Syntax::isDid($did) ? $did : '';
	}

	private function cache(): ICache {
		return $this->cache ??= $this->cacheFactory->createDistributed('social-atproto-discovery');
	}
}
