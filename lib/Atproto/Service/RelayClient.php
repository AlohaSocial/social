<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Service;

use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Service\CurlService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The relays this instance announces itself to: `requestCrawl` tells one
 * to subscribe to the firehose.
 */
class RelayClient {
	private const TIMEOUT = 15;

	public function __construct(
		private AtprotoConfig $config,
		private CurlService $curlService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Asks every configured relay to crawl this PDS.
	 *
	 * @return array<string, string> relay => 'ok' or what went wrong
	 */
	public function requestCrawl(): array {
		$hostname = (string)parse_url($this->config->pdsEndpoint(), PHP_URL_HOST);
		$port = parse_url($this->config->pdsEndpoint(), PHP_URL_PORT);
		if (is_int($port)) {
			$hostname .= ':' . $port;
		}
		if (!$this->config->isSecure()) {
			// a relay takes a bare hostname as https; a plain-http instance
			// (a development one) has to say so
			$hostname = 'http://' . $hostname;
		}
		$results = [];
		foreach ($this->config->relays() as $relay) {
			$results[$relay] = $this->crawl($relay, $hostname);
		}

		return $results;
	}

	private function crawl(string $relay, string $hostname): string {
		$status = 0;
		try {
			$answer = $this->curlService->doRequest('post', $relay . '/xrpc/com.atproto.sync.requestCrawl', [
				'body' => (string)json_encode(['hostname' => $hostname]),
				'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
				'timeout' => self::TIMEOUT,
				'json_headers' => false,
				'allow_local_address' => !str_starts_with($relay, 'https://'),
			], $contentType, $status);
		} catch (Throwable $e) {
			$this->logger->notice('Relay not reached', ['relay' => $relay, 'exception' => $e]);

			return 'not reached: ' . $e->getMessage();
		}
		if ($status >= 200 && $status < 300) {
			return 'ok';
		}
		$decoded = json_decode($answer, true);
		$message = is_array($decoded) && is_string($decoded['message'] ?? null) ? $decoded['message'] : substr(trim($answer), 0, 200);

		return 'refused (' . (int)$status . '): ' . $message;
	}

	/**
	 * @throws AtprotoException when no relay accepted
	 */
	public function requestCrawlOrFail(): array {
		$results = $this->requestCrawl();
		if (!in_array('ok', $results, true)) {
			throw new AtprotoException('No relay accepted the crawl request: ' . json_encode($results));
		}

		return $results;
	}
}
