<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto;

use OCA\Social\Atproto\Repository\Record;

/** Public indexing is checked independently of local persistence and relay delivery. */
class PublicPublicationVerifier {
	public function __construct(
		private readonly AppViewClient $appview,
	) {
	}
	public function verify(Record $record): array {
		if ($record->collection !== 'app.bsky.feed.post') {
			throw new \InvalidArgumentException('Only native Bluesky posts can be checked');
		}
		$uri = $record->getAtUri();
		$response = $this->appview->get('app.bsky.feed.getPostThread', ['uri' => $uri, 'depth' => 0, 'parentHeight' => 0]);
		$post = $response['thread']['post'] ?? null;
		if (!is_array($post)) {
			return ['indexed' => false, 'uri' => $uri, 'url' => null];
		}
		if (($post['uri'] ?? null) !== $uri || ($post['cid'] ?? null) !== $record->cid || ($post['author']['did'] ?? null) !== $record->did) {
			throw new \RuntimeException('Public AppView does not match the native post URI, author and CID');
		}
		return ['indexed' => true, 'uri' => $uri, 'cid' => $record->cid,
			'url' => 'https://bsky.app/profile/' . rawurlencode($record->did) . '/post/' . rawurlencode($record->rkey)];
	}
}
