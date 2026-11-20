<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\HashtagsRequest;
use OCA\Social\Model\Client\DirectorySource;
use OCA\Social\Model\Client\PeerTag;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What the rest of the fediverse is talking about: asks other servers what is
 * trending there and shows the answer as theirs.
 *
 * Nothing is stored or ingested; answers are cached for fifteen minutes.
 * Tags are ranked by how many servers named them, not by summed counts,
 * since instance sizes differ by orders of magnitude.
 *
 * Sources are `FediverseDirectoryService`'s: `local` (the cron's trend, no
 * network), `mastodon` (`/api/v1/trends/tags`; with no public tag search, a
 * search filters the trending page here), and `misskey` (`hashtags/trend`
 * and `hashtags/search`). `lemmy` and `wordpress` have no hashtags and are
 * not asked. A source that cannot answer is reported, not dropped.
 */
class PeerTrendService {
	/** How many tags one page holds. */
	public const LIMIT = 20;
	public const MAX_LIMIT = 40;

	/** How many servers are asked, this instance aside. */
	public const SOURCES = 5;

	/** How long one server has to answer before it is left out. */
	public const TIMEOUT = 4;

	/**
	 * How long the whole fan-out has, in seconds. A float because it is added to
	 * `microtime(true)` (psalm strict binary operands).
	 */
	public const BUDGET = 10.0;

	/**
	 * How long an answer is kept; Mastodon recomputes trends on a schedule, so
	 * asking more often returns the same list.
	 */
	public const CACHE_TTL = 900;

	/** How many tags one server contributes before the rest are ignored. */
	private const PER_SOURCE = 20;

	/**
	 * What a hashtag may look like: letters, digits and underscores in any
	 * script. Anything else in a peer's answer is dropped.
	 */
	private const SHAPE = '/^[\p{L}\p{N}_]+$/u';

	private ICache $cache;

	public function __construct(
		private CurlService $curlService,
		private FediverseDirectoryService $directoryService,
		private HashtagService $hashtagService,
		private TrendReviewService $trendReviewService,
		private LoggerInterface $logger,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed('social.peertrends');
	}

	/**
	 * The tags the asked servers are busy with, most-vouched-for first.
	 *
	 * @param string $query what to look for; empty asks each server what is trending there
	 * @param string $host one server to ask, or '' for all of them
	 *
	 * @return array{tags: PeerTag[], sources: array<int, array<string, mixed>>}
	 */
	public function tags(string $query = '', string $host = '', int $limit = self::LIMIT): array {
		$query = $this->normalise($query);
		$limit = max(1, min(self::MAX_LIMIT, $limit));

		/** @var array<string, PeerTag> $tags */
		$tags = [];
		$reports = [];
		$deadline = microtime(true) + self::BUDGET;
		$asked = 0;

		foreach ($this->directoryService->sources() as $source) {
			if ($host !== '' && $source->getHost() !== $host) {
				continue;
			}

			if (!$this->answersAboutTags($source)) {
				// Reported, so the reader can tell which server has nothing to say.
				$reports[] = $this->report($source, 'unsupported', 0);
				continue;
			}

			if (!$source->isLocal() && ($asked >= self::SOURCES || microtime(true) >= $deadline)) {
				$reports[] = $this->report($source, 'skipped', 0);
				continue;
			}

			if (!$source->isLocal()) {
				$asked++;
			}

			try {
				$found = $this->ask($source, $query);
				$reports[] = $this->report($source, 'ok', count($found));
				foreach ($found as $name => $uses) {
					$tags[$name] ??= new PeerTag($name);
					$tags[$name]->reportedBy(
						$source->getHost(), $source->getLabel(), $uses, $source->isLocal()
					);
				}
			} catch (Throwable $e) {
				$this->logger->debug('[PeerTrendService] a server did not answer about hashtags', [
					'host' => $source->getHost(), 'exception' => $e,
				]);
				$reports[] = $this->report($source, 'failed', 0);
			}
		}

		return ['tags' => array_slice($this->ranked($tags), 0, $limit), 'sources' => $reports];
	}

	/**
	 * Most servers first, then the busiest, after dropping what a moderator here
	 * took off the local trending page.
	 *
	 * @param array<string, PeerTag> $tags
	 *
	 * @return PeerTag[]
	 */
	private function ranked(array $tags): array {
		$rows = array_map(
			static fn (PeerTag $tag): array => ['hashtag' => $tag->getName()], $tags
		);
		$kept = [];
		foreach ($this->trendReviewService->filterTags(array_values($rows)) as $row) {
			$kept[(string)$row['hashtag']] = true;
		}

		$ranked = array_values(array_filter(
			$tags, static fn (PeerTag $tag): bool => isset($kept[$tag->getName()])
		));
		usort($ranked, static function (PeerTag $first, PeerTag $second): int {
			return [$second->countServers(), $second->getUses(), $first->getName()]
				<=> [$first->countServers(), $first->getUses(), $second->getName()];
		});

		return $ranked;
	}

	/**
	 * Whether this source has a hashtag endpoint at all (Lemmy and the curated
	 * directory do not).
	 */
	private function answersAboutTags(DirectorySource $source): bool {
		return in_array($source->getKind(), [
			DirectorySource::KIND_LOCAL,
			DirectorySource::KIND_MASTODON,
			DirectorySource::KIND_MISSKEY,
		], true);
	}

