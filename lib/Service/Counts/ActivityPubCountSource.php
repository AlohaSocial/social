<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service\Counts;

use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\CurlService;

/**
 * The counts of a post on the fediverse, from the document its own server
 * serves at the post's `id`: the `totalItems` of its `likes`, `shares` and
 * `replies` collections, where it states them (`Stream::statedCount()`).
 *
 * Nothing federates a count — a `Like` travels to the post's server and is
 * totalled there — so this instance hears of no change to a remote post's
 * counts unless it asks. An answer that names some other document (a
 * redirect to a page, a post that moved) or none at all, a post deleted at
 * its source among them, leaves what is stored as it was.
 */
class ActivityPubCountSource implements CountSource {
	/** Documents asked for in one round of requests. */
	public const PARALLEL = 20;

	public function __construct(
		private CurlService $curl,
		private CountWriter $writer,
	) {
	}

	#[\Override]
	public function supports(Stream $post): bool {
		return !$post->isLocal()
			&& BlueskyIds::didOf($post->getId()) === ''
			&& preg_match('#^https?://#i', $post->getId()) === 1;
	}

	#[\Override]
	public function refresh(array $posts): int {
		$answered = 0;
		foreach (array_chunk($posts, self::PARALLEL) as $chunk) {
			$answers = $this->curl->retrieveObjectsMany(
				array_map(static fn (Stream $post): string => $post->getId(), $chunk)
			);
			foreach ($chunk as $post) {
				$data = $answers[$post->getId()] ?? null;
				if (!is_array($data) || ($data['id'] ?? '') !== $post->getId()) {
					$this->writer->stamp($post->getId());
					continue;
				}
				if ($this->writer->write(
					$post->getId(),
					Stream::statedCount($data, 'likes'),
					Stream::statedCount($data, 'shares'),
					Stream::statedCount($data, 'replies'),
				)) {
					$answered++;
				}
			}
		}

		return $answered;
	}
}
