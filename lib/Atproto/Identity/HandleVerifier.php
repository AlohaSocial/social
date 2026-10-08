<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Identity;

use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCP\Http\Client\IClientService;
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
		private IClientService $clientService,
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
			$response = $this->clientService->newClient()->get('https://' . $handle . '/.well-known/atproto-did', [
				'timeout' => 10,
				'allow_redirects' => false,
				'http_errors' => false,
				'stream' => true,
			]);
			$body = '';
			if ($response->getStatusCode() === 200) {
				$raw = $response->getBody();
				$body = is_resource($raw) ? (string)stream_get_contents($raw, self::MAX_BODY) : substr((string)$raw, 0, self::MAX_BODY);
			}
		} catch (Throwable) {
			$body = '';
		}

		return trim($body) === $did ? 'https' : '';
	}
}
