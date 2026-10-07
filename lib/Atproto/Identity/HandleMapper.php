<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Identity;

use OCA\Social\Service\ConfigService;

class HandleMapper {
	public function __construct(
		private readonly ConfigService $config,
	) {
	}

	public function mapUsernameToHandle(string $username): string {
		$host = $this->getHandleHost();
		$localPart = $this->sanitizeLocalPart($username);
		return $localPart . '.' . $host;
	}

	public function sanitizeLocalPart(string $username): string {
		// Lowercase
		$localPart = strtolower($username);
		// Replace dots and underscores with hyphens
		$localPart = preg_replace('/[^a-z0-9-]/', '-', $localPart);
		// Collapse multiple hyphens
		$localPart = preg_replace('/-+/', '-', $localPart);
		// Trim hyphens from start/end
		$localPart = trim($localPart, '-');
		// Ensure valid DNS label (max 63 chars)
		$localPart = substr($localPart, 0, 63);
		// Must start and end with alphanumeric
		$localPart = preg_replace('/^[^a-z0-9]+|[^a-z0-9]+$/', '', $localPart);

		if (empty($localPart)) {
			$localPart = 'user';
		}

		return $localPart;
	}

	public function resolveCollision(string $baseHandle, callable $existsCheck): string {
		$handle = $baseHandle;
		$suffix = 1;

		while ($existsCheck($handle)) {
			$suffix++;
			$dot = strpos($baseHandle, '.');
			$handle = rtrim(substr($baseHandle, 0, min($dot, 63 - strlen((string)$suffix) - 1)), '-') . '-' . $suffix . substr($baseHandle, $dot);
		}

		return $handle;
	}

	public function getHandleHost(): string {
		return strtolower((string)parse_url($this->getPdsEndpoint(), PHP_URL_HOST));
	}

	public function getPdsEndpoint(): string {
		$url = $this->config->getAppValue(ConfigService::ATPROTO_PDS_URL);
		if ($url === '') {
			return 'https://' . ConfigService::authorityOf($this->config->getSocialUrl());
		}
		$parts = parse_url($url);
		if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
			|| isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])
			|| !in_array($parts['path'] ?? '', ['', '/'], true)) {
			throw new \InvalidArgumentException('atproto_pds_url must be an HTTPS origin without credentials, path, query or fragment');
		}
		return 'https://' . ConfigService::authorityOf($url);
	}

	public function getWellKnownUrl(string $handle): string {
		return 'https://' . $handle . '/.well-known/atproto-did';
	}

	public function getDnsTxtRecord(string $handle): string {
		return '_atproto.' . $handle;
	}
}
