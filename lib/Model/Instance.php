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
	 * The Mastodon version advertised to API clients, Pleroma-style: clients
	 * gate features on the version, so a plain app version would make them
	 * treat the API as ancient. Only the /api/v1/instance entity carries it —
	 * NodeInfo keeps reporting the real app version.
	 *
	 * The claim has to be one this app can honour, because a client believes
	 * it. It said `3.5.0` for a long time, and the reason was sound when it was
	 * written: `4.x` switches Tusky and Ivory onto a feature set this app did
	 * not have, and a feature a client offers and the server cannot do is a
	 * broken button rather than an absent one.
	 *
	 * That is no longer where the app is. Everything `4.x` turns on is here:
	 * editing with `GET /statuses/{id}/source` and `/history`, v2 filters
	 * applied server-side, `/api/v2/instance`, and
	 * `/api/v1/notifications/unread_count`. Claiming 3.5.0 was hiding all of
	 * them — a client that believes the string never asks.
	 *
	 * The features still missing are announced as missing rather than left to
	 * fail: `urls` is an empty object, which is how a client learns there is no
	 * streaming endpoint, and Web Push is absent, so a client that tries
	 * `/api/v1/push/subscription` gets a 404 and falls back to polling — what
	 * it does against any server without a VAPID key.
	 *
	 * **4.3 now, and it was 4.2 while the 4.3 surface was already being
	 * served**: grouped notifications (`/api/v2/notifications` and its four
	 * routes), the notification policy and its requests inbox, and a real
	 * translation through `OCP\TaskProcessing`. A client reads this string
	 * before it asks, so claiming 4.2 meant every one of those was built and
	 * never reached. Raising it is a promise about the whole 4.3 client
	 * surface, which is why it waited until that surface was there.
	 */
	public const COMPAT_VERSION = '4.3.0';

	/**
	 * The API generations this server implements, as Mastodon 4.3 reports them.
	 *
	 * A client that knows about `api_versions` reads it *instead of* parsing
	 * the version string, because a fork can implement Mastodon's API at a
	 * generation that has nothing to do with its own version number — which is
	 * exactly this app's situation. Absent, a 4.3 client falls back to the
	 * string, and a fork's string is the least reliable thing about it.
	 *
	 * `mastodon: 3` is what 4.3 reports. It is the API generation, not the
	 * release: 4.3 and 4.4 both say 3, and 4.5 says 4.
	 *
	 * `aloha_social` is this app's own generation, for what a client cannot
	 * learn from `mastodon` because older versions of this app reported the
	 * same number without it. It is raised when such a behaviour is added;
	 * a client gates on `>=`:
	 *
	 * - 1: `/api/v2/search` honours `account_id`, `offset`, `max_id` and
	 *   `min_id` for `statuses`.
	 */
	public const API_VERSIONS = ['mastodon' => 3, 'aloha_social' => 1];

	public function getCompatVersion(): string {
		return self::COMPAT_VERSION . ' (compatible; Aloha Social ' . $this->version . ')';
	}

	/**
	 * Whether a client should offer the translate button.
	 *
	 * True when this Nextcloud has a translation provider — see
	 * `TranslationService`. It was hard-coded `false` for as long as
	 * `POST /statuses/{id}/translate` returned the post unchanged, which was
	 * the honest answer then and is the wrong one now.
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
	 * Mastodon's V1::Instance entity — the very first request every client
	 * makes, and the one that decides whether it will talk to this server at
	 * all.
	 *
	 * `urls`, `stats` and `configuration` are cast to objects: an empty PHP
	 * array json-encodes as `[]`, and a client that decodes `stats.user_count`
	 * out of a dictionary — masto.js and every typed client do — fails on a
	 * list and reports the instance as unreachable. `contact_account` is
	 * always present (null when unset) for the same reason.
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'uri' => $this->getUri(),
			'title' => $this->getTitle(),
			'version' => $this->getCompatVersion(),
			'short_description' => $this->getShortDescription(),
			'description' => $this->getDescription(),
			'email' => $this->getEmail(),
			'urls' => (object)$this->getUrls(),
			'stats' => (object)$this->getStats(),
			'thumbnail' => ($this->getImage() === '') ? null : $this->getImage(),
			'languages' => $this->getLanguages(),
			'registrations' => $this->isRegistrations(),
			'approval_required' => $this->isApprovalRequired(),
			'invites_enabled' => $this->isInvitesEnabled(),
			'configuration' => (object)$this->getConfiguration(),
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
			'thumbnail' => (object)['url' => $this->getImage()],
			'languages' => $this->getLanguages(),
			'configuration' => (object)array_merge(
				$configuration,
				[
					'urls' => (object)$this->getUrls(),
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
			],
			'contact' => (object)[
				'email' => $this->getEmail(),
				'account' => $this->getContactAccount(),
			],
			'rules' => $this->getRules(),
		];
	}
}
