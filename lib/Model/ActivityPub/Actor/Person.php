<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub\Actor;

use DateTime;
use Exception;
use JsonSerializable;
use OCA\Social\AP;
use OCA\Social\AppInfo\Application as SocialApp;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Image;
use OCA\Social\Model\Details;
use OCA\Social\Tools\IQueryRow;
use OCA\Social\Traits\TDetails;
use OCP\IURLGenerator;
use OCP\Server;

/**
 * Class Actor
 *
 * @package OCA\Social\Model\ActivityPub
 */
class Person extends ACore implements IQueryRow, JsonSerializable {
	use TDetails;

	public const TYPE = 'Person';

	/**
	 * The actor types the fediverse serves an automated account as, and which
	 * Mastodon reports as `bot`: a `Service` is a bot account, an `Application`
	 * is a server's own actor. Nothing else on the wire states it.
	 */
	private const BOT_TYPES = [Service::TYPE, Application::TYPE];

	public const LINK_VIEWER = 'viewer';
	public const LINK_REMOTE = 'remote';
	public const LINK_LOCAL = 'local';

	private string $userId = '';
	private string $name = '';
	private string $preferredUsername = '';
	private string $displayName = '';
	private string $description = '';
	private string $publicKey = '';
	private string $privateKey = '';
	private int $creation = 0;
	private int $deleted = 0;
	private string $account = '';
	private string $following = '';
	private string $followers = '';
	private string $inbox = '';
	private string $outbox = '';
	private string $sharedInbox = '';
	private string $featured = '';
	private string $avatar = '';
	private string $header = '';
	/** The banner's type as its own document stated it; '' when nothing did. */
	private string $headerMediaType = '';
	private bool $locked = false;
	private array $emojis = [];
	private bool $bot = false;
	/**
	 * Who this actor belongs to, as ActivityPub's `attributedTo`.
	 *
	 * Empty for an ordinary account, which belongs to nobody but itself. A
	 * **channel** names its owner here, and has to: PeerTube fetches a video's
	 * channel and then looks for a `Person` in the channel's own `attributedTo`,
	 * refusing the video with *"Cannot find account attributed to video
	 * channel"* when there is none.
	 *
	 * @var array<int, array{type: string, id: string}>
	 */
	private array $attributedToActors = [];
	private bool $discoverable = false;
	private bool $indexable = false;
	private string $privacy = 'public';
	private bool $sensitive = false;
	private string $language = 'en';
	private int $avatarVersion = -1;
	private int $headerVersion = -1;
	private string $viewerLink = '';

	/** @var string[] */
	private array $alsoKnownAs = [];

	/** The actor this account moved to, if it has; `movedTo` on the wire. */
	private string $movedTo = '';

	/** The cached copy of the `movedTo` actor, when whoever hydrated this one had it. */
	private ?Person $movedToActor = null;

	/** @var array[] profile metadata, [['name' => string, 'value' => string], …] */
	private array $fields = [];

	public function __construct($parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
	}

	/**
	 * @return string
	 */
	public function getUserId(): string {
		return $this->userId;
	}

