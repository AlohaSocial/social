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
use OCA\Social\Db\ServerBlockRequest;
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
		private ServerBlockRequest $serverBlockRequest,
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
	 * `$onBehalfOf` is the instance that named the address, e.g. a post whose
	 * media is on a sibling host or CDN (`6-28.mastodon.xyz` for
	 * `mastodon.xyz`). The allow list judges that instance, since nobody can list
	 * every media bucket; the block list judges both, and covers subdomains
	 * (`isListed()`).
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
			// The allow list matches exactly: a subdomain is a different instance.
			// `$onBehalfOf` covers what an allowed instance holds elsewhere.
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
	 * Every instance this one has met, from the cached shared inboxes (one row
	 * per instance), so `occ social:fediverse list` can show names to put on the
	 * access list. Servers publishing no shared inbox are missing.
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
	 * Whether an address is covered by the instance access list: a listed
	 * domain covers itself and every subdomain, as elsewhere in the Fediverse.
	 * A trailing dot and case are ignored.
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
	 * The instances this one refuses, normalised, as a set: the access list
	 * when it is a block list, nothing when it names the only instances this
	 * one talks to.
	 *
	 * @return array<string, true>
	 */
	public function blockedHosts(): array {
		return $this->isBlockList() ? $this->exactlyListedHosts() : [];
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
	 * Silences a batch of instances with one read and one write of the list,
	 * for published block lists of thousands of domains.
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
	 * Lifts a silence. Nothing was deleted by it, so everything comes back,
	 * unlike after a block.
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
		$this->forgetUnlisted([$host]);
	}

	/**
	 * Whether the access list carries this exact address, no subdomains: what an
	 * allow list permits, and what `addAddress()` treats as already known.
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
		$listed = array_map('strval', $this->getListedAddresses());
		$this->configService->setAppValue(ConfigService::SOCIAL_ACCESS_LIST, '[]');
		$this->forgetUnlisted($listed);
	}

	/**
	 * @param string $address
	 */
	public function addAddress(string $address) {
		$this->addAddresses([$address]);
	}

	/**
	 * Adds a batch of addresses, validated by the caller, with one settings
	 * write and the same audit and purge as individual additions.
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
			// Audit and purge each, as when adding domains one by one.
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
	 * Queues a newly blocked instance for the purge job, here so `occ` and the
	 * admin API behave the same. Only in block-list mode: in allow-list mode the
	 * entry is an instance this server chose to talk to.
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
		$this->forgetUnlisted([$address]);
	}

	/**
	 * Drops the recorded reasons of the domains that are now on neither the
	 * access list nor the silenced list, so a domain blocked again later does
	 * not come back with the reason of an earlier block.
	 *
	 * @param string[] $addresses
	 */
	private function forgetUnlisted(array $addresses): void {
		$listed = $this->exactlyListedHosts();
		$silenced = $this->silencedHosts();

		$gone = [];
		foreach ($addresses as $address) {
			$host = self::normalizeHost($address);
			if ($host !== '' && !isset($listed[$host]) && !isset($silenced[$host])) {
				$gone[] = $host;
			}
		}

		if ($gone !== []) {
			$this->serverBlockRequest->delete($gone);
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
