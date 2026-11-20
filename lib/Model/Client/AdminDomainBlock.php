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
 * `FediverseService` keeps (not a user's own `/api/v1/domain_blocks`), with
 * its `social_server_block` row where there is one, else empty values:
 *
 *  - `severity` is `suspend` for the deny list (refused outright, so
 *    `reject_media` and `reject_reports` are `true`), `silence` otherwise.
 *  - `private_comment` and `public_comment` are the reasons, `null` if none.
 *  - `obfuscate` is always `false`.
 *  - `created_at` is the epoch when not recorded, as the field is a date.
 *
 * `id` is the first eight hex digits of the domain's md5 as a decimal string:
 * numeric and stable. Routes that take an id accept the domain too.
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
