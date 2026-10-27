<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\ReactionsRequest;
use OCA\Social\Model\ActivityPub\Object\EmojiReact;
use OCA\Social\Model\ActivityPub\Stream;

/**
 * Counting the reactions on a post, which is a read and nothing else.
 *
 * Apart from `ReactionService`, which writes them, because writing one asks
 * `ModerationService` whether the account may act — and `ModerationService`
 * takes `StreamService`, which is what hangs the bars on a timeline. Putting
 * both halves in one class closed that circle and the container refused to
 * build anything: "Tried to query StreamService, but it is already in the
 * chain". Reading needs nothing but the table.
 */
class ReactionSummaryService {
	public function __construct(
		private ReactionsRequest $reactionsRequest,
		private EmojiService $emojiService,
	) {
	}

	/**
	 * The reaction bar of a post: each emoji, how many used it, and whether
	 * the viewer is one of them.
	 *
	 * Ordered by how many, then by the emoji itself, so the bar does not
	 * reshuffle between two readers or between two page loads when counts are
	 * equal.
	 *
	 * @param string $viewerId the reader's actor id, or '' for anonymous
	 * @return list<array{name: string, count: int, me: bool, url?: string}>
	 */
	public function summaryOf(string $postId, string $viewerId = ''): array {
		return $this->addLocalEmojiUrls(self::summarise($this->reactionsRequest->getByObjectId($postId), $viewerId));
	}

	/**
	 * Hangs the reaction bars on a page of posts.
	 *
	 * Called where the link previews are attached, and for the same reason:
	 * one query for the page rather than one per card. Neither is part of the
	 * stored post or of the wire object.
	 *
	 * @param Stream[] $posts
	 * @param string $viewerId the reader's actor id, or '' for anonymous
	 */
	public function attachReactions(array $posts, string $viewerId = ''): void {
		if ($posts === []) {
			return;
		}

		$ids = [];
		foreach ($posts as $post) {
			if ($post instanceof Stream && $post->getId() !== '') {
				$ids[] = $post->getId();
			}
		}

		$summaries = $this->summaryOfMany($ids, $viewerId);
		if ($summaries === []) {
			return;
		}

		foreach ($posts as $post) {
			if ($post instanceof Stream && isset($summaries[$post->getId()])) {
				$post->setReactions($summaries[$post->getId()]);
			}
		}
	}

	/**
	 * The same, for a page of posts at once.
	 *
	 * @param string[] $postIds
	 * @param string $viewerId the reader's actor id, or '' for anonymous
	 * @return array<string, list<array{name: string, count: int, me: bool, url?: string}>> keyed by post id
	 */
	public function summaryOfMany(array $postIds, string $viewerId = ''): array {
		$byPrim = $this->reactionsRequest->getByObjectIds($postIds);

		$summaries = [];
		foreach ($postIds as $postId) {
			$reactions = $byPrim[md5($postId)] ?? [];
			if ($reactions !== []) {
				$summaries[$postId] = $this->addLocalEmojiUrls(self::summarise($reactions, $viewerId));
			}
		}

		return $summaries;
	}

	/**
	 * Only an emoji this instance owns gets an image URL. Remote custom emoji
	 * remain labels, so displaying a reaction never contacts its source server.
	 *
	 * @param list<array{name: string, count: int, me: bool}> $summary
	 * @return list<array{name: string, count: int, me: bool, url?: string}>
	 */
	private function addLocalEmojiUrls(array $summary): array {
		foreach ($summary as &$reaction) {
			if (preg_match('/^:([a-z0-9_]{2,64}):$/iD', $reaction['name'], $match) !== 1) {
				continue;
			}
			$emoji = $this->emojiService->byShortcode($match[1]);
			if ($emoji !== null && $emoji->getUrl() !== '') {
				$reaction['url'] = $emoji->getUrl();
			}
		}
		unset($reaction);

		return $summary;
	}

	/**
	 * @param EmojiReact[] $reactions
	 * @return list<array{name: string, count: int, me: bool}>
	 */
	private static function summarise(array $reactions, string $viewerId): array {
		$counts = [];
		$mine = [];

		foreach ($reactions as $reaction) {
			$emoji = $reaction->getContent();
			if ($emoji === '') {
				continue;
			}

			$counts[$emoji] = ($counts[$emoji] ?? 0) + 1;
			if ($viewerId !== '' && $reaction->getActorId() === $viewerId) {
				$mine[$emoji] = true;
			}
		}

		$summary = [];
		foreach ($counts as $emoji => $count) {
			// the key is a string, not an int: isUsableEmoji() refuses digits,
			// so no emoji can be the numeric string PHP would have converted
			$summary[] = ['name' => $emoji, 'count' => $count, 'me' => isset($mine[$emoji])];
		}

		usort($summary, static function (array $a, array $b): int {
			return ($b['count'] <=> $a['count']) ?: strcmp($a['name'], $b['name']);
		});

		return $summary;
	}

}
