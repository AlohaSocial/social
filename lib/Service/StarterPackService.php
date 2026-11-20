<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Atproto\Reader\SuggestedStarterPacks;
use OCA\Social\Db\StarterPacksRequest;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\StarterPack;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Starter packs: a named handful of accounts worth following, for a new
 * account with no follow graph for `SuggestionService` to use.
 *
 * The shipped packs are the official accounts of the projects this app
 * federates with: organisations, unambiguously who they claim to be. An
 * administrator replaces or extends them with the `starter_packs` app value, a
 * JSON array of `{slug, name, description, handles}`:
 *
 * - unset: the shipped packs;
 * - `[]`: no packs at all;
 * - a list: the shipped packs, each replaced by an entry with its slug, plus
 *   the rest.
 *
 * Nothing here writes a row; the handles are the only durable reference.
 */
class StarterPackService {
	/** The config key an administrator overrides the shipped packs with. */
	public const CONFIG_KEY = 'starter_packs';

	/** How many handles one pack may hold, resolved or not. */
	public const MAX_HANDLES = 50;

	/**
	 * The shipped packs.
	 *
	 * Official project accounts only -- see the class docblock for why the list
	 * is this narrow. Handles are `user@host` without a leading `@`.
	 */
	private const BUILTIN = [
		[
			'slug' => 'fediverse-projects',
			'name' => 'The projects behind the network',
			'description' => 'The official accounts of the software this app talks to. A reasonable first follow for anyone who wants to know what is changing under them.',
			'handles' => [
				'nextcloud@mastodon.xyz',
				'Mastodon@mastodon.social',
				'pixelfed@mastodon.social',
			],
		],
		[
			'slug' => 'photography',
			'name' => 'Photography',
			'description' => 'Where the pictures are. Pixelfed is the photo-sharing side of the same network, and these are its own accounts.',
			'handles' => [
				'pixelfed@mastodon.social',
				'dansup@mastodon.social',
			],
		],
	];

	public function __construct(
		private ConfigService $configService,
		private CacheActorService $cacheActorService,
		private FollowService $followService,
		private LoggerInterface $logger,
		private ?StarterPacksRequest $starterPacksRequest = null,
		private ?StarterPackModeration $moderation = null,
		private ?RemoteFetchQueue $remoteFetchQueue = null,
	) {
	}

	/**
	 * Every pack, without resolving anybody: the index needs only names and
	 * counts, and resolving would mean a WebFinger lookup per handle.
	 *
	 * @return StarterPack[]
	 */
	public function packs(): array {
		$packs = $this->chosenPacks();
		if ($packs === null) {
			return [];
		}

		// the collections read in from other servers come after the packs this
		// instance chose itself, and never replace one of them
		foreach ($this->collectionPacks() as $pack) {
			$packs[$pack->getSlug()] ??= $pack;
		}

		return array_values($packs);
	}

	/**
	 * The packs this instance chose itself, shipped and configured, by slug;
	 * null when it suggests nobody.
	 *
	 * @return array<string, StarterPack>|null
	 */
	private function chosenPacks(): ?array {
		$configured = $this->configured();

		// An explicit empty list means "suggest nobody". `null` is unset or
		// malformed and falls back to what ships, so a typo cannot empty the page.
		if ($configured === []) {
			return null;
		}

		$packs = [];
		foreach (self::BUILTIN as $definition) {
			$pack = $this->fromDefinition($definition, StarterPack::SOURCE_BUILTIN);
			if ($pack !== null) {
				$packs[$pack->getSlug()] = $pack;
			}
		}

		foreach ($configured ?? [] as $definition) {
			// `{"a": "b"}` decodes to an array whose *entries* are strings, so
			// "is it an array" is not enough to know an entry is a definition
			if (!is_array($definition)) {
				continue;
			}

			$pack = $this->fromDefinition($definition, StarterPack::SOURCE_LOCAL);
			if ($pack !== null) {
				// a configured pack with a shipped slug replaces it, so an
				// instance can edit what is shipped rather than only add to it
				$packs[$pack->getSlug()] = $pack;
			}
		}

		return $packs;
	}