	/**
	 * @return array<string, int> tag => uses, as that one source reports them
	 *
	 * @throws Throwable when the source could not be asked
	 */
	private function ask(DirectorySource $source, string $query): array {
		if ($source->isLocal()) {
			return $this->askLocal($query);
		}

		$key = $this->cacheKey($source, $query);
		$cached = $this->cache->get($key);
		if (is_string($cached)) {
			return $this->decode($cached);
		}

		$found = match ($source->getKind()) {
			DirectorySource::KIND_MISSKEY => $this->askMisskey($source, $query),
			default => $this->askMastodon($source, $query),
		};

		$this->cache->set($key, (string)json_encode($found), self::CACHE_TTL);

		return $found;
	}

	/**
	 * This instance's own hashtags, from the cron's trend; no network.
	 *
	 * @return array<string, int>
	 */
	private function askLocal(string $query): array {
		$rows = ($query === '')
			? $this->hashtagService->getTrending(self::PER_SOURCE)
			: $this->hashtagService->searchHashtags($query, true);

		$found = [];
		foreach (array_slice($rows, 0, self::PER_SOURCE) as $row) {
			$name = $this->normalise((string)($row['hashtag'] ?? ''));
			if ($name === '') {
				continue;
			}

			$trend = $row['trend'] ?? [];
			$found[$name] = is_array($trend) ? max([0, ...array_map('intval', $trend)]) : 0;
		}

		return $found;
	}

	/**
	 * Mastodon and everything that copies its API; a search filters the trending
	 * page here.
	 *
	 * @return array<string, int>
	 */
	private function askMastodon(DirectorySource $source, string $query): array {
		$rows = $this->get($source, '/api/v1/trends/tags', ['limit' => self::PER_SOURCE]);

		$found = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}

			$name = $this->normalise((string)($row['name'] ?? ''));
			if ($name === '' || ($query !== '' && !str_contains($name, $query))) {
				continue;
			}

			// The first spelling a server offers keeps the tag.
			$found[$name] ??= $this->usesFromHistory($row['history'] ?? null);
		}

		return $found;
	}

	/**
	 * Mastodon reports a per-day history whose length differs between versions;
	 * the most recent day is the one figure comparable across servers.
	 */
	private function usesFromHistory(mixed $history): int {
		if (!is_array($history) || $history === []) {
			return 0;
		}

		$latest = $history[0];

		return is_array($latest) ? max(0, (int)($latest['uses'] ?? 0)) : 0;
	}

	/**
	 * Misskey, which has a real search. `hashtags/search` returns bare strings,
	 * so such a tag counts a server and no uses.
	 *
	 * @return array<string, int>
	 */
	private function askMisskey(DirectorySource $source, string $query): array {
		if ($query === '') {
			$found = [];
			foreach ($this->post($source, '/api/hashtags/trend', []) as $row) {
				if (!is_array($row)) {
					continue;
				}

				$name = $this->normalise((string)($row['tag'] ?? ''));
				if ($name !== '') {
					$found[$name] ??= max(0, (int)($row['usersCount'] ?? 0));
				}
			}

			return $found;
		}

		$found = [];
		$rows = $this->post($source, '/api/hashtags/search', [
			'query' => $query, 'limit' => self::PER_SOURCE,
		]);
		foreach ($rows as $row) {
			$name = $this->normalise(is_string($row) ? $row : '');
			if ($name !== '') {
				$found[$name] = 0;
			}
		}

		return $found;
	}

	/**
	 * A tag as this instance spells it: no leading '#', lower case, and only
	 * if it is shaped like a hashtag at all.
	 */
	private function normalise(string $tag): string {
		$tag = mb_strtolower(ltrim(trim($tag), '#'));
		if ($tag === '' || mb_strlen($tag) > HashtagsRequest::HASHTAG_MAX_LENGTH) {
			return '';
		}

		return preg_match(self::SHAPE, $tag) === 1 ? $tag : '';
	}

	/**
	 * @return array<mixed>
	 */
	private function get(DirectorySource $source, string $path, array $params): array {
		return $this->curlService->retrieveJson(
			'get',
			'https://' . $source->getHost() . $path . '?' . http_build_query($params),
			// Plain REST: the ActivityPub `Accept` would ask for another representation.
			['timeout' => self::TIMEOUT, 'json_headers' => false, 'headers' => ['Accept' => 'application/json']]
		);
	}

	/**
	 * @param array<string, mixed> $body
	 *
	 * @return array<mixed>
	 */
	private function post(DirectorySource $source, string $path, array $body): array {
		return $this->curlService->retrieveJson(
			'post',
			'https://' . $source->getHost() . $path,
			[
				'timeout' => self::TIMEOUT,
				'body' => (string)json_encode($body),
				'json_headers' => false,
				'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
			]
		);
	}

	/**
	 * @return array{host: string, kind: string, label: string, status: string, count: int}
	 */
	private function report(DirectorySource $source, string $status, int $count): array {
		return $source->jsonSerialize() + ['status' => $status, 'count' => $count];
	}

	private function cacheKey(DirectorySource $source, string $query): string {
		return sha1($source->getKind() . '|' . $source->getHost() . '|' . $query);
	}

	/**
	 * @return array<string, int>
	 */
	private function decode(string $raw): array {
		$rows = json_decode($raw, true);
		if (!is_array($rows)) {
			return [];
		}

		$found = [];
		foreach ($rows as $name => $uses) {
			$found[(string)$name] = (int)$uses;
		}

		return $found;
	}
}
