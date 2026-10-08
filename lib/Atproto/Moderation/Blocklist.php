<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Moderation;

use InvalidArgumentException;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Reader\ActorMapper;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Db\AtprotoBlocklistRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;

/**
 * The administrator's Bluesky block list (§12.4), as the reading side asks
 * it: whether a DID is blocked, or a PDS host — and with it every account
 * it hosts. A blocked account is not resolved, its posts are not read and
 * its interactions are dropped on arrival. Changing the list, and purging
 * what it newly blocks, is `BlocklistManager`'s. Never published: a block
 * here is this instance's business.
 */
class Blocklist {
	/** @var array<string, array<string, true>>|null kind → value → true */
	private ?array $memo = null;

	public function __construct(
		private AtprotoBlocklistRequest $request,
	) {
	}

	public function isBlockedDid(string $did): bool {
		return isset($this->all()[AtprotoBlocklistRequest::KIND_DID][strtolower($did)]);
	}

	/**
	 * Whether a host is blocked, or a domain it is under.
	 */
	public function isBlockedHost(string $host): bool {
		$host = strtolower(trim($host, '.'));
		$hosts = $this->all()[AtprotoBlocklistRequest::KIND_HOST] ?? [];
		while ($host !== '') {
			if (isset($hosts[$host])) {
				return true;
			}
			$dot = strpos($host, '.');
			$host = $dot === false ? '' : substr($host, $dot + 1);
		}

		return false;
	}

	/**
	 * Whether a Bluesky account is blocked, by its DID or its PDS's host.
	 */
	public function isBlockedAccount(string $did, string $pds = ''): bool {
		if ($this->isBlockedDid($did)) {
			return true;
		}
		$host = (string)parse_url($pds, PHP_URL_HOST);

		return $host !== '' && $this->isBlockedHost($host);
	}

	public function isBlockedActor(Person $actor): bool {
		$did = BlueskyIds::didOf($actor->getId());
		if ($did === '') {
			return false;
		}

		return $this->isBlockedAccount($did, (string)($actor->getDetails(ActorMapper::DETAIL)['pds'] ?? ''));
	}

	/**
	 * @return list<array{kind: string, value: string, reason: string, creation: int}>
	 */
	public function list(): array {
		return $this->request->getAll();
	}

	/**
	 * @return array{0: string, 1: string} the kind and the normalised value
	 * @throws InvalidArgumentException
	 */
	public static function classify(string $value): array {
		$value = strtolower(trim($value));
		if (Syntax::isDid($value)) {
			return [AtprotoBlocklistRequest::KIND_DID, $value];
		}
		$host = (string)parse_url(str_contains($value, '://') ? $value : 'https://' . $value, PHP_URL_HOST);
		if ($host !== '' && Syntax::isHandle($host)) {
			return [AtprotoBlocklistRequest::KIND_HOST, $host];
		}

		throw new InvalidArgumentException('not a DID or a host: ' . $value);
	}

	/** Forgets what was read, after the list changed. */
	public function reset(): void {
		$this->memo = null;
	}

	/**
	 * @return array<string, array<string, true>>
	 */
	private function all(): array {
		if ($this->memo === null) {
			$this->memo = [];
			foreach ($this->request->getAll() as $row) {
				$this->memo[$row['kind']][$row['value']] = true;
			}
		}

		return $this->memo;
	}
}