	/**
	 * The packs `CollectionPackService` read in, fediverse collections and
	 * Bluesky's suggestions alike, listed by their size alone: the index
	 * draws no member, so none is read. A pack whose curator this instance's
	 * moderation keeps out is not listed.
	 *
	 * @return list<StarterPack>
	 */
	private function collectionPacks(): array {
		if ($this->starterPacksRequest === null) {
			return [];
		}

		try {
			$stored = $this->starterPacksRequest->getIndex();
		} catch (Throwable $e) {
			$this->logger->warning('[StarterPackService] could not read the starter packs read in from other servers', ['exception' => $e]);

			return [];
		}

		$curators = array_flip($this->admitted(array_values(array_unique(array_column($stored, 'curator')))));
		$packs = [];
		foreach ($stored as $row) {
			if (!isset($curators[$row['curator']])) {
				continue;
			}
			$packs[] = (new StarterPack($row['slug'], $row['name'], $row['summary'], [], self::sourceOf($row['origin'])))
				->setOrigin($row['curator_acct'], $row['url'], $row['topic'], $row['language'])
				->setSize(min($row['member_count'], self::MAX_HANDLES));
		}

		return $packs;
	}

	/** A pack read in, with its members; null when there is none to offer at that slug. */
	private function collectionPack(string $slug): ?StarterPack {
		if ($this->starterPacksRequest === null) {
			return null;
		}

		try {
			$row = $this->starterPacksRequest->getBySlug($slug);
		} catch (Throwable $e) {
			$this->logger->warning('[StarterPackService] could not read a starter pack read in from another server', ['exception' => $e]);

			return null;
		}

		if ($row === null || $row['members'] === [] || $this->admitted([$row['curator']]) === []) {
			return null;
		}
		$members = array_slice($row['members'], 0, self::MAX_HANDLES);

		return (new StarterPack($row['slug'], $row['name'], $row['summary'], $members, self::sourceOf($row['origin'])))
			->setOrigin($row['curator_acct'], $row['url'], $row['topic'], $row['language']);
	}

	private static function sourceOf(string $origin): string {
		return ($origin === SuggestedStarterPacks::ORIGIN) ? StarterPack::SOURCE_BLUESKY : StarterPack::SOURCE_FEDIVERSE;
	}

	/**
	 * The actor ids this instance's moderation lets a pack offer.
	 *
	 * @param list<string> $actorIds
	 *
	 * @return list<string>
	 */
	private function admitted(array $actorIds): array {
		return $this->moderation?->admitted($actorIds) ?? $actorIds;
	}

	/**
	 * One pack, with its handles resolved to the profiles held here. Nothing
	 * is fetched: a handle not held yet, or one that will not resolve, is
	 * reported in `unresolved` and queued, so a pack never quietly shrinks.
	 * Accounts this instance's moderation keeps out are not offered.
	 *
	 * @throws ItemNotFoundException when there is no such pack
	 */
	public function pack(string $slug): StarterPack {
		return $this->resolve($this->find($slug), false);
	}

	/**
	 * A pack by its slug, its handles not resolved: one this instance chose,
	 * or else one read in.
	 *
	 * @throws ItemNotFoundException when there is no such pack
	 */
	private function find(string $slug): StarterPack {
		$chosen = $this->chosenPacks();
		$pack = ($chosen === null) ? null : ($chosen[$slug] ?? $this->collectionPack($slug));
		if ($pack === null) {
			throw new ItemNotFoundException('unknown starter pack');
		}

		return $pack;
	}

	/**
	 * Follows everyone in a pack that can be reached; one that cannot is
	 * skipped rather than failing the lot. A member not held here yet is
	 * fetched now, once, and followed on whichever network.
	 *
	 * @return string[] the handles that were followed
	 *
	 * @throws ItemNotFoundException when there is no such pack
	 */
	public function followAll(Person $viewer, string $slug): array {
		$followed = [];
		$pack = $this->resolve($this->find($slug), true);
		foreach ($pack->getAccounts() as $account) {
			if ($account->getId() === $viewer->getId()) {
				continue;
			}

			try {
				$this->followService->followActor($viewer, $account);
				$followed[] = $account->getAccount();
			} catch (Throwable $e) {
				$this->logger->info('[StarterPackService] could not follow an account from a pack', [
					'account' => $account->getAccount(),
					'pack' => $slug,
					'exception' => $e,
				]);
			}
		}

		return $followed;
	}

