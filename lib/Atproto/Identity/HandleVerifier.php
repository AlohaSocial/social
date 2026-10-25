<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Identity;

use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Service\CurlService;
use Throwable;

/**
 * Whether a domain names a DID as its handle (§4.2), the two ways AT
 * Protocol knows: a DNS TXT record `_atproto.<handle>` reading `did=<DID>`,
 * or `https://<handle>/.well-known/atproto-did` answering the DID. Either is
 * enough, as it is for the AppView that will check the same thing.
 */
class HandleVerifier {
	/** what a well-known answer may weigh: a DID and a newline */
	private const MAX_BODY = 2048;

	public function __construct(
		private AtprotoConfig $config,
		private DnsLookup $dns,
		private CurlService $curl,
	) {
	}

	/**
	 * A handle as typed, made what it is stored as: lower case, without an
	 * `@` or the dots around it.
	 */
	public static function normal(string $handle): string {
		return strtolower(trim(ltrim(trim($handle), '@'), '.'));
	}

	/**
	 * Why a handle cannot be anybody's custom handle here, '' when it can.
	 */
	public function refusal(string $handle): string {
		if (!Syntax::isResolvableHandle($handle)) {
			return 'That is not a domain name a handle can be';
		}
		$host = strtolower($this->config->handleHost());
		if ($handle === $host || str_ends_with($handle, '.' . $host)) {
			return 'Handles under ' . $host . ' are the ones this server gives out';
		}

		return '';
	}

	/**
	 * How the handle names the DID: `dns`, `https`, or '' when it does not.
	 */
	public function verify(string $handle, string $did): string {
		foreach ($this->dns->txt('_atproto.' . $handle) as $text) {
			if (trim($text) === 'did=' . $did) {
				return 'dns';
			}
		}
		try {
			// read where the handle is, not where it sends: no redirect is followed
			$status = 0;
			$raw = $this->curl->doRequest('get', 'https://' . $handle . '/.well-known/atproto-did', [
				'timeout' => 10,
				'json_headers' => false,
				'accept_errors' => true,
				'max_redirects' => 0,
			], $contentType, $status);
			$body = $status === 200 ? substr($raw, 0, self::MAX_BODY) : '';
		} catch (Throwable) {
			$body = '';
		}

		return trim($body) === $did ? 'https' : '';
	}

	/** Resolve a network handle to the DID it publishes, or null when no valid method answers. */
	public function resolve(string $handle): ?string {
		$handle = self::normal($handle);
		if ($this->refusal($handle) !== '') {
			return null;
		}
		foreach ($this->dns->txt('_atproto.' . $handle) as $text) {
			if (preg_match('/^did=(.+)$/', trim($text), $match) === 1 && Syntax::isDid(trim($match[1]))) {
				return trim($match[1]);
			}
		}

		try {
			$status = 0;
			$body = $this->curl->doRequest('get', 'https://' . $handle . '/.well-known/atproto-did', [
				'timeout' => 10,
				'json_headers' => false,
				'accept_errors' => true,
				'max_redirects' => 0,
			], $contentType, $status);
		} catch (Throwable) {
			return null;
		}
		$did = $status === 200 ? trim(substr($body, 0, self::MAX_BODY)) : '';

		return Syntax::isDid($did) ? $did : null;
	}
}
