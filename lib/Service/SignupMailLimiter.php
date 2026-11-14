<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCP\IConfig;

/**
 * How often the public registration form may write to one email address.
 *
 * The form mails whatever address is typed into it: a confirmation link, or
 * a notice that the address or the username is taken. The per-address limits
 * of the form count the sender's IP address, so somebody with many of them
 * could fill a stranger's inbox. This counts the recipient instead:
 *
 * - a notice ("You already have an account", "Choose another username")
 *   goes to one address at most once per NOTICE_COOLDOWN, and a confirmation
 *   link starts that cooldown too, so a second registration with the same
 *   address is treated alike whether or not the first one made a pending
 *   registration;
 * - all form mails together reach one address at most DAILY_CAP times in any
 *   24 hours.
 *
 * The recipient is a keyed hash of the normalised address. The claims live
 * in the table of `DurableCache` whatever memcache there is, so neither an
 * instance without memcache nor an evicted cache entry forgets them.
 */
class SignupMailLimiter {
	public const CACHE_NAMESPACE = 'social.signup-mail';

	public const NOTICE_COOLDOWN = 21600;
	public const DAILY_CAP = 3;
	public const DAY = 86400;

	public const KIND_VERIFICATION = 'verification';
	public const KIND_NOTICE = 'notice';

	/** Mail providers that ignore dots in the local part. */
	private const DOTLESS_DOMAINS = ['gmail.com' => 'gmail.com', 'googlemail.com' => 'gmail.com'];

	public function __construct(
		private DurableCache $cache,
		private IConfig $config,
	) {
	}

	/**
	 * Claims one mail of a kind to an address.
	 *
	 * @param self::KIND_* $kind
	 * @return list<string>|null the keys claimed, for release(), or null when
	 *                           no mail may go to the address now
	 */
	public function claim(string $email, string $kind): ?array {
		$recipient = $this->recipient($email);
		$cooldown = 'notice.' . $recipient;
		$claims = [];

		if ($kind === self::KIND_NOTICE) {
			if (!$this->cache->addShared(self::CACHE_NAMESPACE, $cooldown, 1, self::NOTICE_COOLDOWN)) {
				return null;
			}
			$claims[] = $cooldown;
		}

		$slot = $this->daySlot($recipient);
		if ($slot === null) {
			$this->release($claims);

			return null;
		}
		$claims[] = $slot;

		if ($kind === self::KIND_VERIFICATION
			&& $this->cache->addShared(self::CACHE_NAMESPACE, $cooldown, 1, self::NOTICE_COOLDOWN)) {
			$claims[] = $cooldown;
		}

		return $claims;
	}

	/**
	 * Gives back what claim() took, for a mail that could not be sent.
	 *
	 * @param list<string> $claims
	 */
	public function release(array $claims): void {
		foreach ($claims as $key) {
			$this->cache->removeShared(self::CACHE_NAMESPACE, $key);
		}
	}

	/**
	 * An address as one inbox receives it: lower case, without a `+tag`
	 * sub-address, and without the dots that Gmail ignores.
	 */
	public static function normalise(string $email): string {
		$email = mb_strtolower(trim($email));
		$at = strrpos($email, '@');
		if ($at === false) {
			return $email;
		}

		$local = substr($email, 0, $at);
		$domain = substr($email, $at + 1);
		$plus = strpos($local, '+');
		if ($plus !== false && $plus > 0) {
			$local = substr($local, 0, $plus);
		}
		if (isset(self::DOTLESS_DOMAINS[$domain])) {
			$domain = self::DOTLESS_DOMAINS[$domain];
			$local = str_replace('.', '', $local);
		}

		return $local . '@' . $domain;
	}

	/** The normalised address, hashed with this instance's secret. */
	private function recipient(string $email): string {
		return hash_hmac(
			'sha256',
			'signup-mail' . "\0" . self::normalise($email),
			$this->config->getSystemValueString('secret', '')
		);
	}

	/** One of the DAILY_CAP slots of the day, or null when all are taken. */
	private function daySlot(string $recipient): ?string {
		for ($i = 0; $i < self::DAILY_CAP; $i++) {
			$key = 'day.' . $recipient . '.' . $i;
			if ($this->cache->addShared(self::CACHE_NAMESPACE, $key, 1, self::DAY)) {
				return $key;
			}
		}

		return null;
	}
}
