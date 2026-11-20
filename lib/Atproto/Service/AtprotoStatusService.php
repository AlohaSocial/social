<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Service;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Firehose\EventService;
use OCA\Social\Atproto\Firehose\FirehoseDaemon;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\InstanceKeyService;
use OCA\Social\Atproto\Identity\KeySealService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Moderation\Blocklist;
use OCA\Social\Atproto\Reader\Jetstream\JetstreamListener;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Db\AtprotoWatchRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\IConfig;
use Throwable;

/**
 * What the administrator sees of the Bluesky side: settings, numbers, the
 * daemon, and the PDS requirements. Network checks are cached so the page
 * does not probe the instance on every load.
 */
class AtprotoStatusService {
	private const CACHE_KEY = 'social_atproto_checks';
	private const CACHE_OK = 3600;
	private const CACHE_FAILED = 300;

	public function __construct(
		private AtprotoConfig $config,
		private ConfigService $configService,
		private IdentityService $identities,
		private InstanceKeyService $instanceKeys,
		private EventService $events,
		private FirehoseDaemon $daemon,
		private AtprotoRepoRequest $repoRequest,
		private CurlService $curlService,
		private IConfig $systemConfig,
		private ICacheFactory $cacheFactory,
		private ITimeFactory $time,
		private ?AtprotoWatchRequest $watches = null,
		private ?Blocklist $blocklist = null,
		private ?JetstreamListener $listener = null,
		private ?KeySealService $keySeals = null,
		private ?AppViewClient $appView = null,
	) {
	}

	/**
	 * Everything the admin section draws.
	 */
	public function current(): array {
		return [
			'settings' => $this->config->export(),
			'status' => [
				'handle_host' => $this->safe(fn (): string => $this->config->handleHost()),
				'pds_endpoint' => $this->safe(fn (): string => $this->config->pdsEndpoint()),
				'service_did' => $this->safe(fn (): string => $this->config->serviceDid()),
				'identities' => $this->identities->count(),
				// of those, the ones their owners switched off for Bluesky
				'identities_off' => $this->identities->countDeactivated(),
				'repositories' => $this->repoRequest->countHeads(),
				'events_in_window' => $this->events->countInWindow(),
				'head_seq' => $this->events->latestSeq(),
				'relay_crawl' => $this->relayCrawlStatus(),
				// Reading Bluesky: followed authors and notification accounts, and how far
				// behind the slowest of each is.
				'reading' => $this->reading(),
				'blocks' => $this->blocklist?->list() ?? [],
				'rotation_key_age' => $this->rotationKeyAgeDays(),
				'daemon' => $this->daemon->status(),
				// the Jetstream listener, where one is configured
				'listener' => $this->config->jetstream() === '' ? null : $this->listener?->status(),
			],
			'checks' => $this->checks(),
		];
	}

	/** The last automatic request and whether any relay accepted it. */
	private function relayCrawlStatus(): array {
		$results = json_decode((string)$this->configService->getAppValue(ConfigService::ATPROTO_CRAWL_RESULTS), true);

		return [
			'attempted_at' => (int)$this->configService->getAppValue(ConfigService::ATPROTO_CRAWL_ATTEMPTED),
			'accepted_at' => (int)$this->configService->getAppValue(ConfigService::ATPROTO_CRAWL_ACCEPTED),
			'results' => is_array($results) ? $results : [],
		];
	}

	/**
	 * The requirements of §14.1, each with a state and what to do.
	 *
	 * @return array<int, array{id: string, state: string, detail: string}>
	 */
	public function checks(bool $fresh = false): array {
		$cache = $this->cacheFactory->createDistributed(self::CACHE_KEY);
		if (!$fresh) {
			$cached = $cache->get('checks');
			if (is_array($cached)) {
				return $cached;
			}
		}

		if (!$this->config->isEnabled() && !$fresh) {
			// nothing is probed for a switched-off instance; the switch runs the checks fresh
			return [];
		}
		$checks = [];
		$secure = $this->safe(fn (): bool => $this->config->isSecure(), false);
		$checks[] = ['id' => 'https', 'state' => $secure ? 'ok' : 'error', 'detail' => $secure ? '' : 'The instance is served over plain http; a PDS has to be https'];
		$checks[] = $this->trustedDomainCheck();
		$checks[] = $this->rootCheck();
		$checks[] = $this->oauthReachabilityCheck();
		$checks[] = $this->wellKnownCheck();
		$daemon = $this->daemon->status();
		$checks[] = [
			'id' => 'daemon',
			'state' => ($daemon['running'] ?? false) ? 'ok' : 'error',
			'detail' => ($daemon['running'] ?? false) ? '' : 'occ social:atproto:serve is not running',
		];
		$checks[] = $this->firehoseCheck();
		$checks[] = $this->relayCheck();
		$identity = $this->identities->latestActive();
		$checks[] = $this->relayRepoCheck($identity);
		$checks[] = $this->didResolutionCheck($identity);
		$checks[] = $this->appViewProfileCheck($identity);
		$checks[] = $this->appViewPostCheck();
		if ($this->keySeals !== null) {
			$checks[] = $this->keysCheck();
		}

		$failed = count(array_filter($checks, static fn (array $check): bool => $check['state'] === 'error')) > 0;
		$cache->set('checks', $checks, $failed ? self::CACHE_FAILED : self::CACHE_OK);

		return $checks;
	}

