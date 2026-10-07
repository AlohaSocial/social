<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto;

use OCP\Http\Client\IClientService;

/** Fixed public AppView, allowlisted read calls only; no client URLs or credentials are forwarded. */
class AppViewClient {
	public function __construct(
		private readonly IClientService $clients,
	) {
	}
	public function get(string $method, array $params): array {
		if (!in_array($method, ['app.bsky.actor.searchActors', 'app.bsky.actor.getProfile', 'app.bsky.feed.getPostThread', 'app.bsky.feed.getAuthorFeed'], true)) {
			throw new \InvalidArgumentException('Unsupported AppView method');
		}
		$response = $this->clients->newClient()->get('https://public.api.bsky.app/xrpc/' . $method . '?' . http_build_query($params), ['timeout' => 10, 'allow_redirects' => false]);
		if ($response->getStatusCode() !== 200) {
			throw new \RuntimeException('AppView request failed');
		}
		$body = $response->getBody();
		if (is_resource($body)) {
			$body = stream_get_contents($body, 2 * 1024 * 1024 + 1);
		}
		if (strlen($body) > 2 * 1024 * 1024) {
			throw new \RuntimeException('AppView response too large');
		}
		$data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
		if (!is_array($data)) {
			throw new \RuntimeException('Invalid AppView response');
		} return $data;
	}
}
