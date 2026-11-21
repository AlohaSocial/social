<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;

/**
 * A named handful of accounts worth following, as Mastodon's starter packs
 * are, for new accounts that `SuggestionService` has no friends of friends
 * for yet.
 *
 * A pack is a list of `user@host` handles, not rows of its own, since the
 * accounts are not this instance's. Packs read in from another server (a
 * fediverse collection or a Bluesky starter pack, `CollectionPackService`)
 * are named by actor ids, carry curator and source, and are kept in
 * `social_starter_pack`. Handles are resolved only when a pack is opened,
 * never for the index, which would wait on remote servers.
 */
class StarterPack implements JsonSerializable {
	/** Shipped with the app. */
	public const SOURCE_BUILTIN = 'builtin';
	/** Written by an administrator of this instance. */
	public const SOURCE_LOCAL = 'local';
	/** A collection another server publishes, read in (FEP-7aa9). */
	public const SOURCE_FEDIVERSE = 'fediverse';
	/** A starter pack Bluesky suggests, read in. */
	public const SOURCE_BLUESKY = 'bluesky';

	/** Who curated a pack read in, as `user@host`; '' for the others. */
	private string $curator = '';

	/** Where a pack read in can be seen on its own server; '' for the others. */
	private string $url = '';

	/** The hashtag a pack read in is about, without the `#`. */
	private string $topic = '';

	/** The language a pack read in is written in, where it says. */
	private string $language = '';

	/** @var Person[] filled in only when the pack is opened */
	private array $accounts = [];

	/** @var string[] the handles that could not be resolved */
	private array $unresolved = [];

	/** How many accounts the pack holds, where its handles are not loaded. */
	private ?int $size = null;

	/**
	 * @param string[] $handles `user@host`, without a leading `@`, or the
	 *                          actor ids of a pack read in
	 */
	public function __construct(
		private string $slug,
		private string $name,
		private string $description,
		private array $handles,
		private string $source = self::SOURCE_BUILTIN,
	) {
	}

	public function getSlug(): string {
		return $this->slug;
	}

	public function getName(): string {
		return $this->name;
	}

	public function getDescription(): string {
		return $this->description;
	}

	/** @return string[] */
	public function getHandles(): array {
		return $this->handles;
	}

	public function getSource(): string {
		return $this->source;
	}

	public function getCurator(): string {
		return $this->curator;
	}

	public function getUrl(): string {
		return $this->url;
	}

	public function getTopic(): string {
		return $this->topic;
	}

	public function getLanguage(): string {
		return $this->language;
	}

	/** Where a pack read in came from. */
	public function setOrigin(string $curator, string $url, string $topic, string $language): self {
		$this->curator = $curator;
		$this->url = $url;
		$this->topic = $topic;
		$this->language = $language;

		return $this;
	}

	/** How many accounts the pack holds: its handles, or the count it was listed with. */
	public function getSize(): int {
		return $this->size ?? count($this->handles);
	}

	/** For a pack listed without its handles. */
	public function setSize(int $size): self {
		$this->size = $size;

		return $this;
	}

	/** @return Person[] */
	public function getAccounts(): array {
		return $this->accounts;
	}

	/** @param Person[] $accounts */
	public function setAccounts(array $accounts): self {
		$this->accounts = $accounts;

		return $this;
	}

	/** @return string[] */
	public function getUnresolved(): array {
		return $this->unresolved;
	}

	/**
	 * The handles this instance could not reach, reported so a curator can tell
	 * an unreachable server or moved account from a badly written pack.
	 *
	 * @param string[] $unresolved
	 */
	public function setUnresolved(array $unresolved): self {
		$this->unresolved = $unresolved;

		return $this;
	}

	#[\Override]
	public function jsonSerialize(): array {
		// A `Person` serialises as an ActivityPub actor unless told otherwise, and
		// clients read packs as Mastodon `Account` entities (`acct`,
		// `display_name`, `avatar`).
		foreach ($this->accounts as $account) {
			$account->setExportFormat(ACore::FORMAT_LOCAL);
		}

		return [
			'id' => $this->getSlug(),
			'name' => $this->getName(),
			'description' => $this->getDescription(),
			'source' => $this->getSource(),
			'curator' => ($this->curator !== '') ? $this->curator : null,
			'url' => ($this->url !== '') ? $this->url : null,
			'topic' => ($this->topic !== '') ? $this->topic : null,
			'language' => ($this->language !== '') ? $this->language : null,
			'size' => $this->getSize(),
			'accounts' => $this->getAccounts(),
			'unresolved' => $this->getUnresolved(),
		];
	}
}
