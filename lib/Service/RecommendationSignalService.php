<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\RecommendationSignalRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use Throwable;

/** Consent-gated projector from validated observations to compact recommendation profiles. */
final class RecommendationSignalService implements IRecommendationSignalRecorder, IRecommendationSignalEvents {
	public const MAX_EVENTS = 100;
	public const IMPRESSION_MS = 750;
	private const EVENT_KINDS = ['impression', 'skip', 'open', 'media', 'link', 'video_watch_ratio', 'profile_visit'];

	public function __construct(
		private RecommendationPreferenceService $preferences,
		private RecommendationSignalRequest $signals,
		private RecommendationFeatureExtractor $features,
		private StreamRequest $streams,
		private ITimeFactory $timeFactory,
		private IConfig $config,
		private CacheActorsRequest $cachedActors,
		private LinkPreviewService $linkPreviews,
	) {
	}

	/** @param array<array-key, mixed> $events @return array{accepted: int, duplicates: int, discarded: int, profile_version: int} */
	#[\Override]
	public function recordEvents(Person $viewer, array $events): array {
		$state = $this->preferences->get($viewer->getUserId());
		if (!$this->preferences->isEnabled() || $this->preferences->learningPaused() || $state['learning_mode'] !== 'on') {
			return ['accepted' => 0, 'duplicates' => 0, 'discarded' => min(count($events), self::MAX_EVENTS), 'profile_version' => $state['revision']];
		}

		$batch = [];
		$postIds = [];
		$discarded = max(0, count($events) - self::MAX_EVENTS);
		$processed = 0;
		foreach ($events as $event) {
			if ($processed++ >= self::MAX_EVENTS) {
				break;
			}
			if (!is_array($event)) {
				$discarded++;
				continue;
			}
			$eventId = $event['event_id'] ?? null;
			$generation = $event['generation'] ?? null;
			$kind = $event['kind'] ?? null;
			$context = $event['context'] ?? null;
			if (!is_string($eventId) || !self::validUuid($eventId)
				|| !is_int($generation) || $generation !== $state['generation']
				|| !is_string($kind) || !is_string($context)) {
				$discarded++;
				continue;
			}
			if (!in_array($kind, self::EVENT_KINDS, true)) {
				$discarded++;
				continue;
			}
			$validContext = $kind === 'profile_visit'
				? $context === 'profile'
				: in_array($context, InterestService::CONTEXTS, true);
			if (!$validContext) {
				$discarded++;
				continue;
			}
			if ($kind === 'profile_visit') {
				$profileId = $event['profile_id'] ?? null;
				$author = is_string($profileId) ? trim($profileId) : '';
				if ($author !== '' && strlen($author) <= 2048) {
					$batch[] = ['event' => $event, 'kind' => $kind, 'profile_id' => $author];
				} else {
					$discarded++;
				}
				continue;
			}
			$postId = $event['status_id'] ?? null;
			if (!is_string($postId) || preg_match('/^[0-9]{1,20}$/', $postId) !== 1) {
				$discarded++;
				continue;
			}
			if ($kind === 'video_watch_ratio'
				&& ((!is_int($event['ratio'] ?? null) && !is_float($event['ratio'] ?? null))
					|| !is_finite((float)$event['ratio'])
					|| (float)$event['ratio'] < 0.0 || (float)$event['ratio'] > 1.0)) {
				$discarded++;
				continue;
			}
			if ($kind === 'impression') {
				$sessionId = $event['session_id'] ?? null;
				$visibleMs = $event['visible_ms'] ?? null;
				if (!is_string($sessionId) || !self::validUuid($sessionId)
					|| !is_int($visibleMs) || $visibleMs < self::IMPRESSION_MS) {
					$discarded++;
					continue;
				}
			}
			$batch[] = ['event' => $event, 'kind' => $kind, 'status_id' => $postId];
			$postIds[] = $postId;
		}

		$posts = [];
		if ($postIds !== []) {
			$this->streams->setViewer($viewer);
			$posts = $this->streams->getVisibleByNids(array_values(array_unique($postIds)));
			$this->linkPreviews->attachCards(array_values($posts));
		}

		$counts = ['accepted' => 0, 'duplicates' => 0, 'discarded' => $discarded, 'profile_version' => $state['revision']];
		foreach ($batch as $entry) {
			$event = $entry['event'];
			$kind = $entry['kind'];
			$signals = [];
			$impression = null;
			if ($kind === 'profile_visit') {
				try {
					$profile = $this->cachedActors->getFromId($entry['profile_id']);
				} catch (CacheActorDoesNotExistException) {
					$counts['discarded']++;
					continue;
				}
				if ($profile->getId() === $viewer->getId()
					|| ($profile->getUserId() !== '' && $profile->getUserId() === $viewer->getUserId())) {
					$counts['discarded']++;
					continue;
				}
				$signals[] = ['dimension' => 'authors', 'entity_key' => hash('sha256', $profile->getId()), 'delta' => 0.08];
			} else {
				$post = $posts[$entry['status_id']] ?? null;
				if (!$post instanceof Stream || $post->getAttributedTo() === $viewer->getId()) {
					$counts['discarded']++;
					continue;
				}
				if ($kind === 'video_watch_ratio' && !self::hasVideo($post)) {
					$counts['discarded']++;
					continue;
				}
				if ($kind === 'impression') {
					$sessionId = (string)$event['session_id'];
					$features = $this->features->extract($post, true);
					$postKeys = RecommendationStoryFingerprint::keys(
						$features['story_terms'] ?? [],
						$this->config->getSystemValueString('secret', ''),
						$viewer->getUserId(),
						$features['story_headline_terms'] ?? [],
					);
					// An empty sensitive-aware story sample also means the source was
					// clipped or matched a sensitive alias; do not retain a topic key.
					$topicId = ($features['story_terms'] ?? []) !== []
						? ($this->features->exposureTopics(array_keys($features['topics']))[0] ?? '')
						: '';
					$topicKey = RecommendationTopicFingerprint::key(
						$topicId,
						$this->config->getSystemValueString('secret', ''),
						$viewer->getUserId(),
					);
					if ($topicKey !== null) {
						$postKeys[] = $topicKey;
					}
					$exactPostKey = RecommendationStoryFingerprint::exactPostKey(
						(string)$post->getNid(),
						$this->config->getSystemValueString('secret', ''),
						$viewer->getUserId(),
					);
					if (($features['story_terms'] ?? []) === [] && $exactPostKey !== null) {
						$postKeys[] = $exactPostKey;
					}
					// Short, sensitive or unclassifiable text keeps the existing
					// exact-post exposure behaviour without retaining its contents or
					// a reversible, unsalted hash of the local post ID.
					$postKeys = $postKeys !== [] ? $postKeys : ($exactPostKey === null ? [] : [$exactPostKey]);
					$impression = ['post_keys' => $postKeys, 'session_key' => $sessionId];
				} else {
					$features = $this->features->extract($post);
					$delta = $this->delta($kind, $event);
					// Merely leaving a post on screen is not a reliable signal of
					// interest: the reader may be reading slowly, interrupted, or
					// scrolling past. Explicit actions and measured video playback
					// carry the positive intent; a quick pass can still be negative.
					if ($delta['topic'] !== 0.0) {
						foreach ($features['topics'] as $topicId => $_feature) {
							$signals[] = ['dimension' => 'topics', 'entity_key' => $topicId, 'delta' => $delta['topic']];
						}
					}
					$author = $post->getAttributedTo();
					if ($author !== '' && $delta['author'] !== 0.0) {
						$signals[] = ['dimension' => 'authors', 'entity_key' => hash('sha256', $author), 'delta' => $delta['author']];
					}
					if ($delta['format'] !== 0.0) {
						$signals[] = ['dimension' => 'formats', 'entity_key' => $features['format'], 'delta' => $delta['format']];
					}
				}
			}

			$result = $this->signals->record(
				$viewer->getUserId(), (string)$event['event_id'], $state['generation'], $kind,
				$signals, $impression, $this->timeFactory->getTime()
			);
			$counts['accepted'] += $result['accepted'] && !$result['duplicate'] ? 1 : 0;
			$counts['duplicates'] += $result['duplicate'] ? 1 : 0;
			$counts['discarded'] += $result['accepted'] ? 0 : 1;
			$counts['profile_version'] = max($counts['profile_version'], $result['profile_version']);
		}

		return $counts;
	}

