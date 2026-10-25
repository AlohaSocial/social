<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Identity;

use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Service\CurlService;

/** Fetches did:web documents using the same guarded HTTP client as the rest of Social. */
final class DidWebResolver {
	/** Reads the document for this DID, or null when it cannot be read or does not match. */
	public static function document(string $did, CurlService $curl): ?array {
		if (!Syntax::isDid($did) || !str_starts_with($did, 'did:web:')) {
			return null;
		}
		$parts = explode(':', substr($did, strlen('did:web:')));
		$host = rawurldecode((string)array_shift($parts));
		$hostParts = parse_url('https://' . $host);
		$hostName = is_array($hostParts) ? strtolower($hostParts['host'] ?? '') : '';
		if (!is_array($hostParts) || $hostName === ''
			|| isset($hostParts['user']) || isset($hostParts['pass']) || isset($hostParts['path'])
			|| isset($hostParts['query']) || isset($hostParts['fragment'])
			|| filter_var(trim($hostName, '[]'), FILTER_VALIDATE_IP) !== false
			|| preg_match('/(?:^|\.)(?:localhost|local)$/i', $hostName) === 1) {
			return null;
		}
		$path = [];
		foreach ($parts as $part) {
			$part = rawurldecode($part);
			if ($part === '' || $part === '.' || $part === '..' || str_contains($part, '/') || str_contains($part, '\\')) {
				return null;
			}
			$path[] = rawurlencode($part);
		}
		$url = 'https://' . $hostName . (isset($hostParts['port']) ? ':' . $hostParts['port'] : '')
			. ($path === [] ? '/.well-known/did.json' : '/' . implode('/', $path) . '/did.json');
		$status = 0;
		try {
			$body = $curl->doRequest('get', $url, [
				'timeout' => 5,
				'json_headers' => false,
				'headers' => ['Accept' => 'application/did+json, application/json'],
				'max_redirects' => 0,
				'accept_errors' => true,
			], $contentType, $status);
		} catch (\Throwable $e) {
			throw new AtprotoException('DID document could not be reached', 0, $e);
		}
		if ($status === 404) {
			return null;
		}
		if ($status < 200 || $status >= 300) {
			throw new AtprotoException('DID document endpoint answered ' . (string)$status);
		}
		$document = json_decode($body, true);

		return is_array($document) && ($document['id'] ?? '') === $did ? $document : null;
	}
}