	/** whether every requirement is met, for the switch */
	public function ready(bool $fresh = false): bool {
		foreach ($this->checks($fresh) as $check) {
			if ($check['state'] === 'error') {
				return false;
			}
		}

		return true;
	}

	/** Whether an active repository is still missing from configured relays or the public AppView. */
	public function hasRelayIndexingGap(): bool {
		if ($this->identities->latestActive() === null) {
			return false;
		}
		$watched = ['relay_repo', 'appview_profile', 'appview_post'];
		foreach ($this->checks() as $check) {
			if (in_array($check['id'] ?? '', $watched, true) && ($check['state'] ?? '') !== 'ok') {
				return true;
			}
		}

		return false;
	}

	private function trustedDomainCheck(): array {
		$host = $this->safe(fn (): string => $this->config->handleHost());
		$domains = $this->systemConfig->getSystemValue('trusted_domains', []);
		$wanted = '*.' . $host;
		$ok = is_array($domains) && in_array($wanted, $domains, true);

		return [
			'id' => 'trusted_domains',
			'state' => $ok ? 'ok' : 'error',
			'detail' => $ok ? '' : 'Add ' . $wanted . ' to trusted_domains, so a handle host like alice.' . $host . ' is served',
		];
	}

	private function rootCheck(): array {
		$endpoint = $this->safe(fn (): string => $this->config->pdsEndpoint());
		$answer = $this->fetch($endpoint . '/xrpc/com.atproto.server.describeServer');
		$ok = $answer !== null && isset($answer['did']);

		return [
			'id' => 'xrpc_root',
			'state' => $ok ? 'ok' : 'error',
			'detail' => $ok ? '' : $endpoint . '/xrpc/ does not reach the app: add the root rules from contrib/webserver',
		];
	}

	/** Verify the public OAuth discovery chain that Bluesky clients use to sign in. */
	private function oauthReachabilityCheck(): array {
		$endpoint = rtrim($this->safe(fn (): string => $this->config->pdsEndpoint()), '/');
		$resource = $this->fetchOAuthMetadata($endpoint . '/.well-known/oauth-protected-resource');
		if ($resource === null || ($resource['resource'] ?? null) !== $endpoint || ($resource['authorization_servers'] ?? null) !== [$endpoint]) {
			return ['id' => 'oauth_discovery', 'state' => 'error', 'detail' => $endpoint . '/.well-known/oauth-protected-resource does not identify this PDS and its authorization server; add the root rules from contrib/webserver'];
		}

		$metadata = $this->fetchOAuthMetadata($endpoint . '/.well-known/oauth-authorization-server');
		$valid = $metadata !== null
			&& ($metadata['issuer'] ?? null) === $endpoint
			&& ($metadata['pushed_authorization_request_endpoint'] ?? '') === $endpoint . '/oauth/par'
			&& ($metadata['authorization_endpoint'] ?? '') === $endpoint . '/oauth/authorize'
			&& ($metadata['token_endpoint'] ?? '') === $endpoint . '/oauth/token'
			&& ($metadata['require_pushed_authorization_requests'] ?? false) === true
			&& ($metadata['authorization_response_iss_parameter_supported'] ?? false) === true
			&& ($metadata['client_id_metadata_document_supported'] ?? false) === true
			&& ($metadata['require_request_uri_registration'] ?? true) !== false
			&& $this->containsAll($metadata['response_types_supported'] ?? null, ['code'])
			&& $this->containsAll($metadata['grant_types_supported'] ?? null, ['authorization_code', 'refresh_token'])
			&& $this->containsAll($metadata['code_challenge_methods_supported'] ?? null, ['S256'])
			&& $this->containsAll($metadata['token_endpoint_auth_methods_supported'] ?? null, ['none', 'private_key_jwt'])
			&& $this->containsAll($metadata['token_endpoint_auth_signing_alg_values_supported'] ?? null, ['ES256'])
			&& $this->containsAll($metadata['scopes_supported'] ?? null, ['atproto'])
			&& $this->containsAll($metadata['dpop_signing_alg_values_supported'] ?? null, ['ES256']);

		return [
			'id' => 'oauth_discovery',
			'state' => $valid ? 'ok' : 'error',
			'detail' => $valid ? '' : $endpoint . '/.well-known/oauth-authorization-server is missing required AT Protocol OAuth metadata or is not served as JSON without redirects',
		];
	}

