<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Service;

use OCA\Social\Atproto\Firehose\EventService;
use OCA\Social\Atproto\Firehose\FirehoseDaemon;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\InstanceKeyService;
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
 * What the administrator sees of the Bluesky side: the settings, the
 * numbers, the daemon, and whether the requirements of a PDS are met.
 *
 * The requirement checks that need the network (the well-known and XRPC
 * surfaces reachable at the root) are cached for a while, as the client
 * API check is: an admin page must not probe the instance on every load.
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
				'repositories' => $this->repoRequest->countHeads(),
				'events_in_window' => $this->events->countInWindow(),
				'head_seq' => $this->events->latestSeq(),
				// reading Bluesky: the followed authors and the local accounts
				// whose notifications are asked for, and how far behind the
				// slowest of each is
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
		$checks[] = $this->wellKnownCheck();
		$daemon = $this->daemon->status();
		$checks[] = [
			'id' => 'daemon',
			'state' => ($daemon['running'] ?? false) ? 'ok' : 'error',
			'detail' => ($daemon['running'] ?? false) ? '' : 'occ social:atproto:serve is not running',
		];
		$checks[] = $this->firehoseCheck();

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
		try {
			$this->curlService->doRequest('get', $endpoint . '/xrpc/com.atproto.sync.subscribeRepos', ['timeout' => 5, 'json_headers' => false, 'allow_local_address' => !str_starts_with($endpoint, 'https://')], $contentType, $status);
		} catch (Throwable) {
		}
		// without the upgrade the daemon answers 426; the app answers 426 too,
		// so a 426 says only that something is there — the daemon check says
		// whether it is the daemon
		$ok = $status === 426;

		return [
			'id' => 'firehose',
			'state' => $ok ? 'ok' : 'error',
			'detail' => $ok ? '' : $endpoint . '/xrpc/com.atproto.sync.subscribeRepos is not served (' . (int)$status . '): the web server must proxy the WebSocket to the daemon',
		];
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
