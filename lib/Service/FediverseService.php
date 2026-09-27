<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\Cron\DomainPurge;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCP\BackgroundJob\IJobList;

/**
 * Class FediverseService
 *
 * @package OCA\Social\Service
 */
class FediverseService {
	public function __construct(
		private ConfigService $configService,
		private MiscService $miscService,
		private CacheActorsRequest $cacheActorsRequest,
		private IJobList $jobList,
		private AuditService $auditService,
	) {
	}

	/**
	 * @param string $address
	 *
	 * @return bool
	 * @throws UnauthorizedFediverseException
	 * @throws SocialAppConfigException
	 */
	/**
	 * Whether this instance may talk to an address.
	 *
	 * `$onBehalfOf` is the instance that *named* the address, where the two
	 * differ: a picture on a post is fetched from whatever host the post says
	 * holds it, and that is routinely not the host the post came from —
	 * Mastodon serves its media from a sibling host (`6-28.mastodon.xyz` for a
	 * post on `mastodon.xyz`), and object storage or a CDN is common besides.
	 *
	 * That distinction only matters to the allow list, which matches exactly.
	 * An admin allows the instances they mean to federate with, by the names
	 * those instances are known by; nobody lists the buckets their pictures sit
	 * in, and there is no way for them to know what those are. Judging the
	 * media host against that list refused every picture from every allowed
	 * instance, so an allow-listed instance federated text and nothing else.
	 * What the admin decided is that *this instance* may be talked to, and its
	 * pictures are part of what it publishes.
	 *
	 * The block list is unchanged and still judges both: it matches subdomains
	 * already (`isListed()`), so a blocked instance's media host is blocked
	 * with it, and naming a third party's address does not launder it either.
	 */
	public function authorized(string $address, string $onBehalfOf = ''): bool {
		if ($address === '') {
			throw new UnauthorizedFediverseException('Empty Origin');
		}

		if ($this->getAccessType()
			=== $this->configService->accessTypeList['BLACKLIST']
			&& !$this->isListed($address)
			&& ($onBehalfOf === '' || !$this->isListed($onBehalfOf))) {
			return true;
		}

		if ($this->getAccessType() === $this->configService->accessTypeList['WHITELIST']) {
			// an allow list widens no further than what the admin wrote: a
			// subdomain of an allowed domain is a different instance, and
			// whoever runs the parent domain is not asked before one appears.
			// The one thing it does cover is what an allowed instance holds
			// elsewhere, which is what `$onBehalfOf` names.
			$judged = ($onBehalfOf !== '') ? $onBehalfOf : $address;
			if ($this->isExactlyListed($judged) || $this->isLocal($judged)) {
				return true;
			}
		}

		throw new UnauthorizedFediverseException('Unauthorized Fediverse');
	}

	/**
	 * @throws UnauthorizedFediverseException
	 */
	public function jailed() {
		if ($this->getAccessType() !== $this->configService->accessTypeList['WHITELIST']
			|| !empty($this->getListedAddresses())) {
			return;
		}

		throw new UnauthorizedFediverseException('Jailed Fediverse');
	}

	/**
	 * @return string
	 */
	public function getAccessType(): string {
		return $this->configService->getAppValue(ConfigService::SOCIAL_ACCESS_TYPE);
	}

	/**
	 * @param string $type
	 *
	 * @throws Exception
	 */
	public function setAccessType(string $type) {
		$accepted = array_values($this->configService->accessTypeList);
		if (!in_array($type, $accepted, true)) {
			throw new Exception('invalid type: ' . json_encode($accepted));
		}

		$this->configService->setAppValue(ConfigService::SOCIAL_ACCESS_TYPE, $type);
	}

	/**
	 * @param string $address
	 *
	 * @return bool
	 * @throws SocialAppConfigException
	 */
	public function isLocal(string $address): bool {
		$local = $this->configService->getCloudHost();

		return ($local === $address);
	}

	/**
	 * Every instance this one has met: the hosts of the remote actors it has
	 * cached. `occ social:fediverse list` prints them above the access list, so
	 * an admin adding an entry can read the name off this instance rather than
	 * guess at it — an empty section was worse than no section.
	 *
	 * Taken from the cached shared inboxes, which is one row per instance
	 * rather than one per account. An actor whose server publishes no shared
	 * inbox is missing from it; every implementation that federates at any
	 * volume publishes one.
	 *
	 * @return string[] hostnames, lowercased, in alphabetical order
	 */
	public function getKnownAddresses(): array {
		$hosts = [];
		foreach ($this->cacheActorsRequest->getSharedInboxes() as $inbox) {
			$host = strtolower((string)parse_url((string)$inbox, PHP_URL_HOST));
			if ($host !== '') {
				$hosts[$host] = true;
			}
		}

		$hosts = array_keys($hosts);
		sort($hosts);

		return $hosts;
	}

