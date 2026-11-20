<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\ModerationRequest;
use OCA\Social\Db\ReactionsRequest;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Moderation;

/**
 * Counting the reactions on a post, a read only. Apart from
 * `ReactionService`, which writes them and needs `ModerationService`, which
 * needs `StreamService`, which needs this: one class would be a dependency
 * cycle.
 */
class ReactionSummaryService {
	public function __construct(
		private ReactionsRequest $reactionsRequest,
		private EmojiService $emojiService,
		private ?ModerationRequest $moderationRequest = null,
		private ?FediverseService $fediverseService = null,
	) {
	}

	/**
	 * The reaction bar of a post: each emoji, how many used it, and whether the
	 * viewer did; ordered by count, then emoji, so it is stable.
	 *
	 * @param string $viewerId the reader's actor id, or '' for anonymous
	 * @return list<array{name: string, count: int, me: bool, url?: string}>
	 */
	public function summaryOf(string $postId, string $viewerId = ''): array {
		return $this->summaryOfMany([$postId], $viewerId)[$postId] ?? [];
	}

	/**
	 * Hangs the reaction bars on a page of posts, in one query, as link
	 * previews are attached.
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
	 * The same, for a page of posts at once: one counting query for the page
	 * and, for a signed-in reader, one for their own reactions.
	 *
	 * The reactions of suspended and silenced accounts, of accounts on a
	 * blocked or silenced instance, and of accounts the reader blocks, is
	 * blocked by or mutes are not counted. The reader's own always are.
	 *
	 * @param string[] $postIds
	 * @param string $viewerId the reader's actor id, or '' for anonymous
	 * @return array<string, list<array{name: string, count: int, me: bool, url?: string}>> keyed by post id
	 */
	public function summaryOfMany(array $postIds, string $viewerId = ''): array {
		$postIds = array_values(array_unique(array_filter($postIds, static fn (string $id): bool => $id !== '')));
		if ($postIds === []) {
			return [];
		}

		$counts = $this->reactionsRequest->countByObjectIds(
			$postIds, $viewerId, $this->hiddenActors($viewerId), $this->hiddenHosts()
		);
		if ($counts === []) {
			return [];
		}
		$mine = $viewerId === '' ? [] : $this->reactionsRequest->emojisOf($viewerId, $postIds);

		$summaries = [];
		foreach ($postIds as $postId) {
			$prim = md5($postId);
			if (isset($counts[$prim])) {
				$summaries[$postId] = $this->addLocalEmojiUrls(self::summarise($counts[$prim], $mine[$prim] ?? []));
			}
		}

		return $summaries;
	}

	/**
	 * The accounts a moderator suspended or silenced, other than the reader.
	 *
	 * @return list<string>
	 */
	private function hiddenActors(string $viewerId): array {
		if ($this->moderationRequest === null) {
			return [];
		}
		$hidden = array_merge(
			$this->moderationRequest->getActorIdsAt(Moderation::SUSPEND),
			$this->moderationRequest->getActorIdsAt(Moderation::SILENCE),
		);

		return array_values(array_diff($hidden, [$viewerId]));
	}

	/**
	 * The instances this one blocks or silences.
	 *
	 * @return list<string>
	 */
	private function hiddenHosts(): array {
		if ($this->fediverseService === null) {
			return [];
		}

		return array_keys($this->fediverseService->blockedHosts() + $this->fediverseService->silencedHosts());
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
	 * @param array<string, int> $counts emoji => how many
	 * @param array<string, true> $mine the reader's own emoji
	 * @return list<array{name: string, count: int, me: bool}>
	 */
	private static function summarise(array $counts, array $mine): array {
		$summary = [];
		foreach ($counts as $emoji => $count) {
			$emoji = (string)$emoji;
			if ($emoji === '' || $count < 1) {
				continue;
			}
			$summary[] = ['name' => $emoji, 'count' => $count, 'me' => isset($mine[$emoji])];
		}

		usort($summary, static function (array $a, array $b): int {
			return ($b['count'] <=> $a['count']) ?: strcmp($a['name'], $b['name']);
		});

		return $summary;
	}
}
