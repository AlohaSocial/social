<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\OAuth;

use OCA\Social\Atproto\Identity\DnsLookup;
use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Atproto\Move\PdsClient;
use OCA\Social\Atproto\Protocol\Syntax;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The permission sets an `include:` scope names, by Lexicon resolution: the
 * NSID's authority, reversed, is a DNS name whose `_lexicon` TXT record
 * names a DID; that DID's repository holds the set as a
 * `com.atproto.lexicon.schema` record keyed by the NSID. Only `repo` and
 * `rpc` permissions are taken from a set, and only for collections and
 * methods in the set's own namespace or below it. A set is kept a day at
 * most, a failure five minutes.
 */
class PermissionSets {
	public const KEPT = 86400;
	private const FAILED_KEPT = 300;
	private const SCHEMA = 'com.atproto.lexicon.schema';

	private ?ICache $cache = null;

	public function __construct(
		private DnsLookup $dns,
		private PlcClient $plc,
		private PdsClient $pds,
		private ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The set an NSID names, or null when it cannot be had or is none.
	 *
	 * @return array{title: string, detail: string, title_lang: array<string, string>, detail_lang: array<string, string>, permissions: list<array>}|null
	 */
	public function resolve(string $nsid): ?array {
		if (!Syntax::isNsid($nsid)) {
			return null;
		}
		$key = md5($nsid);
		$cached = $this->cache()->get($key);
		if (is_array($cached)) {
			return $cached['set'] ?? null;
		}
		$set = $this->fetch($nsid);
		$this->cache()->set($key, ['set' => $set], $set === null ? self::FAILED_KEPT : self::KEPT);

		return $set;
	}

	/**
	 * The rules an `include:` brings, the `aud` it names given to the `rpc`
	 * permissions that inherit it; null when the set cannot be had.
	 *
	 * @return list<array>|null
	 */
	public function expand(string $nsid, string $aud): ?array {
		$set = $this->resolve($nsid);
		if ($set === null) {
			return null;
		}
		$rules = [];
		foreach ($set['permissions'] as $permission) {
			if ($permission['resource'] === 'rpc' && ($permission['inheritAud'] ?? false) === true) {
				if ($aud === '') {
					continue;
				}
				$permission['aud'] = $aud;
			}
			unset($permission['inheritAud']);
			if (($permission['aud'] ?? '') !== '' || $permission['resource'] === 'repo') {
				$rules[] = $permission;
			}
		}

		return $rules;
	}

	private function fetch(string $nsid): ?array {
		$parts = explode('.', $nsid);
		array_pop($parts);
		$group = implode('.', $parts);
		$did = '';
		foreach ($this->dns->txt('_lexicon.' . implode('.', array_reverse($parts))) as $text) {
			if (str_starts_with($text, 'did=')) {
				$did = substr($text, 4);
				break;
			}
		}
		if (!str_starts_with($did, 'did:plc:')) {
			return null;
		}
		try {
			$endpoint = (string)($this->plc->data($did)['services']['atproto_pds']['endpoint'] ?? '');
			$answer = $this->pds->call($this->pds->origin($endpoint), 'com.atproto.repo.getRecord', 'GET', ['repo' => $did, 'collection' => self::SCHEMA, 'rkey' => $nsid]);
		} catch (Throwable $e) {
			$this->logger->info('Permission set not resolved', ['nsid' => $nsid, 'exception' => $e]);

			return null;
		}
		$main = $answer['body']['value']['defs']['main'] ?? null;
		if ($answer['status'] !== 200 || ($answer['body']['value']['id'] ?? '') !== $nsid || !is_array($main) || ($main['type'] ?? '') !== 'permission-set') {
			return null;
		}

		return [
			'title' => is_string($main['title'] ?? null) ? $main['title'] : $nsid,
			'detail' => is_string($main['detail'] ?? null) ? $main['detail'] : '',
			'title_lang' => self::strings($main['title:lang'] ?? []),
			'detail_lang' => self::strings($main['detail:lang'] ?? []),
			'permissions' => self::permissions(is_array($main['permissions'] ?? null) ? $main['permissions'] : [], $group),
		];
	}

	/**
	 * The `repo` and `rpc` permissions of a set that stay in its namespace.
	 *
	 * @return list<array>
	 */
	private static function permissions(array $listed, string $group): array {
		$inside = static fn (string $nsid): bool => str_starts_with($nsid, $group . '.') && Syntax::isNsid($nsid);
		$kept = [];
		foreach ($listed as $permission) {
			if (!is_array($permission) || ($permission['type'] ?? '') !== 'permission') {
				continue;
			}
			if (($permission['resource'] ?? '') === 'repo') {
				$collections = array_values(array_filter(self::strings($permission['collection'] ?? []), $inside));
				$actions = array_values(array_intersect(self::strings($permission['action'] ?? Permissions::REPO_ACTIONS), Permissions::REPO_ACTIONS));
				if ($collections !== [] && $actions !== []) {
					$kept[] = ['resource' => 'repo', 'collection' => $collections, 'action' => $actions];
				}
			} elseif (($permission['resource'] ?? '') === 'rpc') {
				$methods = array_values(array_filter(self::strings($permission['lxm'] ?? []), $inside));
				if ($methods !== []) {
					$kept[] = ['resource' => 'rpc', 'lxm' => $methods, 'aud' => is_string($permission['aud'] ?? null) ? $permission['aud'] : '', 'inheritAud' => ($permission['inheritAud'] ?? false) === true];
				}
			}
		}

		return $kept;
	}

	/**
	 * @return array<array-key, string>
	 */
	private static function strings(mixed $value): array {
		return is_array($value) ? array_filter($value, 'is_string') : [];
	}

	private function cache(): ICache {
		return $this->cache ??= $this->cacheFactory->createDistributed('social-atproto-permission-sets');
	}
}