	/**
	 * Records an action only after the application has applied a real state
	 * transition. Comment text is intentionally not part of the projection.
	 */
	#[\Override]
	public function recordAction(Person $viewer, Stream $post, string $action): void {
		$delta = match ($action) {
			'like' => 0.35,
			'unlike' => -0.35,
			'boost' => 0.25,
			'unboost' => -0.25,
			'save' => 0.55,
			'unsave' => -0.55,
			// A reply is engagement, not evidence of agreement with the post.
			// Keep the short-lived event for deduplication/measurement without
			// projecting it onto topic, author, or format affinity.
			'reply' => 0.0,
			default => 0.0,
		};
		$engagementOnly = $action === 'reply';
		if ($delta === 0.0 && !$engagementOnly) {
			return;
		}

		try {
			$state = $this->preferences->get($viewer->getUserId());
			if (!$this->preferences->isEnabled() || $this->preferences->learningPaused() || $state['learning_mode'] !== 'on') {
				return;
			}
			$this->streams->setViewer($viewer);
			$visible = $this->streams->getVisibleByNids([(string)$post->getNid()]);
			$visiblePost = $visible[(string)$post->getNid()] ?? null;
			if (!$visiblePost instanceof Stream || $visiblePost->getAttributedTo() === $viewer->getId()) {
				return;
			}
			$features = $this->features->extract($visiblePost);
			$signals = [];
			$author = $visiblePost->getAttributedTo();
			if (!$engagementOnly) {
				foreach ($features['topics'] as $topicId => $_feature) {
					$signals[] = ['dimension' => 'topics', 'entity_key' => $topicId, 'delta' => $delta];
				}
				if ($author !== '') {
					$signals[] = ['dimension' => 'authors', 'entity_key' => hash('sha256', $author), 'delta' => $delta * 0.45];
				}
				$signals[] = ['dimension' => 'formats', 'entity_key' => $features['format'], 'delta' => $delta * 0.2];
			}
			$now = $this->timeFactory->getTime();
			$eventId = self::eventId();
			$this->signals->record($viewer->getUserId(), $eventId, $state['generation'], $action, $signals, null, $now);
		} catch (Throwable) {
			// Recommendation learning must not make an otherwise valid social action fail.
		}
	}

