<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Tools\IQueryRow;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Class Instance
 *
 * @package OCA\Social\Model
 */
class Instance implements IQueryRow, JsonSerializable {
	use TArrayTools;

	private bool $local = false;
	private string $uri = '';
	private string $title = '';
	private string $version = '';
	private string $shortDescription = '';
	private string $description = '';
	private string $email = '';
	private array $urls = [];
	private array $stats = [];
	private array $usage = [];
	private string $image = '';
	private array $languages = [];
	private bool $registrations = false;
	private bool $approvalRequired = false;
	private bool $invitesEnabled = false;
	/** Where to sign up in a browser, for a client that cannot create the account itself. */
	private ?string $registrationsUrl = null;
	private bool $translationEnabled = false;
	private ?Person $contactAccount = null;
	private ?string $accountPrim = null;
	private array $configuration = [];
	private array $rules = [];

	public function isLocal(): bool {
		return $this->local;
	}

	public function setLocal(bool $local): self {
		$this->local = $local;

		return $this;
	}

	public function getUri(): string {
		return $this->uri;
	}

	public function setUri(string $uri): self {
		$this->uri = $uri;

		return $this;
	}

	public function getTitle(): string {
		return $this->title;
	}

	public function setTitle(string $title): self {
		$this->title = $title;

		return $this;
	}

	public function getVersion(): string {
		return $this->version;
	}

	/**
	 * The Mastodon version advertised to API clients, Pleroma-style, as
	 * clients gate features on it; NodeInfo reports the real app version.
	 *
	 * Clients believe it, so it may only claim what the app serves: 4.3 covers
	 * editing (`/source`, `/history`), server-side v2 filters,
	 * `/api/v2/instance`, `unread_count`, grouped notifications, the
	 * notification policy and requests inbox, and translation. Streaming
	 * needs the optional `social:mastodon:stream` daemon and its proxy route;
	 * Web Push is served at `/api/v1/push/subscription`.
	 */
	public const COMPAT_VERSION = '4.3.0';

	/**
	 * The API generations this server implements, as Mastodon 4.3 reports
	 * them. Clients that know `api_versions` read it instead of the version
	 * string, which is unreliable for a fork.
	 *
	 * `mastodon: 3` is the API generation of 4.3 and 4.4 (4.5 says 4).
	 * `aloha_social` is this app's own, raised when a behaviour clients may
	 * gate on (`>=`) is added:
	 *
	 * - 1: `/api/v2/search` honours `account_id`, `offset`, `max_id` and
	 *   `min_id` for `statuses`.
	 */
	public const API_VERSIONS = ['mastodon' => 3, 'aloha_social' => 1];

	public function getCompatVersion(): string {
		return self::COMPAT_VERSION . ' (compatible; Aloha Social ' . $this->version . ')';
	}

	/**
	 * Whether a client should offer the translate button: true when this
	 * Nextcloud has a translation provider (`TranslationService`).
	 */
	public function setTranslationEnabled(bool $translationEnabled): self {
		$this->translationEnabled = $translationEnabled;

		return $this;
	}

	public function isTranslationEnabled(): bool {
		return $this->translationEnabled;
	}

	public function setVersion(string $version): self {
		$this->version = $version;

		return $this;
	}

	public function getShortDescription(): string {
		return $this->shortDescription;
	}

	public function setShortDescription(string $shortDescription): self {
		$this->shortDescription = $shortDescription;

		return $this;
	}

	public function getDescription(): string {
		return $this->description;
	}

	public function setDescription(string $description): self {
		$this->description = $description;

		return $this;
	}

	public function getEmail(): string {
		return $this->email;
	}

	public function setEmail(string $email): self {
		$this->email = $email;

		return $this;
	}

	public function getUrls(): array {
		return $this->urls;
	}

	public function setUrls(array $urls): self {
		$this->urls = $urls;

		return $this;
	}

	/** `configuration.urls` of the v2 entity: v1's `urls`, `streaming_api` named `streaming`. */
	private function v2Urls(): array {
		$urls = $this->getUrls();
		if (array_key_exists('streaming_api', $urls)) {
			$urls['streaming'] = $urls['streaming_api'];
			unset($urls['streaming_api']);
		}

		return $urls;
	}

	public function getStats(): array {
		return $this->stats;
	}

	public function setStats(array $stats): self {
		$this->stats = $stats;

		return $this;
	}

	public function getUsage(): array {
		return $this->usage;
	}

