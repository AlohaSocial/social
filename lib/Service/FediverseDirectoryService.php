<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\AlohaDirectory\DirectoryLists;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Atproto\Reader\BlueskySearch;
use OCA\Social\Db\InstanceStatsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\DirectoryAccount;
use OCA\Social\Model\Client\DirectorySource;
use OCA\Social\Model\Details;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Looks for somebody to follow in the directories other servers publish,
 * asking several servers at once through APIs they serve without a token.
 *
 * Nothing about people is stored: an answer is cached for five minutes so
 * typing does not hammer the sources. What is stored is about servers (the
 * server directory's list and each peer's software), written by `refresh()`
 * from `Cron\Cache` so listing the sources never waits on a remote host.
 *
 * Source kinds: `local` (this instance, always first, no network),
 * `mastodon` (`/api/v1/directory` and `/api/v1/accounts/lookup`, also
 * served by Pixelfed and most forks), `misskey` (`users/search`), `lemmy`
 * (`search` with `type_=Users`), `wordpress` (a hand-kept directory) and
 * Bluesky's typeahead. Mastodon has no public search, so a Mastodon-kind
 * source is asked the exact lookup plus a directory page filtered here; see
 * `DirectoryResults::isExhaustive()`.
 *
 * @see DirectorySource for why the kind is configured rather than sniffed
 */
class FediverseDirectoryService {
	/** The config key an administrator sets the sources with. */
	public const CONFIG_KEY = 'directories';

	/** How many people one source may contribute to a page. */
	public const LIMIT = 20;
	public const MAX_LIMIT = 40;

	/** How long one source has to answer before it is left out. */
	public const TIMEOUT = 4;

	/**
	 * How long the whole fan-out has, in seconds; sources not reached in time
	 * are reported as unasked. A float because it is added to `microtime(true)`
	 * (psalm strict binary operands).
	 */
	public const BUDGET = 10.0;

	/** How long an answer is kept. Long enough for typing, short enough to be news. */
	public const CACHE_TTL = 300;

	/**
	 * How much of a Mastodon directory is read before the query is applied: one
	 * page, its maximum of 80, rather than walking somebody else's user list.
	 */
	private const DIRECTORY_PAGE = 80;

	/** How many curated entries one search reads. Their API caps at 100. */
	private const WORDPRESS_PAGE = 20;

	/**
	 * The sources an instance starts with: the large public instances of each
	 * software family this can query without a token, plus a hand-kept
	 * directory. An administrator can replace them in the config.
	 */
	private const BUILTIN = [
		// Hand-kept, and the only one that can answer "who writes about X"; asked first.
		['host' => 'fedi.directory', 'kind' => DirectorySource::KIND_WORDPRESS, 'label' => 'Fedi.Directory'],
		['host' => 'mastodon.social', 'kind' => DirectorySource::KIND_MASTODON],
		['host' => 'misskey.io', 'kind' => DirectorySource::KIND_MISSKEY],
		['host' => 'lemmy.world', 'kind' => DirectorySource::KIND_LEMMY],
		['host' => 'pixelfed.social', 'kind' => DirectorySource::KIND_MASTODON],
	];

	/** How many of the servers this instance federates with are asked. */
	public const CONFIG_PEERS = 'directory_peers';
	public const PEERS_DEFAULT = 4;
	/** Whether the server directory below is asked which servers exist. */
	public const CONFIG_DISCOVERY = 'directory_discovery';

	/**
	 * A directory of servers, publishing a curated list with each server's
	 * software, which tells which API to speak to it.
	 */
	private const DISCOVERY_HOST = 'fediverse.info';
	private const DISCOVERY_PATH = '/api/_meta-api/instances/list';
	/** How many servers a discovery pass contributes. */
	private const DISCOVERY_LIMIT = 3;

	/** How long a server list, or one server's software, is trusted before it is asked again. */
	private const DISCOVERY_TTL = 86400;
	private const SOFTWARE_TTL = 604800;

	/**
	 * Where `refresh()` writes what it found out about servers. An app value
	 * rather than a cache because the cron writes it and a web request reads
	 * it, and APCu is not shared between the two.
	 */
	public const CONFIG_KNOWN = 'directory_known';

	/** How many peers one refresh asks for their NodeInfo, at most. */
	public const LOOKUPS_PER_REFRESH = 8;