	/**
	 * @param string $userId
	 *
	 * @return Person
	 */
	public function setUserId(string $userId): self {
		$this->userId = $userId;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getPreferredUsername(): string {
		return $this->preferredUsername;
	}

	/**
	 * @param string $preferredUsername
	 *
	 * @return Person
	 */
	public function setPreferredUsername(string $preferredUsername): self {
		$this->preferredUsername = $preferredUsername;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getDisplayName(): string {
		if ($this->displayName === '') {
			return $this->getPreferredUsername();
		}

		return $this->displayName;
	}

	/**
	 * @param string $displayName
	 *
	 * @return $this
	 */
	public function setDisplayName(string $displayName): self {
		$this->displayName = $displayName;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getDescription(): string {
		return $this->description;
	}

	/**
	 * @param string $description
	 *
	 * @return Person
	 */
	public function setDescription(string $description): self {
		$this->description = $description;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getAvatar(): string {
		if ($this->hasIcon()) {
			return $this->getIcon()
				->getUrl();
		}

		return $this->avatar;
	}

	/**
	 * @param string $avatar
	 *
	 * @return $this
	 */
	public function setAvatar(string $avatar): self {
		$this->avatar = $avatar;

		return $this;
	}

	/**
	 * The banner, or `''`. A local account's stored header that is its own
	 * Nextcloud avatar is no banner: it is what the header used to fall back
	 * to, and the cached copy kept it as if it were one.
	 */
	public function getHeader(): string {
		if ($this->hasOwnAvatarAsHeader()) {
			return '';
		}

		return $this->header;
	}

	/**
	 * Whether the stored header of a local account is its own avatar: the
	 * address of its icon, or Nextcloud's avatar route of its user.
	 */
	public function hasOwnAvatarAsHeader(): bool {
		if (!$this->isLocal() || $this->header === '') {
			return false;
		}

		if ($this->hasIcon() && $this->getIcon()->getUrl() === $this->header) {
			return true;
		}

		return self::isAvatarRouteOf($this->header, [$this->getPreferredUsername(), $this->getUserId()]);
	}

	/**
	 * Whether a URL is Nextcloud's avatar route of one of these users, at any
	 * size: `/index.php/avatar/{user}/{size}`, or `/avatar/{user}/{size}` with
	 * pretty URLs, each with an optional `/dark`.
	 *
	 * @param string[] $users
	 */
	public static function isAvatarRouteOf(string $url, array $users): bool {
		$path = (string)parse_url($url, PHP_URL_PATH);
		if (preg_match('#/avatar/([^/]+)/\d+(?:/dark)?/?$#', $path, $match) !== 1) {
			return false;
		}

		$user = rawurldecode($match[1]);

		return $user !== '' && in_array($user, array_filter($users, static fn (string $name): bool => $name !== ''), true);
	}

	/**
	 * @param string $header
	 *
	 * @return $this
	 */
	public function setHeader(string $header): self {
		$this->header = $header;
		// whatever was said about the picture this replaces is not about this one
		$this->headerMediaType = '';

		return $this;
	}

	/**
	 * @return string
	 */
	public function getPublicKey(): string {
		return $this->publicKey;
	}

	/**
	 * @param string $publicKey
	 *
	 * @return Person
	 */
	public function setPublicKey(string $publicKey): self {
		$this->publicKey = $publicKey;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getPrivateKey(): string {
		return $this->privateKey;
	}

	/**
	 * @param string $privateKey
	 *
	 * @return Person
	 */
	public function setPrivateKey(string $privateKey): self {
		$this->privateKey = $privateKey;

		return $this;
	}

	/**
	 * @return int
	 */
	public function getCreation(): int {
		return $this->creation;
	}

	/**
	 * @param int $creation
	 *
	 * @return Person
	 */
	public function setCreation(int $creation): self {
		$this->creation = $creation;

		return $this;
	}

	/**
	 * @return int
	 */
	public function getDeleted(): int {
		return $this->deleted;
	}

	/**
	 * @param int $deleted
	 *
	 * @return Person
	 */
	public function setDeleted(int $deleted): self {
		$this->deleted = $deleted;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getFollowing(): string {
		return $this->following;
	}

	/**
	 * @param string $following
	 *
	 * @return Person
	 */
	public function setFollowing(string $following): self {
		$this->following = $following;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getFollowers(): string {
		return $this->followers;
	}

	/**
	 * @param string $followers
	 *
	 * @return Person
	 */
	public function setFollowers(string $followers): self {
		$this->followers = $followers;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getAccount(): string {
		return $this->account;
	}

	/**
	 * @param string $account
	 *
	 * @return Person
	 */
	public function setAccount(string $account): self {
		if ($account !== '' && substr($account, 0, 1) === '@') {
			$account = substr($account, 1);
		}

		$this->account = $account;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getInbox(): string {
		return $this->inbox;
	}

	/**
	 * @param string $inbox
	 *
	 * @return Person
	 */
	public function setInbox(string $inbox): self {
		$this->inbox = $inbox;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getOutbox(): string {
		return $this->outbox;
	}

	/**
	 * @param string $outbox
	 *
	 * @return Person
	 */
	public function setOutbox(string $outbox): self {
		$this->outbox = $outbox;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getSharedInbox(): string {
		return $this->sharedInbox;
	}

	/**
	 * @param string $sharedInbox
	 *
	 * @return Person
	 */
	public function setSharedInbox(string $sharedInbox): self {
		$this->sharedInbox = $sharedInbox;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getName(): string {
		if ($this->name === '') {
			return $this->preferredUsername;
		}

		return $this->name;
	}

	/**
	 * @param string $name
	 *
	 * @return Person
	 */
	public function setName(string $name): self {
		$this->name = $name;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getFeatured(): string {
		return $this->featured;
	}

	/**
	 * @param string $featured
	 *
	 * @return Person
	 */
	public function setFeatured(string $featured): self {
		$this->featured = $featured;

		return $this;
	}

	/**
	 * @return bool
	 */
	/**
	 * @return array[] Mastodon CustomEmoji entries used in the display name/bio
	 */
	public function getEmojis(): array {
		return $this->emojis;
	}

	public function setEmojis(array $emojis): self {
		$this->emojis = $emojis;

		return $this;
	}

	public function isLocked(): bool {
		return $this->locked;
	}

	/**
	 * @param bool $locked
	 *
	 * @return Person
	 */
	public function setLocked(bool $locked): self {
		$this->locked = $locked;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isBot(): bool {
		return $this->bot;
	}

	/**
	 * The type this actor is served as, for the column that stores it.
	 *
	 * `''` for the two the `bot` flag already decides between, so a row means
	 * "decide as before" unless something deliberately said otherwise — which
	 * is what every row written before `actor_type` existed means, and what an
	 * ordinary account goes on meaning.
	 */
	/**
	 * `attributedTo` as a list of typed references, however the document wrote
	 * it: PeerTube sends objects with a `type`, and a bare id is read as a
	 * `Person` because that is the only thing an actor is ever attributed to.
	 *
	 * @param array<string, mixed> $source
	 * @return array<int, array{type: string, id: string}>
	 */
	private function attributedToFrom(array $source): array {
		$raw = $source['attributedTo'] ?? [];
		if (is_string($raw)) {
			$raw = [$raw];
		}
		if (!is_array($raw)) {
			return [];
		}

		$actors = [];
		foreach ($raw as $entry) {
			if (is_string($entry) && $entry !== '') {
				$actors[] = ['type' => self::TYPE, 'id' => $entry];
				continue;
			}

			$id = is_array($entry) ? (string)($entry['id'] ?? '') : '';
			if ($id !== '') {
				$actors[] = ['type' => (string)($entry['type'] ?? self::TYPE), 'id' => $id];
			}
		}

		return $actors;
	}

	/**
	 * @return array<int, array{type: string, id: string}>
	 */
	public function getAttributedToActors(): array {
		return $this->attributedToActors;
	}

	/**
	 * @param array<int, array{type: string, id: string}> $actors
	 */
	public function setAttributedToActors(array $actors): self {
		$this->attributedToActors = $actors;

		return $this;
	}

	/**
	 * Whether `$actorId` is the account this actor belongs to: named in its
	 * `attributedTo` and on its own server, the way a PeerTube channel names
	 * the account behind it. Only the channel's server can speak for it.
	 */
	public function isRunBy(string $actorId): bool {
		$host = parse_url($actorId, PHP_URL_HOST);
		if (!is_string($host) || $host === '' || $host !== parse_url($this->getId(), PHP_URL_HOST)) {
			return false;
		}

		foreach ($this->getAttributedToActors() as $owner) {
			if ($owner['id'] === $actorId) {
				return true;
			}
		}

		return false;
	}

	public function storedActorType(): string {
		$type = $this->getType();

		return ($type === self::TYPE || $type === Service::TYPE) ? '' : $type;
	}

	/**
	 * @param bool $bot
	 *
	 * @return Person
	 */
	public function setBot(bool $bot): self {
		$this->bot = $bot;

		// The flag and the actor type are one fact. A peer reads `type`, a
		// client reads `bot`, and an account marked automated here that went on
		// serving `Person` would have told the two of them different things.
		// Only ever between these two: an `Application` stays an `Application`.
		if ($this->getType() === self::TYPE || $this->getType() === Service::TYPE) {
			$this->setType($bot ? Service::TYPE : self::TYPE);
		}

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isDiscoverable(): bool {
		return $this->discoverable;
	}

	/**
	 * @param bool $discoverable
	 *
	 * @return Person
	 */
	public function setDiscoverable(bool $discoverable): self {
		$this->discoverable = $discoverable;

		return $this;
	}

	/**
	 * Whether the account's public posts may be full-text indexed by other
	 * servers (`toot:indexable`). Opt-in, like `discoverable`.
	 */
	public function isIndexable(): bool {
		return $this->indexable;
	}

	public function setIndexable(bool $indexable): self {
		$this->indexable = $indexable;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getPrivacy(): string {
		return $this->privacy;
	}

	/**
	 * @param string $privacy
	 *
	 * @return Person
	 */
	public function setPrivacy(string $privacy): self {
		$this->privacy = $privacy;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isSensitive(): bool {
		return $this->sensitive;
	}

	/**
	 * @param bool $sensitive
	 *
	 * @return Person
	 */
	public function setSensitive(bool $sensitive): self {
		$this->sensitive = $sensitive;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getLanguage(): string {
		return $this->language;
	}

	/**
	 * @param string $language
	 *
	 * @return $this
	 */
	public function setLanguage(string $language): self {
		$this->language = $language;

		return $this;
	}

	/**
	 * @return int
	 */
	public function getAvatarVersion(): int {
		return $this->avatarVersion;
	}

	/**
	 * @param int $avatarVersion
	 *
	 * @return Person
	 */
	public function setAvatarVersion(int $avatarVersion): self {
		$this->avatarVersion = $avatarVersion;

		return $this;
	}

	/**
	 * @return int
	 */
	public function getHeaderVersion(): int {
		return $this->headerVersion;
	}

	/**
	 * @param int $headerVersion
	 *
	 * @return Person
	 */
	public function setHeaderVersion(int $headerVersion): self {
		$this->headerVersion = $headerVersion;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getViewerLink(): string {
		return $this->viewerLink;
	}

	/**
	 * @param string $viewerLink
	 *
	 * @return Person
	 */
	public function setViewerLink(string $viewerLink): self {
		$this->viewerLink = $viewerLink;

		return $this;
	}

	/**
	 * The actor ids this actor also answers to — a `Move` is only valid when
	 * its target lists the moving actor here.
	 *
	 * @return string[]
	 */
	public function getAlsoKnownAs(): array {
		return $this->alsoKnownAs;
	}

	/**
	 * @param string[] $alsoKnownAs
	 */
	public function setAlsoKnownAs(array $alsoKnownAs): self {
		$this->alsoKnownAs = array_values(array_filter($alsoKnownAs, 'is_string'));

		return $this;
	}

	/**
	 * The id of the actor this account moved to; empty while it has not.
	 */
	public function getMovedTo(): string {
		return $this->movedTo;
	}

	public function setMovedTo(string $movedTo): self {
		$this->movedTo = $movedTo;

		return $this;
	}

	/**
	 * The account behind `movedTo`, when it is known. What the client entity
	 * shows as `moved`; without it, a stub is derived from the id alone.
	 */
	public function getMovedToActor(): ?Person {
		return $this->movedToActor;
	}

	public function setMovedToActor(?Person $movedToActor): self {
		$this->movedToActor = $movedToActor;

		return $this;
	}

	/**
	 * Profile metadata (the name/value table under the bio), federated as
	 * `attachment` entries of type PropertyValue. Mastodon-compatible: at most
	 * four fields, both halves required.
	 *
	 * @return array[]
	 */
	public function getFields(): array {
		return $this->fields;
	}

	/**
	 * The names a profile field goes by when it holds somebody's pronouns, and
	 * when it holds a link for supporting them.
	 *
	 * Neither is a field of its own here, and deliberately so. A pronoun row
	 * is what Mastodon, GoToSocial and Akkoma users already write, it already
	 * federates as a `PropertyValue`, and a new property that only this app
	 * understood would be a pronoun nobody else could read. What this app adds
	 * is *recognising* the row it is in — drawing it beside the name rather
	 * than in a table at the bottom, which is where it belongs and where every
	 * other network puts it.
	 *
	 * Matched case- and accent-insensitively against the handful of spellings
	 * people actually use, in the languages this app is translated into most.
	 */
	private const PRONOUN_NAMES = [
		'pronouns', 'pronoun', 'pronomen', 'pronoms', 'pronombres', 'pronomi',
		'voornaamwoorden', 'zaimki', 'местоимения',
	];

	/** the names a field goes by when it holds the account's website */
	private const WEBSITE_NAMES = [
		'website', 'web', 'homepage', 'home page', 'site', 'blog', 'webseite',
		'site web', 'sitio web', 'sito web', 'strona',
	];

	private const SUPPORT_NAMES = [
		'support', 'donate', 'donation', 'donations', 'tip', 'tips', 'sponsor',
		'funding', 'unterstützen', 'spenden', 'soutien', 'apoyo', 'doar',
	];

	/**
	 * The account's pronouns, or `''`.
	 *
	 * Short by construction — anything past a handful of characters is a
	 * sentence somebody wrote in the wrong row, and drawing it beside the name
	 * would push the name off the line.
	 */
	public function getPronouns(): string {
		$value = $this->fieldNamed(self::PRONOUN_NAMES);

		return (mb_strlen($value) <= 40) ? $value : '';
	}

	/**
	 * Where to support this account, or `''`.
	 *
	 * Only an `https://` address, and only from the account's own profile:
	 * this is drawn as a button, and a button is a stronger invitation than a
	 * row in a table — so it may not be `javascript:`, a bare word, or
	 * anything a reader would not expect to be a link.
	 */
	public function getSupportLink(): string {
		$value = $this->fieldNamed(self::SUPPORT_NAMES);

		return (str_starts_with($value, 'https://') && filter_var($value, FILTER_VALIDATE_URL) !== false)
			? $value : '';
	}

	/**
	 * The account's website, or `''`: the field named for one, else the
	 * first field that is an address and nothing else — the one link a
	 * Bluesky profile has room for.
	 */
	public function getWebsite(): string {
		$named = $this->fieldNamed(self::WEBSITE_NAMES);
		if (self::isAddress($named)) {
			return $named;
		}
		foreach ($this->fields as $field) {
			$value = trim(strip_tags((string)($field['value'] ?? '')));
			if (self::isAddress($value)) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * The fields with the pronoun row and the website row set to these, as a
	 * Bluesky app saving the profile says them: a row already there is
	 * rewritten in place, a new one added while there is room for it, and
	 * one given as `''` taken away. `null` leaves that row as it is.
	 *
	 * @param array[] $fields [['name' => string, 'value' => string], …]
	 * @return array[]
	 */
	public static function withProfileRows(array $fields, ?string $pronouns, ?string $website): array {
		foreach ([[self::PRONOUN_NAMES, 'Pronouns', $pronouns], [self::WEBSITE_NAMES, 'Website', $website]] as [$names, $label, $value]) {
			if ($value === null) {
				continue;
			}
			$value = trim($value);
			$found = false;
			foreach ($fields as $i => $field) {
				if (in_array(self::fieldKey((string)($field['name'] ?? '')), $names, true)) {
					$found = true;
					if ($value === '') {
						unset($fields[$i]);
					} else {
						$fields[$i]['value'] = $value;
					}
					break;
				}
			}
			$fields = array_values($fields);
			if (!$found && $value !== '' && count($fields) < 4) {
				$fields[] = ['name' => $label, 'value' => $value];
			}
		}

		return $fields;
	}

	private static function isAddress(string $value): bool {
		return preg_match('~^https?://\S+$~i', $value) === 1 && filter_var($value, FILTER_VALIDATE_URL) !== false;
	}

	/**
	 * A field's name as it is matched: lowercase, without the colon or the
	 * mark it is often written with.
	 */
	private static function fieldKey(string $name): string {
		return trim(mb_strtolower(trim($name)), " \t:：*-–—_#");
	}

	/**
	 * The value of the first field whose name is one of these.
	 *
	 * @param string[] $names
	 */
	private function fieldNamed(array $names): string {
		foreach ($this->fields as $field) {
			if (in_array(self::fieldKey((string)($field['name'] ?? '')), $names, true)) {
				return trim(strip_tags((string)($field['value'] ?? '')));
			}
		}

		return '';
	}

	/**
	 * @param array[] $fields [['name' => string, 'value' => string], …]
	 */
	public function setFields(array $fields): self {
		$this->fields = [];
		foreach ($fields as $field) {
			if (!is_array($field)) {
				continue;
			}
			$name = trim((string)($field['name'] ?? ''));
			$value = trim((string)($field['value'] ?? ''));
			if ($name === '' || $value === '') {
				continue;
			}
			$this->fields[] = [
				'name' => mb_substr($name, 0, 255),
				'value' => mb_substr($value, 0, 500)
			];
			if (count($this->fields) === 4) {
				break;
			}
		}

		return $this;
	}

	/**
	 * A local field's value as the HTML `PropertyValue.value` is on the wire.
	 *
	 * A local value is stored as it was typed, and every peer renders `value`
	 * as HTML: a `<` or `&` went out as markup, and an address was plain text
	 * on Mastodon, which verifies a field only from a link in it. An address
	 * on its own becomes a link with `rel="me"`, which is what Mastodon's own
	 * fields carry; everything else is escaped.
	 */
	private static function fieldValueAsHtml(string $value): string {
		$escaped = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		if (preg_match('~^https?://\S+$~i', $value) !== 1 || filter_var($value, FILTER_VALIDATE_URL) === false) {
			return $escaped;
		}

		return '<a href="' . $escaped . '" target="_blank" rel="nofollow noopener noreferrer me" translate="no">'
			. $escaped . '</a>';
	}

	/**
	 * @return array[] the PropertyValue entries of an actor's `attachment`
	 */
	private function extractFieldsFromAttachment(array $data): array {
		$fields = [];
		foreach (self::listOf('attachment', $data) as $entry) {
			if (!is_array($entry) || ($entry['type'] ?? '') !== 'PropertyValue') {
				continue;
			}
			$fields[] = [
				'name' => (string)($entry['name'] ?? ''),
				'value' => (string)($entry['value'] ?? '')
			];
		}

		return $fields;
	}

	/**
	 * @param array $data
	 *
	 * @throws ItemUnknownException
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 * @throws InvalidOriginException
	 */
	#[\Override]
	public function import(array $data) {
		parent::import($data);
		$this->setEmojis($this->extractEmojisFromTag($data));
		$this->setDescription($this->validate(ACore::AS_CONTENT, 'summary', $data, ''))
			->setPreferredUsername($this->validate(ACore::AS_USERNAME, 'preferredUsername', $data, ''))
			->setPublicKey($this->get('publicKey.publicKeyPem', $data))
			->setSharedInbox($this->validate(ACore::AS_URL, 'endpoints.sharedInbox', $data))
			->setName($this->validate(ACore::AS_USERNAME, 'name', $data, ''))
			->setInbox($this->validate(ACore::AS_URL, 'inbox', $data, ''))
			->setOutbox($this->validate(ACore::AS_URL, 'outbox', $data, ''))
			->setFollowers($this->validate(ACore::AS_URL, 'followers', $data, ''))
			->setFollowing($this->validate(ACore::AS_URL, 'following', $data, ''))
			->setFeatured($this->validate(ACore::AS_URL, 'featured', $data, ''))
			->setAlsoKnownAs(self::listOf('alsoKnownAs', $data))
			->setMovedTo($this->validate(ACore::AS_URL, 'movedTo', $data, ''));
		// A key that says whose it is has to say this actor. A document handing
		// over somebody else's `owner` is a key takeover written out in full:
		// what is stored against an actor is the key requests from it are then
		// verified with.
		$keyOwner = $this->get('publicKey.owner', $data, '');
		if ($keyOwner !== '' && $keyOwner !== $this->getId()) {
			throw new InvalidOriginException(
				'Person::import - id: ' . $this->getId() . ' - publicKey.owner: ' . $keyOwner
			);
		}

		// `account` is not an ActivityPub field: the handle is whatever the
		// actor's own `preferredUsername` and the host of its id say it is.
		// Read off the document, it let any server claim a handle on any other
		// — and it is what `getFromAccount()` answers with.
		$this->setAccount($this->canonicalAccount());

		$this->setLocked($this->getBool('manuallyApprovesFollowers', $data, false));
		$this->setBot(in_array($this->getType(), self::BOT_TYPES, true));
		// Mastodon serialises an unset preference as null; getBool() reads that as the default
		$this->setDiscoverable($this->getBool('discoverable', $data, false));
		$this->setIndexable($this->getBool('indexable', $data, false));
		$this->setFields($this->extractFieldsFromAttachment($data));

		/** @var Image $icon */
		$icon = AP::instance()->getItemFromType(Image::TYPE);
		$icon->setParent($this);
		$icon->import(self::largestImage($data, 'icon'));

		if ($icon->getType() === Image::TYPE) {
			$this->setIcon($icon);
		}

		$banner = self::largestImage($data, 'image');
		$image = $this->get('url', $banner, '');
		if ($image !== '') {
			$this->setHeader($image);
			$this->headerMediaType = $this->validate(self::AS_STRING, 'mediaType', $banner, '');
		}
	}

	/**
	 * What the banner is, for the `mediaType` of the actor's `image`.
	 *
	 * GoToSocial stores the declared type, so `image/jpeg` for every banner —
	 * what this used to say — was a wrong statement about each PNG and WebP.
	 * A remote banner keeps the type its own document gave; a local one is
	 * served from `/media/{uuid}.{subtype}`, which names it. Anything else is
	 * left unstated rather than guessed.
	 */
	private function headerMediaType(): string {
		if ($this->headerMediaType !== '') {
			return $this->headerMediaType;
		}

		$extension = strtolower(pathinfo((string)parse_url($this->header, PHP_URL_PATH), PATHINFO_EXTENSION));

		return match ($extension) {
			'jpg', 'jpeg' => 'image/jpeg',
			'png', 'gif', 'webp', 'avif' => 'image/' . $extension,
			default => '',
		};
	}

	/**
	 * The one picture to keep out of an `icon` or `image`.
	 *
	 * Mastodon sends one `Image`; PeerTube sends a list of them, one per size,
	 * for an account's avatar and a channel's banner alike. Handed the whole
	 * list, `Image::import()` found no `type` and the picture was dropped, so
	 * every PeerTube account arrived without an avatar. The largest is taken,
	 * as `PeerTubeService::thumbnail()` does for a video's poster: they are
	 * the same picture, and the others are its thumbnails.
	 *
	 * @param array<array-key, mixed> $data
	 *
	 * @return array<array-key, mixed> the `Image`, or [] when there is none
	 */
	private static function largestImage(array $data, string $k): array {
		$best = [];
		$bestArea = -1;
		foreach (self::listOf($k, $data) as $candidate) {
			// an untyped picture is still one; anything else typed is not
			if (!is_array($candidate) || ($candidate['type'] ?? Image::TYPE) !== Image::TYPE) {
				continue;
			}

			$area = (int)($candidate['width'] ?? 0) * (int)($candidate['height'] ?? 0);
			if ($area > $bestArea) {
				$bestArea = $area;
				$best = $candidate;
			}
		}

		return $best;
	}

	/**
	 * `preferredUsername@host`, the handle an actor document states about
	 * itself, or `''` while either half is missing.
	 */
	private function canonicalAccount(): string {
		$host = parse_url($this->getId(), PHP_URL_HOST);
		if ($this->getPreferredUsername() === '' || !is_string($host) || $host === '') {
			return '';
		}

		return $this->getPreferredUsername() . '@' . $host;
	}

	/**
	 * @param array $data
	 *
	 * @return $this
	 */
	#[\Override]
	public function importFromLocal(array $data): self {
		parent::importFromLocal($data);

		$this->setId($this->get('url', $data));
		$this->setPreferredUsername($this->get('username', $data));
		$this->setAccount($this->get('acct', $data));
		$this->setDisplayName($this->get('display_name', $data));
		$this->setLocked($this->getBool('locked', $data));
		$this->setBot($this->getBool('bot', $data));
		$this->setDiscoverable($this->getBool('discoverable', $data));
		$this->setIndexable($this->getBool('indexable', $data));
		$this->setMovedTo($this->get('moved.url', $data, ''));
		$this->setDescription($this->get('note', $data));
		$this->setUrl($this->get('url', $data));

		$this->setAvatar($this->get('avatar', $data));
		$this->setHeader($this->get('header', $data));

		$this->setPrivacy($this->get('source.privacy', $data));
		$this->setSensitive($this->getBool('source.sensitive', $data));
		$this->setLanguage($this->get('source.language', $data));
		$this->setFields($this->getArray('fields', $data, []));

		try {
			$dTime = new DateTime($this->get('created_at', $data, 'yesterday'));
			$this->setCreation($dTime->getTimestamp());
		} catch (Exception $e) {
		}

		$count = [
			'followers' => $this->getInt('followers_count', $data),
			'following' => $this->getInt('following_count', $data),
			'post' => $this->getInt('statuses_count', $data),
			'last_post_creation' => $this->get('last_status_at', $data)
		];
		$this->setDetailArray(Details::COUNT, $count);

		return $this;
	}

	/**
	 * The three counters, laid over what `details` says.
	 *
	 * They live in their own columns so that one can be moved without
	 * recomputing the other two, while the JSON stays what is read — so every
	 * path that turns a row into an actor has to lay one over the other, and
	 * doing it here is what makes that true of all of them. It used to be done
	 * in one of the two query builders that parse this row, and that one did
	 * not select the columns: the overlay never had anything to lay on, and
	 * every profile showed the JSON until the cron's recount rewrote it — a
	 * post count that did not move when you posted.
	 *
	 * `-1` is "never counted" and leaves the JSON alone, which is what a row
	 * written before the columns existed carries. A row without the columns at
	 * all — the local actor table, which has none — is left alone too.
	 */
	private function overlayStoredCounts(array $data): void {
		if (!array_key_exists('count_followers', $data) || (int)$data['count_followers'] < 0) {
			return;
		}

		$count = $this->getDetails(Details::COUNT);
		$count['followers'] = (int)$data['count_followers'];
		$count['following'] = max(0, (int)($data['count_following'] ?? 0));
		$count['post'] = max(0, (int)($data['count_posts'] ?? 0));

		$this->setDetailArray(Details::COUNT, $count);
	}

	/**
	 * @param array $data
	 */
	#[\Override]
	public function importFromDatabase(array $data) {
		parent::importFromDatabase($data);

		// The parent flattens `summary` the way a plain-text field arriving
		// from the wire has to be flattened. A stored bio is not arriving from
		// anywhere: it is the text the user typed, and everything that renders
		// it escapes it (`bioAsHtml()`), so flattening it again protects
		// nothing and destroys plenty — `strip_tags()` reads a bare `<` as the
		// start of a tag and eats the rest of the line, so a bio saying
		// `Maths: a<b and b>c` came back out of the database as `Maths: ac`.
		// A cached remote actor's column holds HTML, but nothing renders
		// `getSummary()` for one: remote bios are read from `description`,
		// which the sanitising pass below still produces.
		$this->setSummary((string)($data['summary'] ?? ''));

		// the columns of a local actor row; a cache row has none of them and
		// carries the same facts in its source document, read just below
		$this->setLocked($this->getInt('locked', $data, 0) === 1);
		$this->setDiscoverable($this->getInt('discoverable', $data, 0) === 1);
		$this->setIndexable($this->getInt('indexable', $data, 0) === 1);
		$this->setMovedTo($this->get('moved_to', $data, ''));
		$storedAliases = json_decode($this->get('also_known_as', $data, ''), true);
		if (is_array($storedAliases)) {
			$this->setAlsoKnownAs($storedAliases);
		}

		$source = json_decode($this->getSource(), true);
		if (is_array($source)) {
			$banner = self::largestImage($source, 'image');
			$image = $this->get('url', $banner, '');
			if ($image !== '') {
				$this->setHeader($image);
				$this->headerMediaType = $this->validate(self::AS_STRING, 'mediaType', $banner, '');
			}
			$this->setAlsoKnownAs(self::listOf('alsoKnownAs', $source));
			// Whose a channel is. The cached copy is what every read of an
			// actor is served from and it has no column for this, so it comes
			// back out of the source document the same way `alsoKnownAs` does —
			// without it the `Group` was served with no owner, which is the
			// second thing PeerTube refuses a video for.
			$this->setAttributedToActors($this->attributedToFrom($source));
			$this->setMovedTo($this->validate(self::AS_URL, 'movedTo', $source, $this->getMovedTo()));
			$this->setLocked($this->getBool('manuallyApprovesFollowers', $source, $this->isLocked()));
			$this->setDiscoverable($this->getBool('discoverable', $source, $this->isDiscoverable()));
			$this->setIndexable($this->getBool('indexable', $source, $this->isIndexable()));
			$this->setEmojis($this->extractEmojisFromTag($source));
			$this->setFields($this->extractFieldsFromAttachment($source));
		}

		// A cached remote row keeps the actor type it was served as, which is
		// the only thing that ever said the account is automated. A local row
		// has the flag itself, because nothing else stores it: the type this
		// app serves *is* the flag, so reading it back off the type would be
		// circular.
		if (array_key_exists('bot', $data)) {
			$this->setBot($this->getInt('bot', $data, 0) === 1);
		} else {
			$this->setBot(in_array($this->getType(), self::BOT_TYPES, true));
		}

		// A local actor that is something other than a person: a channel is a
		// `Group`, which is the one thing PeerTube will look for when it asks
		// which channel a video belongs to. Read *after* the bot flag, because
		// `setBot()` moves the type between Person and Service and would
		// otherwise overwrite this one.
		$actorType = $this->get('actor_type', $data, '');
		if ($actorType !== '') {
			$this->setType($actorType);
		}

		// local actor rows carry the canonical fields in their own column
		$storedFields = json_decode($this->get('fields', $data, ''), true);
		if (is_array($storedFields)) {
			$this->setFields($storedFields);
		}

		$this->setPreferredUsername($this->validate(self::AS_USERNAME, 'preferred_username', $data, ''))
			->setUserId($this->get('user_id', $data, ''))
			->setName($this->validate(self::AS_USERNAME, 'name', $data, ''))
			->setDescription($this->validate(self::AS_CONTENT, 'summary', $data))
			->setAccount($this->validate(self::AS_ACCOUNT, 'account', $data, ''))
			->setPublicKey($this->get('public_key', $data, ''))
			->setPrivateKey($this->get('private_key', $data, ''))
			->setInbox($this->validate(self::AS_URL, 'inbox', $data, ''))
			->setOutbox($this->validate(self::AS_URL, 'outbox', $data, ''))
			->setFollowers($this->validate(self::AS_URL, 'followers', $data, ''))
			->setFollowing($this->validate(self::AS_URL, 'following', $data, ''))
			->setSharedInbox($this->validate(self::AS_URL, 'shared_inbox', $data, ''))
			->setFeatured($this->validate(self::AS_URL, 'featured', $data, ''))
			->setDetailsAll($this->getArray('details', $data, []));

		$this->overlayStoredCounts($data);

		try {
			$cTime = new DateTime($this->get('creation', $data, 'yesterday'));
			$this->setCreation($cTime->getTimestamp());
		} catch (Exception $e) {
		}

		try {
			$deletedValue = $this->get('deleted', $data);
			if ($deletedValue === '' || $deletedValue === '0000-00-00 00:00:00') {
				return;
			}
			$dTime = new DateTime($deletedValue);
			$deleted = $dTime->getTimestamp();
			if ($deleted > 0) {
				$this->setDeleted($deleted);
			}
		} catch (Exception $e) {
		}
	}

	/**
	 * @return array
	 */
	/**
	 * The bio of a local actor as HTML.
	 *
	 * A local bio is stored as the plain text the user typed (see
	 * `AccountService::setSummary()`), because that is the only form a client
	 * can put back in an edit box. `summary` on the wire and `note` on the
	 * client API are HTML everywhere else in the Fediverse, though — Mastodon
	 * renders both, and this app's own profile card hands `note` to `v-html` —
	 * so the stored text is rendered here: a blank line starts a paragraph, a
	 * single newline is a `<br />`, and everything else is escaped, which is
	 * what a bio reading `bees & goats` needs to survive the trip.
	 *
	 * A remote bio never goes through this: it arrived as HTML and was
	 * sanitized on import, and is passed on as it is.
	 */
	private function bioAsHtml(): string {
		$plain = trim(str_replace(["\r\n", "\r"], "\n", $this->getSummary()));
		if ($plain === '') {
			return '';
		}

		$html = '';
		foreach (preg_split('/\n{2,}/', $plain) ?: [] as $paragraph) {
			$escaped = htmlspecialchars($paragraph, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$html .= '<p>' . str_replace("\n", '<br />', $escaped) . '</p>';
		}

		return $html;
	}

	#[\Override]
	public function exportAsActivityPub(): array {
		if ($this->getPublicKey() !== '') {
			$this->setDisplayW3ContextSecurity(true);
		}

		$data = [
			'aliases' => [
				$this->getUrlSocial() . '@' . $this->getPreferredUsername(),
				$this->getUrlSocial() . 'users/' . $this->getPreferredUsername()
			],
			'preferredUsername' => $this->getPreferredUsername(),
			'name' => $this->getName(),
			'inbox' => $this->getInbox(),
			'outbox' => $this->getOutbox(),
			'account' => $this->getAccount(),
			'following' => $this->getFollowing(),
			'followers' => $this->getFollowers(),
			'endpoints' => ['sharedInbox' => $this->getSharedInbox()],
			'publicKey' => [
				'id' => $this->getId() . '#main-key',
				'owner' => $this->getId(),
				'publicKeyPem' => $this->getPublicKey()
			]
		];

		// a local bio is stored as plain text, and `summary` is HTML on the wire
		if ($this->isLocal() && ($bio = $this->bioAsHtml()) !== '') {
			$data['summary'] = $bio;
		}

		$data['manuallyApprovesFollowers'] = $this->isLocked();
		// Both default to false on the receiving side (Mastodon), so an actor
		// that omits them is never listed in a directory, suggested, or
		// full-text searched: they have to be said out loud to opt in.
		$data['discoverable'] = $this->isDiscoverable();
		$data['indexable'] = $this->isIndexable();

		if ($this->getFeatured() !== '') {
			$data['featured'] = $this->getFeatured();
		}

		// Whose this is. Only a channel sets it, and a channel must: PeerTube
		// fetches the `Group` a video names and refuses the video outright
		// unless a `Person` is attributed here.
		if ($this->getAttributedToActors() !== []) {
			$data['attributedTo'] = $this->getAttributedToActors();
		}

		if ($this->getAlsoKnownAs() !== []) {
			$data['alsoKnownAs'] = $this->getAlsoKnownAs();
		}

		if ($this->getMovedTo() !== '') {
			$data['movedTo'] = $this->getMovedTo();
		}

		if ($this->fields !== []) {
			$local = $this->isLocal();
			$data['attachment'] = array_map(
				static fn (array $field): array => [
					'type' => 'PropertyValue',
					'name' => $field['name'],
					'value' => $local ? self::fieldValueAsHtml($field['value']) : $field['value'],
				],
				$this->fields
			);
		}

		if ($this->hasIcon()) {
			$icon = $this->getIcon();
			$data['icon'] = [
				'type' => $icon->getType(),
				'mediaType' => $icon->getMediaType(),
				'url' => $icon->getUrl()
			];
		}

		if ($this->getHeader() !== '') {
			$data['image'] = array_filter([
				'type' => 'Image',
				'mediaType' => $this->headerMediaType(),
				'url' => $this->getHeader(),
			], static fn (string $value): bool => $value !== '');
		}

		$result = array_merge(
			parent::exportAsActivityPub(),
			$data
		);

		if ($this->isCompleteDetails()) {
			$result['details'] = $this->getDetailsAll();
			$result['viewerLink'] = $this->getViewerLink();
		}

		return $result;
	}

	/**
	 * The `source` half of Mastodon's CredentialAccount: an account's own
	 * editable copy of its settings.
	 *
	 * Deliberately *not* part of `exportAsLocal()`. It used to be, so every
	 * Account entity this app emitted carried it — somebody else's profile,
	 * a search result, a page of followers, an anonymous read — and with it
	 * `follow_requests_count`, which is nobody's business but the account's
	 * own. Mastodon puts `source` on exactly two routes, and so does this:
	 * `verify_credentials` and `update_credentials`, which are the two that
	 * know they are answering the account itself.
	 *
	 * @return array<string, mixed>
	 */
	public function exportSourceAsLocal(): array {
		$details = $this->getDetailsAll();

		return [
			'privacy' => $this->getPrivacy(),
			'sensitive' => $this->isSensitive(),
			'language' => $this->getLanguage(),
			// the account's own editable copy, so the bio is the plain text it
			// is stored as, never the rendered HTML
			'note' => $this->isLocal() ? $this->getSummary() : $this->getDescription(),
			'fields' => $this->getFields(),
			'follow_requests_count' => $this->getInt('count.follow_requests', $details),
		];
	}

	/**
	 * The banner an account without one is given on the client API: a plain
	 * picture of the right shape, as Mastodon's `missing.png`, and a PNG like
	 * it, because clients that decode only raster images draw nothing for an
	 * SVG. Never the avatar -- a client draws the header where a header goes,
	 * and a face stretched across the top of a profile with an empty circle
	 * under it is what that looked like.
	 */
	public function placeholderHeader(IURLGenerator $urlGenerator): string {
		return $urlGenerator->getAbsoluteURL(
			$urlGenerator->imagePath(SocialApp::APP_ID, 'header-missing.png')
		);
	}

	/**
	 * The picture shown for an account that has none cached yet: for a local
	 * account Nextcloud's own avatar, which every user has (generated from the
	 * initials when nothing was uploaded), the app icon otherwise.
	 */
	public function placeholderImage(IURLGenerator $urlGenerator): string {
		if ($this->isLocal()) {
			return $urlGenerator->linkToRouteAbsolute(
				'core.avatar.getAvatar', ['userId' => $this->getPreferredUsername(), 'size' => 128]
			);
		}

		return $urlGenerator->getAbsoluteURL(
			$urlGenerator->imagePath(SocialApp::APP_ID, 'social.svg')
		);
	}

	/**
	 * @return array
	 */
	#[\Override]
	public function exportAsLocal(): array {
		$avatarDefault = false;
		if ($this->hasIcon()) {
			// an icon with no local copy yet -- fetched by the next cache run,
			// or replaced a moment ago by a removed or renamed avatar -- has
			// no address here, and the origin is not offered in its place
			$avatar = $this->getIcon()->getMediaUrl(Server::get(IURLGenerator::class));
			if ($avatar === '') {
				$avatar = $this->placeholderImage(Server::get(IURLGenerator::class));
				$avatarDefault = true;
			}
		} elseif ($this->getAvatar() === '') {
			// an account that never set a picture: the client API promises a
			// URL, and a local account always has Nextcloud's own avatar
			$avatar = $this->placeholderImage(Server::get(IURLGenerator::class));
			$avatarDefault = true;
		}

		$headerUrl = $this->getHeader();
		$headerDefault = ($headerUrl === '');
		if ($headerDefault) {
			$headerUrl = $this->placeholderHeader(Server::get(IURLGenerator::class));
		}
		$details = $this->getDetailsAll();
		// when the page a field names was last seen linking back with rel="me"
		// (ProfileLinkVerifier); null until it has, as on Mastodon
		$verified = $details['fields_verified'] ?? [];
		$fields = array_map(
			static fn (array $field): array => array_merge($field, [
				'verified_at' => (isset($verified[$field['value']]) && is_string($verified[$field['value']])) ? $verified[$field['value']] : null,
			]),
			$this->getFields()
		);
		$result
			= [
				'id' => (string)$this->getNid(),
				'username' => $this->getPreferredUsername(),
				'acct' => $this->isLocal() ? $this->getPreferredUsername() : $this->getAccount(),
				'display_name' => $this->getName(),
				'locked' => $this->isLocked(),
				'bot' => $this->isBot(),
				'discoverable' => $this->isDiscoverable(),
				'indexable' => $this->isIndexable(),
				// Mastodon's own flag for "this is not a person". It was
				// hardcoded false, which was true until channels existed: a
				// PeerTube channel and one of ours are both `Group`s, and a
				// client that is told otherwise offers "Follow" where the thing
				// on the screen is something you subscribe to.
				'group' => $this->getType() === Group::TYPE,
				'created_at' => gmdate('Y-m-d\TH:i:s', $this->getCreation()) . '.000Z',
				'note' => $this->isLocal() ? $this->bioAsHtml() : $this->getDescription(),
				'url' => $this->getId(),
				'avatar' => $avatar ?? $this->getAvatar(),
				'avatar_static' => $avatar ?? $this->getAvatar(),
				'header' => $headerUrl,
				'header_static' => $headerUrl,
				// this app's own: whether `avatar` and `header` are placeholders
				// rather than pictures the account set, which a client could
				// otherwise only guess from the shape of the URL
				'avatar_default' => $avatarDefault,
				'header_default' => $headerDefault,
				'followers_count' => $this->getInt('count.followers', $details),
				'following_count' => $this->getInt('count.following', $details),
				'statuses_count' => $this->getInt('count.post', $details),
				// null, not '', while nothing was posted: a date-or-null field in Mastodon's entity
				'last_status_at' => $this->get('last_post_creation', $details) !== ''
					? $this->get('last_post_creation', $details) : null,
				'emojis' => $this->getEmojis(),
				'fields' => $fields,
				// the two rows this app draws rather than tabulates: beside the
				// name, and as a button. Sent as their own keys so a client
				// does not have to know which spellings of "Pronouns" to look
				// for, and left in `fields` as well, because that is what every
				// other network reads
				'pronouns' => $this->getPronouns(),
				'support_link' => $this->getSupportLink(),
				// a local account's Bluesky handle and DID, attached by the
				// controllers that know them (see LocalController::attachBluesky)
				'bluesky' => (isset($details[Details::BLUESKY]) && is_array($details[Details::BLUESKY])) ? $details[Details::BLUESKY] : null,
			];

		if ($this->getMovedTo() !== '') {
			$result['moved'] = $this->exportMovedAccount();
		}

		return array_merge(parent::exportAsLocal(), $result);
	}

	/**
	 * The `moved` account entity: what a client shows as the "has moved"
	 * banner and follows through to. The cached target when it is known; a
	 * stub derived from the id otherwise, complete in shape so a client that
	 * expects a full Account entity does not choke on it. Never chains: a
	 * target that itself moved on is not followed.
	 */
	private function exportMovedAccount(): array {
		$target = $this->movedToActor;
		if ($target === null) {
			$target = new Person();
			$path = (string)parse_url($this->movedTo, PHP_URL_PATH);
			$username = basename(rtrim($path, '/'));
			$username = ltrim($username, '@');
			$host = (string)parse_url($this->movedTo, PHP_URL_HOST);
			$target->setId($this->movedTo);
			$target->setUrl($this->movedTo);
			$target->setPreferredUsername($username)
				->setAccount($host === '' ? $username : $username . '@' . $host);
		}

		$export = $target->exportAsLocal();
		unset($export['moved']);

		return $export;
	}
}