	/** @param mixed $values */
	private function containsAll(mixed $values, array $required): bool {
		return is_array($values)
			&& array_is_list($values)
			&& count(array_filter($values, 'is_string')) === count($values)
			&& array_diff($required, $values) === [];
	}

	/** Discovery documents must be JSON responses, not redirects or HTML fallbacks. */
	private function fetchOAuthMetadata(string $url): ?array {
		$status = 0;
		try {
			$body = $this->curlService->doRequest('get', $url, [
				'timeout' => 5,
				'json_headers' => false,
				'accept_errors' => true,
				'max_redirects' => 0,
				'headers' => ['Accept' => 'application/json'],
				'allow_local_address' => !str_starts_with($url, 'https://'),
			], $contentType, $status);
		} catch (Throwable) {
			return null;
		}
		if ($status !== 200 || !is_string($contentType) || preg_match('/^application\/json(?:\s*;|$)/i', trim($contentType)) !== 1) {
			return null;
		}
		$decoded = json_decode($body, true);

		return is_array($decoded) ? $decoded : null;
	}

	private function wellKnownCheck(): array {
		$identities = $this->identities->getAll(1);
		if ($identities === []) {
			return ['id' => 'handle_host', 'state' => 'warning', 'detail' => 'No identity yet to resolve; run occ social:atproto:identities'];
		}
		$identity = $identities[0];
		$scheme = $this->safe(fn (): bool => $this->config->isSecure(), false) ? 'https' : 'http';
		$url = $scheme . '://' . $identity->handle . '/.well-known/atproto-did';
		$status = 0;
		try {
			$body = $this->curlService->doRequest('get', $url, ['timeout' => 5, 'json_headers' => false, 'allow_local_address' => $scheme === 'http'], $contentType, $status);
			$ok = $status === 200 && trim($body) === $identity->did;
		} catch (Throwable) {
			$ok = false;
		}

		return [
			'id' => 'handle_host',
			'state' => $ok ? 'ok' : 'error',
			'detail' => $ok ? '' : $url . ' does not answer the DID: the wildcard DNS record, certificate and web-server rule for *.' . $this->safe(fn (): string => $this->config->handleHost()) . ' are needed',
		];
	}

	private function firehoseCheck(): array {
		$endpoint = $this->safe(fn (): string => $this->config->pdsEndpoint());
		$status = 0;
		$upgraded = false;
		try {
			$upgraded = $this->curlService->doWebSocketHandshake($endpoint . '/xrpc/com.atproto.sync.subscribeRepos', $status);
		} catch (Throwable) {
		}
		// 426 proves the path exists but not a WebSocket; relays need the firehose,
		// so without a working upgrade posts never reach Bluesky.

		return [
			'id' => 'firehose',
			'state' => $upgraded ? 'ok' : 'error',
			'detail' => $upgraded
				? ''
				: ($status === 426
					? 'The endpoint answers HTTP 426 but did not accept a WebSocket upgrade; verify the reverse proxy and firehose daemon'
					: $endpoint . '/xrpc/com.atproto.sync.subscribeRepos is not served (' . $status . '): the web server must proxy the WebSocket to the daemon'
				),
		];
	}

