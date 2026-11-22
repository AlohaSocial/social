<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub;

use JsonSerializable;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceEntryException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\LinkedDataSignature;
use OCA\Social\Security\HtmlSanitizer;
use OCA\Social\Service\ConfigService;
use OCA\Social\Tools\IQueryRow;
use OCA\Social\Tools\Nid;
use OCA\Social\Tools\Traits\TArrayTools;
use OCA\Social\Tools\Traits\TPathTools;
use OCA\Social\Tools\Traits\TStringTools;
use RuntimeException;

class ACore extends Item implements JsonSerializable, IQueryRow {
	use TArrayTools;
	use TStringTools;
	use TPathTools;

	public const CONTEXT_PUBLIC = 'https://www.w3.org/ns/activitystreams#Public';

	/**
	 * The URI schemes an id may use: the two this app fetches, and the two the
	 * fediverse names things with that are never dereferenced at all.
	 */
	public const ID_SCHEMES = ['http', 'https', 'tag', 'urn'];
	public const CONTEXT_ACTIVITYSTREAMS = 'https://www.w3.org/ns/activitystreams';
	public const CONTEXT_SECURITY = 'https://w3id.org/security/v1';

	/**
	 * The extension terms outgoing documents use, with Mastodon's definitions.
	 * The AS2 context defines none of them and its `@vocab: "_:"` turns them
	 * into blank nodes, which strict JSON-LD consumers drop.
	 *
	 * Inline rather than a URL so signature normalisation needs no resolving
	 * (SignatureService::documentLoader), and always emitted so the signed
	 * and sent bytes expand alike.
	 */
	public const CONTEXT_EXTENSIONS = [
		'manuallyApprovesFollowers' => 'as:manuallyApprovesFollowers',
		'sensitive' => 'as:sensitive',
		'Hashtag' => 'as:Hashtag',
		'movedTo' => ['@id' => 'as:movedTo', '@type' => '@id'],
		'alsoKnownAs' => ['@id' => 'as:alsoKnownAs', '@type' => '@id'],
		'toot' => 'http://joinmastodon.org/ns#',
		'featured' => ['@id' => 'toot:featured', '@type' => '@id'],
		'discoverable' => 'toot:discoverable',
		'indexable' => 'toot:indexable',
		'votersCount' => 'toot:votersCount',
		'blurhash' => 'toot:blurhash',
		'focalPoint' => ['@container' => '@list', '@id' => 'toot:focalPoint'],
		'Emoji' => 'toot:Emoji',
		'schema' => 'http://schema.org#',
		'PropertyValue' => 'schema:PropertyValue',
		'value' => 'schema:value',
		'ostatus' => 'http://ostatus.org#',
		'conversation' => 'ostatus:conversation',
		// quote posts: FEP-044f defines `quote`, `quoteAuthorization` and the
		// `QuoteRequest` activity; the interaction policy that says who may
		// quote a post is GoToSocial's vocabulary, which Mastodon 4.5 adopted.
		// `quoteUrl` and `_misskey_quote` are the pre-FEP aliases emitted
		// beside `quote` — see Stream::exportQuoteAsActivityPub()
		'fep044f' => 'https://w3id.org/fep/044f#',
		'quote' => ['@id' => 'fep044f:quote', '@type' => '@id'],
		'quoteAuthorization' => ['@id' => 'fep044f:quoteAuthorization', '@type' => '@id'],
		'QuoteRequest' => 'fep044f:QuoteRequest',
		'QuoteAuthorization' => 'fep044f:QuoteAuthorization',
		'interactingObject' => ['@id' => 'fep044f:interactingObject', '@type' => '@id'],
		'interactionTarget' => ['@id' => 'fep044f:interactionTarget', '@type' => '@id'],
		'quoteUrl' => ['@id' => 'as:quoteUrl', '@type' => '@id'],
		'misskey' => 'https://misskey-hub.net/ns#',
		'_misskey_quote' => ['@id' => 'misskey:_misskey_quote', '@type' => '@id'],
		'gts' => 'https://gotosocial.org/ns#',
		'interactionPolicy' => ['@id' => 'gts:interactionPolicy', '@type' => '@id'],
		'canQuote' => ['@id' => 'gts:canQuote', '@type' => '@id'],
		'automaticApproval' => ['@id' => 'gts:automaticApproval', '@type' => '@id'],
		'manualApproval' => ['@id' => 'gts:manualApproval', '@type' => '@id'],
	];

