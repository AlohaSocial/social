<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;

/** Filters a reader's accepted topic, author and format signals for ranking. */
final class RecommendationBehavioralProfile {
	/**
	 * Bounded unfamiliarity used for exploration, never a reward for popularity.
	 * Accepted follows count as prior familiarity; sparse or absent interactions
	 * leave the candidate eligible for a small exploration boost.
	 */
	public static function novelty(float $authorAffinity, bool $followed, float $exposurePenalty): float {
		$familiarity = max(abs(max(-1.0, min(1.0, $authorAffinity))), $followed ? 0.5 : 0.0);
		$exposure = max(0.0, min(1.0, $exposurePenalty));

		return max(0.0, min(1.0, (1.0 - $familiarity) * (1.0 - $exposure)));
	}

	public function __construct(
		private RecommendationSignalService $signals,
		private RecommendationTopicCatalog $catalog,
	) {
	}

	/** @return array{topics: array<string, float>, authors: array<string, float>, formats: array<string, float>} */
	public function forViewer(Person $viewer): array {
		$profile = $this->signals->profile($viewer->getUserId());
		foreach (array_keys($profile['topics']) as $topicId) {
			$topic = $this->catalog->find($topicId);
			if ($topic === null || !$topic['enabled'] || in_array('sensitive-topic', $topic['policyTags'], true)) {
				unset($profile['topics'][$topicId]);
			}
		}
		return $profile;
	}

	/** @param list<string> $topicIds @param array<string, float> $profile */
	public function topicAffinity(array $topicIds, array $profile): float {
		$matched = [];
		foreach ($topicIds as $topicId) {
			if (isset($profile[$topicId]) && is_finite($profile[$topicId])) {
				$matched[] = max(-1.0, min(1.0, $profile[$topicId]));
			}
		}

		return $matched === [] ? 0.0 : (float)array_sum($matched) / (float)count($matched);
	}

	/** @param array<string, float> $profile */
	public function authorAffinity(string $authorId, array $profile): float {
		return max(-1.0, min(1.0, $profile[hash('sha256', $authorId)] ?? 0.0));
	}

	/** @param array<string, float> $profile */
	public function formatAffinity(string $format, array $profile): float {
		return max(-1.0, min(1.0, $profile[$format] ?? 0.0));
	}

	/** @param list<Stream> $posts @return array<string, float> stream nid => exposure penalty */
	public function exposurePenalties(Person $viewer, array $posts): array {
		return $this->signals->exposurePenalties($viewer, $posts);
	}
}
