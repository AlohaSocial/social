<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;

/**
 * One hashtag as several servers report it, unlike a Mastodon `Tag`, which
 * is one server's view. Ranked by how many servers named it; summed `uses`
 * only break ties, as instance sizes differ by orders of magnitude.
 */
class PeerTag implements JsonSerializable {
	/** Lower-cased, without the leading '#': the key a tag is merged on. */
	private string $name;

	/** host => the label to show for it, in the order the servers were asked. */
	private array $servers = [];

	/** What those servers said, added up. A tie-breaker, not a ranking. */
	private int $uses = 0;

	/** Whether this instance has seen the tag as well. */
	private bool $local = false;

	public function __construct(string $name) {
		$this->name = $name;
	}

	public function getName(): string {
		return $this->name;
	}

	/**
	 * Records that a server reports this tag; a server counts once however
	 * often it names it.
	 */
	public function reportedBy(string $host, string $label, int $uses, bool $local = false): self {
		if (!array_key_exists($host, $this->servers)) {
			$this->servers[$host] = $label === '' ? $host : $label;
			$this->uses += max(0, $uses);
		}

		$this->local = $this->local || $local;

		return $this;
	}

	/** @return string[] the labels, in the order the servers were asked */
	public function getServers(): array {
		return array_values($this->servers);
	}

	/** How many servers named it: the number this list is ranked by. */
	public function countServers(): int {
		return count($this->servers);
	}

	public function getUses(): int {
		return $this->uses;
	}

	public function isLocal(): bool {
		return $this->local;
	}

	/**
	 * Whether any server other than this one named it; tags only this
	 * instance saw are in its own trending list.
	 */
	public function isRemote(): bool {
		return $this->countServers() > ($this->local ? 1 : 0);
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'name' => $this->name,
			'servers' => $this->getServers(),
			'servers_count' => $this->countServers(),
			'uses' => $this->uses,
			'local' => $this->local,
		];
	}
}