	public const AS_ID = 1;
	public const AS_TYPE = 2;
	public const AS_URL = 3;
	public const AS_DATE = 4;
	public const AS_USERNAME = 5;
	public const AS_ACCOUNT = 6;
	public const AS_STRING = 7;
	public const AS_CONTENT = 8;
	public const AS_TAGS = 10;

	public const FORMAT_ACTIVITYPUB = 1;
	public const FORMAT_LOCAL = 2;
	public const FORMAT_NOTIFICATION = 3;

	private ?ACore $parent = null;

	private string $requestToken = '';
	private array $entries = [];
	private ?ACore $object = null;
	private ?Document $icon = null;

	private bool $displayW3ContextSecurity = false;
	private ?LinkedDataSignature $signature = null;
	private int $format = self::FORMAT_ACTIVITYPUB;

	public function __construct($parent = null) {
		if ($parent instanceof ACore) {
			$this->setParent($parent);
		}
	}

	/**
	 * @return string
	 */
	public function getRequestToken(): string {
		if ($this->isRoot()) {
			return $this->requestToken;
		} else {
			return $this->getRoot()
				->getRequestToken();
		}
	}

	/**
	 * @param string $token
	 *
	 * @return ACore
	 */
	public function setRequestToken(string $token): ACore {
		$this->requestToken = $token;

		return $this;
	}

	/**
	 * @param ACore $parent
	 *
	 * @return ACore
	 */
	public function setParent(ACore $parent): ACore {
		$this->parent = $parent;

		return $this;
	}

	/**
	 * @return ACore
	 */
	public function getParent(): ACore {
		if ($this->parent === null) {
			throw new RuntimeException('this item is the root: guard with isRoot()');
		}

		return $this->parent;
	}

	/**
	 * @return bool
	 */
	public function hasObject(): bool {
		if ($this->object === null) {
			return false;
		}

		return true;
	}

	/**
	 * @return null|self
	 */
	public function getObject(): ?ACore {
		return $this->object;
	}

	/**
	 * @param ACore $object
	 *
	 * @return ACore
	 */
	public function setObject(ACore $object): self {
		$object->setParent($this);
		$this->object = $object;

		return $this;
	}

	/**
	 * @return string
	 */
	#[\Override]
	public function getObjectId(): string {
		if ($this->hasObject()) {
			return $this->getObject()
				->getId();
		}

		return parent::getObjectId();
	}

	/**
	 * @param bool $filter - will remove general url like Public
	 *
	 * @return array
	 */
	public function getRecipients(bool $filter = false): array {
		$recipients = array_merge($this->getToAll(), $this->getCcArray());

		if (!$filter) {
			return $recipients;
		}

		return array_diff($recipients, [self::CONTEXT_PUBLIC]);
	}

	/**
	 * @return bool
	 */
	public function hasIcon(): bool {
		return ($this->icon !== null);
	}

	public function getIcon(): ?Document {
		return $this->icon;
	}