	/** How many servers the stored list keeps, of either kind. */
	private const KNOWN_MAX = 100;

	/**
	 * What a server calls its software, and which of the APIs above that is;
	 * everything Mastodon-compatible is asked as Mastodon.
	 */
	private const SOFTWARE_KINDS = [
		'mastodon' => DirectorySource::KIND_MASTODON,
		'hometown' => DirectorySource::KIND_MASTODON,
		'glitchcafe' => DirectorySource::KIND_MASTODON,
		'pixelfed' => DirectorySource::KIND_MASTODON,
		'pleroma' => DirectorySource::KIND_MASTODON,
		'akkoma' => DirectorySource::KIND_MASTODON,
		'iceshrimp' => DirectorySource::KIND_MISSKEY,
		'misskey' => DirectorySource::KIND_MISSKEY,
		'sharkey' => DirectorySource::KIND_MISSKEY,
		'firefish' => DirectorySource::KIND_MISSKEY,
		'calckey' => DirectorySource::KIND_MISSKEY,
		'foundkey' => DirectorySource::KIND_MISSKEY,
		'lemmy' => DirectorySource::KIND_LEMMY,
	];

	private ICache $cache;

	public function __construct(
		private CurlService $curlService,
		private ConfigService $configService,
		private DirectoryService $directoryService,
		private CacheActorService $cacheActorService,
		private FediverseService $fediverseService,
		private InstanceStatsRequest $instanceStatsRequest,
		private LoggerInterface $logger,
		ICacheFactory $cacheFactory,
		private ?BlueskySearch $bluesky = null,
		private ?DirectoryLists $alohaLists = null,
	) {
		$this->cache = $cacheFactory->createDistributed('social.directories');
	}

	/**
	 * Every source that will be asked, this instance first.
	 *
	 * @return DirectorySource[]
	 */
	public function sources(): array {
		$sources = [new DirectorySource(
			$this->configService->getCloudHost(),
			DirectorySource::KIND_LOCAL,
			$this->configService->getCloudHost()
		)];

		$configured = $this->configured();
		$curated = $configured === null ? $this->curatedSources() : [];
		foreach ($configured ?? ($curated === [] ? self::BUILTIN : []) as $entry) {
			$source = $this->fromDefinition(is_array($entry) ? $entry : []);
			if ($source !== null) {
				$sources[] = $source;
			}
		}

		foreach ([...$curated, ...$this->peerSources($curated !== []), ...$this->discoveredSources($curated !== [])] as $source) {
			$sources[] = $source;
		}
		if ($this->bluesky !== null && $this->bluesky->isCandidate('a.b')) {
			$sources[] = new DirectorySource(BlueskyIds::HOST, DirectorySource::KIND_BLUESKY, 'Bluesky');
		}

		return $this->deduplicated($sources);
	}

	/**
	 * The servers this instance federates with, ranked by how many of their
	 * accounts are cached here. Only peers whose software `refresh()` has
	 * already found, and can speak to, are included.
	 *
	 * @return DirectorySource[]
	 */
	private function peerSources(bool $curatedInUse = false): array {
		$wanted = $this->peersWanted($curatedInUse);
		if ($wanted < 1) {
			return [];
		}

		$software = $this->known()['software'];
		$sources = [];
		foreach (array_keys($this->instanceStatsRequest->remoteHostCounts()) as $host) {
			if (count($sources) >= $wanted) {
				break;
			}

			$host = (string)$host;
			if ($host === '' || !$this->allowed($host)) {
				continue;
			}

			$kind = self::SOFTWARE_KINDS[$software[$host]['name'] ?? ''] ?? null;
			if ($kind === null) {
				continue;
			}

			$sources[] = new DirectorySource($host, $kind, $host, DirectorySource::ORIGIN_FEDERATION);
		}

		return $sources;
	}

	/** Off by default while curated providers are asked, unless the administrator set a number. */
	private function peersWanted(bool $curatedInUse = false): int {
		$raw = trim((string)$this->configService->getAppValue(self::CONFIG_PEERS));
		if ($raw === '') {
			return $curatedInUse ? 0 : self::PEERS_DEFAULT;
		}

		return (int)$raw;
	}

