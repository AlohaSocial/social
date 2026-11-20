<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\AccessBlocksRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\AccessBlock;
use OCP\ICache;
use OCP\ICacheFactory;

/**
 * The two blocks that are about an address rather than an account.
 *
 * Accounts are Nextcloud accounts, so Mastodon's sign-up blocks get the only
 * meanings they can have here: an IP block at `no_access` is enforced on
 * every route this app serves (the sign-up severities are refused, not
 * stored), and an email-domain block refuses a fediverse identity to a
 * Nextcloud account. Canonical email blocks are not implemented: this app
 * never holds a deleted account's address.
 *
 * IP blocks are cached in the distributed cache under a version every write
 * replaces. With a local-only cache, other servers (and `occ`) see a change
 * after `IP_CACHE_TTL`, as does `occ social:reset`, which bypasses this
 * class. An expired block lifts itself where it is read.
 */
class AccessBlockService {
	/** the widest IPv4 prefix a block may have: a /16, 65,536 addresses */
	public const WIDEST_V4 = 16;
	/** the widest IPv6 prefix a block may have: a /32, what one provider is given */
	public const WIDEST_V6 = 32;

	/** the longest a server reads the IP blocks from the cache without a write telling it to stop */
	public const IP_CACHE_TTL = 60;

	private const CACHE_VERSION_KEY = 'ip_version';

	/** @var AccessBlock[]|null the live IP blocks, for this request */
	private ?array $cachedIps = null;

	private ?ICache $cache;

	public function __construct(
		private AccessBlocksRequest $accessBlocksRequest,
		?ICacheFactory $cacheFactory = null,
	) {
		$this->cache = ($cacheFactory !== null && $cacheFactory->isAvailable())
			? $cacheFactory->createDistributed('social.access_blocks') : null;
	}

	// IP blocks

	/** @return AccessBlock[] newest first */
	public function ipBlocks(): array {
		return $this->accessBlocksRequest->getByType(AccessBlock::TYPE_IP);
	}

	/** @throws ItemNotFoundException */
	public function ipBlock(int $id): AccessBlock {
		return $this->accessBlocksRequest->getById($id, AccessBlock::TYPE_IP);
	}

	/**
	 * Refuses an address or a range everything this app serves. A range wider
	 * than WIDEST_V4 or WIDEST_V6, or one holding the caller's own address, is
	 * refused, as it could lock out the admin API that would lift it.
	 *
	 * @param string $ip an address or a CIDR range, v4 or v6
	 * @param int $expiresAt when it lifts itself, or 0 for never
	 * @param string $callerIp the address the request came from, as the
	 *                         middleware sees it (`IRequest::getRemoteAddress()`); '' from the command line
	 *
	 * @throws InvalidResourceException the address, a range too wide or holding
	 *                                  the caller, or a severity this instance cannot honour
	 */
	public function blockIp(
		string $ip, string $severity = AccessBlock::SEVERITY_NO_ACCESS,
		string $comment = '', int $expiresAt = 0, string $callerIp = '',
	): AccessBlock {
		$range = self::normaliseRange($ip);
		if ($range === '') {
			throw new InvalidResourceException('not an IP address or range: ' . $ip);
		}

		[$address, $bits] = explode('/', $range, 2);
		$widest = str_contains($address, ':') ? self::WIDEST_V6 : self::WIDEST_V4;
		if ((int)$bits < $widest) {
			throw new InvalidResourceException(
				$range . ' is wider than /' . $widest . ': a block that wide shuts out whole networks. Block narrower ranges.'
			);
		}

		if (trim($callerIp) !== '' && self::inRange(trim($callerIp), $range)) {
			throw new InvalidResourceException(
				$range . ' holds your own address, ' . trim($callerIp) . ', as this server sees it: the block would '
				. 'lock you out, and where that is a reverse proxy, everybody.'
			);
		}

		if ($severity !== AccessBlock::SEVERITY_NO_ACCESS) {
			// Storing it unread would make an admin believe sign-ups were turned away.
			throw new InvalidResourceException(
				'this instance has no sign-up to apply "' . $severity . '" to; accounts are '
				. 'Nextcloud accounts and the server decides who gets one. Use "no_access".'
			);
		}

		$block = new AccessBlock(
			AccessBlock::TYPE_IP, $range, $severity, trim($comment), max(0, $expiresAt)
		);
		$this->accessBlocksRequest->save($block);
		$this->forgetIpBlocks();

		return $block;
	}

	/** @throws ItemNotFoundException */
	public function unblockIp(int $id): void {
		$this->accessBlocksRequest->delete($id, AccessBlock::TYPE_IP);
		$this->forgetIpBlocks();
	}

	/** Drops the cached IP blocks, here and on every server sharing the cache. */
	private function forgetIpBlocks(): void {
		$this->cachedIps = null;
		$this->cache?->set(self::CACHE_VERSION_KEY, self::newVersion());
	}