	/**
	 * @param Document $icon
	 *
	 * @return ACore
	 */
	public function setIcon(Document $icon): ACore {
		$icon->setParent($this);
		$this->icon = $icon;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isDisplayW3ContextSecurity(): bool {
		return $this->displayW3ContextSecurity;
	}

	/**
	 * @param bool $display
	 *
	 * @return ACore
	 */
	public function setDisplayW3ContextSecurity(bool $display): ACore {
		$this->displayW3ContextSecurity = $display;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isPublic(): bool {
		return in_array(self::CONTEXT_PUBLIC, $this->getRecipients(), true);
	}

	/**
	 * @return bool
	 */
	public function hasSignature(): bool {
		return ($this->signature !== null);
	}

	public function getSignature(): ?LinkedDataSignature {
		return $this->signature;
	}

	/**
	 * @param LinkedDataSignature $signature
	 *
	 * @return ACore
	 */
	public function setSignature(LinkedDataSignature $signature): Acore {
		$this->signature = $signature;

		return $this;
	}

	/** Remove a signature before signing a changed wire representation. */
	public function clearSignature(): self {
		$this->signature = null;
		unset($this->entries['signature']);

		return $this;
	}

	/**
	 * @param string $base
	 * @param bool $root
	 *
	 * @throws UrlCloudException
	 */
	public function generateUniqueId(string $base = '', bool $root = true) {
		$url = '';
		if ($root) {
			$url = $this->getUrlCloud();
			if ($url === '') {
				throw new UrlCloudException();
			}
		}

		if ($base !== '') {
			$base = $this->withoutEndSlash($this->withBeginSlash($base));
		}

		$this->setId($url . $base . '/' . $this->uuid());
	}

	/**
	 * An id for an activity with no resource of its own (an Accept, Reject or
	 * Undo), as a fragment of the acting actor's id, as Mastodon does
	 * (`https://host/users/alice#accepts/follows/1`). A fragment of the cloud
	 * root would resolve to the Nextcloud landing page.
	 */
	public function generateUniqueIdFromActor(string $actorId, string $base): void {
		if ($actorId === '') {
			$this->generateUniqueId($base);

			return;
		}

		$this->setId($actorId . '#' . ltrim($base, '#/') . '/' . $this->uuid());
	}

	/**
	 * @param string $id
	 *
	 * @throws InvalidOriginException
	 */
	public function checkOrigin(string $id) {
		// host and port: a service on another port of a host is not the
		// instance on its default one
		$authority = strtolower(ConfigService::authorityOf($id));
		$origin = strtolower($this->getRoot()
			->getOriginAuthority());

		if ($id !== '' && $authority !== '' && $origin === $authority) {
			return;
		}

		throw new InvalidOriginException(
			'ACore::checkOrigin - id: ' . $id . ' - origin: ' . $origin
		);
	}

	/**
	 * Holds an activity to the one actor it may act for. `checkOrigin()` only
	 * checks the host, which on a shared server covers every account, so
	 * anything acting on something an actor owns checks here too.
	 *
	 * @param string $expectedActorId whose the thing being acted on is
	 * @param string $actualActorId who the activity says is acting
	 *
	 * @throws InvalidOriginException
	 */
	public function checkActor(string $expectedActorId, string $actualActorId): void {
		if ($expectedActorId !== '' && $expectedActorId === $actualActorId) {
			return;
		}

		throw new InvalidOriginException(
			'ACore::checkActor - expected: ' . $expectedActorId . ' - actor: ' . $actualActorId
		);
	}

	/**
	 * @return bool
	 */
	public function isRoot(): bool {
		return ($this->parent === null);
	}

	/**
	 * @param array $chain
	 *
	 * @return ACore
	 */
	public function getRoot(array &$chain = []): ACore {
		$chain[] = $this;
		if ($this->isRoot()) {
			return $this;
		}

		return $this->getParent()
			->getRoot($chain);
	}

	/**
	 * @param array $arr
	 *
	 * @return ACore
	 */
	public function setEntries(array $arr): ACore {
		$this->entries = $arr;

		return $this;
	}

	/**
	 * @return array
	 */
	public function getEntries(): array {
		return $this->entries;
	}

	/**
	 * The list `to` is emitted as when a single recipient and a list are
	 * both set: the single one first, then the list, each once and none
	 * empty. Empty while the list is, so a single recipient on its own is
	 * still emitted as the string it is.
	 *
	 * @return string[]
	 */
	private function toEmitted(): array {
		if ($this->getToArray() === []) {
			return [];
		}

		return array_values(array_unique(array_filter(
			array_merge([$this->getTo()], $this->getToArray()),
			static fn ($recipient): bool => $recipient !== ''
		)));
	}

	/**
	 * @param string $k
	 * @param string $v
	 *
	 * @return ACore
	 */
	public function addEntry(string $k, string $v): ACore {
		if ($v === '') {
			//			unset($this->entries[$k]);

			return $this;
		}

		$this->entries[$k] = $v;

		return $this;
	}

	/**
	 * @param string $k
	 * @param int $v
	 *
	 * @return ACore
	 */
	public function addEntryInt(string $k, int $v): ACore {
		if ($v === 0) {
			return $this;
		}

		$this->entries[$k] = $v;

		return $this;
	}

	/**
	 * @param string $k
	 * @param bool $v
	 *
	 * @return ACore
	 */
	public function addEntryBool(string $k, bool $v): ACore {
		if ($v === false) {
			return $this;
		}

		$this->entries[$k] = $v;

		return $this;
	}

	/**
	 * @param string $k
	 * @param array $v
	 *
	 * @return ACore
	 */
	public function addEntryArray(string $k, array $v): ACore {
		if ($v === []) {
			//			unset($this->entries[$k]);

			return $this;
		}

		$this->entries[$k] = $v;

		return $this;
	}

	/**
	 * @param string $k
	 * @param ACore $v
	 *
	 * @return ACore
	 */
	public function addEntryItem(string $k, ACore $v): ACore {
		if ($v === null) {
			//			unset($this->entries[$k]);

			return $this;
		}

		$this->entries[$k] = $v;

		return $this;
	}

	/**
	 * @param int $as
	 * @param string $k
	 * @param array $arr
	 * @param string $default
	 *
	 * @return string
	 */
	public function validate(int $as, string $k, array $arr, string $default = ''): string {
		try {
			return $this->validateEntryString($as, $this->linkOf($as, $k, $arr, $default));
		} catch (InvalidResourceEntryException $e) {
			return $default;
		}
	}

	/**
	 * A URL as ActivityStreams sends one: a string, a `Link` (Loops sends
	 * `{"type": "Link", "href": …}` for a video), or a list of either, of
	 * which the first usable one wins. Only `AS_URL` is read this way.
	 *
	 * @param array<string, mixed> $arr
	 */
	private function linkOf(int $as, string $k, array $arr, string $default): string {
		$value = $arr[$k] ?? null;
		if ($as !== self::AS_URL || !is_array($value)) {
			return $this->get($k, $arr, $default);
		}

		// a single Link, or a list of them mixed with plain strings
		$candidates = array_is_list($value) ? $value : [$value];
		foreach ($candidates as $candidate) {
			$href = is_array($candidate) ? ($candidate['href'] ?? '') : $candidate;
			if (is_string($href) && $href !== '') {
				try {
					return $this->validateEntryString(self::AS_URL, $href);
				} catch (InvalidResourceEntryException $e) {
					// try the next one it offered
				}
			}
		}

		return $default;
	}

	/**
	 * @param int $as
	 * @param string $k
	 * @param array $arr
	 * @param array $default
	 *
	 * @return array
	 */
	public function validateArray(int $as, string $k, array $arr, array $default = []): array {
		$values = $this->getArray($k, $arr, $default);

		$result = [];
		foreach ($values as $value) {
			try {
				if (is_array($value)) {
					$result[] = $this->validateEntryArray($as, $value);
				} else {
					$result[] = $this->validateEntryString($as, $value);
				}
			} catch (InvalidResourceEntryException $e) {
			}
		}

		return $result;
	}

	/**
	 * One field of an incoming document, checked and normalised against what
	 * this app will do with it.
	 *
	 * @param int $as which kind of field it is
	 * @param string $value the field as it arrived
	 * @param bool $exception whether an unusable value throws or comes back as ''
	 *
	 * @return string the value this app will store
	 * @throws InvalidResourceEntryException
	 */
	public function validateEntryString(int $as, string $value, bool $exception = true): string {
		switch ($as) {
			case self::AS_ID:
				// An id must be an absolute URI; `parse_url()` alone accepts
				// `javascript:` and `data:`, and ids end up in `href`s. http(s)
				// is fetched; `tag:` and `urn:` (Mastodon names conversations
				// with `tag:`) are never dereferenced and harmless in a browser.
				// An http(s) id needs a host, as an AS_URL does.
				$scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
				if (in_array($scheme, self::ID_SCHEMES, true)
					&& (!in_array($scheme, ['http', 'https'], true) || HtmlSanitizer::isAllowedUrl($value))) {
					return $value;
				}
				break;

			case self::AS_TYPE:
				return $value;
			case self::AS_URL:
				// `parse_url()` accepts `javascript:alert(1)`, and every URL here
				// is either fetched or rendered as a link, so the scheme is checked,
				// and a host required: a browser resolves `https:/path` against
				// this server.
				$scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
				if (in_array($scheme, ['http', 'https'], true) && HtmlSanitizer::isAllowedUrl($value)) {
					return $value;
				}
				break;

			case self::AS_DATE:
				return $value;
			case self::AS_STRING:
				return self::asPlainText($value);
			case self::AS_CONTENT:
				// Remote HTML is rendered in local timelines; strip_tags() keeps
				// attributes such as `onclick`, so it is sanitised, and relative
				// links resolve against the object's own server.
				return HtmlSanitizer::sanitize($value, $this->getId());
			case self::AS_USERNAME:
			case self::AS_ACCOUNT:
				// a display name is prose, and strip_tags() turns `Alice <3`
				// into `Alice `
				return self::withoutMarkup($value);
		}

		if ($exception) {
			throw new InvalidResourceEntryException($as . ' ' . $value);
		} else {
			return '';
		}
	}

	/**
	 * A plain-text field arriving from elsewhere.
	 *
	 * Entities are decoded first, because removing markup and *then* decoding
	 * would let `&lt;script&gt;` come back to life as a real element.
	 */
	private static function asPlainText(string $value): string {
		return self::withoutMarkup(html_entity_decode($value, ENT_QUOTES | ENT_HTML5));
	}

	/**
	 * The text with its tags removed and nothing else lost. Unlike
	 * `strip_tags()`, a bare `<` is kept, so `I <3 cats` survives. A tag opened
	 * with an attribute and never closed is dropped to the end (`<img src=x
	 * onerror=…`); without an attribute, `a<b` stays.
	 *
	 * A flattener, not a sanitiser: plain text is escaped where it is
	 * rendered, and markup goes through AS_CONTENT.
	 */
	public static function withoutMarkup(string $value): string {
		return (string)preg_replace(
			['/<\/?[a-zA-Z][^>]*>/', '/<[a-zA-Z][\w-]*\s[^>]*=[^>]*$/'],
			'',
			$value
		);
	}

	/**
	 * @param int $as
	 * @param array $values
	 *
	 * @return array
	 * @throws InvalidResourceEntryException
	 */
	public function validateEntryArray(int $as, array $values): array {
		switch ($as) {
			case self::AS_TAGS:
				$tag = [
					'type' => $this->validateEntryString(
						self::AS_TYPE, $this->get('type', $values, ''), false
					),
					'href' => $this->validateEntryString(
						self::AS_URL, $this->get('href', $values, ''), false
					),
					'name' => $this->validateEntryString(
						self::AS_STRING, $this->get('name', $values, ''), false
					)
				];

				// an Emoji tag needs its icon, the only thing that says what to
				// draw for the shortcode
				$icon = $this->validateIconEntry($this->getArray('icon', $values, []));
				if ($icon !== []) {
					$tag['icon'] = $icon;
				}

				return $tag;
		}

		throw new InvalidResourceEntryException($as . ' ' . json_encode($values));
	}

	/**
	 * The icon of a tag, reduced to the three keys a reader needs; dropped
	 * when its URL is not valid.
	 */
	private function validateIconEntry(array $icon): array {
		if ($icon === []) {
			return [];
		}

		try {
			$url = $this->validateEntryString(self::AS_URL, $this->get('url', $icon, ''));
		} catch (InvalidResourceEntryException $e) {
			return [];
		}

		if ($url === '') {
			return [];
		}

		return [
			'type' => $this->validateEntryString(
				self::AS_TYPE, $this->get('type', $icon, 'Image'), false
			),
			'mediaType' => $this->validateEntryString(
				self::AS_STRING, $this->get('mediaType', $icon, ''), false
			),
			'url' => $url,
		];
	}

	/**
	 * @param array $data
	 */
	/**
	 * Mastodon-style custom emoji from a raw wire `tag` array: entries of
	 * type Emoji with an icon URL become {shortcode, url, static_url,
	 * visible_in_picker} entries the client API serves.
	 */
	protected function extractEmojisFromTag(array $data): array {
		$emojis = [];
		foreach (self::listOf('tag', $data) as $tag) {
			if (!is_array($tag) || ($tag['type'] ?? '') !== 'Emoji') {
				continue;
			}
			$shortcode = trim((string)($tag['name'] ?? ''), ':');
			$url = (string)($tag['icon']['url'] ?? '');
			// the scheme guards the image `src` against `javascript:` and
			// `data:`; plain http is allowed for instances being set up
			if ($shortcode === ''
				|| !(str_starts_with($url, 'https://') || str_starts_with($url, 'http://'))) {
				continue;
			}
			$emojis[$shortcode] = [
				'shortcode' => $shortcode,
				'url' => $url,
				'static_url' => $url,
				'visible_in_picker' => false,
			];
		}

		return array_values($emojis);
	}

	public function import(array $data) {
		$this->setId($this->validate(self::AS_ID, 'id', $data, ''));
		$this->setType($this->validate(self::AS_TYPE, 'type', $data, ''));
		$this->setUrl($this->validate(self::AS_URL, 'url', $data, ''));
		$this->setSummary($this->get('summary', $data, ''));
		$this->setToArray($this->validateRecipients('to', $data));
		$this->setCcArray($this->validateRecipients('cc', $data));
		$this->setPublished($this->validate(self::AS_DATE, 'published', $data, ''));
		$this->setActorId($this->validate(self::AS_ID, 'actor', $data, ''));
		$this->setObjectId($this->validate(self::AS_ID, 'object', $data, ''));
		$this->setTags($this->validateArray(self::AS_TAGS, 'tag', ['tag' => self::listOf('tag', $data)], []));
	}

	/**
	 * The ids in an addressing field, whether a list or one bare string (a
	 * single recipient is often sent unwrapped, and lost it would make a
	 * public post look direct to `NoteInterface::estimateVisibility()`). Not
	 * in the trait, whose `getArray()` treats a string as a JSON column.
	 *
	 * @return string[]
	 */
	private function validateRecipients(string $k, array $data): array {
		// The public collection has three spellings: the full IRI, `as:Public`
		// and `Public`. The short two are not http URIs and would be dropped,
		// so they are mapped here, as Mastodon accepts all three.
		$recipients = array_map(
			static fn ($recipient) => (is_string($recipient) && in_array($recipient, ['as:Public', 'Public'], true))
				? self::CONTEXT_PUBLIC : $recipient,
			self::listOf($k, $data)
		);

		return $this->validateArray(self::AS_ID, $k, [$k => $recipients], []);
	}

	/**
	 * A field of an incoming document as a list, whichever form it came in:
	 * any non-functional property may be one value or a list. For wire
	 * documents only; database rows keep `getArray()`, where a string is a
	 * JSON column.
	 *
	 * @param array<array-key, mixed> $data
	 *
	 * @return list<mixed>
	 */
	public static function listOf(string $k, array $data): array {
		$value = $data[$k] ?? null;
		if (is_string($value) || (is_array($value) && $value !== [] && !array_is_list($value))) {
			return [$value];
		}

		return is_array($value) ? array_values($value) : [];
	}

	/**
	 * @param array $data
	 */
	#[\Override]
	public function importFromDatabase(array $data) {
		// A row is checked on the way out as on the way in, as rows stored
		// under older, looser rules must not reach a client unchecked.
		$this->setNid($this->get('nid', $data, '0'));
		$this->setId($this->validate(self::AS_ID, 'id', $data, ''));
		$this->setType($this->validate(self::AS_TYPE, 'type', $data, ''));
		$this->setSubType($this->validate(self::AS_TYPE, 'subtype', $data, ''));
		$this->setUrl($this->validate(self::AS_URL, 'url', $data, ''));
		$this->setSummary($this->validate(self::AS_STRING, 'summary', $data, ''));
		$this->setTo($this->validate(self::AS_ID, 'to', $data, ''));
		$this->setToArray($this->validateArray(self::AS_ID, 'to_array', $data, []));
		$this->setCcArray($this->validateArray(self::AS_ID, 'cc', $data, []));
		$this->setBccArray($this->validateArray(self::AS_ID, 'bcc', $data, []));
		$this->setPublished($this->validate(self::AS_DATE, 'published', $data, ''));
		$this->setActorId($this->validate(self::AS_ID, 'actor_id', $data, ''));
		$this->setObjectId($this->validate(self::AS_ID, 'object_id', $data, ''));
		$this->setSource($this->get('source', $data, ''));
		$this->setLocal(($this->getInt('local', $data, 0) === 1));
	}

	/**
	 * based on json generated/exported as LOCAL
	 * @param array $data
	 */
	public function importFromLocal(array $data) {
		$this->setNid($this->get('id', $data, '0'));
	}

	/**
	 * @param int $format
	 *
	 * @return $this
	 */
	public function setExportFormat(int $format): self {
		if ($format > 0) {
			$this->format = $format;
		}

		return $this;
	}

	/**
	 * @return int
	 */
	public function getExportFormat(): int {
		return $this->format;
	}

	/**
	 * @return array
	 */
	#[\Override]
	public function jsonSerialize(): array {
		if ($this->getExportFormat() === self::FORMAT_LOCAL) {
			return $this->exportAsLocal();
		}

		if ($this->getExportFormat() === self::FORMAT_NOTIFICATION) {
			return $this->exportAsNotification();
		}

		return $this->exportAsActivityPub();
	}

	/**
	 * @return array
	 */
	public function exportAsActivityPub(): array {
		if ($this->hasSignature()) {
			$this->entries['signature'] = $this->getSignature();
		}

		if ($this->isRoot()) {
			$context = [self::CONTEXT_ACTIVITYSTREAMS];

			if ($this->hasSignature() || $this->isDisplayW3ContextSecurity()) {
				array_push($context, self::CONTEXT_SECURITY);
			}

			// last, so a term this app defines can never shadow one of the two
			// named contexts — see CONTEXT_EXTENSIONS
			$context[] = $this->activityPubContextExtensions();

			$this->addEntryArray('@context', $context);
		} else {
			// the entries outlive an export: an object serialised on its own
			// first (a post's stored source) and then inside an activity would
			// otherwise carry its root context into the activity
			unset($this->entries['@context']);
		}

		$this->addEntry('id', $this->getId());
		$this->addEntry('type', $this->getType());
		$this->addEntry('url', $this->getUrl());
		$this->addEntry('to', $this->getTo());
		$this->addEntryArray('to', $this->toEmitted());
		$this->addEntryArray('cc', $this->getCcArray());

		if ($this->hasActor()) {
			$this->addEntry(
				'actor', $this->getActor()
					->getId()
			);
			if ($this->isCompleteDetails()) {
				$this->addEntryItem('actor_info', $this->getActor());
			}
		} else {
			$this->addEntry('actor', $this->getActorId());
		}

		$this->addEntry('summary', $this->getSummary());
		$this->addEntry('published', $this->getPublished());
		$this->addEntryArray('tag', $this->getTags());

		if ($this->hasObject()) {
			$this->addEntryItem('object', $this->getObject());
		} else {
			$this->addEntry('object', $this->getObjectId());
		}

		// `icon` is declared here because ACore holds it, though in practice
		// only an actor has one: moving it to Person means moving the accessors
		// too, and LocalController and the search provider read them off ACore.
		if ($this->hasIcon()) {
			$this->addEntryItem('icon', $this->getIcon());
		}

		if ($this->isCompleteDetails()) {
			$this->addEntry('source', $this->getSource());
		}

		if ($this->isLocal()) {
			$this->addEntryBool('local', $this->isLocal());
		}

		$result = $this->getEntries();
		$this->cleanArray($result);

		return $result;
	}

	/**
	 * Context terms needed by this root document. Subclasses can add terms for
	 * fields they actually emit, keeping unrelated wire documents stable.
	 *
	 * @return array<string, mixed>
	 */
	protected function activityPubContextExtensions(): array {
		$extensions = self::CONTEXT_EXTENSIONS;
		if ($this->getObject() instanceof \OCA\Social\Model\ActivityPub\Activity\ReplyRequest) {
			$extensions['ReplyRequest'] = 'gts:ReplyRequest';
		}

		return $extensions;
	}

	/**
	 * @return array
	 */
	public function exportAsLocal(): array {
		$result = [
			'id' => $this->getId(),
		];

		if (Nid::compare($this->getNid(), '0') > 0) {
			$result['id'] = (string)$this->getNid();
		}
		// Stream NIDs exceed JavaScript's exact-integer range, so they are
		// decimal strings at the JSON boundary.
		$nid = $this->getNid();
		$result['nid'] = (Nid::compare($nid, '0') > 0) ? (string)$nid : 0;

		return $result;
	}

	/**
	 * @return array
	 */
	public function exportAsNotification(): array {
		$result = [
			'id' => $this->getId()
		];

		if (Nid::compare($this->getNid(), '0') > 0) {
			$result['id'] = (string)$this->getNid();
		}

		return $result;
	}
}