	/** Off by default while curated providers are asked, unless the administrator turned it on. */
	private function discoveryWanted(bool $curatedInUse = false): bool {
		$raw = trim((string)$this->configService->getAppValue(self::CONFIG_DISCOVERY));
		if ($raw === '') {
			return !$curatedInUse;
		}

		return $raw !== '0';
	}

	/**
	 * The search providers of the Aloha Directory's signed `search.json`: Aloha
	 * servers speaking the Mastodon directory and lookup. They replace the
	 * built-in sources while the administrator configured none, spreading
	 * searches over curated servers.
	 *
	 * @return DirectorySource[]
	 */
	private function curatedSources(): array {
		if ($this->alohaLists === null) {
			return [];
		}

		$sources = [];
		foreach ($this->alohaLists->curatedSearchProviders($this->configService->getCloudHost()) as $provider) {
			if ($this->allowed($provider['domain'])) {
				$sources[] = new DirectorySource($provider['domain'], DirectorySource::KIND_MASTODON, $provider['domain'], DirectorySource::ORIGIN_CURATED);
			}
		}

		return $sources;
	}

	/**
	 * Servers the directory of servers lists, refreshed daily by `refresh()`.
	 * The weakest source, so it comes last and contributes few.
	 *
	 * @return DirectorySource[]
	 */
	private function discoveredSources(bool $curatedInUse = false): array {
		if (!$this->discoveryWanted($curatedInUse)) {
			return [];
		}

		$sources = [];
		foreach ($this->known()['discovered']['servers'] as $row) {
			if (count($sources) >= self::DISCOVERY_LIMIT) {
				break;
			}

			$kind = self::SOFTWARE_KINDS[$row['software']] ?? null;
			if ($kind === null || !$this->allowed($row['host'])) {
				continue;
			}

			$sources[] = new DirectorySource($row['host'], $kind, $row['host'], DirectorySource::ORIGIN_DISCOVERED);
		}

		return $sources;
	}

	/**
	 * Finds out what `sources()` reads: the server directory's list once a day
	 * and each peer's software once a week. Called from `Cron\Cache`, never from
	 * a request. Bounded by `LOOKUPS_PER_REFRESH`; peers not yet checked are
	 * left out of `sources()`.
	 */
	public function refresh(): void {
		$known = $this->known();
		$now = time();
		$curatedInUse = $this->configured() === null && $this->curatedSources() !== [];

		if ($this->discoveryWanted($curatedInUse) && $now - $known['discovered']['fetched'] >= self::DISCOVERY_TTL) {
			$known['discovered'] = ['fetched' => $now, 'servers' => $this->fetchServerList()];
		}

		$wanted = $this->peersWanted($curatedInUse);
		$found = 0;
		$lookups = 0;
		foreach (($wanted < 1) ? [] : array_keys($this->instanceStatsRequest->remoteHostCounts()) as $host) {
			$host = (string)$host;
			if ($found >= $wanted || $lookups >= self::LOOKUPS_PER_REFRESH) {
				break;
			}
			if ($host === '' || !$this->allowed($host)) {
				continue;
			}

			$entry = $known['software'][$host] ?? null;
			if ($entry === null || $now - $entry['checked'] >= self::SOFTWARE_TTL) {
				$lookups++;
				$entry = ['name' => $this->softwareName($host), 'checked' => $now];
				// Remember a miss too; a server without NodeInfo is retried next week.
				$known['software'][$host] = $entry;
			}

			if (isset(self::SOFTWARE_KINDS[$entry['name']])) {
				$found++;
			}
		}

		// the most recently checked are the ones still worth keeping
		uasort($known['software'], static fn (array $a, array $b): int => $b['checked'] <=> $a['checked']);
		$known['software'] = array_slice($known['software'], 0, self::KNOWN_MAX, true);

		$this->configService->setAppValue(self::CONFIG_KNOWN, json_encode($known));
	}