	/**
	 * What each relay says of this server (`com.atproto.sync.getHostStatus`):
	 * whether it crawls it, how many accounts it carries, whether it throttles
	 * or bans it. Past a relay's limits for a new server, posts stop reaching
	 * Bluesky. Never an error.
	 */
	private function relayCheck(): array {
		$host = (string)parse_url($this->safe(fn (): string => $this->config->pdsEndpoint()), PHP_URL_HOST);
		$relays = $this->config->relays();
		if ($host === '' || $relays === []) {
			return ['id' => 'relay_host', 'state' => 'warning', 'detail' => 'No relay is configured, so nothing on Bluesky reads this server'];
		}
		$nonPublicReason = $this->nonPublicHostReason($host);
		$state = 'ok';
		$details = [];
		foreach ($relays as $relay) {
			$name = (string)parse_url($relay, PHP_URL_HOST);
			$answer = $this->fetchAnswer($relay . '/xrpc/com.atproto.sync.getHostStatus?hostname=' . rawurlencode($host));
			$status = (string)($answer['status'] ?? '');
			$accounts = (int)($answer['accountCount'] ?? 0);
			[$ok, $detail] = match (true) {
				($answer['error'] ?? '') === 'HostNotFound' && $nonPublicReason !== null => [false, $name . ' cannot crawl this PDS because ' . $host . ' is ' . $nonPublicReason . '; configure public DNS, HTTPS and an externally reachable web server before requesting a crawl'],
				($answer['error'] ?? '') === 'HostNotFound' => [false, $name . ' has not crawled this server: verify public DNS, HTTPS and firehose reachability, then run occ social:atproto:crawl'],
				$status === 'active', $status === 'idle' => [true, $name . ': ' . $status . ', ' . $accounts . ' accounts'],
				$status === 'throttled' => [false, $name . ' throttles this server at ' . $accounts . ' accounts: ask its operator to raise the limit for this host'],
				$status === 'banned' => [false, $name . ' has banned this server: nothing it publishes reaches Bluesky through this relay'],
				$status === 'offline' => [false, $name . ' finds this server offline: is the firehose reachable from outside?'],
				default => [false, $name . ' did not say how it sees this server'],
			};
			$state = $ok ? $state : 'warning';
			$details[] = $detail;
		}

		return ['id' => 'relay_host', 'state' => $state, 'detail' => implode('; ', $details)];
	}

	private function nonPublicHostReason(string $host): ?string {
		if (filter_var($host, FILTER_VALIDATE_IP) !== false
			&& filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
			return 'a private or reserved IP address';
		}

		$host = strtolower(rtrim($host, '.'));
		if (in_array($host, ['localhost', 'ip6-localhost'], true)
			|| preg_match('/\.(?:localhost|local|test|invalid|example|internal)$/D', $host) === 1
			|| str_ends_with($host, '.home.arpa')) {
			return 'a local or reserved hostname';
		}

		return null;
	}

	private function didResolutionCheck(?Identity $identity): array {
		if ($identity === null) {
			return ['id' => 'did_resolution', 'state' => 'warning', 'detail' => 'No active identity is available for a public DID resolution check'];
		}
		$document = $this->fetch($this->config->plcDirectory() . '/' . rawurlencode($identity->did));
		$services = is_array($document['service'] ?? null) ? $document['service'] : [];
		$pds = null;
		foreach ($services as $service) {
			if (is_array($service) && ($service['id'] ?? '') === '#atproto_pds') {
				$pds = $service['serviceEndpoint'] ?? null;
				break;
			}
		}
		$ok = ($document['id'] ?? '') === $identity->did && is_string($pds) && rtrim($pds, '/') === rtrim($this->config->pdsEndpoint(), '/');

		return ['id' => 'did_resolution', 'state' => $ok ? 'ok' : 'warning', 'detail' => $ok ? '' : 'The public PLC directory did not resolve an active local DID to this PDS'];
	}

	/** Whether each configured relay has synchronized an actual local repository. */
	private function relayRepoCheck(?Identity $identity): array {
		if ($identity === null) {
			return ['id' => 'relay_repo', 'state' => 'warning', 'detail' => 'No active identity is available to check repository visibility on relays'];
		}
		$relays = $this->config->relays();
		if ($relays === []) {
			return ['id' => 'relay_repo', 'state' => 'warning', 'detail' => 'No relay is configured to check repository visibility'];
		}

		$state = 'ok';
		$details = [];
		foreach ($relays as $relay) {
			$name = (string)parse_url($relay, PHP_URL_HOST);
			$answer = $this->fetchAnswer($relay . '/xrpc/com.atproto.sync.getRepoStatus?did=' . rawurlencode($identity->did));
			if (($answer['did'] ?? '') === $identity->did && ($answer['active'] ?? false) === true) {
				$details[] = $name . ': repository active' . (isset($answer['rev']) && is_string($answer['rev']) ? ', revision ' . $answer['rev'] : '');
				continue;
			}

			$state = 'warning';
			if (($answer['error'] ?? '') === 'RepoNotFound') {
				$details[] = $name . ' has not synchronized ' . $identity->handle . ' yet';
			} elseif (($answer['did'] ?? '') === $identity->did && ($answer['active'] ?? null) === false) {
				$details[] = $name . ' reports ' . ($answer['status'] ?? 'an inactive repository') . ' for ' . $identity->handle;
			} else {
				$details[] = $name . ' could not confirm repository visibility for ' . $identity->handle;
			}
		}

		return ['id' => 'relay_repo', 'state' => $state, 'detail' => implode('; ', $details)];
	}

