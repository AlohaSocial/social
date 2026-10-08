<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Identity;

use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Service\CurlService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The PLC directory: where a `did:plc` is registered and read back.
 *
 * Every request goes through the app's guarded HTTP client. A submission
 * is tried three times on a network failure or a 5xx, because the
 * operation is deterministic and resending it is harmless; a 4xx is the
 * directory's verdict and is reported as it is.
 */
class PlcClient {
	private const ATTEMPTS = 3;
	private const TIMEOUT = 15;

	public function __construct(
		private AtprotoConfig $config,
		private CurlService $curlService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Sends a signed operation.
	 *
	 * @throws AtprotoException when the directory refuses it or cannot be reached
	 */
	public function submit(string $did, array $signedOperation): void {
		$body = (string)json_encode($signedOperation, JSON_UNESCAPED_SLASHES);
		$last = null;
		for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
			$status = 0;
			try {
				$answer = $this->curlService->doRequest('post', $this->url($did), $this->options($body), $contentType, $status);
			} catch (Throwable $e) {
				$last = $e;
				$this->logger->notice('PLC directory not reached', ['did' => $did, 'attempt' => $attempt, 'exception' => $e]);
				continue;
			}
			if ($status >= 200 && $status < 300) {
				return;
			}
			if ($status >= 500) {
				$last = new AtprotoException('PLC directory answered ' . $status);
				continue;
			}

			throw new AtprotoException('PLC directory refused the operation (' . (int)$status . '): ' . self::reason($answer));
		}

		throw new AtprotoException('PLC directory could not be reached: ' . ($last?->getMessage() ?? ''), 0, $last);
	}

	/**
	 * The DID document, or null when the directory does not know the DID.
	 *
	 * @throws AtprotoException when the directory cannot be reached
	 */
	public function document(string $did): ?array {
		return $this->get($this->url($did));
	}

	/**
	 * The directory's current state of the DID (keys, handle, services), or
	 * null when it does not know it.
	 *
	 * @throws AtprotoException
	 */
	public function data(string $did): ?array {
		return $this->get($this->url($did) . '/data');
	}

	/**
	 * The audit log, nullified operations included.
	 *
	 * @throws AtprotoException
	 */
	public function auditLog(string $did): array {
		return $this->get($this->url($did) . '/log/audit') ?? [];
	}

	/**
	 * @throws AtprotoException
	 */
	private function get(string $url): ?array {
		$status = 0;
		try {
			$answer = $this->curlService->doRequest('get', $url, $this->options(), $contentType, $status);
		} catch (Throwable $e) {
			throw new AtprotoException('PLC directory could not be reached: ' . $e->getMessage(), 0, $e);
		}
		if ($status === 404) {
			return null;
		}
		if ($status < 200 || $status >= 300) {
			throw new AtprotoException('PLC directory answered ' . (int)$status . ': ' . self::reason($answer));
		}
		$decoded = json_decode($answer, true);
		if (!is_array($decoded)) {
			throw new AtprotoException('PLC directory did not answer JSON');
		}

		return $decoded;
	}

	private function url(string $did): string {
		return $this->config->plcDirectory() . '/' . rawurlencode($did);
	}

	/**
	 * @return array{headers: array<string, string>, body?: string, timeout: int, json_headers: bool, allow_local_address: bool}
	 */
	private function options(string $body = ''): array {
		$options = [
			'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
			'timeout' => self::TIMEOUT,
			'json_headers' => false,
			// the interop job runs its own directory on this machine
			'allow_local_address' => !str_starts_with($this->config->plcDirectory(), 'https://'),
		];
		if ($body !== '') {
			$options['body'] = $body;
		}

		return $options;
	}

	private static function reason(string $answer): string {
		$decoded = json_decode($answer, true);
		if (is_array($decoded) && is_string($decoded['message'] ?? null)) {
			return $decoded['message'];
		}

		return substr(trim($answer), 0, 200);
	}
}