	/**
	 * What `refresh()` last wrote, normalised, since an app value can be edited
	 * by hand.
	 *
	 * @return array{
	 *     discovered: array{fetched: int, servers: list<array{host: string, software: string}>},
	 *     software: array<string, array{name: string, checked: int}>
	 * }
	 */
	private function known(): array {
		$raw = json_decode((string)$this->configService->getAppValue(self::CONFIG_KNOWN), true);
		$raw = is_array($raw) ? $raw : [];

		$discovered = is_array($raw['discovered'] ?? null) ? $raw['discovered'] : [];
		$servers = [];
		foreach (is_array($discovered['servers'] ?? null) ? $discovered['servers'] : [] as $row) {
			if (is_array($row) && is_string($row['host'] ?? null) && is_string($row['software'] ?? null)) {
				$servers[] = ['host' => $row['host'], 'software' => $row['software']];
			}
		}

		$software = [];
		foreach (is_array($raw['software'] ?? null) ? $raw['software'] : [] as $host => $entry) {
			if (is_array($entry) && is_string($entry['name'] ?? null)) {
				$software[(string)$host] = ['name' => $entry['name'], 'checked' => (int)($entry['checked'] ?? 0)];
			}
		}

		return [
			'discovered' => ['fetched' => (int)($discovered['fetched'] ?? 0), 'servers' => $servers],
			'software' => $software,
		];
	}

	/**
	 * Software name from the bounded NodeInfo cache, without network I/O; an
	 * unknown or stale peer falls back to the instance-wide publishing setting.
	 */
	public function knownSoftware(string $host): string {
		$host = strtolower(trim($host));
		if ($host === '') {
			return '';
		}

		$entry = $this->known()['software'][$host] ?? null;
		if (!is_array($entry) || (int)($entry['checked'] ?? 0) < time() - self::SOFTWARE_TTL) {
			return '';
		}

		return strtolower(trim((string)($entry['name'] ?? '')));
	}

	/**
	 * The servers the directory of servers lists whose software one of these
	 * APIs fits. A failure is remembered as empty, so it is retried tomorrow.
	 *
	 * @return list<array{host: string, software: string}>
	 */
	private function fetchServerList(): array {
		try {
			$listed = $this->curlService->retrieveJson(
				'get',
				'https://' . self::DISCOVERY_HOST . self::DISCOVERY_PATH,
				['timeout' => self::TIMEOUT, 'json_headers' => false, 'headers' => ['Accept' => 'application/json']]
			);
		} catch (Throwable $e) {
			$this->logger->debug('[FediverseDirectoryService] no server list', ['exception' => $e]);

			return [];
		}

		$rows = $listed['data'] ?? $listed;
		$servers = [];
		foreach (is_array($rows) ? $rows : [] as $row) {
			if (count($servers) >= self::KNOWN_MAX) {
				break;
			}

			$host = strtolower(trim((string)(is_array($row) ? ($row['domain'] ?? '') : '')));
			$software = strtolower(trim((string)(is_array($row) ? ($row['software_name'] ?? '') : '')));
			if ($host !== '' && isset(self::SOFTWARE_KINDS[$software])) {
				$servers[] = ['host' => $host, 'software' => $software];
			}
		}

		return $servers;
	}

	/**
	 * What software a server says it runs, from its NodeInfo, or '' when it
	 * does not say. Over https unless the caller knows the server speaks
	 * plain http, as a development instance does.
	 */
	public function softwareName(string $host, string $scheme = 'https'): string {
		$name = '';
		try {
			$index = $this->curlService->retrieveJson(
				'get',
				$scheme . '://' . $host . '/.well-known/nodeinfo',
				['timeout' => self::TIMEOUT, 'json_headers' => false, 'headers' => ['Accept' => 'application/json']]
			);

			$document = '';
			foreach ($index['links'] ?? [] as $link) {
				$href = (string)(is_array($link) ? ($link['href'] ?? '') : '');
				// Any schema version: they all carry the software name.
				if ($href !== '' && str_starts_with($href, $scheme . '://' . $host . '/')) {
					$document = $href;
				}
			}

			if ($document !== '') {
				$nodeinfo = $this->curlService->retrieveJson(
					'get',
					$document,
					['timeout' => self::TIMEOUT, 'json_headers' => false, 'headers' => ['Accept' => 'application/json']]
				);
				$name = strtolower(trim((string)($nodeinfo['software']['name'] ?? '')));
			}
		} catch (Throwable $e) {
			$this->logger->debug('[FediverseDirectoryService] no nodeinfo', [
				'host' => $host, 'exception' => $e,
			]);
		}

		return $name;
	}