	/** @return array{topics: array<string, float>, authors: array<string, float>, formats: array<string, float>} */
	public function profile(string $actorKey): array {
		$state = $this->preferences->get($actorKey);
		if ($state['learning_mode'] === 'off' || !$this->preferences->isEnabled() || $this->preferences->learningPaused()) {
			return ['topics' => [], 'authors' => [], 'formats' => []];
		}
		$profile = $this->signals->profile($actorKey);
		$now = $this->timeFactory->getTime();
		$projected = ['topics' => [], 'authors' => [], 'formats' => []];
		foreach ($profile as $dimension => $entities) {
			foreach ($entities as $key => $scores) {
				$elapsed = max(0, $now - $scores['updated_at']);
				$fast = $scores['fast'] * 2.0 ** (-((float)$elapsed) / (36.0 * 3600.0));
				$slow = $scores['slow'] * 2.0 ** (-((float)$elapsed) / (45.0 * 86400.0));
				$projected[$dimension][$key] = self::project($fast, $slow);
			}
		}
		return $projected;
	}

	/** @param list<Stream> $posts @return array<string, float> stream nid => bounded recency-weighted exposure penalty */
	public function exposurePenalties(Person $viewer, array $posts): array {
		$state = $this->preferences->get($viewer->getUserId());
		if (!$this->preferences->isEnabled() || $this->preferences->learningPaused() || ($state['learning_mode'] ?? 'off') === 'off' || $posts === []) {
			return [];
		}

		$storyKeysByNid = [];
		$topicKeysByNid = [];
		$postKeys = [];
		foreach ($posts as $post) {
			$nid = (string)$post->getNid();
			if ($nid === '') {
				continue;
			}
			$features = $this->features->extract($post, true);
			$storyKeys = RecommendationStoryFingerprint::keys(
				$features['story_terms'] ?? [],
				$this->config->getSystemValueString('secret', ''),
				$viewer->getUserId(),
				$features['story_headline_terms'] ?? [],
			);
			$topicId = ($features['story_terms'] ?? []) !== []
				? ($this->features->exposureTopics(array_keys($features['topics']))[0] ?? '')
				: '';
			$topicKey = RecommendationTopicFingerprint::key(
				$topicId,
				$this->config->getSystemValueString('secret', ''),
				$viewer->getUserId(),
			);
			$topicKeys = $topicKey === null ? [] : [$topicKey];
			$exactPostKey = RecommendationStoryFingerprint::exactPostKey(
				$nid,
				$this->config->getSystemValueString('secret', ''),
				$viewer->getUserId(),
			);
			$storyKeysByNid[$nid] = $storyKeys !== [] ? $storyKeys : ($exactPostKey === null ? [] : [$exactPostKey]);
			$topicKeysByNid[$nid] = $topicKeys;
			foreach ([...$storyKeysByNid[$nid], ...$topicKeys] as $key) {
				$postKeys[$key] = true;
			}
		}
		if ($postKeys === []) {
			return [];
		}

		$now = $this->timeFactory->getTime();
		$exposures = $this->signals->exposures(
			$viewer->getUserId(), $state['generation'], array_keys($postKeys), $now - 30 * 86400
		);
		$penalties = [];
		foreach ($storyKeysByNid as $nid => $keys) {
			$bySession = [];
			$exactKey = count($keys) === RecommendationStoryFingerprint::MINHASH_BAND_COUNT + 1 ? $keys[0] : null;
			$hasHeadlineKeys = count($keys) === RecommendationStoryFingerprint::MINHASH_BAND_COUNT * 2;
			foreach ($keys as $index => $key) {
				foreach ($exposures[$key] ?? [] as $session => $exposure) {
					if ($key === $exactKey) {
						$bySession[$session]['exact'] = true;
					} elseif ($hasHeadlineKeys && $index >= RecommendationStoryFingerprint::MINHASH_BAND_COUNT) {
						$bySession[$session]['headline_bands'] = ($bySession[$session]['headline_bands'] ?? 0) + 1;
					} else {
						$bySession[$session]['bands'] = ($bySession[$session]['bands'] ?? 0) + 1;
					}
					$bySession[$session]['impressions'] = max($bySession[$session]['impressions'] ?? 0, $exposure['impressions']);
					$bySession[$session]['updated_at'] = max($bySession[$session]['updated_at'] ?? 0, $exposure['updated_at']);
				}
			}
			$story = count($keys) > 1;
			$minimumMatches = count($keys) === RecommendationStoryFingerprint::MINHASH_BAND_COUNT + 1
				? RecommendationStoryFingerprint::SHORT_STORY_MATCH_COUNT
				: ($story ? 2 : 1);
			$impressionCount = 0;
			$updatedAt = 0;
			foreach ($bySession as $exposure) {
				$matchesBody = ($exposure['bands'] ?? 0) >= $minimumMatches;
				$matchesHeadline = ($exposure['headline_bands'] ?? 0) >= RecommendationStoryFingerprint::HEADLINE_MATCH_COUNT;
				if (!$matchesBody && !$matchesHeadline && !($exposure['exact'] ?? false)) {
					continue;
				}
				// Each HMAC band tracks the same views. Use the largest band count
				// per session (rather than summing all bands), then include repeats
				// from that session in the penalty.
				$impressionCount += $exposure['impressions'];
				$updatedAt = max($updatedAt, $exposure['updated_at']);
			}
			$storyPenalty = self::exposurePenalty($impressionCount, $updatedAt, $now);
			$topicBySession = [];
			foreach ($topicKeysByNid[$nid] ?? [] as $topicKey) {
				foreach ($exposures[$topicKey] ?? [] as $session => $exposure) {
					$topicBySession[$session]['impressions'] = max($topicBySession[$session]['impressions'] ?? 0, $exposure['impressions']);
					$topicBySession[$session]['updated_at'] = max($topicBySession[$session]['updated_at'] ?? 0, $exposure['updated_at']);
				}
			}
			$topicImpressions = 0;
			$topicUpdatedAt = 0;
			foreach ($topicBySession as $exposure) {
				$topicImpressions += $exposure['impressions'];
				$topicUpdatedAt = max($topicUpdatedAt, $exposure['updated_at']);
			}
			$topicPenalty = self::exposurePenalty($topicImpressions, $topicUpdatedAt, $now);
			if (max($storyPenalty, $topicPenalty) > 0.0) {
				$penalties[$nid] = max($storyPenalty, $topicPenalty);
			}
		}

		return $penalties;
	}

