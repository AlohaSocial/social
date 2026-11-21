<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;

/** Reads a small local trend sample and rechecks it in the caller's viewer scope. */
final class RecommendationTrendCandidates {
	public const MAX_CANDIDATES = 40;

	public function __construct(
		private TrendService $trends,
		private StreamRequest $streams,
		private RecommendationFeatureExtractor $features,
	) {
	}

	/**
	 * Trending is a retrieval hint, not a ranking shortcut: the caller still
	 * requires a topic match, and re-reading through the viewer-aware stream
	 * query applies access, block, mute and hidden-status rules.
	 *
	 * @param list<string> $excludeNids
	 * @param list<string> $languages
	 * @param list<string> $topicIds
	 * @return array<string, Stream> by nid
	 */
	public function forViewer(Person $viewer, int $limit, array $excludeNids = [], array $languages = [], array $topicIds = [], string $media = ''): array {
		$limit = max(0, min(self::MAX_CANDIDATES, $limit));
		if ($limit === 0) {
			return [];
		}

		$mediaType = match ($media) {
			'photos' => 'image',
			'videos' => 'video',
			'shorts' => 'short',
			default => '',
		};
		$trending = $this->trends->trendingStatuses(
			HashtagService::PERIOD_DEFAULT,
			self::MAX_CANDIDATES,
			0,
			$media !== '',
			$mediaType,
		);

		$excluded = array_fill_keys(array_map('strval', $excludeNids), true);
		$nids = [];
		foreach ($trending as $post) {
			$nid = (string)$post->getNid();
			if ($nid === '' || $nid === '0' || isset($excluded[$nid]) || $post->getAttributedTo() === $viewer->getId()) {
				continue;
			}
			$topicMatches = array_keys($this->features->extract($post)['topics']);
			if ($topicIds !== [] && array_intersect($topicIds, $topicMatches) === []) {
				continue;
			}
			$nids[] = $nid;
			$excluded[$nid] = true;
		}

		if ($nids === []) {
			return [];
		}

		$visible = $this->streams->getVisibleByNids($nids);
		$result = [];
		foreach ($nids as $nid) {
			$post = $visible[$nid] ?? null;
			if (!$post instanceof Stream || $post->getAttributedTo() === $viewer->getId()) {
				continue;
			}
			if ($languages !== [] && !in_array($this->features->extract($post)['language'], $languages, true)) {
				continue;
			}
			$result[$nid] = $post;
			if (count($result) >= $limit) {
				break;
			}
		}

		return $result;
	}
}
