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
use OCA\Social\Model\Moderation;

/**
 * Mastodon's `Admin::Account`: the ordinary `Account` entity plus what the
 * instance decided about it.
 *
 * Every documented key is emitted, since Codable-based clients (Ivory, Mona)
 * cannot decode a missing one; keys with nothing behind them carry their
 * type's empty value: `email`, `ip`, `ips`, `locale`, `invite_request` (the
 * Nextcloud user's email is not republished), `role` (`null`, no roles here),
 * `disabled`, `sensitized` (never true), and `created_by_application_id`,
 * `invited_by_account_id`. `confirmed` and `approved` are true for local
 * accounts and false for remote ones, as on Mastodon.
 *
 * `id` is the numeric id, except for an account whose cached actor a
 * suspension purged, where it is the ActivityPub id so `/unsuspend` can
 * still name it; routes accept both.
 */
class AdminAccount implements JsonSerializable {
	private string $id = '';
	private string $actorId = '';
	private string $username = '';
	private ?string $domain = null;
	private int $creation = 0;
	private bool $local = true;
	private string $level = '';
	private ?Person $account = null;

	/**
	 * The account as it is known here, under the decision that stands against
	 * it.
	 *
	 * @param string $level one of Moderation::LEVELS, or '' for none
	 */
	public static function fromPerson(Person $actor, string $level = ''): self {
		$actor->setExportFormat(ACore::FORMAT_LOCAL);

		$admin = new self();
		$admin->setActorId($actor->getId())
			->setId((string)($actor->getNid() > 0 ? $actor->getNid() : $actor->getId()))
			->setUsername($actor->getPreferredUsername())
			->setLocal($actor->isLocal())
			->setDomain($actor->isLocal() ? null : self::hostOf($actor->getAccount(), $actor->getId()))
			->setCreation($actor->getCreation())
			->setLevel($level)
			->setAccount($actor);

		return $admin;
	}

	/**
	 * An account known only by the decision against it, as a suspended account
	 * is once its cached actor is deleted. The `account` entity is built from
	 * what can be read off the id rather than sent as null, which Mastodon does
	 * not allow.
	 */
	public static function fromDecision(Moderation $decision, bool $local = false): self {
		$actorId = $decision->getActorId();
		$host = self::hostOf('', $actorId);

		$actor = new Person();
		$actor->setPreferredUsername(self::usernameOf($actorId))
			->setAccount(self::usernameOf($actorId) . ($host === null ? '' : '@' . $host))
			->setCreation($decision->getCreation());
		$actor->setId($actorId);
		$actor->setLocal($local);
		$actor->setExportFormat(ACore::FORMAT_LOCAL);

		$admin = new self();
		$admin->setActorId($actorId)
			->setId($actorId)
			->setUsername($actor->getPreferredUsername())
			->setLocal($local)
			->setDomain($local ? null : $host)
			->setCreation($decision->getCreation())
			->setLevel($decision->getLevel())
			->setAccount($actor);

		return $admin;
	}

	public function setId(string $id): self {
		$this->id = $id;

		return $this;
	}

	public function getId(): string {
		return $this->id;
	}

	public function setActorId(string $actorId): self {
		$this->actorId = $actorId;

		return $this;
	}

	public function getActorId(): string {
		return $this->actorId;
	}

	public function setUsername(string $username): self {
		$this->username = $username;

		return $this;
	}

	public function getUsername(): string {
		return $this->username;
	}

	public function setDomain(?string $domain): self {
		$this->domain = $domain;

		return $this;
	}

	public function getDomain(): ?string {
		return $this->domain;
	}

	public function setCreation(int $creation): self {
		$this->creation = $creation;

		return $this;
	}

	public function getCreation(): int {
		return $this->creation;
	}

	public function setLocal(bool $local): self {
		$this->local = $local;

		return $this;
	}

	public function isLocal(): bool {
		return $this->local;
	}

	public function setLevel(string $level): self {
		$this->level = $level;

		return $this;
	}

	public function getLevel(): string {
		return $this->level;
	}

	public function isSilenced(): bool {
		return $this->level === Moderation::SILENCE;
	}

	public function isSuspended(): bool {
		return $this->level === Moderation::SUSPEND;
	}

	public function setAccount(?Person $account): self {
		$this->account = $account;

		return $this;
	}

	public function getAccount(): ?Person {
		return $this->account;
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'username' => $this->getUsername(),
			'domain' => $this->getDomain(),
			'created_at' => gmdate('Y-m-d\TH:i:s', $this->getCreation()) . '.000Z',
			'email' => '',
			'ip' => null,
			'ips' => [],
			'locale' => '',
			'invite_request' => null,
			'role' => null,
			'confirmed' => $this->isLocal(),
			'approved' => $this->isLocal(),
			'disabled' => false,
			'silenced' => $this->isSilenced(),
			'suspended' => $this->isSuspended(),
			'sensitized' => false,
			'created_by_application_id' => null,
			'invited_by_account_id' => null,
			'account' => $this->getAccount(),
		];
	}

	/**
	 * The instance an account belongs to: the host of its handle, or of its
	 * actor id when there is no handle to read one from.
	 */
	private static function hostOf(string $account, string $actorId): ?string {
		$account = trim($account);
		$at = strrpos($account, '@');
		if ($at !== false && $at < strlen($account) - 1) {
			return strtolower(substr($account, $at + 1));
		}

		$host = strtolower((string)parse_url($actorId, PHP_URL_HOST));

		return ($host === '') ? null : $host;
	}

	/**
	 * The username of an actor known only by its id: the last segment of the
	 * path, which is where every implementation puts it.
	 */
	private static function usernameOf(string $actorId): string {
		$path = (string)parse_url($actorId, PHP_URL_PATH);
		$name = substr($path, (int)strrpos($path, '/') + 1);

		return ltrim($name, '@');
	}
}
