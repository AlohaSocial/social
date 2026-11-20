<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Atproto\Reader\SuggestedStarterPacks;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\StarterPacksRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The starter packs other servers publish, read in: fediverse collections
 * and Bluesky's suggested starter packs.
 *
 * Fediverse collections are FEP-7aa9 `FeaturedCollection`s (Mastodon 4.6),
 * linked from the curator's actor as `featuredCollections`. Each item's
 * `featureAuthorization` must resolve to the member's own consent on its own
 * server, or the item is left out; a member whose server does not answer
 * keeps its earlier standing.
 *
 * The `starter_pack_sources` app value lists handles, collection addresses
 * and `bluesky:suggested`; unset is the shipped list, `[]` reads nobody. A
 * collection that is not `discoverable`, is `sensitive`, or has no verified
 * member is not offered, nor is an account moderation keeps out
 * (`StarterPackModeration`).
 *
 * Read daily by `Cron\StarterPackSources` and by
 * `occ social:starter-packs:refresh`, never on a page; an unreachable curator
 * keeps what was read last, and uncached members are queued
 * (`RemoteFetchQueue`).
 */
class CollectionPackService {
	/** The app value naming the curators and collections to read. */
	public const CONFIG_KEY = 'starter_pack_sources';

	/** The source naming Bluesky's suggested starter packs. */
	public const BLUESKY_SUGGESTED = 'bluesky:suggested';

	/** Read when the app value is unset: @Gargron's curated collections on mastodon.social, and Bluesky's suggestions. */
	public const DEFAULT_SOURCES = [
		'Gargron@mastodon.social',
		self::BLUESKY_SUGGESTED,
	];

	/** The `origin` of a collection read from the fediverse. */
	public const ORIGIN = 'fediverse';

	/** The most sources read in one pass. */
	public const MAX_SOURCES = 20;

	/** The most collections read from one curator. */
	public const MAX_COLLECTIONS = 20;

	/** The most members kept of one collection: as many as a starter pack offers. */
	public const MAX_ITEMS = StarterPackService::MAX_HANDLES;

	private const TYPE_COLLECTION = 'FeaturedCollection';
	private const TYPE_ITEM = 'FeaturedItem';
	private const TYPE_AUTHORIZATION = 'FeatureAuthorization';

	public function __construct(
		private ConfigService $configService,
		private CacheActorService $cacheActorService,
		private CurlService $curlService,
		private StarterPacksRequest $starterPacksRequest,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
		private RemoteFetchQueue $remoteFetchQueue,
		private StarterPackModeration $moderation,
		private ?ContainerInterface $container = null,
	) {
	}

	/**
	 * The handles and collection addresses to read.
	 *
	 * @return list<string>
	 */
	public function sources(): array {
		$raw = trim((string)$this->configService->getAppValue(self::CONFIG_KEY));
		if ($raw === '') {
			return self::DEFAULT_SOURCES;
		}

		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			$this->logger->warning('[CollectionPackService] the ' . self::CONFIG_KEY . ' app value is not a JSON array; reading the shipped sources');

			return self::DEFAULT_SOURCES;
		}

		$sources = [];
		foreach ($decoded as $source) {
			$source = is_string($source) ? self::source($source) : '';
			if ($source !== '' && !in_array($source, $sources, true)) {
				$sources[] = $source;
			}
		}

