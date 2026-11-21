<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use DateTime;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Details;
use OCA\Social\Security\RemoteAddress;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The tick next to a profile link, verified as Mastodon does: the linked page
 * must link back to the profile with `rel="me"`. Stored on the cached actor's
 * details are when the page was last seen linking back (`fields_verified`,
 * keyed by field value; the Account entity's `verified_at`) and when the
 * fields were last checked (`fields_checked`).
 *
 * Fetches go through the app's HTTP client: http(s) only, no local host
 * unless allowed, re-checked on every redirect, size-capped, and only the
 * start of the page is read. An unreachable page is retried next pass.
 */
class ProfileLinkVerifier {
	/** how much of a page is worth scanning for a link back */
	public const MAX_SCAN = 512 * 1024;
	/** how long a verdict stands before the page is asked again */
	public const RECHECK_SECONDS = 24 * 3600;
	/** how many local accounts one cron pass looks at */
	public const LOCAL_BATCH = 20;
	/** how many accounts with a link one page of the walk reads */
	public const LOCAL_PAGE = 200;
	/** ...and how many pages one pass may read */
	public const LOCAL_PAGES = 5;

	public function __construct(
		private CacheDocumentService $cacheDocumentService,
		private ConfigService $configService,
		private ActorsRequest $actorsRequest,
		private CacheActorsRequest $cacheActorsRequest,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The web address a field value names, or '' when it names none. A remote
	 * field arrives as HTML (`<a href="…">…</a>`); a local one is what the
	 * account typed. The address is the one to fetch: an internationalised
	 * host in punycode, and the root path added where none is given.
	 */
	public static function linkOf(string $value): string {
		if (preg_match('/href=["\']([^"\']+)["\']/i', $value, $match) === 1) {
			$link = html_entity_decode($match[1]);
		} elseif (preg_match('#https?://[^\s<>"\']+#i', $value, $match) === 1) {
			$link = $match[0];
		} else {
			return '';
		}

		$link = self::asciiHost($link);
		if (filter_var($link, FILTER_VALIDATE_URL) === false) {
			return '';
		}

		if (($path = parse_url($link, PHP_URL_PATH)) === null || $path === '') {
			$link = preg_replace('~^((?:[a-z][a-z0-9+.\-]*:)?//[^/?#]*)~i', '$1/', $link) ?? $link;
		}

		return $link;
	}

	/**
	 * The address with its host in punycode, which is both what DNS spells
	 * and the only form `FILTER_VALIDATE_URL` accepts. The mapping is
	 * nontransitional, so a character such as `ß` keeps its own code point
	 * rather than being folded to `ss`. Everything outside the host is left
	 * exactly as it was.
	 */
	private static function asciiHost(string $url): string {
		if (preg_match('/[^\x20-\x7e]/', $url) !== 1 || function_exists('idn_to_ascii') === false) {
			return $url;
		}

		$parsed = parse_url($url);
		$host = is_array($parsed) ? ($parsed['host'] ?? '') : '';
		if ($host === '') {
			return $url;
		}
		$ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
		if ($ascii === false || $ascii === $host) {
			return $url;
		}

		$authority = (isset($parsed['user']) ? $parsed['user'] . (isset($parsed['pass']) ? ':' . $parsed['pass'] : '') . '@' : '')
			. $ascii . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
		$rest = ($parsed['path'] ?? '')
			. (isset($parsed['query']) ? '?' . $parsed['query'] : '')
			. (isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '');

		return (isset($parsed['scheme']) ? $parsed['scheme'] . ':' : '') . '//' . $authority . $rest;
	}

	/**
	 * Whether the page at `$link` links back to the actor with `rel="me"`.
	 */
	public function linksBack(string $link, Person $actor): bool {
		$parsed = parse_url($link);
		$scheme = strtolower($parsed['scheme'] ?? '');
		$host = $parsed['host'] ?? '';
		if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
			return false;
		}
		if (!$this->configService->isLocalNetworkAllowed() && RemoteAddress::isLocalHost($host)) {
			return false;
		}

		try {
			$html = substr($this->cacheDocumentService->retrieveContent($link), 0, self::MAX_SCAN);
		} catch (Throwable $e) {
			$this->logger->debug('[ProfileLinkVerifier] page not reachable', ['link' => $link, 'exception' => $e->getMessage()]);

			return false;
		}

		$targets = array_filter(array_unique([self::normalise($actor->getId()), self::normalise($actor->getUrl())]));
		if (preg_match_all('/<(?:a|link)\b[^>]*>/i', $html, $tags) < 1) {
			return false;
		}
		foreach ($tags[0] as $tag) {
			if (preg_match('/\brel=["\']([^"\']*)["\']/i', $tag, $rel) !== 1
				|| !in_array('me', preg_split('/\s+/', strtolower(trim($rel[1]))) ?: [], true)) {
				continue;
			}
			if (preg_match('/\bhref=["\']([^"\']+)["\']/i', $tag, $href) !== 1) {
				continue;
			}
			if (in_array(self::normalise(html_entity_decode($href[1])), $targets, true)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Checks every linked field of an actor and records the verdicts on the
	 * cached copy's details. Skipped when the fields were looked at recently,
	 * so the details refresh (which runs often) costs a fetch per field only
	 * once a day.
	 *
	 * @return bool whether anything was checked
	 */
	public function verify(Person $cached, bool $force = false): bool {
		$details = $cached->getDetailsAll();
		$checked = (int)($details[Details::FIELDS_CHECKED] ?? 0);
		if (!$force && $checked > time() - self::RECHECK_SECONDS) {
			return false;
		}

		$previous = $details[Details::FIELDS_VERIFIED] ?? [];
		$verified = [];
		foreach ($cached->getFields() as $field) {
			$link = self::linkOf((string)($field['value'] ?? ''));
			if ($link === '') {
				continue;
			}
			if ($this->linksBack($link, $cached)) {
				// the first time it was seen linking back, kept as long as it does
				$verified[$field['value']] = (string)($previous[$field['value']] ?? (new DateTime('now'))->format(DATE_ATOM));
			}
		}

		$cached->setDetailArray(Details::FIELDS_VERIFIED, $verified);
		$cached->setDetailInt(Details::FIELDS_CHECKED, time());

		return true;
	}

	/**
	 * The cron pass for this instance's own accounts, written to their cached
	 * copy. Walks a page at a time from where the last pass stopped
	 * (`SOCIAL_PROFILE_LINK_CURSOR`), only over accounts whose fields name a
	 * web address.
	 *
	 * @param int $deadline when the pass must stop, or 0 for no limit
	 *
	 * @return int how many accounts were checked
	 */
	public function verifyLocalActors(int $limit = self::LOCAL_BATCH, int $deadline = 0): int {
		$after = (string)$this->configService->getAppValue(ConfigService::SOCIAL_PROFILE_LINK_CURSOR);
		$checked = 0;

		for ($page = 0; $page < self::LOCAL_PAGES; $page++) {
			$batch = $this->actorsRequest->getPage(self::LOCAL_PAGE, $after, true);
			if ($batch === []) {
				// the end of the table: begin again next pass
				$after = '';
				break;
			}

			foreach ($batch as $actor) {
				$after = ActorsRequest::rowPrimOf($actor);
				if ($this->verifyLocal($actor)) {
					$checked++;
				}
				if ($checked >= $limit || ($deadline > 0 && time() >= $deadline)) {
					break 2;
				}
			}
		}

		$this->configService->setAppValue(ConfigService::SOCIAL_PROFILE_LINK_CURSOR, $after);

		return $checked;
	}

	private function verifyLocal(Person $actor): bool {
		if (array_filter(array_map(fn (array $f): string => self::linkOf((string)($f['value'] ?? '')), $actor->getFields())) === []) {
			return false;
		}
		try {
			$cached = $this->cacheActorsRequest->getFromId($actor->getId());
		} catch (Throwable $e) {
			return false;
		}
		// the cached copy is what is checked and what carries the verdict,
		// but the fields themselves are what the account set
		$cached->setFields($actor->getFields());
		if (!$this->verify($cached)) {
			return false;
		}
		$this->cacheActorsRequest->updateDetails($cached);

		return true;
	}

	private static function normalise(string $url): string {
		$url = trim($url);
		if ($url === '') {
			return '';
		}
		$url = preg_replace('/#.*$/', '', $url) ?? $url;
		$parsed = parse_url($url);
		if (!is_array($parsed) || !isset($parsed['host'])) {
			return rtrim($url, '/');
		}
		$path = rtrim($parsed['path'] ?? '', '/');

		return strtolower($parsed['scheme'] ?? 'https') . '://' . strtolower($parsed['host'])
			. (isset($parsed['port']) ? ':' . $parsed['port'] : '') . $path
			. (isset($parsed['query']) ? '?' . $parsed['query'] : '');
	}
}