	/**
	 * Whether this instance refuses that address. An expired block lifts itself
	 * here, so a broken cron cannot extend it.
	 */
	public function isBlockedIp(string $ip, ?int $now = null): bool {
		$ip = trim($ip);
		if ($ip === '') {
			return false;
		}

		if ($this->cachedIps === null) {
			$this->cachedIps = $this->cachedIpBlocks();
		}

		foreach ($this->cachedIps as $block) {
			if ($block->isLiveAt($now) && self::inRange($ip, $block->getValue())) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The IP blocks as the cache holds them, read from the table when it does
	 * not. The version is settled before the table is read, so a list read
	 * across a concurrent write is stored under a version nobody asks for.
	 *
	 * @return AccessBlock[]
	 */
	private function cachedIpBlocks(): array {
		if ($this->cache === null) {
			return $this->ipBlocks();
		}

		$version = $this->cache->get(self::CACHE_VERSION_KEY);
		if (!is_string($version) || $version === '') {
			$version = self::newVersion();
			$this->cache->set(self::CACHE_VERSION_KEY, $version);
		}

		$key = 'ip_blocks/' . $version;
		$blocks = self::fromCache($this->cache->get($key));
		if ($blocks !== null) {
			return $blocks;
		}

		$blocks = $this->ipBlocks();
		$this->cache->set(
			$key,
			array_map(static fn (AccessBlock $block): array => [$block->getValue(), $block->getExpires()], $blocks),
			self::IP_CACHE_TTL
		);

		return $blocks;
	}

	/**
	 * The blocks a cached list names, or null for anything not wholly valid: a
	 * list missing an entry would let that range in.
	 *
	 * @return AccessBlock[]|null
	 */
	private static function fromCache(mixed $cached): ?array {
		if (!is_array($cached)) {
			return null;
		}

		$blocks = [];
		foreach ($cached as $entry) {
			if (!is_array($entry) || !is_string($entry[0] ?? null) || !is_int($entry[1] ?? null)) {
				return null;
			}
			$blocks[] = new AccessBlock(AccessBlock::TYPE_IP, $entry[0], AccessBlock::SEVERITY_NO_ACCESS, '', $entry[1]);
		}

		return $blocks;
	}

	private static function newVersion(): string {
		return bin2hex(random_bytes(8));
	}

	// email-domain blocks

	/** @return AccessBlock[] newest first */
	public function emailDomainBlocks(): array {
		return $this->accessBlocksRequest->getByType(AccessBlock::TYPE_EMAIL_DOMAIN);
	}

	/** @throws ItemNotFoundException */
	public function emailDomainBlock(int $id): AccessBlock {
		return $this->accessBlocksRequest->getById($id, AccessBlock::TYPE_EMAIL_DOMAIN);
	}

	/**
	 * Refuses a fediverse identity to Nextcloud accounts at that domain.
	 *
	 * @throws InvalidResourceException
	 */
	public function blockEmailDomain(string $domain, string $comment = ''): AccessBlock {
		$domain = self::normaliseDomain($domain);
		if ($domain === '') {
			throw new InvalidResourceException('not a domain');
		}

		$block = new AccessBlock(
			AccessBlock::TYPE_EMAIL_DOMAIN, $domain, '', trim($comment)
		);
		$this->accessBlocksRequest->save($block);

		return $block;
	}

	/** @throws ItemNotFoundException */
	public function unblockEmailDomain(int $id): void {
		$this->accessBlocksRequest->delete($id, AccessBlock::TYPE_EMAIL_DOMAIN);
	}

	/**
	 * Whether an address is at a blocked domain, subdomains included, as a
	 * domain block reads them.
	 */
	public function isBlockedEmail(string $email): bool {
		$at = strrpos($email, '@');
		if ($at === false) {
			return false;
		}

		$host = self::normaliseDomain(substr($email, $at + 1));
		if ($host === '') {
			return false;
		}

		foreach ($this->emailDomainBlocks() as $block) {
			$blocked = $block->getValue();
			if ($host === $blocked || str_ends_with($host, '.' . $blocked)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A hostname in the form `FediverseService` compares: lowercase, trimmed,
	 * without the trailing dot.
	 */
	public static function normaliseDomain(string $domain): string {
		$domain = rtrim(strtolower(trim($domain)), '.');

		return preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain) === 1 ? $domain : '';
	}

	/**
	 * An address or range in one comparable form, or '' for neither; a bare
	 * address becomes the range holding only itself.
	 */
	public static function normaliseRange(string $range): string {
		$range = trim($range);
		if ($range === '') {
			return '';
		}

		[$address, $bits] = array_pad(explode('/', $range, 2), 2, null);
		if (filter_var($address, FILTER_VALIDATE_IP) === false) {
			return '';
		}

		$full = str_contains($address, ':') ? 128 : 32;
		if ($bits === null) {
			return strtolower($address) . '/' . $full;
		}

		if (!ctype_digit($bits) || (int)$bits > $full) {
			return '';
		}

		return strtolower($address) . '/' . (int)$bits;
	}

	/**
	 * Whether an address falls inside a range, compared on the packed bytes so
	 * `::1` and `0:0:0:0:0:0:0:1` are the same address.
	 */
	public static function inRange(string $ip, string $range): bool {
		$packedIp = @inet_pton($ip);
		[$address, $bits] = array_pad(explode('/', $range, 2), 2, null);
		$packedRange = @inet_pton((string)$address);

		if ($packedIp === false || $packedRange === false
			|| strlen($packedIp) !== strlen($packedRange)) {
			// a v4 address is never inside a v6 range and the reverse
			return false;
		}

		$bits = ($bits === null) ? strlen($packedIp) * 8 : (int)$bits;
		$wholeBytes = intdiv($bits, 8);
		$spareBits = $bits % 8;

		if ($wholeBytes > 0
			&& substr($packedIp, 0, $wholeBytes) !== substr($packedRange, 0, $wholeBytes)) {
			return false;
		}

		if ($spareBits === 0) {
			return true;
		}

		$mask = 0xFF << (8 - $spareBits) & 0xFF;

		return (ord($packedIp[$wholeBytes]) & $mask) === (ord($packedRange[$wholeBytes]) & $mask);
	}
}