		return array_slice($sources, 0, self::MAX_SOURCES);
	}

	/**
	 * Reads every source again and stores what it publishes now.
	 *
	 * `failed` counts the sources and the collections that could not be
	 * read, and the sources the deadline left unread; each keeps what was
	 * read from it last time.
	 *
	 * @param int $deadline the time past which no more is read; 0 for none
	 *
	 * @return array{sources: int, packs: int, members: int, refused: int, failed: int}
	 */
	public function refresh(int $deadline = 0): array {
		$sources = $this->sources();
		$result = ['sources' => count($sources), 'packs' => 0, 'members' => 0, 'refused' => 0, 'failed' => 0];
		/** @var array<string, true> $seen the collections read this pass, or kept as they were, by id */
		$seen = [];
		/** @var array<string, true> $read the curators read in full this pass */
		$read = [];
		$outOfTime = false;

		foreach ($sources as $source) {
			if ($source === self::BLUESKY_SUGGESTED) {
				continue;
			}
			if ($outOfTime || $this->pastDeadline($deadline)) {
				$outOfTime = true;
				$result['failed']++;
				continue;
			}
			try {
				[$curator, $collections] = $this->collectionsOf($source);
			} catch (Throwable $e) {
				$result['failed']++;
				$this->logger->notice('[CollectionPackService] could not read a source of starter packs', [
					'source' => $source, 'exception' => $e,
				]);
				continue;
			}

			// two sources may name one curator, a handle and one of its
			// collections; what was read for it once is not read again
			if (isset($read[$curator->getId()])) {
				continue;
			}
			$read[$curator->getId()] = true;
			// a curator kept out here offers nothing, and what was read from
			// it goes with the cleanup below
			if (!$this->moderation->admits($curator)) {
				continue;
			}

			foreach ($collections as $collection) {
				if ($this->pastDeadline($deadline)) {
					// the collections not read yet are not gone
					$outOfTime = true;
					unset($read[$curator->getId()]);
					$result['failed']++;
					break;
				}
				$this->readCollection($curator, $collection, $seen, $result);
			}
		}

		if ($outOfTime) {
			$this->logger->notice('[CollectionPackService] out of time; the starter packs not read keep what was read last time');
		}

		$this->forgetGone($sources, $seen, $read);
		$this->refreshBluesky(in_array(self::BLUESKY_SUGGESTED, $sources, true), $outOfTime || $this->pastDeadline($deadline), $result);

		return $result;
	}

	private function pastDeadline(int $deadline): bool {
		return $deadline > 0 && $this->timeFactory->getTime() >= $deadline;
	}

	/**
	 * Reads one collection of a curator's and stores it. One that cannot be
	 * read keeps what was stored of it, and the run goes on with the next.
	 *
	 * @param array<string, mixed> $collection
	 * @param array<string, true> $seen
	 * @param array{sources: int, packs: int, members: int, refused: int, failed: int} $result
	 */
	private function readCollection(Person $curator, array $collection, array &$seen, array &$result): void {
		$uri = self::id($collection['id'] ?? '');
		try {
			$pack = $this->pack($curator, $collection, $result['refused']);
			if ($pack === null) {
				return;
			}
			if (!$this->starterPacksRequest->save($pack)) {
				$this->logger->notice('[CollectionPackService] a starter pack was not stored: its slug is another pack\'s', [
					'collection' => $pack['uri'], 'slug' => $pack['slug'],
				]);

				return;
			}
		} catch (Throwable $e) {
			if ($uri !== '') {
				$seen[$uri] = true;
			}
			$result['failed']++;
			$this->logger->notice('[CollectionPackService] could not read a collection; what was read last time stays', [
				'collection' => $uri, 'exception' => $e,
			]);

			return;
		}

		$seen[$pack['uri']] = true;
		$result['packs']++;
		$result['members'] += count($pack['members']);
		$this->queueUncached($pack['members']);
	}

	/**
	 * Bluesky's suggested starter packs, read while they are a source and
	 * Bluesky is on, and removed when either stops being so. Out of time,
	 * what was read last time stays.
	 *
	 * @param array{sources: int, packs: int, members: int, refused: int, failed: int} $result
	 */
	private function refreshBluesky(bool $wanted, bool $outOfTime, array &$result): void {
		$config = $this->container?->get(AtprotoConfig::class);
		$suggested = $this->container?->get(SuggestedStarterPacks::class);
		if (!$suggested instanceof SuggestedStarterPacks) {
			return;
		}

		if (!$wanted || !$config instanceof AtprotoConfig || !$config->isEnabled()) {
			$suggested->forgetAllBut();

			return;
		}
		if ($outOfTime) {
			$result['failed']++;

			return;
		}

		try {
			$read = $suggested->refresh();
			$result['packs'] += $read['packs'];
			$result['members'] += $read['members'];
		} catch (Throwable $e) {
			$result['failed']++;
			$this->logger->notice('[CollectionPackService] could not read Bluesky\'s suggested starter packs', ['exception' => $e]);
		}
	}

	/**
	 * The curator and the collection documents one source names.
	 *
	 * @return array{0: Person, 1: list<array<string, mixed>>}
	 */
	private function collectionsOf(string $source): array {
		if (str_starts_with($source, 'https://')) {
			$collection = $this->curlService->retrieveObject($source);
			$curator = $this->cacheActorService->getFromId(self::id($collection['attributedTo'] ?? ''));

			return [$curator, [$collection]];
		}

		$curator = $this->cacheActorService->getFromAccount($source);
		// the actor document as it is now: a cached copy may predate its
		// collections
		$document = $this->curlService->retrieveObject($curator->getId());
		$listId = self::id($document['featuredCollections'] ?? '');
		if ($listId === '') {
			return [$curator, []];
		}

		$entries = [];
		foreach ($this->entriesOf($this->curlService->retrieveObject($listId)) as $entry) {
			if (count($entries) >= self::MAX_COLLECTIONS) {
				break;
			}
			if (is_array($entry) && self::isType($entry, self::TYPE_COLLECTION)) {
				$entries[] = $entry;
			} elseif (self::id($entry) !== '') {
				$entries[] = $this->curlService->retrieveObject(self::id($entry));
			}
		}

		return [$curator, $entries];
	}

	/**
	 * The entries of a collection, from its first page where it is paged.
	 *
	 * @param array<string, mixed> $collection
	 *
	 * @return list<mixed>
	 */
	private function entriesOf(array $collection): array {
		foreach (['orderedItems', 'items'] as $key) {
			if (is_array($collection[$key] ?? null)) {
				return array_values($collection[$key]);
			}
		}

		$first = $collection['first'] ?? null;
		if (is_array($first)) {
			return $this->entriesOf($first);
		}
		if (self::id($first) !== '') {
			return $this->entriesOf($this->curlService->retrieveObject(self::id($first)));
		}

		return [];
	}

	/**
	 * One collection as a row, or null when it is not one to offer.
	 *
	 * @param array<string, mixed> $collection
	 *
	 * @return array{uri: string, slug: string, origin: string, curator: string, curator_acct: string, name: string, summary: string, topic: string, language: string, url: string, members: list<string>, fetched: int}|null
	 */
	private function pack(Person $curator, array $collection, int &$refused): ?array {
		$uri = self::id($collection['id'] ?? '');
		if ($uri === '' || !self::isType($collection, self::TYPE_COLLECTION)
			|| !$this->sameActor(self::id($collection['attributedTo'] ?? ''), $curator->getId())
			|| self::host($uri) !== self::host($curator->getId())
			|| ($collection['discoverable'] ?? true) === false
			|| ($collection['sensitive'] ?? false) === true) {
			return null;
		}

		$members = $this->verifiedMembers($uri, $this->entriesOf($collection), $refused);
		if ($members === []) {
			return null;
		}

		$name = self::text(self::mapped($collection, 'name'), 255);
		$language = self::language($collection);

		return [
			'uri' => $uri,
			'slug' => self::slug($name, $uri),
			'origin' => self::ORIGIN,
			'curator' => $curator->getId(),
			'curator_acct' => $curator->getAccount(),
			'name' => ($name !== '') ? $name : $curator->getAccount(),
			'summary' => self::text(self::mapped($collection, 'summary'), 1000),
			'topic' => self::topic($collection['topic'] ?? null),
			'language' => $language,
			'url' => self::url($collection['url'] ?? null),
			'members' => $members,
			'fetched' => $this->timeFactory->getTime(),
		];
	}

	/**
	 * The accounts of a collection whose consent holds, in the curator's order,
	 * up to `MAX_ITEMS`. A member whose authorization's server did not answer
	 * stays if it was there before; if no member could be checked, the
	 * collection is not read this time.
	 *
	 * @param list<mixed> $items
	 *
	 * @return list<string>
	 *
	 * @throws RequestNetworkException when no member could be checked
	 */
	private function verifiedMembers(string $collectionId, array $items, int &$refused): array {
		$wanted = [];
		foreach ($items as $item) {
			if (!is_array($item) || !self::isType($item, self::TYPE_ITEM)) {
				continue;
			}
			$member = self::id($item['featuredObject'] ?? '');
			$authorization = self::id($item['featureAuthorization'] ?? '');
			if ($member === '' || $authorization === '' || isset($wanted[$member])) {
				continue;
			}
			$wanted[$member] = $authorization;
		}
		$admitted = array_slice($this->moderation->admitted(array_keys($wanted)), 0, self::MAX_ITEMS);
		$wanted = array_intersect_key($wanted, array_flip($admitted));
		if ($wanted === []) {
			return [];
		}

		$failed = [];
		$documents = $this->curlService->retrieveObjectsMany(array_values(array_unique(array_values($wanted))), [], failed: $failed);
		$failed = array_flip($failed);

		$members = [];
		$unanswered = 0;
		$before = null;
		foreach ($wanted as $member => $authorization) {
			if (self::authorizes($documents[$authorization] ?? null, $authorization, $member, $collectionId, $this->sameActor(...))) {
				$members[] = $member;
			} elseif (!isset($failed[$authorization])) {
				$refused++;
			} else {
				$unanswered++;
				$before ??= array_flip($this->starterPacksRequest->getByUri($collectionId)['members'] ?? []);
				if (isset($before[$member])) {
					$members[] = $member;
				}
			}
		}

		if ($members === [] && $unanswered > 0) {
			throw new RequestNetworkException('no member of ' . $collectionId . ' could be checked');
		}

		return $members;
	}

	/**
	 * Whether a document is the member's own consent to be in this collection:
	 * a `FeatureAuthorization` served from the address it was asked for, on
	 * the member's server, naming the member and this collection.
	 *
	 * @param array<string, mixed>|null $document
	 * @param callable(string, string): bool $sameActor whether two ids name one actor
	 */
	public static function authorizes(?array $document, string $requested, string $member, string $collectionId, callable $sameActor): bool {
		if ($document === null || !self::isType($document, self::TYPE_AUTHORIZATION)) {
			return false;
		}

		$id = self::id($document['id'] ?? '');

		return $id !== ''
			&& self::host($id) === self::host($requested)
			&& self::host($id) === self::host($member)
			&& self::id($document['interactingObject'] ?? '') === $collectionId
			&& $sameActor(self::id($document['interactionTarget'] ?? ''), $member);
	}

	/**
	 * Whether two ids name one actor: Mastodon gives an account a numbered
	 * address (`/ap/users/<n>`) beside `/users/<name>`, and the cache knows an
	 * actor by every address it was fetched at.
	 */
	private function sameActor(string $one, string $other): bool {
		if ($one === '' || $other === '') {
			return false;
		}
		if ($one === $other) {
			return true;
		}
		if (self::host($one) !== self::host($other)) {
			return false;
		}

		try {
			return $this->cacheActorService->getFromId($one)->getId() === $this->cacheActorService->getFromId($other)->getId();
		} catch (Throwable $e) {
			return false;
		}
	}

	/**
	 * Queues the members not cached yet to be fetched, so an opened pack
	 * draws from the cache rather than waiting on every member's server.
	 *
	 * @param list<string> $members
	 */
	private function queueUncached(array $members): void {
		$cached = $this->cacheActorService->getCachedFromIds($members);
		$missing = array_values(array_filter($members, static fn (string $member): bool => !isset($cached[$member])));
		foreach (array_chunk($missing, RemoteFetchQueue::MAX_PER_CALL) as $chunk) {
			$this->remoteFetchQueue->resolveActors($chunk);
		}
	}

	/**
	 * Removes the packs no source publishes any more: collections a curator read
	 * in full no longer lists, and those of sources taken off the list. An
	 * unreachable curator keeps its packs.
	 *
	 * @param list<string> $sources
	 * @param array<string, true> $seen
	 * @param array<string, true> $read
	 */
	private function forgetGone(array $sources, array $seen, array $read): void {
		$configured = array_map('strtolower', $sources);
		$gone = [];
		foreach ($this->starterPacksRequest->staleOf(self::ORIGIN, array_keys($seen)) as $pack) {
			$stillNamed = in_array(strtolower($pack['curator_acct']), $configured, true)
				|| in_array(strtolower($pack['uri']), $configured, true);
			if (isset($read[$pack['curator']]) || !$stillNamed) {
				$gone[] = $pack['id'];
			}
		}
		$this->starterPacksRequest->deleteByIds($gone);
	}

	/** A configured source shaped: `user@host`, or an `https://` address. */
	public static function source(string $source): string {
		$source = trim($source);
		if ($source === self::BLUESKY_SUGGESTED) {
			return $source;
		}
		if (str_starts_with($source, 'https://')) {
			return filter_var($source, FILTER_VALIDATE_URL) !== false ? $source : '';
		}
		$source = ltrim($source, '@');

		return preg_match('/^[\w.\-]+@[\w.\-]+\.[a-zA-Z]{2,}$/', $source) ? $source : '';
	}

	/**
	 * A path segment for the pack: its name made safe, and a hash of its id
	 * so that two collections of the same name are two packs. A pack stored
	 * before keeps the slug it was stored with (`StarterPacksRequest::save()`).
	 */
	public static function slug(string $name, string $uri): string {
		$base = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
		$base = trim(substr($base, 0, 40), '-');

		return (($base !== '') ? $base : 'collection') . '-' . substr(md5($uri), 0, 16);
	}

	/** The id an ActivityStreams value names: a string, or an object's `id` or `href`. */
	private static function id(mixed $value): string {
		if (is_string($value)) {
			return $value;
		}
		if (is_array($value)) {
			return self::id($value['id'] ?? $value['href'] ?? '');
		}

		return '';
	}

	/** @param array<string, mixed> $object */
	private static function isType(array $object, string $type): bool {
		return in_array($type, (array)($object['type'] ?? []), true);
	}

	private static function host(string $id): string {
		return strtolower((string)parse_url($id, PHP_URL_HOST));
	}

	/**
	 * A natural-language property, or the first value of its map.
	 *
	 * @param array<string, mixed> $object
	 */
	private static function mapped(array $object, string $property): string {
		if (is_string($object[$property] ?? null) && $object[$property] !== '') {
			return $object[$property];
		}
		foreach ((array)($object[$property . 'Map'] ?? []) as $value) {
			if (is_string($value) && $value !== '') {
				return $value;
			}
		}

		return '';
	}

	/** The language the collection is written in, where its maps say. */
	private static function language(array $collection): string {
		foreach (['nameMap', 'summaryMap'] as $map) {
			$keys = array_keys((array)($collection[$map] ?? []));
			if (count($keys) === 1 && is_string($keys[0]) && preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', $keys[0])) {
				return $keys[0];
			}
		}

		return '';
	}

	/** ActivityStreams text is HTML: shown here as plain text, and bounded. */
	private static function text(string $html, int $length): string {
		$text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

		return mb_substr((string)preg_replace('/\s+/u', ' ', $text), 0, $length);
	}

	private static function topic(mixed $topic): string {
		$name = is_array($topic) ? (string)($topic['name'] ?? '') : '';

		return mb_substr(ltrim(trim($name), '#'), 0, 100);
	}

	private static function url(mixed $url): string {
		$url = self::id(is_array($url) && array_is_list($url) ? ($url[0] ?? '') : $url);

		return str_starts_with($url, 'https://') ? $url : '';
	}
}
