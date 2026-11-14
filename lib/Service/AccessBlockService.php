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
 * Mastodon keeps three lists here — IP blocks, email-domain blocks and
 * canonical email blocks — and all three exist to police a sign-up. This app
 * has no sign-up: an account is a Nextcloud account, and the server decides
 * who gets one. So two of the three are given the only meanings they can
 * honestly have here, and the third is not implemented at all rather than
 * stored and never consulted:
 *
 * - An **IP block** at `no_access` is enforced on every route this app serves,
 *   which is what "no access" says. The two sign-up severities are refused
 *   rather than stored, because storing a rule nothing will ever read is worse
 *   than saying it cannot be honoured.
 * - An **email-domain block** refuses the one thing this app does decide:
 *   whether a Nextcloud account gets a fediverse identity. A server with open
 *   registration is where that matters, and it is the same question Mastodon
 *   is asking, one step later.
 * - A **canonical email block** is a hash of the address of a *deleted*
 *   Mastodon account, kept so the same person cannot sign up again. Nothing
 *   here deletes an account's address, because the address is not this app's
 *   to hold.
 *
 * The IP blocks are read on every request, so they are kept in Nextcloud's
 * distributed cache under a version that every write replaces: a block or a
 * lift is in force on the next request on every server sharing that cache.
 * A cache that is only local to each server (APCu alone on several web
 * servers, or `occ`, which does not share APCu with the web server) still
 * holds the server that wrote it to that; the others read the table again
 * once `IP_CACHE_TTL` has passed. Expiry needs no write: an
 * expired block lifts itself where it is read, cached or not. `occ
 * social:reset` empties the table without going through here, so what it
 * removes stays enforced for at most `IP_CACHE_TTL`.
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
	 * Refuses an address or a range everything this app serves.
	 *
	 * A range wider than WIDEST_V4 or WIDEST_V6 is refused, and so is one
	 * holding the caller's own address: either answers every request from
	 * whole networks, the admin API that would lift it included.
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
			// stored and never read would be worse: an admin would believe
			// sign-ups from that range were being turned away
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
	 * Whether this instance answers that address at all.
	 *
	 * An expired block does not: it lifts itself where it is read, so an
	 * instance whose cron is broken does not go on refusing an address the
	 * admin gave an end date to.
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
	 * The IP blocks as the cache holds them, read from the table when it
	 * does not: the range and the expiry of each, which is all
	 * `isBlockedIp()` reads.
	 *
	 * The version is settled before the table is read. A write that lands
	 * in between replaces it, so a list read before that write is stored
	 * under a version nobody asks for again.
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
	 * The blocks a cached list names, or null for anything that is not one
	 * whole: a list missing one of its entries would let that range in.
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
	 * Whether an address is at a blocked domain, subdomains included.
	 *
	 * Read the way a domain block reads them: blocking `mail.example` while
	 * `a.mail.example` walks straight back in is not a block, and whoever runs
	 * a throwaway-address service runs the subdomains too.
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
	 * A hostname in the one form comparisons can be made in.
	 *
	 * The same shape `FediverseService` reduces an address to: lowercase,
	 * unpadded, without the trailing dot of the absolute form.
	 */
	public static function normaliseDomain(string $domain): string {
		$domain = rtrim(strtolower(trim($domain)), '.');

		return preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain) === 1 ? $domain : '';
	}

	/**
	 * An address or range in the one form comparisons can be made in, or ''
	 * for something that is neither.
	 *
	 * A bare address is stored as the range holding only itself, so there is
	 * one shape to match against rather than two.
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
	 * Whether an address falls inside a range.
	 *
	 * Compared on the packed bytes rather than on the text, which is the only
	 * way `::1` and `0:0:0:0:0:0:0:1` come out the same address — and the only
	 * way a range means anything at all.
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
