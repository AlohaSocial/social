<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;

/**
 * Mastodon's `Admin::DomainBlock`: one entry of the instance-wide access list
 * that `FediverseService` keeps — not the per-account domain blocks of
 * `/api/v1/domain_blocks`, which are one user hiding a server from themselves.
 *
 * The two lists store a hostname each; what else is known about an entry is
 * a row of `social_server_block` (`ServerBlockRequest`), and an entry without
 * one carries the empty value of each field rather than an invented one:
 *
 *  - `severity` is `suspend` for the deny list, which refuses the domain
 *    outright — `FediverseService::authorized()` throws before anything is
 *    read or delivered — so `reject_media` and `reject_reports` are `true`;
 *    and `silence` for the silenced list, which refuses nothing.
 *  - `private_comment` is the reason the moderators keep for themselves,
 *    `public_comment` the one `/api/v1/instance/domain_blocks` shows; `null`
 *    when none was given.
 *  - `obfuscate` is `false`: the published list names domains as they are.
 *  - `created_at` is when the row was recorded, or the epoch for an entry
 *    added without one (by `occ`, or before reasons were stored) — the epoch
 *    is how this API says "not recorded" in a field Mastodon declares as a
 *    date and clients decode as one.
 *
 * `id` is derived from the domain rather than stored: the first eight hex
 * digits of its md5, as a decimal string, so it is numeric the way every other
 * id on this API is, stable for as long as the entry names the same domain,
 * and unchanged by other entries being added or removed — which a position in
 * the list would not be. Routes that take an id accept the domain itself too.
 */
class AdminDomainBlock implements JsonSerializable {
	/** The tier an entry on the deny list is: nothing from the domain gets in. */
	public const SEVERITY = 'suspend';

	/** The middle tier: the domain's accounts stay readable to their followers but leave the public timelines. */
	public const SEVERITY_SILENCE = 'silence';

	public const SEVERITIES = [self::SEVERITY, self::SEVERITY_SILENCE];

	/** The longest reason, in characters, either comment may hold. */
	public const MAX_COMMENT = 1000;

	public function __construct(
		private string $domain = '',
		private string $severity = self::SEVERITY,
		private ?string $privateComment = null,
		private ?string $publicComment = null,
		private int $created = 0,
	) {
	}

	public function getPrivateComment(): ?string {
		return $this->privateComment;
	}

	public function getPublicComment(): ?string {
		return $this->publicComment;
	}

	/** When the entry was recorded, in unix time; 0 when it was not. */
	public function getCreated(): int {
		return $this->created;
	}

	public function getDomain(): string {
		return $this->domain;
	}

	public function getSeverity(): string {
		return $this->severity;
	}

	public function isSilence(): bool {
		return $this->severity === self::SEVERITY_SILENCE;
	}

	public function getId(): string {
		return self::idOf($this->domain);
	}

	/** Whether an id or a domain names this entry. */
	public function isNamedBy(string $reference): bool {
		$reference = strtolower(trim($reference));

		return ($reference !== '')
			&& (($reference === strtolower($this->domain)) || ($reference === $this->getId()));
	}

	public static function idOf(string $domain): string {
		return (string)hexdec(substr(md5(strtolower(trim($domain))), 0, 8));
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'domain' => $this->domain,
			'created_at' => gmdate('Y-m-d\TH:i:s', $this->created) . '.000Z',
			'severity' => $this->severity,
			// a suspension refuses everything from the domain, media and
			// reports included; a silence refuses nothing, it only hides
			'reject_media' => !$this->isSilence(),
			'reject_reports' => !$this->isSilence(),
			'private_comment' => $this->privateComment,
			'public_comment' => $this->publicComment,
			'obfuscate' => false,
		];
	}

	/**
	 * The entry as `/api/v1/instance/domain_blocks` publishes it: Mastodon's
	 * `DomainBlock`, with the public reason and never the private one.
	 *
	 * @return array{domain: string, digest: string, severity: string, comment: string}
	 */
	public function published(): array {
		return [
			'domain' => $this->domain,
			'digest' => hash('sha256', $this->domain),
			'severity' => $this->severity,
			'comment' => $this->publicComment ?? '',
		];
	}
}
