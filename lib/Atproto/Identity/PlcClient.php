<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Identity;

use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class PlcClient {
	public function __construct(
		private readonly IConfig $config,
		private readonly LoggerInterface $logger,
		private readonly IClientService $clients,
	) {
	}
	private function url(string $did, string $suffix = ''): string {
		if (AtprotoDid::parse($did) === null) {
			throw new \InvalidArgumentException('Invalid PLC DID');
		}
		return rtrim($this->config->getAppValue('social', 'atproto_plc_directory', 'https://plc.directory'), '/') . '/' . $did . $suffix;
	}
	public function submitOperation(string $did, array $operation): bool {
		try {
			$response = $this->clients->newClient()->post($this->url($did), ['timeout' => 15, 'body' => json_encode($operation, JSON_THROW_ON_ERROR), 'headers' => ['Content-Type' => 'application/json']]);
			return in_array($response->getStatusCode(), [200, 201], true);
		} catch (\Throwable $e) {
			$this->logger->warning('AT Protocol PLC submission failed', ['did' => $did, 'exception' => $e]);
			return false;
		}
	}
	public function resolveDid(string $did): ?array {
		return $this->get($did);
	}
	public function getOperationLog(string $did): array {
		return $this->get($did, '/log/audit') ?? [];
	}
	private function get(string $did, string $suffix = ''): ?array {
		try {
			$response = $this->clients->newClient()->get($this->url($did, $suffix), ['timeout' => 10]);
			return json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
		} catch (\Throwable $e) {
			$this->logger->warning('AT Protocol PLC request failed', ['did' => $did, 'exception' => $e]);
			return null;
		}
	}
}
