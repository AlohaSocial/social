<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;

/**
 * One directory this instance will ask about people: a host, and which API it
 * speaks. The kind is configured, not sniffed, as that would cost a request
 * for an answer that hardly ever changes.
 */
class DirectorySource implements JsonSerializable {
	/** This instance's own directory. Never asked over the network. */
	public const KIND_LOCAL = 'local';

	/**
	 * Mastodon's own API, which is what Pixelfed, Hometown, Glitch and most
	 * forks serve too: a public profile directory, and a public exact-handle
	 * lookup.
	 */
	public const KIND_MASTODON = 'mastodon';

	/** Misskey and its forks: `users/search`, a real substring search. */
	public const KIND_MISSKEY = 'misskey';

	/** Lemmy: `search` with `type_=Users`. */
	public const KIND_LEMMY = 'lemmy';

	/**
	 * A hand-kept directory published as a WordPress site and asked through its
	 * REST API, such as the shipped `fedi.directory`. The only kind that
	 * searches by subject; its entries name accounts anywhere, so the handle
	 * comes out of the entry.
	 */
	public const KIND_WORDPRESS = 'wordpress';
	public const KIND_BLUESKY = 'bluesky';

	public const KINDS = [
		self::KIND_LOCAL,
		self::KIND_MASTODON,
		self::KIND_MISSKEY,
		self::KIND_LEMMY,
		self::KIND_WORDPRESS,
		self::KIND_BLUESKY,
	];

	/**
	 * How this source came to be asked, for a reader who wants to know why a
	 * stranger's server is in the list.
	 */
	public const ORIGIN_CONFIGURED = 'configured';
	/** Derived from the servers this instance actually federates with. */
	public const ORIGIN_FEDERATION = 'federation';
	/** Named by a directory of servers this instance asked. */
	public const ORIGIN_DISCOVERED = 'discovered';
	/** A search provider the curators of the Aloha Directory accepted (`search.json`). */
	public const ORIGIN_CURATED = 'curated';

	public function __construct(
		private string $host,
		private string $kind,
		private string $label = '',
		private string $origin = self::ORIGIN_CONFIGURED,
	) {
	}

	public function getHost(): string {
		return $this->host;
	}

	public function getKind(): string {
		return $this->kind;
	}

	/** What a reader is offered it as; the host when nobody named it. */
	public function getLabel(): string {
		return ($this->label === '') ? $this->host : $this->label;
	}

	public function getOrigin(): string {
		return $this->origin;
	}

	public function isLocal(): bool {
		return $this->kind === self::KIND_LOCAL;
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'host' => $this->host,
			'kind' => $this->kind,
			'label' => $this->getLabel(),
			'origin' => $this->origin,
		];
	}
}