	/**
	 * @param bool $mayFetch whether a handle not held here is fetched now, or
	 *                       only queued to be
	 *
	 * @return StarterPack the same pack, with `accounts` and `unresolved` filled in
	 */
	private function resolve(StarterPack $pack, bool $mayFetch): StarterPack {
		// a pack read in names its members by actor id, all looked up at once
		$ids = array_values(array_filter($pack->getHandles(), static fn (string $handle): bool => str_starts_with($handle, 'https://')));
		$cached = ($ids === []) ? [] : $this->cacheActorService->getCachedFromIds($ids);

		$accounts = [];
		$unresolved = [];
		foreach ($pack->getHandles() as $handle) {
			$byId = str_starts_with($handle, 'https://');
			$actor = $cached[$handle] ?? null;
			try {
				if ($actor === null && $byId && $mayFetch) {
					$actor = $this->cacheActorService->getFromId($handle);
				} elseif ($actor === null && !$byId) {
					$actor = $this->cacheActorService->getFromAccount($handle, $mayFetch);
				}
			} catch (Throwable $e) {
				$this->logger->debug('[StarterPackService] could not resolve a handle', [
					'handle' => $handle,
					'exception' => $e->getMessage(),
				]);
			}

			if ($actor === null) {
				$unresolved[] = $handle;
			} else {
				$accounts[] = $actor;
			}
		}

		if (!$mayFetch && $unresolved !== []) {
			$this->remoteFetchQueue?->resolveActors(array_values(array_filter($unresolved, static fn (string $handle): bool => str_starts_with($handle, 'https://'))));
			$this->remoteFetchQueue?->resolveAccounts(array_values(array_filter($unresolved, static fn (string $handle): bool => !str_starts_with($handle, 'https://'))));
		}

		return $pack->setAccounts($this->moderation?->admittedActors($accounts) ?? $accounts)->setUnresolved($unresolved);
	}

	/**
	 * What the administrator configured: `null` for unset or malformed
	 * (malformed is logged, never thrown, and falls back to what ships), `[]`
	 * for "suggest nobody". A JSON object decodes to an array too, so the
	 * caller checks each entry.
	 *
	 * @return array<int, mixed>|null
	 */
	private function configured(): ?array {
		$raw = trim((string)$this->configService->getAppValue(self::CONFIG_KEY));
		if ($raw === '') {
			return null;
		}

		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			$this->logger->warning(
				'[StarterPackService] the ' . self::CONFIG_KEY . ' app value is not a JSON array; ignoring it'
			);

			return null;
		}

		return $decoded;
	}

	/**
	 * @param array $definition one entry from the shipped list or the config
	 *
	 * @return StarterPack|null null when the entry is unusable
	 */
	private function fromDefinition(array $definition, string $source): ?StarterPack {
		$slug = $this->slug((string)($definition['slug'] ?? ''));
		if ($slug === '') {
			return null;
		}

		$handles = [];
		foreach ((array)($definition['handles'] ?? []) as $handle) {
			$handle = $this->handle((string)$handle);
			if ($handle !== '' && !in_array($handle, $handles, true)) {
				$handles[] = $handle;
			}

			if (count($handles) >= self::MAX_HANDLES) {
				break;
			}
		}

		if ($handles === []) {
			return null;
		}

		return new StarterPack(
			$slug,
			(string)($definition['name'] ?? $slug),
			(string)($definition['description'] ?? ''),
			$handles,
			$source
		);
	}

	/** A slug is a path segment, so it is held to what one may contain. */
	private function slug(string $slug): string {
		$slug = strtolower(trim($slug));

		return preg_match('/^[a-z0-9-]{1,64}$/', $slug) ? $slug : '';
	}

	/**
	 * `user@host`, with any leading `@` taken off. Shaped rather than trusted,
	 * as these strings reach a WebFinger lookup.
	 */
	private function handle(string $handle): string {
		$handle = ltrim(trim($handle), '@');

		return preg_match('/^[\w.\-]+@[\w.\-]+\.[a-zA-Z]{2,}$/', $handle) ? $handle : '';
	}
}
