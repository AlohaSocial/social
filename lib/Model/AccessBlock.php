<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model;

use JsonSerializable;
use OCA\Social\Tools\DbTime;

/**
 * A block on an address rather than an account:
 *
 * - `TYPE_IP` is Mastodon's `Admin::IpBlock`; only `no_access` means anything,
 *   as there is no sign-up flow here.
 * - `TYPE_EMAIL_DOMAIN` is Mastodon's `Admin::EmailDomainBlock`; it refuses a
 *   Nextcloud account a fediverse identity.
 */
class AccessBlock implements JsonSerializable {
	public const TYPE_IP = 'ip';
	public const TYPE_EMAIL_DOMAIN = 'email_domain';
	public const TYPES = [self::TYPE_IP, self::TYPE_EMAIL_DOMAIN];

	/** The whole of the address is refused: Mastodon's own wording. */
	public const SEVERITY_NO_ACCESS = 'no_access';

	/** Mastodon's other two, which police a sign-up this instance has not. */
	public const SEVERITY_SIGN_UP_BLOCK = 'sign_up_block';
	public const SEVERITY_SIGN_UP_REQUIRES_APPROVAL = 'sign_up_requires_approval';

	public function __construct(
		private string $type = self::TYPE_IP,
		private string $value = '',
		private string $severity = self::SEVERITY_NO_ACCESS,
		private string $comment = '',
		private int $expires = 0,
		private int $creation = 0,
		private int $id = 0,
	) {
	}

	public static function fromRow(array $row): self {
		return new self(
			(string)($row['type'] ?? self::TYPE_IP),
			(string)($row['value'] ?? ''),
			(string)($row['severity'] ?? self::SEVERITY_NO_ACCESS),
			(string)($row['comment'] ?? ''),
			DbTime::toTimestamp($row['expires'] ?? null),
			DbTime::toTimestamp($row['creation'] ?? null),
			(int)($row['id'] ?? 0),
		);
	}

	public function getId(): int {
		return $this->id;
	}

	public function getType(): string {
		return $this->type;
	}

	public function getValue(): string {
		return $this->value;
	}

	public function getSeverity(): string {
		return $this->severity;
	}

	public function getComment(): string {
		return $this->comment;
	}

	/** When it lifts itself, or 0 for never. */
	public function getExpires(): int {
		return $this->expires;
	}

	public function getCreation(): int {
		return $this->creation;
	}

	/** Whether it still stands at that moment. */
	public function isLiveAt(?int $now = null): bool {
		return $this->expires === 0 || $this->expires > ($now ?? time());
	}

	/**
	 * Mastodon's `Admin::IpBlock` or `Admin::EmailDomainBlock`, whichever this
	 * is. `history` is always empty, as there is no sign-up, but sent because
	 * some clients require it.
	 */
	#[\Override]
	public function jsonSerialize(): array {
		if ($this->type === self::TYPE_EMAIL_DOMAIN) {
			return [
				'id' => (string)$this->id,
				'domain' => $this->value,
				'created_at' => self::datetime($this->creation),
				'history' => [],
			];
		}

		return [
			'id' => (string)$this->id,
			'ip' => $this->value,
			'severity' => $this->severity,
			'comment' => $this->comment,
			'created_at' => self::datetime($this->creation),
			'expires_at' => $this->expires === 0 ? null : self::datetime($this->expires),
		];
	}

	private static function datetime(int $timestamp): string {
		return gmdate('Y-m-d\TH:i:s', $timestamp) . '.000Z';
	}
}