	/** Whether this instance is willing to talk to that host at all. */
	private function allowed(string $host): bool {
		if ($host === $this->configService->getCloudHost()) {
			return false;
		}

		try {
			$this->fediverseService->authorized($host);
		} catch (Throwable $e) {
			return false;
		}

		return true;
	}

	/**
	 * One entry per host, the first to claim it winning, so a configured server
	 * keeps the administrator's label and origin.
	 *
	 * @param DirectorySource[] $sources
	 *
	 * @return DirectorySource[]
	 */
	private function deduplicated(array $sources): array {
		$seen = [];
		foreach ($sources as $source) {
			$seen[$source->getHost()] ??= $source;
		}

		return array_values($seen);
	}

	/**
	 * Asks the sources about `$query` and merges what they say. A failing source
	 * is reported, not dropped, so "nobody by that name" and "did not answer"
	 * stay distinct.
	 *
	 * @param string $host one source's host, or '' for all of them
	 *
	 * @return array{accounts: DirectoryAccount[], sources: array<int, array<string, mixed>>}
	 */
	public function search(string $query, string $host = '', int $limit = self::LIMIT): array {
		$query = trim(ltrim(trim($query), '@'));
		$limit = max(1, min(self::MAX_LIMIT, $limit));

		$accounts = [];
		$reports = [];
		$deadline = microtime(true) + self::BUDGET;

		foreach ($this->sources() as $source) {
			if ($host !== '' && $source->getHost() !== $host) {
				continue;
			}

			if (!$source->isLocal() && microtime(true) >= $deadline) {
				$reports[] = $this->report($source, 'skipped', 0);
				continue;
			}

			try {
				$found = $this->ask($source, $query, $limit);
				$reports[] = $this->report($source, 'ok', count($found));
				foreach ($found as $account) {
					// The first source to name somebody wins; this instance is asked first, so
					// a person it already knows is offered as its own copy.
					$accounts[$account->getAcct()] ??= $account;
				}
			} catch (Throwable $e) {
				$this->logger->debug('[FediverseDirectoryService] a directory did not answer', [
					'host' => $source->getHost(), 'exception' => $e,
				]);
				$reports[] = $this->report($source, 'failed', 0);
			}
		}

		$accounts = $this->withoutBlockedHosts($accounts);
		$this->markKnown($accounts);

		return ['accounts' => array_values($accounts), 'sources' => $reports];
	}

	/**
	 * @return DirectoryAccount[]
	 *
	 * @throws Throwable when the source could not be asked
	 */
	private function ask(DirectorySource $source, string $query, int $limit): array {
		if ($source->isLocal()) {
			return $this->askLocal($query, $limit);
		}

		$cacheKey = $this->cacheKey($source, $query, $limit);
		$cached = $this->cache->get($cacheKey);
		if (is_string($cached)) {
			return $this->decode($cached, $source);
		}

		$found = match ($source->getKind()) {
			DirectorySource::KIND_MISSKEY => $this->askMisskey($source, $query, $limit),
			DirectorySource::KIND_LEMMY => $this->askLemmy($source, $query, $limit),
			DirectorySource::KIND_WORDPRESS => $this->askWordpress($source, $query, $limit),
			DirectorySource::KIND_BLUESKY => $this->askBluesky($source, $query, $limit),
			default => $this->askMastodon($source, $query, $limit),
		};

		$this->cache->set($cacheKey, json_encode(array_map(
			static fn (DirectoryAccount $account): array => $account->jsonSerialize(), $found
		)), self::CACHE_TTL);

		return $found;
	}

	/**
	 * This instance's own people: those who opted in to being listed, and the
	 * remote accounts it already holds. No network.
	 *
	 * @return DirectoryAccount[]
	 */
	private function askLocal(string $query, int $limit): array {
		$host = $this->configService->getCloudHost();

		$people = ($query === '')
			? $this->directoryService->page('active', $limit, 0)
			: $this->cacheActorService->searchCachedAccounts($query);

		$found = [];
		foreach (array_slice($people, 0, $limit) as $person) {
			if (!($person instanceof Person)) {
				continue;
			}

			$acct = $person->getAccount();
			if ($acct === '') {
				continue;
			}

			$account = new DirectoryAccount(
				str_contains($acct, '@') ? $acct : $acct . '@' . $host,
				$host,
				DirectorySource::KIND_LOCAL
			);
			$account->setDisplayName($person->getDisplayName() ?: $person->getPreferredUsername())
				->setNote($person->getDescription())
				->setAvatar($person->getAvatar())
				->setUrl($person->getId())
				->setKnown(true);

			$found[] = $account;
		}

		return $found;
	}

