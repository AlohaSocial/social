<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Details;

/** Which posts appear as cards in a reader's feeds. */
class FeedDisplayService {
	public function __construct(
		private ConfigService $configService,
	) {
	}

	/**
	 * Whether replies stay out of this reader's feeds as cards of their own.
	 * They do for every signed-in reader; replies are read in their
	 * conversations.
	 */
	public function hidesReplies(string $userId): bool {
		return $userId !== '';
	}

	/** The feed preferences as the client API answers them. */
	public function export(string $userId): array {
		return [
			'hide_replies' => $this->hidesReplies($userId),
			'thread_sort' => $this->threadSort($userId),
		];
	}

	/** The thread order saved by Bluesky clients on this account's PDS. */
	public function threadSort(string $userId): string {
		if ($userId === '') {
			return 'oldest';
		}

		$preferences = json_decode($this->configService->getUserValue('atproto_preferences', $userId), true);
		if (!is_array($preferences)) {
			return 'oldest';
		}

		foreach ($preferences as $preference) {
			if (is_array($preference)
				&& ($preference['$type'] ?? '') === 'app.bsky.actor.defs#threadViewPref'
				&& in_array($preference['sort'] ?? null, ['oldest', 'newest', 'most-likes', 'random'], true)) {
				return $preference['sort'];
			}
		}

		return 'oldest';
	}

	/**
	 * Apply Bluesky's home-feed view preference to Bluesky posts only.
	 *
	 * @param Stream[] $posts
	 * @return Stream[]
	 */
	public function filterBlueskyHomePosts(array $posts, string $userId, Person $viewer, FollowService $follows): array {
		$preferences = $this->blueskyHomePreferences($userId);
		if ($preferences === null) {
			return $posts;
		}

		$replyAuthors = [];
		if ($preferences['hideRepliesByUnfollowed']) {
			foreach ($posts as $post) {
				$source = $this->blueskySource($post);
				if ($source !== null && $source->getInReplyTo() !== '') {
					$replyAuthors[] = $source->getAttributedTo();
				}
			}
		}
		$followed = array_fill_keys($follows->followingAmong($viewer, $replyAuthors), true);

		return array_values(array_filter($posts, function (Stream $post) use ($preferences, $followed): bool {
			$source = $this->blueskySource($post);
			if ($source === null) {
				return true;
			}

			if ($post instanceof Announce && $preferences['hideReposts']) {
				return false;
			}

			$isReply = $source->getInReplyTo() !== '';
			if ($isReply && ($preferences['hideReplies']
				|| ($preferences['hideRepliesByUnfollowed'] && !isset($followed[$source->getAttributedTo()]))
				|| $source->getDetailInt(Details::LIKES) < $preferences['hideRepliesByLikeCount'])) {
				return false;
			}

			return !$preferences['hideQuotePosts'] || $source->getQuote() === '';
		}));
	}

	/** @return array{hideReplies: bool, hideRepliesByUnfollowed: bool, hideRepliesByLikeCount: int, hideReposts: bool, hideQuotePosts: bool}|null */
	public function blueskyHomePreferences(string $userId): ?array {
		if ($userId === '') {
			return null;
		}

		$stored = json_decode($this->configService->getUserValue('atproto_preferences', $userId), true);
		if (!is_array($stored)) {
			return null;
		}

		foreach ($stored as $preference) {
			if (!is_array($preference)
				|| ($preference['$type'] ?? '') !== 'app.bsky.actor.defs#feedViewPref'
				|| ($preference['feed'] ?? '') !== 'home') {
				continue;
			}

			return [
				'hideReplies' => ($preference['hideReplies'] ?? false) === true,
				'hideRepliesByUnfollowed' => ($preference['hideRepliesByUnfollowed'] ?? true) === true,
				'hideRepliesByLikeCount' => max(0, (int)($preference['hideRepliesByLikeCount'] ?? 0)),
				'hideReposts' => ($preference['hideReposts'] ?? false) === true,
				'hideQuotePosts' => ($preference['hideQuotePosts'] ?? false) === true,
			];
		}

		return null;
	}

	private function blueskySource(Stream $post): ?Stream {
		$source = $post instanceof Announce ? $post->getObject() : $post;
		if (!$source instanceof Stream || ($source->getDetails(Details::ATPROTO)['uri'] ?? '') === '') {
			return null;
		}

		return $source;
	}
}
