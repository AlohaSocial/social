<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\Atproto;

use OCA\Social\AP;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Image;
use OCA\Social\Service\ActorService;
use OCA\Social\Service\ConfigService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Who a Bluesky account is, from this instance's side.
 *
 * Three things have to be settled before a post read from AT-Proto can be
 * stored: the ids it will carry, the person who wrote it, and the server
 * that holds their repository. The ids are made up here rather than taken
 * from AT-Proto, because everything downstream — timelines, notifications,
 * the origin check on an activity — reads a *local* id and a local host, and
 * an `at://` URI is neither. What is kept is the shape:
 *
 *     actor   <social url>ap/bluesky/<did>
 *     post    <social url>ap/bluesky/<did>/app.bsky.feed.post/<rkey>
 *
 * so an id says in which repository its record lives without asking
 * anything. Both are this host's, which is what makes `checkOrigin()` and
 * `checkAuthorship()` pass on documents assembled here, and what keeps the
 * actor cache from offering them to the remote-actor cron: a row whose id is
 * local is a local row, and local rows are never fetched over ActivityPub.
 *
 * Everything else is read from AT-Proto itself rather than from an app view:
 * the app view indexes the Bluesky network, and an account on an instance's
 * own PDS is not on it — `getProfile` answers `501` for a did this network
 * has never heard of, while the record is sitting in the repository all
 * along. So a handle is resolved to a did (app view first, the `_atproto`
 * TXT record second), the did is resolved to a PDS (did:plc directory or
 * did:web's own document), and every record after that comes from that PDS.
 */
class AtprotoIdentity {
	/** The path segment every Bluesky id starts with, after the social url. */
	public const PATH = 'ap/bluesky/';

	/** Bluesky's own profile page, the one place a person can go and edit. */
	private const PROFILE_URL = 'https://bsky.app/profile/';

	/** @var array<string, string> did => the pds endpoint, for this process */
	private array $endpoints = [];

	public function __construct(
		private AtprotoClient $client,
		private ConfigService $configService,
		private CacheActorsRequest $cacheActorsRequest,
		private ActorService $actorService,
		private LoggerInterface $logger,
	) {
	}

	// ---------------------------------------------------------------- ids

	/**
	 * The id a Bluesky actor is known here under.
	 *
	 * @throws \OCA\Social\Exceptions\SocialAppConfigException
	 */
	public function actorId(string $did): string {
		return $this->configService->getSocialUrl() . self::PATH . $did;
	}

	/**
	 * The id of one record of an actor's repository.
	 *
	 * @throws \OCA\Social\Exceptions\SocialAppConfigException
	 */
	public function recordId(string $did, string $collection, string $rkey): string {
		return $this->actorId($did) . '/' . $collection . '/' . $rkey;
	}

	/**
	 * Whether an id this instance issues for a Bluesky record. The path
	 * prefix, not the host alone: every local id has that host.
	 */
	public function isBlueskyId(string $id): bool {
		$prefix = $this->prefix();

		return $prefix !== '' && str_starts_with($id, $prefix);
	}

	/**
	 * The did inside a Bluesky id, or `''` when the id is not one. The did
	 * carries colons but no slash, so it is the first segment.
	 */
	public function didOf(string $id): string {
		$prefix = $this->prefix();
		if ($prefix === '' || !str_starts_with($id, $prefix)) {
			return '';
		}

		$rest = substr($id, strlen($prefix));
		$slash = strpos($rest, '/');
		$did = ($slash === false) ? $rest : substr($rest, 0, $slash);

		return str_starts_with($did, 'did:') ? $did : '';
	}

	/**
	 * The rkey of one record inside a Bluesky id, or `''`.
	 */
	public function rkeyOf(string $id): string {
		$did = $this->didOf($id);
		if ($did === '') {
			return '';
		}

		$rest = substr($id, strlen($this->prefix()) + strlen($did));
		// /<collection>/<rkey> — the collection, then the record's own name
		$parts = explode('/', trim($rest, '/'));

		return $parts === [] ? '' : (string)end($parts);
	}

	/**
	 * The collection a Bluesky id names, or `''` when it names an actor
	 * rather than a record. The did carries no slash, so the collection is
	 * the one segment between it and the record's name.
	 */
	public function collectionOf(string $id): string {
		$did = $this->didOf($id);
		if ($did === '') {
			return '';
		}

		$rest = substr($id, strlen($this->prefix()) + strlen($did));
		$parts = explode('/', trim($rest, '/'));

		return count($parts) === 2 ? (string)$parts[0] : '';
	}

	private function prefix(): string {
		try {
			return $this->configService->getSocialUrl() . self::PATH;
		} catch (Throwable $e) {
			// nothing configured yet: no id can be one of ours
			return '';
		}
	}

	// ------------------------------------------------------- resolution

	/**
	 * Whether a name somebody typed or a client sent could be a Bluesky
	 * handle at all — a domain, in other words, and nothing a WebFinger
	 * lookup could be the better answer for.
	 *
	 * The `acct:` prefix and the leading `@` are both habits of the
	 * fediverse and are read as one: `acct:@alice.bsky.social` is how the
	 * name reaches a form field, not how AT-Proto spells it.
	 */
	public function isHandle(string $account): bool {
		return $this->cleaned($account) !== '';
	}

	/**
	 * A handle as AT-Proto spells it: lower case, no `@`, no `acct:`.
	 *
	 * @throws AtprotoException when what was given is not a handle
	 */
	public function normalize(string $handle): string {
		$cleaned = $this->cleaned($handle);
		if ($cleaned === '') {
			throw new AtprotoException(trim($handle) . ' is not a Bluesky handle', 400);
		}

		return $cleaned;
	}

	/**
	 * The handle inside a name, or `''` when there is none to be had.
	 *
	 * A Bluesky handle is a domain, so the `.` is what separates it from the
	 * bare local names this instance already answers for — `admin`,
	 * `benedikt` — and from the `name@host` an ActivityPub server looks up.
	 */
	private function cleaned(string $account): string {
		$account = mb_strtolower(trim($account), 'UTF-8');
		// a `acct:` prefix is a WebFinger habit and not part of the handle
		if (str_starts_with($account, 'acct:')) {
			$account = substr($account, 5);
		}

		$account = ltrim($account, '@');

		return (str_contains($account, '.')
			&& !str_contains($account, '@')
			&& !str_contains($account, ' ')
			&& !str_contains($account, '/'))
			? $account
			: '';
	}

	/**
	 * A handle turned into where its records are kept.
	 *
	 * @return array{did: string, handle: string, pds: string}
	 *
	 * @throws AtprotoException when no did or no PDS can be found for it
	 */
	public function resolve(string $handle): array {
		$handle = $this->normalize($handle);
		$did = $this->didOfHandle($handle);
		$pds = $this->pdsOf($did);

		return ['did' => $did, 'handle' => $handle, 'pds' => $pds];
	}

	/**
	 * The did a handle is published under.
	 *
	 * The app view knows every handle on the Bluesky network and, because a
	 * did:plc document says which handle it is, the ones on a PDS outside it
	 * too. The DNS TXT record is the fallback that needs no server at all —
	 * it is how a did:web PDS whose handle has never been indexed anywhere
	 * announces itself, and one call to the resolver either way.
	 */
	private function didOfHandle(string $handle): string {
		try {
			$answer = $this->client->get('com.atproto.identity.resolveHandle', ['handle' => $handle]);
			$did = (string)($answer['did'] ?? '');
			if (str_starts_with($did, 'did:')) {
				return $did;
			}
		} catch (AtprotoException $e) {
			$this->logger->debug('AT-Proto handle not answered by the app view', [
				'handle' => $handle, 'error' => $e->getMessage(),
			]);
		}

		$did = $this->didFromDns($handle);
		if ($did === '') {
			throw new AtprotoException('no AT-Proto identity is published for ' . $handle, 404);
		}

		return $did;
	}

	/**
	 * `_atproto.<handle>` in TXT, as `did=did:plc:…`.
	 */
	private function didFromDns(string $handle): string {
		$records = @dns_get_record('_atproto.' . $handle, DNS_TXT);
		if (!is_array($records)) {
			return '';
		}

		foreach ($records as $record) {
			foreach ((array)($record['txt'] ?? '') as $text) {
				foreach (explode(';', (string)$text) as $part) {
					$part = trim($part);
					if (str_starts_with($part, 'did=') && str_starts_with(substr($part, 4), 'did:')) {
						return substr($part, 4);
					}
				}
			}
		}

		return '';
	}

	/**
	 * The PDS endpoint a did's records live on, looked up once per process.
	 *
	 * @throws AtprotoException when the did document names no PDS
	 */
	public function pdsOf(string $did): string {
		if (isset($this->endpoints[$did])) {
			return $this->endpoints[$did];
		}

		$endpoint = match (true) {
			str_starts_with($did, 'did:plc:') => $this->endpointOf($this->documentOfPlc($did), $did),
			str_starts_with($did, 'did:web:') => $this->endpointOf($this->documentOfWeb($did), $did),
			default => throw new AtprotoException('unsupported DID method in ' . $did, 400),
		};

		return $this->endpoints[$did] = $endpoint;
	}

	/**
	 * A did:plc document, from the public directory it is held in.
	 */
	private function documentOfPlc(string $did): array {
		return $this->client->document($this->client->plc() . '/' . $did);
	}

	/**
	 * A did:web document, from the origin it is named after.
	 *
	 * `did:web:example.com` is `https://example.com/.well-known/did.json`,
	 * and a did with a path after the host swaps that path in — the same
	 * rule every AT-Proto client implements, and the reason an instance
	 * running its own PDS needs no directory of its own.
	 */
	private function documentOfWeb(string $did): array {
		$name = substr($did, strlen('did:web:'));
		$segments = explode(':', $name);
		$host = (string)array_shift($segments);
		$path = ($segments === []) ? '/.well-known/did.json' : '/' . implode('/', $segments) . '/did.json';

		return $this->client->document('https://' . $host . $path);
	}

	/**
	 * The `#atproto_pds` service of a did document.
	 *
	 * @param array<string, mixed> $document
	 *
	 * @throws AtprotoException when the document carries none
	 */
	private function endpointOf(array $document, string $did): string {
		foreach ((array)($document['service'] ?? []) as $service) {
			if (!is_array($service)) {
				continue;
			}

			$id = (string)($service['id'] ?? '');
			$type = (string)($service['type'] ?? '');
			if ($id !== '#atproto_pds' && $type !== 'AtprotoPersonalDataServer') {
				continue;
			}

			$endpoint = $service['serviceEndpoint'] ?? '';
			if (is_array($endpoint)) {
				$endpoint = $endpoint['uri'] ?? '';
			}

			$endpoint = rtrim((string)$endpoint, '/');
			$scheme = parse_url($endpoint, PHP_URL_SCHEME);
			if ($endpoint !== '' && in_array($scheme, ['http', 'https'], true)) {
				return $endpoint;
			}
		}

		throw new AtprotoException($did . ' names no AT-Proto PDS', 404);
	}

	// ------------------------------------------------------------ people

	/**
	 * The profile record an actor's own PDS holds, or `[]` when they never
	 * made one — which is most accounts, and not a failure.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws AtprotoException
	 */
	public function profile(string $did, ?string $pds = null): array {
		$pds = $pds ?? $this->pdsOf($did);

		try {
			$answer = $this->client->get('com.atproto.repo.getRecord', [
				'repo' => $did,
				'collection' => 'app.bsky.actor.profile',
				'rkey' => 'self',
			], $pds);
		} catch (AtprotoException $e) {
			if ($e->getXrpcError() === 'RecordNotFound' || $e->getXrpcError() === 'NotFound') {
				return [];
			}

			throw $e;
		}

		$value = $answer['value'] ?? null;

		return is_array($value) ? $value : [];
	}

	/**
	 * The cached actor for a did, made on first sight.
	 *
	 * The row exists for one reason before any other: a post is addressed to
	 * its author, and the home timeline joins the author's followers through
	 * `social_cache_actor`. A post saved without the row underneath it is
	 * invisible in every timeline it was written for.
	 *
	 * @param string $handle what the account is called, when it is already known
	 * @param array<string, mixed>|null $profile a profile record the caller has just read, or null to read it
	 *
	 * @throws AtprotoException
	 * @throws \OCA\Social\Exceptions\SocialAppConfigException
	 */
	public function actor(string $did, string $handle = '', ?array $profile = null, ?string $pds = null): Person {
		$known = null;
		try {
			$known = $this->cacheActorsRequest->getFromId($this->actorId($did));
		} catch (CacheActorDoesNotExistException $e) {
		}

		if ($known !== null && $profile === null && ($handle === '' || $handle === $known->getPreferredUsername())) {
			// already held, and nothing new to say about them: the row every
			// timeline reads is here, and a profile read would be one more
			// round trip per post for a display name that changes rarely
			return $known;
		}

		$pds = $pds ?? $this->pdsOf($did);
		$profile = $profile ?? $this->profile($did, $pds);
		if ($handle === '') {
			$handle = $this->handleOf($did, $profile);
		}

		if ($known === null) {
			$actor = $this->makeActor($did, $handle, $profile, $pds);
			$this->actorService->save($actor);

			return $actor;
		}

		$this->fillActor($known, $handle, $profile, $pds);
		$this->actorService->update($known);

		return $known;
	}

	/**
	 * What the account calls itself.
	 *
	 * A profile record carries no handle — it is written *by* the account,
	 * under whatever name it already has — so a caller that resolved one is
	 * asked first, then the did itself says it (did:web is the domain), then
	 * the did:plc document's `alsoKnownAs`, which names the handle as an
	 * `at://` URI. The did is the last answer: never pretty, never wrong.
	 */
	private function handleOf(string $did, array $profile): string {
		$handle = trim((string)($profile['handle'] ?? ''));

		if ($handle === '' && str_starts_with($did, 'did:web:')) {
			$handle = str_replace(':', '.', substr($did, 8));
		}

		if ($handle === '' && str_starts_with($did, 'did:plc:')) {
			$handle = $this->handleFromPlc($did);
		}

		return $handle !== '' ? $handle : $did;
	}

	/**
	 * The handle a did:plc document says it is also known as: one
	 * `at://<handle>` entry, and nothing after the host.
	 */
	private function handleFromPlc(string $did): string {
		try {
			$document = $this->documentOfPlc($did);
		} catch (AtprotoException $e) {
			return '';
		}

		foreach ((array)($document['alsoKnownAs'] ?? []) as $entry) {
			$entry = trim((string)$entry);
			if (str_starts_with($entry, 'at://') && !str_contains($entry, '/')) {
				return substr($entry, 5);
			}
		}

		return '';
	}

	/**
	 * A `Person` from what a PDS knows: built as an ActivityPub document so
	 * that every validation, derived field and icon the rest of the app
	 * expects comes from the same code a peer's actor document goes through.
	 *
	 * @param array<string, mixed> $profile
	 */
	private function makeActor(string $did, string $handle, array $profile, string $pds): Person {
		$id = $this->actorId($did);
		$document = [
			'id' => $id,
			'type' => Person::TYPE,
			'preferredUsername' => $handle,
			'name' => $this->displayNameOf($handle, $profile),
			'summary' => $this->htmlOf((string)($profile['description'] ?? '')),
			'inbox' => $id . '/inbox',
			'outbox' => $id . '/outbox',
			'followers' => $id . '/followers',
			'following' => $id . '/following',
			'featured' => $id . '/collections/featured',
			'url' => self::PROFILE_URL . $did,
		];

		$avatar = $this->blobUrl($did, $profile['avatar'] ?? null, $pds);
		if ($avatar !== '') {
			$document['icon'] = ['type' => 'Image', 'url' => $avatar];
		}

		$banner = $this->blobUrl($did, $profile['banner'] ?? null, $pds);
		if ($banner !== '') {
			$document['image'] = ['type' => 'Image', 'url' => $banner];
		}

		$actor = AP::instance()->getItemFromData($document);
		if (!($actor instanceof Person)) {
			throw new AtprotoException('the profile of ' . $did . ' is not an actor', 500);
		}

		// `import()` derives `account` from the host of the id, which here is
		// this instance: the handle on its own is what a Bluesky account is
		// searched by, and `host` is then empty, which keeps a network that is
		// not the fediverse out of every "which servers do we know" query
		$actor->setAccount($handle);

		return $actor;
	}

	/**
	 * A profile change written onto the row that already exists, keeping what
	 * only the row knows — counters, details, the local flag.
	 *
	 * @param array<string, mixed> $profile
	 */
	private function fillActor(Person $actor, string $handle, array $profile, string $pds): void {
		$did = $this->didOf($actor->getId());
		if ($handle !== '' && $handle !== $did) {
			$actor->setPreferredUsername($handle);
			$actor->setAccount($handle);
		}

		$actor->setName($this->displayNameOf($handle, $profile));
		$actor->setDescription($this->htmlOf((string)($profile['description'] ?? '')));
		$actor->setUrl(self::PROFILE_URL . $did);

		$avatar = $this->blobUrl($did, $profile['avatar'] ?? null, $pds);
		if ($avatar !== '') {
			// built the way `Person::import()` builds one from a peer's
			// document, so `ActorService::update()` caches the bytes the same
			// way it does for every other picture
			$icon = AP::instance()->getItemFromType(Image::TYPE);
			$icon->setParent($actor);
			$icon->import(['type' => Image::TYPE, 'url' => $avatar]);
			$actor->setIcon($icon);
		}

		$banner = $this->blobUrl($did, $profile['banner'] ?? null, $pds);
		if ($banner !== '') {
			$actor->setHeader($banner);
		}
	}

	/**
	 * A profile's display name, or the handle when it has none.
	 */
	private function displayNameOf(string $handle, array $profile): string {
		$name = mb_substr(trim((string)($profile['displayName'] ?? '')), 0, 255);

		return $name !== '' ? $name : $handle;
	}

	/**
	 * A profile's description as ActivityPub wants it: HTML, and never the
	 * raw text — an AT-Proto profile is plain text, and a reader that renders
	 * an actor's bio as HTML would show the angle brackets of one.
	 */
	private function htmlOf(string $text): string {
		$text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

		return str_replace(["\r\n", "\r", "\n"], '<br />', $text);
	}

	/**
	 * Where a blob a did holds can be fetched from: the PDS's own blob
	 * endpoint, which answers for any public repository whether or not this
	 * instance's own PDS is on the Bluesky network.
	 *
	 * @param mixed $blob the `avatar`/`image` field of a profile record
	 */
	private function blobUrl(string $did, mixed $blob, string $pds): string {
		if (!is_array($blob)) {
			return '';
		}

		$cid = (string)($blob['ref']['$link'] ?? ($blob['ref'] ?? ''));
		if ($cid === '') {
			return '';
		}

		return $pds . '/xrpc/com.atproto.sync.getBlob?did=' . rawurlencode($did) . '&cid=' . rawurlencode($cid);
	}
}