	private function appViewProfileCheck(?Identity $identity): array {
		if ($identity === null || $this->appView === null) {
			return ['id' => 'appview_profile', 'state' => 'warning', 'detail' => 'No active identity or public AppView client is available for this check'];
		}
		try {
			$profile = $this->appView->query('app.bsky.actor.getProfile', ['actor' => $identity->did]);
			$ok = ($profile['did'] ?? '') === $identity->did;
		} catch (Throwable) {
			$ok = false;
		}

		return ['id' => 'appview_profile', 'state' => $ok ? 'ok' : 'warning', 'detail' => $ok ? '' : 'The public AppView cannot currently resolve an active local profile; check relay crawling and allow indexing time'];
	}

	private function appViewPostCheck(): array {
		$record = $this->repoRequest->latestActivePost();
		if ($record === null || $this->appView === null) {
			return ['id' => 'appview_post', 'state' => 'warning', 'detail' => 'No active local post or public AppView client is available to verify post indexing'];
		}
		try {
			$answer = $this->appView->query('app.bsky.feed.getPostThread', ['uri' => $record->uri(), 'depth' => 0]);
			$post = $answer['thread']['post']['uri'] ?? '';
			$ok = $post === $record->uri();
		} catch (Throwable) {
			$ok = false;
		}

		return ['id' => 'appview_post', 'state' => $ok ? 'ok' : 'warning', 'detail' => $ok ? '' : 'The public AppView does not yet show the newest local post; check relay/firehose reachability and allow indexing time'];
	}

	/**
	 * A JSON answer whatever its status: an XRPC error is an object too.
	 */
	private function fetchAnswer(string $url): array {
		$status = 0;
		try {
			$body = $this->curlService->doRequest('get', $url, ['timeout' => 5, 'json_headers' => false, 'accept_errors' => true, 'headers' => ['Accept' => 'application/json'], 'allow_local_address' => !str_starts_with($url, 'https://')], $contentType, $status);
		} catch (Throwable) {
			return [];
		}
		$decoded = json_decode($body, true);

		return is_array($decoded) ? $decoded : [];
	}

	private function fetch(string $url): ?array {
		$status = 0;
		try {
			$body = $this->curlService->doRequest('get', $url, ['timeout' => 5, 'json_headers' => false, 'headers' => ['Accept' => 'application/json'], 'allow_local_address' => !str_starts_with($url, 'https://')], $contentType, $status);
		} catch (Throwable) {
			return null;
		}
		if ($status !== 200) {
			return null;
		}
		$decoded = json_decode($body, true);

		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * Whether every sealed key still opens: after a change of the instance
	 * secret none does, and nothing can be signed until they are re-sealed.
	 */
	private function keysCheck(): array {
		$sealed = $this->safe(fn (): int => $this->keySeals?->countSealed() ?? 0, 0);

		return [
			'id' => 'keys',
			'state' => $sealed === 0 ? 'ok' : 'error',
			'detail' => $sealed === 0 ? '' : $sealed . ' keys cannot be opened with the current secret: run occ social:atproto:keys:reseal with the previous secret in OC_OLD_SECRET',
		];
	}

	private function rotationKeyAgeDays(): int {
		$made = $this->instanceKeys->rotationKeyAge();

		return $made === 0 ? 0 : (int)floor(($this->time->getTime() - $made) / 86400);
	}

	/**
	 * @template T
	 * @param callable(): T $read
	 * @param T $fallback
	 * @return T
	 */
	private function safe(callable $read, mixed $fallback = ''): mixed {
		try {
			return $read();
		} catch (Throwable) {
			return $fallback;
		}
	}
	/**
	 * @return array{watches: int, lag: int, accounts: int, lag_notifications: int}
	 */
	private function reading(): array {
		if ($this->watches === null) {
			return ['watches' => 0, 'lag' => 0, 'accounts' => 0, 'lag_notifications' => 0];
		}
		$now = $this->time->getTime();

		return [
			'watches' => $this->watches->count(),
			'lag' => $this->watches->lag($now),
			'accounts' => $this->watches->count(CoreRequestBuilder::TABLE_ATPROTO_NOTIFY_CURSOR),
			'lag_notifications' => $this->watches->lag($now, CoreRequestBuilder::TABLE_ATPROTO_NOTIFY_CURSOR),
		];
	}

}