	private static function exposurePenalty(int $impressions, int $updatedAt, int $now): float {
		if ($impressions <= 0) {
			return 0.0;
		}

		$ageSeconds = max(0, $now - $updatedAt);
		$recency = exp(-0.6931471805599453 * (float)$ageSeconds / (72.0 * 3600.0));
		$repetition = (float)$impressions / ((float)$impressions + 2.0);

		return min(1.0, max(0.0, $recency * $repetition));
	}

	/** @return array{topic: float, author: float, format: float} */
	private function delta(string $kind, array $event): array {
		$signal = match ($kind) {
			'open' => 0.12,
			'media' => 0.12,
			'video_watch_ratio' => self::watchSignal((float)($event['ratio'] ?? 0.0)),
			'skip' => -0.08,
			'link' => 0.05,
			default => 0.0,
		};

		return [
			'topic' => $signal,
			'author' => 0.0,
			'format' => in_array($kind, ['media', 'video_watch_ratio', 'skip'], true) ? $signal : 0.0,
		];
	}

	private static function watchSignal(float $ratio): float {
		$ratio = max(0.0, min(1.0, $ratio));
		return $ratio >= 0.9 ? 0.3 : ($ratio >= 0.5 ? 0.15 : 0.0);
	}

	private static function hasVideo(Stream $post): bool {
		foreach ($post->getAttachments() as $attachment) {
			if (in_array($attachment->getType(), ['video', 'gifv'], true)) {
				return true;
			}
		}

		return false;
	}

	private static function project(float $fast, float $slow): float {
		return max(-1.0, min(1.0, (0.65 * $fast + 0.35 * $slow) / 3.0));
	}

	private static function validUuid(string $value): bool {
		return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $value) === 1;
	}

	private static function eventId(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
		$hex = bin2hex($bytes);
		return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
	}
}