	/**
	 * Bluesky's typeahead: accounts whose handle or name starts like the
	 * query. Nothing to page through, and nothing for text that is not the
	 * start of a handle.
	 *
	 * @return DirectoryAccount[]
	 */
	private function askBluesky(DirectorySource $source, string $query, int $limit): array {
		$found = [];
		foreach ($this->bluesky?->typeahead($query, $limit) ?? [] as $person) {
			$account = new DirectoryAccount($person->getAccount(), $source->getHost(), DirectorySource::KIND_BLUESKY);
			$count = $person->getDetails(Details::COUNT);
			$account->setDisplayName($person->getName() ?: $person->getAccount())
				->setNote($person->getSummary())
				->setAvatar($person->getAvatar())
				->setUrl($person->getUrl())
				->setFollowersCount((int)($count['followers'] ?? -1))
				->setStatusesCount((int)($count['post'] ?? -1));
			$found[] = $account;
		}

		return $found;
	}

	/**
	 * A Mastodon-compatible source, asked its two public questions: the exact
	 * lookup, and a directory page filtered here because the endpoint takes no
	 * query.
	 *
	 * @return DirectoryAccount[]
	 */
	private function askMastodon(DirectorySource $source, string $query, int $limit): array {
		$found = [];

		if ($query !== '' && !str_contains($query, ' ')) {
			try {
				$exact = $this->get($source, '/api/v1/accounts/lookup', ['acct' => $query]);
				$account = $this->fromMastodon($exact, $source);
				if ($account !== null) {
					$found[$account->getAcct()] = $account;
				}
			} catch (Throwable $e) {
				// No such handle there: an answer, not a failure.
				$this->logger->debug('[FediverseDirectoryService] no exact handle', [
					'host' => $source->getHost(), 'exception' => $e,
				]);
			}
		}

		try {
			$page = $this->get($source, '/api/v1/directory', [
				'limit' => self::DIRECTORY_PAGE, 'offset' => 0, 'order' => 'active', 'local' => 'true',
			]);
		} catch (Throwable $e) {
			// Pixelfed and servers with the directory turned off answer 404 here; keep
			// what the exact lookup found rather than reporting the source as failed.
			$this->logger->debug('[FediverseDirectoryService] no public directory', [
				'host' => $source->getHost(), 'exception' => $e,
			]);
			if ($found === []) {
				throw $e;
			}

			return array_values($found);
		}

		foreach ($page as $row) {
			if (count($found) >= $limit) {
				break;
			}

			$account = $this->fromMastodon(is_array($row) ? $row : [], $source);
			if ($account !== null && $this->matches($account, $query)) {
				$found[$account->getAcct()] ??= $account;
			}
		}

		return array_values($found);
	}

	/**
	 * Misskey, which has a real substring search over names and handles;
	 * `origin: combined` includes the remote people it knows.
	 *
	 * @return DirectoryAccount[]
	 */
	private function askMisskey(DirectorySource $source, string $query, int $limit): array {
		if ($query === '') {
			// Its only listing endpoint, `users`, answers a different question.
			return [];
		}

		$rows = $this->post($source, '/api/users/search', [
			'query' => $query, 'limit' => $limit, 'detail' => true, 'origin' => 'combined',
		]);

		$found = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}

			$username = trim((string)($row['username'] ?? ''));
			if ($username === '') {
				continue;
			}

			// `host` is null for that server's own people.
			$host = trim((string)($row['host'] ?? '')) ?: $source->getHost();

			$account = new DirectoryAccount($username . '@' . $host, $source->getHost(), $source->getKind());
			$account->setDisplayName((string)($row['name'] ?? ''))
				->setNote((string)($row['description'] ?? ''))
				->setAvatar((string)($row['avatarUrl'] ?? ''))
				->setUrl((string)($row['uri'] ?? '') ?: 'https://' . $host . '/@' . $username)
				->setFollowersCount((int)($row['followersCount'] ?? -1))
				->setStatusesCount((int)($row['notesCount'] ?? -1))
				->setBot((bool)($row['isBot'] ?? false));

