<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;
use OCA\Social\Tools\Nid;

/**
 * One keyword filter of one account, as Mastodon's v2 `Filter` entity; the
 * matching is `FilterService`'s. `expiresAt` 0 means never: reads go through
 * `isActive()`, not the number.
 */
class Filter implements JsonSerializable {
	/** The home timeline and any list. */
	public const CONTEXT_HOME = 'home';
	public const CONTEXT_NOTIFICATIONS = 'notifications';
	/** The public and hashtag timelines. */
	public const CONTEXT_PUBLIC = 'public';
	/** A status shown inside a conversation. */
	public const CONTEXT_THREAD = 'thread';
	/** A profile's own statuses. */
	public const CONTEXT_ACCOUNT = 'account';

	/** @var string[] in the order Mastodon documents them */
	public const CONTEXTS = [
		self::CONTEXT_HOME,
		self::CONTEXT_NOTIFICATIONS,
		self::CONTEXT_PUBLIC,
		self::CONTEXT_THREAD,
		self::CONTEXT_ACCOUNT,
	];

	/** The status is sent with `filtered`, and the client blurs it. */
	public const ACTION_WARN = 'warn';
	/** The status is not sent at all. */
	public const ACTION_HIDE = 'hide';

	/** @var string[] */
	public const ACTIONS = [self::ACTION_WARN, self::ACTION_HIDE];

	/** The width of `social_filter.title`. */
	public const MAX_TITLE = 255;

	/**
	 * The width of `social_filter.contexts`, which holds the comma-joined list,
	 * checked so a longer list cannot be cut silently.
	 */
	public const MAX_CONTEXTS = 255;

	private int $id = 0;
	private string $actorId = '';
	private string $title = '';
	/** @var string[] */
	private array $contexts = [];
	private string $action = self::ACTION_WARN;
	/** Unix time; 0 is "never expires". */
	private int $expiresAt = 0;
	private int $creation = 0;
	/** @var FilterKeyword[] */
	private array $keywords = [];
	/** @var FilterStatus[] the posts this filter covers by name */
	private array $statuses = [];

	public function setId(int $id): self {
		$this->id = $id;

		return $this;
	}

	public function getId(): int {
		return $this->id;
	}

	public function setActorId(string $actorId): self {
		$this->actorId = $actorId;

		return $this;
	}

	public function getActorId(): string {
		return $this->actorId;
	}

	public function setTitle(string $title): self {
		$this->title = $title;

		return $this;
	}

	public function getTitle(): string {
		return $this->title;
	}

	/**
	 * @param string[] $contexts
	 */
	public function setContexts(array $contexts): self {
		$this->contexts = $contexts;

		return $this;
	}

	/**
	 * @return string[]
	 */
	public function getContexts(): array {
		return $this->contexts;
	}

	public function setAction(string $action): self {
		$this->action = $action;

		return $this;
	}

	public function getAction(): string {
		return $this->action;
	}

	public function isHiding(): bool {
		return $this->action === self::ACTION_HIDE;
	}

	public function setExpiresAt(int $expiresAt): self {
		$this->expiresAt = $expiresAt;

		return $this;
	}

	public function getExpiresAt(): int {
		return $this->expiresAt;
	}

	public function setCreation(int $creation): self {
		$this->creation = $creation;

		return $this;
	}

	public function getCreation(): int {
		return $this->creation;
	}

	/**
	 * @param FilterKeyword[] $keywords
	 */
	public function setKeywords(array $keywords): self {
		$this->keywords = array_values($keywords);

		return $this;
	}

	public function addKeyword(FilterKeyword $keyword): self {
		$this->keywords[] = $keyword;

		return $this;
	}

	/**
	 * @return FilterKeyword[]
	 */
	public function getKeywords(): array {
		return $this->keywords;
	}

	/**
	 * @param FilterStatus[] $statuses
	 */
	public function setStatuses(array $statuses): self {
		$this->statuses = array_values($statuses);

		return $this;
	}

	public function addStatus(FilterStatus $status): self {
		$this->statuses[] = $status;

		return $this;
	}

	/**
	 * @return FilterStatus[]
	 */
	public function getStatuses(): array {
		return $this->statuses;
	}

	/**
	 * Whether this filter covers that post, by the `nid` the client API hands out
	 * (see the `social_filter_st` migration).
	 */
	public function covers(int|string $statusId): bool {
		if (!ctype_digit((string)$statusId) || Nid::compare($statusId, '0') < 1) {
			return false;
		}

		$statusId = Nid::normalize($statusId);
		foreach ($this->statuses as $status) {
			if ($status->getStatusId() === $statusId) {
				return true;
			}
		}

		return false;
	}

	/**
	 * An expired filter stops applying by itself, with no cleanup job to run.
	 */
	public function isActive(?int $now = null): bool {
		return $this->expiresAt === 0 || $this->expiresAt > ($now ?? time());
	}

	public function appliesTo(string $context): bool {
		return in_array($context, $this->contexts, true);
	}

	/**
	 * The contexts of a filter as stored and compared: Mastodon's names, once
	 * each, in its order. Anything else is dropped, since nothing would read it.
	 *
	 * @param array<mixed> $raw
	 *
	 * @return string[]
	 */
	public static function normaliseContexts(array $raw): array {
		$named = [];
		foreach ($raw as $context) {
			if (!is_string($context)) {
				continue;
			}

			$named[] = strtolower(trim($context));
		}

		return array_values(array_intersect(self::CONTEXTS, $named));
	}

	/**
	 * The action a client asked for, or '' for something that is not one, which
	 * the caller answers with 422.
	 */
	public static function normaliseAction(string $raw): string {
		$action = strtolower(trim($raw));

		return in_array($action, self::ACTIONS, true) ? $action : '';
	}

	/**
	 * The title as stored: trimmed, and cut to the column's width in characters,
	 * not bytes.
	 */
	public static function normaliseTitle(string $raw): string {
		return mb_substr(trim($raw), 0, self::MAX_TITLE, 'UTF-8');
	}

	/**
	 * The stored form of the context list, and back: comma-joined, since the
	 * names carry no comma and SQL never searches it.
	 */
	public function exportContexts(): string {
		return implode(',', $this->contexts);
	}

	public function importContexts(string $stored): self {
		return $this->setContexts(self::normaliseContexts(explode(',', $stored)));
	}

	/**
	 * The filter as it appears inside a status' `filtered` key, without the
	 * keywords, as on Mastodon.
	 */
	public function exportAsResultFilter(): array {
		return [
			'id' => (string)$this->id,
			'title' => $this->title,
			'context' => $this->contexts,
			'expires_at' => $this->exportExpiresAt(),
			'filter_action' => $this->action,
		];
	}

	#[\Override]
	public function jsonSerialize(): array {
		$keywords = [];
		foreach ($this->keywords as $keyword) {
			$keywords[] = $keyword->jsonSerialize();
		}

		$statuses = [];
		foreach ($this->statuses as $status) {
			$statuses[] = $status->jsonSerialize();
		}

		return array_merge(
			$this->exportAsResultFilter(),
			[
				'keywords' => $keywords,
				'statuses' => $statuses,
			]
		);
	}

	/** Null rather than a date, for a filter that never expires. */
	private function exportExpiresAt(): ?string {
		if ($this->expiresAt === 0) {
			return null;
		}

		return gmdate('Y-m-d\TH:i:s', $this->expiresAt) . '.000Z';
	}
}