	/**
	 * @param array $usage
	 *
	 * @return Instance
	 */
	public function setUsage(array $usage): self {
		$this->usage = $usage;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getImage(): string {
		return $this->image;
	}

	public function setImage(string $image): self {
		$this->image = $image;

		return $this;
	}

	public function getLanguages(): array {
		return $this->languages;
	}

	public function setLanguages(array $languages): self {
		$this->languages = $languages;

		return $this;
	}

	public function isRegistrations(): bool {
		return $this->registrations;
	}

	public function setRegistrations(bool $registrations): self {
		$this->registrations = $registrations;

		return $this;
	}

	public function isApprovalRequired(): bool {
		return $this->approvalRequired;
	}

	public function setApprovalRequired(bool $approvalRequired): self {
		$this->approvalRequired = $approvalRequired;

		return $this;
	}

	public function getRegistrationsUrl(): ?string {
		return $this->registrationsUrl;
	}

	public function setRegistrationsUrl(?string $registrationsUrl): self {
		$this->registrationsUrl = $registrationsUrl;

		return $this;
	}

	public function isInvitesEnabled(): bool {
		return $this->invitesEnabled;
	}

	public function setInvitesEnabled(bool $invitesEnabled): self {
		$this->invitesEnabled = $invitesEnabled;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function hasContactAccount(): bool {
		return ($this->contactAccount !== null);
	}

	public function getContactAccount(): ?Person {
		return $this->contactAccount;
	}

	/**
	 * @param Person $account
	 *
	 * @return Instance
	 */
	public function setContactAccount(Person $account): self {
		$this->contactAccount = $account;

		return $this;
	}

	/**
	 * Mastodon's `configuration` block: the limits a client has to respect
	 * before it lets someone write a post it cannot send.
	 */
	public function getConfiguration(): array {
		return $this->configuration;
	}

	public function setConfiguration(array $configuration): self {
		$this->configuration = $configuration;

		return $this;
	}

	public function getRules(): array {
		return $this->rules;
	}

	public function setRules(array $rules): self {
		$this->rules = $rules;

		return $this;
	}

	public function getAccountPrim(): ?string {
		return $this->accountPrim;
	}

	public function setAccountPrim(string $prim): self {
		$this->accountPrim = $prim;

		return $this;
	}

	/**
	 * @param array $data
	 *
	 * @return $this
	 */
	#[\Override]
	public function importFromDatabase(array $data): self {
		$this->setLocal($this->getBool('local', $data));
		$this->setUri($this->get('uri', $data));
		$this->setTitle($this->get('title', $data));
		$this->setVersion($this->get('version', $data));
		$this->setShortDescription($this->get('short_description', $data));
		$this->setDescription($this->get('description', $data));
		$this->setEmail($this->get('email', $data));
		$this->setUrls($this->getArray('urls', $data));
		$this->setStats($this->getArray('stats', $data));
		$this->setUsage($this->getArray('usage', $data));
		$this->setImage($this->get('image', $data));
		$this->setLanguages($this->getArray('languages', $data));
		$this->setAccountPrim($this->get('account_prim', $data));

		//		$contact = new Person();
		//		$this->setContactAccount($contact);

		return $this;
	}

	/**
	 * Mastodon's V1::Instance entity, the first request every client makes.
	 * `urls`, `stats` and `configuration` are cast to objects, since an empty
	 * PHP array encodes as `[]` and typed clients fail on it;
	 * `contact_account` is always present (null when unset) for the same
	 * reason.
	 */
	#[\Override]
	public function jsonSerialize(): array {
		$configuration = $this->getConfiguration();

		return [
			'uri' => $this->getUri(),
			'title' => $this->getTitle(),
			'version' => $this->getCompatVersion(),
			'short_description' => $this->getShortDescription(),
			'description' => $this->getDescription(),
			'email' => $this->getEmail(),
			'urls' => (object)$this->getUrls(),
			// Older Mastodon clients read the VAPID key from the v1 entity;
			// newer clients use configuration.vapid.public_key.
			'vapid_key' => (string)($configuration['vapid']['public_key'] ?? ''),
			'stats' => (object)$this->getStats(),
			'thumbnail' => ($this->getImage() === '') ? null : $this->getImage(),
			'languages' => $this->getLanguages(),
			'registrations' => $this->isRegistrations(),
			'approval_required' => $this->isApprovalRequired(),
			'invites_enabled' => $this->isInvitesEnabled(),
			'configuration' => (object)$configuration,
			'rules' => $this->getRules(),
			'contact_account' => $this->getContactAccount(),
		];
	}

	/**
	 * Mastodon's V2::Instance entity, served at /api/v2/instance. Same facts,
	 * re-shaped: v1's flat fields moved under `contact`, `thumbnail` became an
	 * object, and `uri` became `domain`.
	 */
	public function asV2(): array {
		$configuration = $this->getConfiguration();
		$stats = $this->getStats();

		return [
			'domain' => $this->getUri(),
			'title' => $this->getTitle(),
			'version' => $this->getCompatVersion(),
			// read instead of the version string by a client that knows about
			// it, and the string is the least reliable thing about a fork
			'api_versions' => (object)self::API_VERSIONS,
			'source_url' => 'https://github.com/alohasocial/social',
			'description' => ($this->getShortDescription() !== '')
				? $this->getShortDescription() : $this->getDescription(),
			'usage' => (object)[
				'users' => (object)['active_month' => (int)($stats['user_count'] ?? 0)],
			],
			// null rather than a `url` of "": Ivory and Mastodon for iOS
			// fetched the empty one as the instance root
			'thumbnail' => ($this->getImage() === '') ? null : (object)['url' => $this->getImage()],
			'languages' => $this->getLanguages(),
			'configuration' => (object)array_merge(
				$configuration,
				[
					// v2 names v1's `streaming_api` `streaming`
					'urls' => (object)$this->v2Urls(),
					// an object, as Mastodon's is; `configuration()` fills in
					// whether there is a provider
					'translation' => (object)($configuration['translation']
						?? ['enabled' => $this->isTranslationEnabled()]),
				]
			),
			'registrations' => (object)[
				'enabled' => $this->isRegistrations(),
				'approval_required' => $this->isApprovalRequired(),
				'message' => null,
				'url' => $this->getRegistrationsUrl(),
			],
			'contact' => (object)[
				'email' => $this->getEmail(),
				'account' => $this->getContactAccount(),
			],
			'rules' => $this->getRules(),
		];
	}
}