			$found[] = $account;
		}

		return $found;
	}

	/**
	 * A hand-kept directory published as a WordPress site, the one source that
	 * can answer a subject. Handles are read out of each entry's text only;
	 * links in it are never followed.
	 *
	 * @return DirectoryAccount[]
	 */
	private function askWordpress(DirectorySource $source, string $query, int $limit): array {
		if ($query === '') {
			// With no query the endpoint returns the newest entries, not a directory.
			return [];
		}

		$rows = $this->get($source, '/wp-json/wp/v2/posts', [
			'search' => $query,
			'per_page' => min($limit, self::WORDPRESS_PAGE),
			'_fields' => 'title,excerpt,content,link',
		]);

		$found = [];
		foreach ($rows as $row) {
			if (count($found) >= $limit) {
				break;
			}

			$row = is_array($row) ? $row : [];
			$handle = $this->handleIn(
				(string)($row['content']['rendered'] ?? '') . ' ' . (string)($row['excerpt']['rendered'] ?? '')
			);
			if ($handle === '') {
				continue;
			}

			$host = substr($handle, (int)strrpos($handle, '@') + 1);
			if (!$this->allowed($host)) {
				continue;
			}

			$account = new DirectoryAccount($handle, $host, $source->getKind());
			$account->setDisplayName(trim(html_entity_decode(strip_tags((string)($row['title']['rendered'] ?? '')))))
				->setNote(trim(html_entity_decode(strip_tags((string)($row['excerpt']['rendered'] ?? '')))))
				->setUrl((string)($row['link'] ?? ''));

			$found[$handle] ??= $account;
		}

		return array_values($found);
	}

	/**
	 * The first fediverse handle written in a piece of prose: letters, digits,
	 * dot, dash and underscore around a single `@`, and a host with a dot.
	 */
	private function handleIn(string $text): string {
		$text = html_entity_decode(strip_tags($text));
		if (preg_match('/@([A-Za-z0-9_.-]+)@([A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+)/', $text, $match) !== 1) {
			return '';
		}

		return strtolower($match[1] . '@' . $match[2]);
	}

	/**
	 * Lemmy, whose search is public and whose people carry their full actor id,
	 * so the handle names the server they are actually on.
	 *
	 * @return DirectoryAccount[]
	 */
	private function askLemmy(DirectorySource $source, string $query, int $limit): array {
		if ($query === '') {
			return [];
		}

		$answer = $this->get($source, '/api/v3/search', [
			'q' => $query, 'type_' => 'Users', 'listing_type' => 'All', 'limit' => $limit,
		]);

		$found = [];
		foreach ((array)($answer['users'] ?? []) as $row) {
			$person = is_array($row) ? (array)($row['person'] ?? []) : [];
			$name = trim((string)($person['name'] ?? ''));
			$actorId = (string)($person['actor_id'] ?? '');
			$host = (string)parse_url($actorId, PHP_URL_HOST);
			if ($name === '' || $host === '') {
				continue;
			}

			$account = new DirectoryAccount($name . '@' . $host, $source->getHost(), $source->getKind());
			$account->setDisplayName((string)($person['display_name'] ?? ''))
				->setNote((string)($person['bio'] ?? ''))
				->setAvatar((string)($person['avatar'] ?? ''))
				->setUrl($actorId)
				->setBot((bool)($person['bot_account'] ?? false));

			$found[] = $account;
		}

		return $found;
	}

	/**
	 * @param array<string, mixed> $row one Mastodon `Account`
	 */
	private function fromMastodon(array $row, DirectorySource $source): ?DirectoryAccount {
		$acct = trim((string)($row['acct'] ?? ''));
		if ($acct === '') {
			return null;
		}

		// A Mastodon `acct` is bare for that server's own people.
		if (!str_contains($acct, '@')) {
			$acct .= '@' . $source->getHost();
		}

		$account = new DirectoryAccount($acct, $source->getHost(), $source->getKind());
		$account->setDisplayName((string)($row['display_name'] ?? ''))
			->setNote((string)($row['note'] ?? ''))
			->setAvatar((string)($row['avatar'] ?? ''))
			->setUrl((string)($row['url'] ?? ''))
			->setFollowersCount((int)($row['followers_count'] ?? -1))
			->setStatusesCount((int)($row['statuses_count'] ?? -1))
			->setBot((bool)($row['bot'] ?? false));

		return $account;
	}

	/**
	 * Whether a directory row answers the query, for a source that cannot be
	 * asked one.
	 */
	private function matches(DirectoryAccount $account, string $query): bool {
		if ($query === '') {
			return true;
		}

		$needle = mb_strtolower($query);
		foreach ([$account->getAcct(), $account->getDisplayName(), $account->getNote()] as $field) {
			if (str_contains(mb_strtolower($field), $needle)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Drops everybody on a host this instance will not federate with:
	 * `CurlService` guards which server is asked, not who it tells us about.
	 *
	 * @param array<string, DirectoryAccount> $accounts
	 *
	 * @return array<string, DirectoryAccount>
	 */
	private function withoutBlockedHosts(array $accounts): array {
		return array_filter(
			$accounts,
			function (DirectoryAccount $account): bool {
				$host = $account->getHost();
				if ($host === '') {
					return false;
				}

				try {
					$this->fediverseService->authorized($host);
				} catch (Throwable $e) {
					return false;
				}

				return !$this->fediverseService->isSilenced($host);
			}
		);
	}

	/**
	 * Marks the people this instance already holds in its actor cache;
	 * nothing is fetched.
	 *
	 * @param array<string, DirectoryAccount> $accounts
	 */
	private function markKnown(array $accounts): void {
		foreach ($accounts as $account) {
			if ($account->isKnown()) {
				continue;
			}

			try {
				$this->cacheActorService->getFromAccount($account->getAcct(), false);
				$account->setKnown(true);
			} catch (Throwable $e) {
			}
		}
	}

	/**
	 * @param array<string, string|int> $params
	 *
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

	private function cacheKey(DirectorySource $source, string $query, int $limit): string {
		return sha1($source->getKind() . '|' . $source->getHost() . '|' . mb_strtolower($query) . '|' . $limit);
	}

	/**
	 * @return DirectoryAccount[]
	 */
	private function decode(string $raw, DirectorySource $source): array {
		$rows = json_decode($raw, true);
		if (!is_array($rows)) {
			return [];
		}

		$accounts = [];
		foreach ($rows as $row) {
			if (!is_array($row) || ($row['acct'] ?? '') === '') {
				continue;
			}

			$account = new DirectoryAccount((string)$row['acct'], $source->getHost(), $source->getKind());
			$account->setDisplayName((string)($row['display_name'] ?? ''))
				->setNote((string)($row['note'] ?? ''))
				->setAvatar((string)($row['avatar'] ?? ''))
				->setUrl((string)($row['url'] ?? ''))
				->setFollowersCount((int)($row['followers_count'] ?? -1))
				->setStatusesCount((int)($row['statuses_count'] ?? -1))
				->setBot((bool)($row['bot'] ?? false));

			$accounts[] = $account;
		}

		return $accounts;
	}

	/**
	 * @param array<string, mixed> $definition
	 */
	private function fromDefinition(array $definition): ?DirectorySource {
		$host = strtolower(trim((string)($definition['host'] ?? '')));
		// Keep only the host; the scheme and path are this class's to decide.
		$host = (string)(parse_url(str_contains($host, '://') ? $host : 'https://' . $host, PHP_URL_HOST) ?? '');
		if ($host === '') {
			return null;
		}

		$kind = strtolower(trim((string)($definition['kind'] ?? DirectorySource::KIND_MASTODON)));
		if (!in_array($kind, DirectorySource::KINDS, true) || $kind === DirectorySource::KIND_LOCAL) {
			// An unknown kind falls back to the most common one, so a config typo does
			// not hide a source.
			$kind = DirectorySource::KIND_MASTODON;
		}

		return new DirectorySource($host, $kind, trim((string)($definition['label'] ?? '')));
	}

	/**
	 * @return array<mixed>|null null when there is nothing usable configured
	 */
	private function configured(): ?array {
		$raw = trim((string)$this->configService->getAppValue(self::CONFIG_KEY));
		if ($raw === '') {
			return null;
		}

		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			$this->logger->warning(
				'[FediverseDirectoryService] the ' . self::CONFIG_KEY . ' app value is not a JSON array; ignoring it'
			);

			return null;
		}

		return $decoded;
	}
}
