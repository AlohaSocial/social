<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\AlohaDirectory;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Social\Atproto\Protocol\Encoding;
use OCA\Social\Service\CurlService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The signed lists of the Aloha Directory: `keys.json`, `servers.json` (the
 * servers apps suggest to newcomers) and `search.json` (the curated search
 * providers this server asks when somebody searches for people).
 *
 * They are fetched once a day and kept only after they verified: the key set
 * against the pinned root keys, the lists against the key set, and every
 * serial higher than the one kept before. A list that does not verify changes
 * nothing; the last good copy stays in use. A list older than 90 days is no
 * longer used at all, as the concept prescribes for apps.
 */
class DirectoryLists {
	public const LISTS = ['servers', 'search'];
	/** How many curated providers one search asks. */
	public const MAX_PROVIDERS = 4;

	private const REFRESH_SECONDS = 86400;
	private const RETRY_SECONDS = 3600;
	private const TIMEOUT = 15;

	public function __construct(
		private DirectoryConfig $config,
		private CurlService $curlService,
		private ListVerifier $verifier,
		private IConfig $systemConfig,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The curated providers to ask in a search, when the administrator uses the
	 * curated list (the default): sharing the server's default language first.
	 *
	 * @return list<array{domain: string, languages: list<string>, weight: float}>
	 */
	public function curatedSearchProviders(string $exceptDomain): array {
		if (!$this->config->usesAlohaList()) {
			return [];
		}
		$language = $this->systemConfig->getSystemValue('default_language', 'en');

		return $this->searchProviders(is_string($language) ? explode('_', $language)[0] : '', $exceptDomain);
	}

	/** Whether the lists are due: daily, or an hour after a failed attempt. */
	public function refreshDue(): bool {
		$status = $this->config->json(DirectoryConfig::LIST_PREFIX . 'status');
		$last = (int)($status['checked_at'] ?? 0);
		$wait = ($status['error'] ?? '') === '' ? self::REFRESH_SECONDS : self::RETRY_SECONDS;

		return $this->time->getTime() - $last >= $wait;
	}

	/**
	 * Fetches and verifies all three lists.
	 *
	 * @return array{ok: bool, error: string, changed: list<string>}
	 */
	public function refresh(): array {
		$now = $this->now();
		$changed = [];
		$error = '';

		try {
			$rootKeys = $this->config->rootKeys();
			if ($rootKeys === []) {
				throw new ListVerificationException('No root key of the Aloha Directory is configured');
			}

			[$listKeys, $keysChanged] = $this->refreshKeySet($rootKeys, $now);
			if ($keysChanged) {
				$changed[] = 'keys';
			}
			foreach (self::LISTS as $list) {
				if ($this->refreshList($list, $listKeys, $now)) {
					$changed[] = $list;
				}
			}
		} catch (Throwable $e) {
			$error = $e->getMessage();
			$this->logger->warning('the lists of the Aloha Directory could not be refreshed', ['exception' => $e]);
		}

		$status = $this->config->json(DirectoryConfig::LIST_PREFIX . 'status');
		$this->config->setJson(DirectoryConfig::LIST_PREFIX . 'status', [
			'checked_at' => $now->getTimestamp(),
			'ok_at' => $error === '' ? $now->getTimestamp() : (int)($status['ok_at'] ?? 0),
			'error' => $error,
		]);

		return ['ok' => $error === '', 'error' => $error, 'changed' => $changed];
	}

	/**
	 * The entries of `servers.json`, empty while there is no usable copy.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function servers(): array {
		return $this->usableEntries('servers');
	}

	/**
	 * Up to four curated providers that search accounts: those sharing
	 * `$language` first, then the rest, each group in a random order weighted
	 * by the directory's `weight`, so that the load spreads over the providers.
	 *
	 * @return list<array{domain: string, languages: list<string>, weight: float}>
	 */
	public function searchProviders(string $language, string $exceptDomain, int $limit = self::MAX_PROVIDERS): array {
		$sharing = [];
		$others = [];
		foreach ($this->usableEntries('search') as $entry) {
			$domain = is_string($entry['domain'] ?? null) ? strtolower($entry['domain']) : '';
			$capabilities = is_array($entry['capabilities'] ?? null) ? $entry['capabilities'] : [];
			if (($entry['kind'] ?? '') !== 'aloha' || $domain === '' || $domain === strtolower($exceptDomain)
				|| !in_array('accounts', $capabilities, true)) {
				continue;
			}
			$languages = [];
			foreach (is_array($entry['languages'] ?? null) ? $entry['languages'] : [] as $tag) {
				if (is_string($tag)) {
					$languages[] = $tag;
				}
			}
			$provider = [
				'domain' => $domain,
				'languages' => $languages,
				'weight' => max(0.01, is_numeric($entry['weight'] ?? null) ? (float)$entry['weight'] : 1.0),
			];
			if ($language !== '' && in_array(strtolower($language), array_map('strtolower', $languages), true)) {
				$sharing[] = $provider;
			} else {
				$others[] = $provider;
			}
		}

		return array_slice([...$this->weightedShuffle($sharing), ...$this->weightedShuffle($others)], 0, max(0, $limit));
	}

	/**
	 * What the administration page shows about each list.
	 *
	 * @return array<string, mixed>
	 */
	public function summary(): array {
		$lists = [];
		foreach (['keys', ...self::LISTS] as $list) {
			$stored = $this->config->json(DirectoryConfig::LIST_PREFIX . $list);
			$lists[$list] = [
				'serial' => (int)($stored['serial'] ?? 0),
				'issuedAt' => (string)($stored['issued_at'] ?? ''),
				'expiresAt' => (string)($stored['expires_at'] ?? ''),
				'fetchedAt' => (int)($stored['fetched_at'] ?? 0),
				'entries' => count(is_array($stored['entries'] ?? null) ? $stored['entries'] : []),
				'expired' => $this->isExpired($stored),
				'tooOld' => $this->isTooOld($stored),
			];
		}
		$status = $this->config->json(DirectoryConfig::LIST_PREFIX . 'status');

		return [
			'rootKeyConfigured' => $this->config->rootKeys() !== [],
			'checkedAt' => (int)($status['checked_at'] ?? 0),
			'okAt' => (int)($status['ok_at'] ?? 0),
			'error' => (string)($status['error'] ?? ''),
			'lists' => $lists,
		];
	}

	/**
	 * @param array<string, string> $rootKeys
	 *
	 * @return array{0: array<string, string>, 1: bool} the list keys, and whether the key set changed
	 */
	private function refreshKeySet(array $rootKeys, DateTimeImmutable $now): array {
		$stored = $this->config->json(DirectoryConfig::LIST_PREFIX . 'keys');
		$json = $this->fetch('keys');
		if ($this->unchanged($stored, $json)) {
			return [$this->decodeKeys($stored['list_keys'] ?? []), false];
		}

		$result = $this->verifier->verifyKeySet($json, $rootKeys, isset($stored['serial']) ? (int)$stored['serial'] : null, $now);
		$this->config->setJson(DirectoryConfig::LIST_PREFIX . 'keys', [
			'serial' => (int)$result['payload']['serial'],
			'sha256' => hash('sha256', $json),
			'issued_at' => (string)$result['payload']['issued_at'],
			'expires_at' => (string)$result['payload']['expires_at'],
			'fetched_at' => $now->getTimestamp(),
			'list_keys' => array_map(static fn (string $key): string => Encoding::base64UrlEncode($key), $result['listKeys']),
		]);

		return [$result['listKeys'], true];
	}

	/**
	 * @param 'servers'|'search' $list
	 * @param array<string, string> $listKeys
	 */
	private function refreshList(string $list, array $listKeys, DateTimeImmutable $now): bool {
		$stored = $this->config->json(DirectoryConfig::LIST_PREFIX . $list);
		$json = $this->fetch($list);
		if ($this->unchanged($stored, $json)) {
			return false;
		}

		$result = $this->verifier->verifyList($json, $listKeys, $list, isset($stored['serial']) ? (int)$stored['serial'] : null, $now);
		$entries = $result['payload']['entries'] ?? [];
		$this->config->setJson(DirectoryConfig::LIST_PREFIX . $list, [
			'serial' => (int)$result['payload']['serial'],
			'sha256' => hash('sha256', $json),
			'issued_at' => (string)$result['payload']['issued_at'],
			'expires_at' => (string)$result['payload']['expires_at'],
			'fetched_at' => $now->getTimestamp(),
			'entries' => is_array($entries) ? array_values(array_filter($entries, 'is_array')) : [],
		]);

		return true;
	}

	private function fetch(string $list): string {
		$status = 0;
		$contentType = null;
		$body = $this->curlService->doRequest(
			'GET',
			$this->config->listBaseUrl() . '/lists/v1/' . $list . '.json',
			['headers' => ['Accept' => 'application/json'], 'timeout' => self::TIMEOUT, 'json_headers' => false, 'federation' => false, 'accept_errors' => true],
			$contentType,
			$status,
		);
		if ($status !== 200) {
			throw new ListVerificationException($list . '.json answered HTTP ' . (int)$status);
		}

		return $body;
	}

	/**
	 * The same bytes as the copy kept: verified when they were kept, and their
	 * serial would be refused as not higher if checked again.
	 *
	 * @param array<string, mixed> $stored
	 */
	private function unchanged(array $stored, string $json): bool {
		return is_string($stored['sha256'] ?? null) && hash_equals($stored['sha256'], hash('sha256', $json));
	}

	/**
	 * @param mixed $encoded
	 *
	 * @return array<string, string>
	 */
	private function decodeKeys($encoded): array {
		$keys = [];
		foreach (is_array($encoded) ? $encoded : [] as $kid => $key) {
			if (is_string($kid) && is_string($key)) {
				$keys[$kid] = ListVerifier::publicKey($key);
			}
		}
		if ($keys === []) {
			throw new ListVerificationException('The kept key set has no list key');
		}

		return $keys;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function usableEntries(string $list): array {
		$stored = $this->config->json(DirectoryConfig::LIST_PREFIX . $list);
		if ($this->isTooOld($stored)) {
			return [];
		}
		$entries = $stored['entries'] ?? [];

		return is_array($entries) ? array_values(array_filter($entries, 'is_array')) : [];
	}

	/**
	 * @param array<string, mixed> $stored
	 */
	private function isExpired(array $stored): bool {
		$expires = $this->parse($stored['expires_at'] ?? null);

		return $expires !== null && $expires < $this->now();
	}

	/**
	 * @param array<string, mixed> $stored
	 */
	private function isTooOld(array $stored): bool {
		$issued = $this->parse($stored['issued_at'] ?? null);

		return $issued === null || $issued->modify('+90 days') < $this->now();
	}

	/**
	 * @param mixed $value
	 */
	private function parse($value): ?DateTimeImmutable {
		if (!is_string($value) || $value === '') {
			return null;
		}
		try {
			return new DateTimeImmutable($value);
		} catch (Throwable) {
			return null;
		}
	}

	private function now(): DateTimeImmutable {
		return (new DateTimeImmutable('@' . $this->time->getTime()))->setTimezone(new DateTimeZone('UTC'));
	}

	/**
	 * Efraimidis–Spirakis: each provider drawn with a probability proportional to its weight.
	 *
	 * @param list<array{domain: string, languages: list<string>, weight: float}> $items
	 *
	 * @return list<array{domain: string, languages: list<string>, weight: float}>
	 */
	private function weightedShuffle(array $items): array {
		$keyed = [];
		foreach ($items as $index => $item) {
			$u = (float)random_int(1, PHP_INT_MAX) / (float)PHP_INT_MAX;
			$keyed[] = [$u ** (1.0 / $item['weight']), $index];
		}
		usort($keyed, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

		return array_map(static fn (array $pair) => $items[$pair[1]], $keyed);
	}
}
