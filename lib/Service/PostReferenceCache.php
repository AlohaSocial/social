<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Reference\PostReferenceProvider;
use OCP\Collaboration\Reference\IReferenceManager;
use OCP\IURLGenerator;

/**
 * Drops the cards `PostReferenceProvider` rendered for a local post or
 * account from Nextcloud's reference cache, so a deleted, edited or
 * suspended one does not keep showing for the hour the cache holds it.
 *
 * The provider caches one card per link as it was pasted, so each address
 * it reads a post by is dropped: the post's id tail and its nid, with and
 * without `index.php` and a trailing slash. Removing a card that was never
 * cached costs one cache lookup.
 */
class PostReferenceCache {
	public function __construct(
		private IReferenceManager $referenceManager,
		private IURLGenerator $urlGenerator,
		private ConfigService $configService,
		private StreamRequest $streamRequest,
	) {
	}

	public function forgetPost(Stream $post): void {
		$this->forgetLocalPost($post->getId(), $post->getNid());
	}

	/**
	 * The cards of a local account and of every post of it that one could
	 * have been rendered for. Does nothing for a remote account.
	 */
	public function forgetAuthor(string $actorId): void {
		$username = $this->localPath($actorId);
		if ($username === '' || str_contains($username, '/')) {
			return;
		}

		$this->forget('@' . $username);
		$after = 0;
		while (($page = $this->streamRequest->getOpenPostAddressesByAuthor($actorId, StreamRequest::DELETE_BATCH, $after)) !== []) {
			foreach ($page as $post) {
				$this->forgetLocalPost($post['id'], $post['nid']);
				$after = $post['nid'];
			}
		}
	}

	private function forgetLocalPost(string $id, int|string $nid): void {
		$path = $this->localPath($id);
		$slash = strpos($path, '/');
		if ($slash === false || $slash === 0) {
			return;
		}

		$this->forget('@' . $path);
		if ((int)$nid > 0) {
			$this->forget('@' . substr($path, 0, $slash) . '/' . $nid);
		}
	}

	/** What follows `{social url}@` in a local id, or '' for any other id. */
	private function localPath(string $id): string {
		$base = $this->configService->getSocialUrl() . '@';

		return str_starts_with($id, $base) ? substr($id, strlen($base)) : '';
	}

	private function forget(string $path): void {
		foreach (PostReferenceProvider::prefixesOf($this->urlGenerator) as $prefix) {
			$this->referenceManager->invalidateCache($prefix . $path);
			$this->referenceManager->invalidateCache($prefix . $path . '/');
		}
	}
}