	/**
	 * @return array
	 */
	public function getListedAddresses(): array {
		$list = json_decode($this->configService->getAppValue(ConfigService::SOCIAL_ACCESS_LIST));

		return is_array($list) ? array_values($list) : [];
	}

	/**
	 * Whether an address is covered by the instance access list.
	 *
	 * A listed domain covers the domain itself and everything under it, the way
	 * every other Fediverse implementation reads a domain block: matching the
	 * exact string only meant that blocking `evil.test` still let
	 * `www.evil.test` and `a.evil.test` straight back in, so a suspension
	 * lasted as long as it took to point another wildcard record at the same
	 * host. A trailing dot (the absolute form of the same name) is the same
	 * name, and case never matters in a hostname.
	 */
	public function isListed(string $address): bool {
		$host = $this->normalizeAddress($address);
		if ($host === '') {
			return false;
		}

		foreach ($this->getListedAddresses() as $listed) {
			$listed = $this->normalizeAddress((string)$listed);
			if ($listed === '') {
				continue;
			}

			if ($host === $listed || str_ends_with($host, '.' . $listed)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The instances whose accounts are silenced: kept out of the public and
	 * global timelines, still readable by the people who follow them.
	 *
	 * Mastodon's middle tier. Without it the only answer to an instance that
	 * is a nuisance rather than a menace was to block it outright, which also
	 * cuts off the local users who deliberately follow somebody there — so the
	 * tool was too blunt to use and the nuisance stayed.
	 *
	 * @return string[]
	 */
	public function getSilencedAddresses(): array {
		$list = json_decode(
			(string)$this->configService->getAppValue(ConfigService::SOCIAL_SILENCED_LIST), true
		);

		return is_array($list) ? array_values(array_map('strval', $list)) : [];
	}

	/** Whether an address is silenced, subdomains included as a block is. */
	public function isSilenced(string $address): bool {
		return self::covers($this->silencedHosts(), $address);
	}

	/**
	 * The silenced instances, normalised, as a set to look hosts up in.
	 *
	 * @return array<string, true>
	 */
	public function silencedHosts(): array {
		return self::hostSet($this->getSilencedAddresses());
	}

	/**
	 * The access list's own entries, normalised, as a set — what
	 * `isExactlyListed()` asks about, for a caller with many hosts to ask.
	 *
	 * @return array<string, true>
	 */
	public function exactlyListedHosts(): array {
		return self::hostSet(array_map('strval', $this->getListedAddresses()));
	}

	/**
	 * Whether a set of hosts covers an address: the address itself or any
	 * domain it sits under, the way a block or a silence is read.
	 *
	 * @param array<string, true> $hosts as silencedHosts() returns
	 */
	public static function covers(array $hosts, string $address): bool {
		$host = self::normalizeHost($address);
		while ($host !== '') {
			if (isset($hosts[$host])) {
				return true;
			}
			$dot = strpos($host, '.');
			$host = ($dot === false) ? '' : substr($host, $dot + 1);
		}

		return false;
	}

	/** Silences an instance. Silencing one already silenced is a no-op. */
	public function silenceAddress(string $address): void {
		$this->silenceAddresses([$address]);
	}

	/**
	 * Silences a batch of instances with one read and one write of the list.
	 *
	 * A published block list is a couple of thousand domains; silencing them
	 * one call at a time decoded and rewrote the whole list per domain, which
	 * is quadratic in the list and a settings write per entry.
	 *
	 * @param string[] $addresses
	 * @return int how many were not silenced already
	 */
	public function silenceAddresses(array $addresses): int {
		$list = $this->getSilencedAddresses();
		$known = self::hostSet($list);

		$added = 0;
		foreach ($addresses as $address) {
			$host = self::normalizeHost($address);
			if ($host === '' || self::covers($known, $host)) {
				continue;
			}

			$known[$host] = true;
			$list[] = $host;
			$added++;
		}

		if ($added > 0) {
			$this->configService->setAppValue(
				ConfigService::SOCIAL_SILENCED_LIST, (string)json_encode(array_values($list))
			);
		}

		return $added;
	}

	/**
	 * @param string[] $addresses
	 * @return array<string, true>
	 */
	private static function hostSet(array $addresses): array {
		$set = [];
		foreach ($addresses as $address) {
			$host = self::normalizeHost($address);
			if ($host !== '') {
				$set[$host] = true;
			}
		}

		return $set;
	}

	/**
	 * Lifts a silence. Nothing was deleted by it, so everything comes back —
	 * which is the difference between this and a block, whose purge does not.
	 */
	public function unsilenceAddress(string $address): void {
		$host = $this->normalizeAddress($address);
		$list = array_values(array_filter(
			$this->getSilencedAddresses(),
			fn (string $silenced): bool => $this->normalizeAddress($silenced) !== $host
		));

		$this->configService->setAppValue(
			ConfigService::SOCIAL_SILENCED_LIST, (string)json_encode($list)
		);
	}

	/**
	 * Whether the access list carries this exact address — no subdomains.
	 *
	 * What an allow list permits, and what `addAddress()` treats as already
	 * known so an admin can still remove what they added.
	 */
	public function isExactlyListed(string $address): bool {
		$host = $this->normalizeAddress($address);
		if ($host === '') {
			return false;
		}

		foreach ($this->getListedAddresses() as $listed) {
			if ($this->normalizeAddress((string)$listed) === $host) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A hostname in the one form comparisons can be made in: lowercase, without
	 * the trailing dot of the absolute form, and without surrounding space.
	 */
	private function normalizeAddress(string $address): string {
		return self::normalizeHost($address);
	}

	public static function normalizeHost(string $address): string {
		return rtrim(strtolower(trim($address)), '.');
	}

	/**
	 *
	 */
	public function resetAddresses() {
		$this->configService->setAppValue(ConfigService::SOCIAL_ACCESS_LIST, '[]');
	}

	/**
	 * @param string $address
	 */
	public function addAddress(string $address) {
		$this->addAddresses([$address]);
	}

	/**
	 * Adds a batch of addresses with one settings write. The set is validated
	 * by its caller before it reaches this method; this method retains the same
	 * audit and purge behavior as individual additions.
	 *
	 * @param string[] $addresses
	 * @return int number of new exact addresses
	 */
	public function addAddresses(array $addresses): int {
		$list = $this->getListedAddresses();
		$known = [];
		foreach ($list as $listed) {
			$known[$this->normalizeAddress((string)$listed)] = true;
		}

		$added = [];
		foreach ($addresses as $address) {
			$address = $this->normalizeAddress($address);
			if ($address === '' || isset($known[$address])) {
				continue;
			}

			$known[$address] = true;
			$list[] = $address;
			$added[] = $address;
		}

		if ($added === []) {
			return 0;
		}

		$this->configService->setAppValue(
			ConfigService::SOCIAL_ACCESS_LIST,
			(string)json_encode(array_values($list))
		);

		foreach ($added as $address) {
			// Keep individual audit records and purge jobs: importing a list must
			// have the same moderation and cleanup effect as adding each domain.
			$this->auditService->accessListChanged($address, true, $this->isBlockList());
			$this->purgeBlocked($address);
		}

		return count($added);
	}

	/**
	 * @param string $address
	 *
	 * @return void
	 * @throws Exception
	 */
	/**
	 * Hands a newly blocked instance to the purge job.
	 *
	 * Here rather than in the admin API, so that `occ social:fediverse add`
	 * and `POST /api/v1/admin/domain_blocks` behave the same way — a block
	 * that only purges when it was made through one of the two would be worse
	 * than one that never purges, because nobody could tell which they got.
	 *
	 * Only in block-list mode: the same app value holds the allow list, where
	 * an entry is an instance this server is choosing to *talk to*, and
	 * deleting everything it ever sent us would be the exact opposite of what
	 * the admin asked for.
	 *
	 * Queued, not run: blocking a domain is one admin request and the purge is
	 * however much that instance sent over its lifetime. The block itself is
	 * already in effect — nothing new arrives while the job works through what
	 * is left.
	 */
	private function purgeBlocked(string $address): void {
		if (!$this->isBlockList()) {
			return;
		}

		try {
			$this->jobList->add(DomainPurge::class, ['domain' => $address]);
		} catch (Exception $e) {
			// a block that could not queue its purge is still a block
			$this->miscService->log('could not queue the purge of ' . $address . ': ' . $e->getMessage(), 1);
		}
	}

	public function removeAddress(string $address) {
		$listed = $this->getListedAddresses();
		$list = array_values(array_udiff($listed, [$address], 'strcasecmp'));
		$this->configService->setAppValue(ConfigService::SOCIAL_ACCESS_LIST, json_encode($list));

		if (count($list) !== count($listed)) {
			$this->auditService->accessListChanged($address, false, $this->isBlockList());
		}
	}

	/**
	 * Whether the one access list names the instances this server refuses, as
	 * opposed to the only ones it talks to.
	 */
	private function isBlockList(): bool {
		return $this->getAccessType() === $this->configService->accessTypeList['BLACKLIST'];
	}
}
