<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\RecommendationSignalRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Service\InterestService;
use OCA\Social\Service\LinkPreviewService;
use OCA\Social\Service\RecommendationFeatureExtractor;
use OCA\Social\Service\RecommendationPreferenceService;
use OCA\Social\Service\RecommendationSignalService;
use OCA\Social\Service\RecommendationStoryFingerprint;
use OCA\Social\Service\RecommendationTopicCatalog;
use OCA\Social\Service\RecommendationTopicFingerprint;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class RecommendationSignalServiceTest extends TestCase {
	private RecommendationPreferenceService $preferences;
	private RecommendationSignalRequest $signals;
	private StreamRequest $streams;
	private CacheActorsRequest $cachedActors;
	private RecommendationSignalService $service;
	private IConfig $config;
	private int $generation = 7;
	private string $mode = 'on';

	protected function setUp(): void {
		$this->preferences = $this->createStub(RecommendationPreferenceService::class);
		$this->preferences->method('isEnabled')->willReturn(true);
		$this->preferences->method('get')->willReturnCallback(fn (): array => [
			'revision' => 8, 'generation' => $this->generation, 'learning_mode' => $this->mode,
			'learning' => $this->mode !== 'off', 'topics' => [],
		]);
		$this->signals = $this->createMock(RecommendationSignalRequest::class);
		$this->streams = $this->createMock(StreamRequest::class);
		$this->cachedActors = $this->createStub(CacheActorsRequest::class);
		$this->cachedActors->method('getFromId')->willThrowException(new \LogicException('Unexpected profile lookup'));
		$clock = $this->createStub(ITimeFactory::class);
		$clock->method('getTime')->willReturn(2_000_000_000);
		$this->config = $this->createStub(IConfig::class);
		$this->config->method('getSystemValueString')->willReturn('test-server-secret');
		$catalog = new RecommendationTopicCatalog();
		$this->service = new RecommendationSignalService(
			$this->preferences,
			$this->signals,
			new RecommendationFeatureExtractor($catalog),
			$this->streams,
			$clock,
			$this->config,
			$this->cachedActors,
			$this->createStub(LinkPreviewService::class),
		);
	}

	private function viewer(): Person {
		return (new Person())->setId('https://local.test/users/alice')->setUserId('alice');
	}

	private function parentPost(): Note {
		return (new Note())
			->setNid('123')
			->setId('https://remote.test/posts/123')
			->setAttributedTo('https://remote.test/users/bob')
			->setContent('<p>Apple releases a new laptop</p>')
			->setHashtags(['apple']);
	}

	public function testReplyIsRecordedAsEngagementWithoutInferringTopicOrAuthorAffinity(): void {
		$post = $this->parentPost();
		$this->streams->expects($this->once())->method('getVisibleByNids')->with(['123'])->willReturn(['123' => $post]);
		$this->signals->expects($this->once())->method('record')->with(
			'alice', $this->callback(static fn (string $id): bool => preg_match('/^[a-f0-9-]{36}$/i', $id) === 1), 7, 'reply',
			[], null, 2_000_000_000
		)->willReturn(['accepted' => true, 'duplicate' => false, 'profile_version' => 9]);

		$this->service->recordAction($this->viewer(), $post, 'reply');
	}

	public function testSeparateActionTransitionsWithinOneSecondReceiveDistinctEventIds(): void {
		$post = $this->parentPost();
		$this->streams->expects($this->exactly(3))->method('getVisibleByNids')->with(['123'])->willReturn(['123' => $post]);
		$ids = [];
		$deltas = [];
		$this->signals->expects($this->exactly(3))->method('record')->willReturnCallback(
			static function (string $actor, string $eventId, int $generation, string $kind, array $signals) use (&$ids, &$deltas): array {
				$ids[] = $eventId;
				$topics = array_values(array_filter($signals, static fn (array $signal): bool => $signal['dimension'] === 'topics'));
				$deltas[] = $topics[0]['delta'];
				return ['accepted' => true, 'duplicate' => false, 'profile_version' => count($ids) + 8];
			}
		);

		$this->service->recordAction($this->viewer(), $post, 'like');
		$this->service->recordAction($this->viewer(), $post, 'unlike');
		$this->service->recordAction($this->viewer(), $post, 'like');

		$this->assertCount(3, array_unique($ids));
		$this->assertSame([0.35, -0.35, 0.35], $deltas);
	}

	public function testOffPausedAndStaleGenerationEventsAreDiscardedBeforeReadingPosts(): void {
		$this->mode = 'off';
		$this->streams->expects($this->never())->method('getVisibleByNids');
		$this->signals->expects($this->never())->method('record');
		$event = ['event_id' => '1d9cfd49-4e69-4bb8-b7fc-854df448d310', 'generation' => 7, 'kind' => 'like', 'status_id' => '123'];
		$this->assertSame(1, $this->service->recordEvents($this->viewer(), [$event])['discarded']);

		$this->mode = 'paused';
		$this->assertSame(1, $this->service->recordEvents($this->viewer(), [$event])['discarded']);

		$this->mode = 'on';
		$this->generation++;
		$this->assertSame(1, $this->service->recordEvents($this->viewer(), [$event])['discarded']);
	}

	public function testInstanceWideLearningPauseBlocksBrowserAndNativeActionSignals(): void {
		$this->preferences->method('learningPaused')->willReturn(true);
		$this->streams->expects($this->never())->method('getVisibleByNids');
		$this->signals->expects($this->never())->method('record');
		$event = ['event_id' => '1d9cfd49-4e69-4bb8-b7fc-854df448d310', 'generation' => 7, 'kind' => 'like', 'status_id' => '123'];

		$this->assertSame(1, $this->service->recordEvents($this->viewer(), [$event])['discarded']);
		$this->service->recordAction($this->viewer(), $this->parentPost(), 'like');
	}

	public function testSignalBatchesAreBoundedBeforeValidationAndPostLookup(): void {
		$this->streams->expects($this->never())->method('getVisibleByNids');
		$this->signals->expects($this->never())->method('record');
		$events = array_fill(0, RecommendationSignalService::MAX_EVENTS + 1, null);

		$result = $this->service->recordEvents($this->viewer(), $events);

		$this->assertSame(0, $result['accepted']);
		$this->assertSame(0, $result['duplicates']);
		$this->assertSame(RecommendationSignalService::MAX_EVENTS + 1, $result['discarded']);
	}

	public function testNonStringPostAndProfileIdsAreDiscardedWithoutLookup(): void {
		$this->streams->expects($this->never())->method('getVisibleByNids');
		$this->signals->expects($this->never())->method('record');
		$events = [
			[
				'event_id' => '1d9cfd49-4e69-4bb8-b7fc-854df448d310', 'generation' => 7,
				'kind' => 'open', 'context' => 'interests', 'status_id' => ['123'],
			],
			[
				'event_id' => '2d9cfd49-4e69-4bb8-b7fc-854df448d310', 'generation' => 7,
				'kind' => 'profile_visit', 'context' => 'profile', 'profile_id' => ['https://remote.example/@bob'],
			],
		];

		$result = $this->service->recordEvents($this->viewer(), $events);

		$this->assertSame(0, $result['accepted']);
		$this->assertSame(2, $result['discarded']);
	}

	public function testImpressionRequiresAQualifiedVisiblePostAndSessionId(): void {
		$this->streams->expects($this->once())->method('getVisibleByNids')->willReturn(['123' => $this->parentPost()]);
		$features = (new RecommendationFeatureExtractor(new RecommendationTopicCatalog()))->extract($this->parentPost(), true);
		$storyKeys = RecommendationStoryFingerprint::keys(
			$features['story_terms'],
			'test-server-secret',
			'alice',
			$features['story_headline_terms'],
		);
		$topicId = (new RecommendationFeatureExtractor(new RecommendationTopicCatalog()))->exposureTopics(array_keys($features['topics']))[0];
		$topicKey = RecommendationTopicFingerprint::key($topicId, 'test-server-secret', 'alice');
		$this->assertNotNull($topicKey);
		$storyKeys[] = $topicKey;
		$this->signals->expects($this->once())->method('record')->with(
			'alice', '1d9cfd49-4e69-4bb8-b7fc-854df448d310', 7, 'impression', [],
			['post_keys' => $storyKeys, 'session_key' => 'f7f454f1-d33d-4fb5-a132-335837049d23'], 2_000_000_000
		)->willReturn(['accepted' => true, 'duplicate' => false, 'profile_version' => 9]);

		$result = $this->service->recordEvents($this->viewer(), [[
			'event_id' => '1d9cfd49-4e69-4bb8-b7fc-854df448d310', 'generation' => 7,
			'kind' => 'impression', 'context' => 'interests', 'status_id' => '123',
			'session_id' => 'f7f454f1-d33d-4fb5-a132-335837049d23', 'visible_ms' => 750,
		]]);

		$this->assertSame(1, $result['accepted']);
	}

	public function testMalformedImpressionNumbersAreDiscardedBeforeReadingPosts(): void {
		$this->streams->expects($this->never())->method('getVisibleByNids');
		$this->signals->expects($this->never())->method('record');

		$result = $this->service->recordEvents($this->viewer(), [
			[
				'event_id' => '1d9cfd49-4e69-4bb8-b7fc-854df448d310', 'generation' => 7,
				'kind' => 'impression', 'context' => 'interests', 'status_id' => '123',
				'session_id' => 'f7f454f1-d33d-4fb5-a132-335837049d23', 'visible_ms' => '750',
			],
			[
				'event_id' => '2d9cfd49-4e69-4bb8-b7fc-854df448d310', 'generation' => 7,
				'kind' => 'impression', 'context' => 'interests', 'status_id' => '123',
				'session_id' => 'f7f454f1-d33d-4fb5-a132-335837049d23', 'visible_ms' => 750.5,
			],
		]);

		$this->assertSame(0, $result['accepted']);
		$this->assertSame(2, $result['discarded']);
	}

	public function testSensitiveImpressionUsesAnOwnerScopedExactPostHmac(): void {
		$post = (new Note())
			->setNid('123')
			->setId('https://remote.test/posts/123')
			->setAttributedTo('https://remote.test/users/bob')
			->setContent('<p>Personal mental health treatment information</p>');
		$this->streams->expects($this->once())->method('getVisibleByNids')->with(['123'])->willReturn(['123' => $post]);
		$exactKey = RecommendationStoryFingerprint::exactPostKey('123', 'test-server-secret', 'alice');
		$this->assertNotNull($exactKey);
		$this->signals->expects($this->once())->method('record')->with(
			'alice', '1d9cfd49-4e69-4bb8-b7fc-854df448d310', 7, 'impression', [],
			['post_keys' => [$exactKey], 'session_key' => 'f7f454f1-d33d-4fb5-a132-335837049d23'], 2_000_000_000
		)->willReturn(['accepted' => true, 'duplicate' => false, 'profile_version' => 9]);

		$this->service->recordEvents($this->viewer(), [[
			'event_id' => '1d9cfd49-4e69-4bb8-b7fc-854df448d310', 'generation' => 7,
			'kind' => 'impression', 'context' => 'interests', 'status_id' => '123',
			'session_id' => 'f7f454f1-d33d-4fb5-a132-335837049d23', 'visible_ms' => 750,
		]]);
	}

	public function testQualifiedImpressionsReduceLaterCandidatesFromTheSameStory(): void {
		$post = $this->parentPost();
		$this->streams->expects($this->never())->method('getVisibleByNids');
		$featureExtractor = new RecommendationFeatureExtractor(new RecommendationTopicCatalog());
		$features = $featureExtractor->extract($post, true);
		$storyKeys = RecommendationStoryFingerprint::keys(
			$features['story_terms'],
			'test-server-secret',
			'alice',
			$features['story_headline_terms'],
		);
		$topicId = (new RecommendationFeatureExtractor(new RecommendationTopicCatalog()))->exposureTopics(array_keys($features['topics']))[0];
		$topicKey = RecommendationTopicFingerprint::key($topicId, 'test-server-secret', 'alice');
		$this->assertNotNull($topicKey);
		$this->signals->expects($this->once())->method('exposures')->with(
			'alice', 7, $this->callback(static fn (array $keys): bool => count($keys) === count($storyKeys) + 1 && array_diff([...$storyKeys, $topicKey], $keys) === []),
			2_000_000_000 - 30 * 86400,
		)->willReturnCallback(static function () use ($post): array {
			$sessions = [];
			foreach (['session-one', 'session-two'] as $session) {
				$sessions[$session] = ['impressions' => 1, 'updated_at' => 2_000_000_000 - 3600];
			}
			$keys = [];
			$features = (new RecommendationFeatureExtractor(new RecommendationTopicCatalog()))->extract($post, true);
			foreach (RecommendationStoryFingerprint::keys(
				$features['story_terms'],
				'test-server-secret',
				'alice',
				$features['story_headline_terms'],
			) as $key) {
				$keys[$key] = $sessions;
			}
			return $keys;
		});

		$penalties = $this->service->exposurePenalties($this->viewer(), [$post]);

		$this->assertEqualsWithDelta(0.5 * 2 ** (-1.0 / 72.0), $penalties['123'], 0.00001);
	}

	public function testSensitiveCandidateExposureUsesTheSameOwnerScopedExactPostKey(): void {
		$this->streams->expects($this->never())->method('getVisibleByNids');
		$post = (new Note())
			->setNid('123')
			->setId('https://remote.test/posts/123')
			->setAttributedTo('https://remote.test/users/bob')
			->setContent('<p>Personal mental health treatment information</p>');
		$exactKey = RecommendationStoryFingerprint::exactPostKey('123', 'test-server-secret', 'alice');
		$this->assertNotNull($exactKey);
		$this->signals->expects($this->once())->method('exposures')->with(
			'alice', 7, [$exactKey], 2_000_000_000 - 30 * 86400
		)->willReturn([
			$exactKey => ['session-one' => ['impressions' => 1, 'updated_at' => 2_000_000_000 - 3600]],
		]);

		$this->assertEqualsWithDelta(['123' => 1.0 / 3.0 * 2 ** (-1.0 / 72.0)], $this->service->exposurePenalties($this->viewer(), [$post]), 0.00001);
	}

	public function testVisiblePostDetailEventsCanTeachWhenConsentIsOn(): void {
		$this->streams->expects($this->once())->method('getVisibleByNids')->with(['123'])->willReturn(['123' => $this->parentPost()]);
		$this->signals->expects($this->once())->method('record')->willReturn(['accepted' => true, 'duplicate' => false, 'profile_version' => 9]);

		$result = $this->service->recordEvents($this->viewer(), [[
			'event_id' => '1d9cfd49-4e69-4bb8-b7fc-854df448d310', 'generation' => 7,
			'kind' => 'open', 'context' => 'detail', 'status_id' => '123',
		]]);

		$this->assertSame(1, $result['accepted']);
	}

	public function testAllDocumentedTimelineContextsAreAcceptedWhenConsentIsOn(): void {
		$this->streams->expects($this->once())->method('getVisibleByNids')->with(['123'])->willReturn(['123' => $this->parentPost()]);
		$this->signals->expects($this->exactly(count(InterestService::CONTEXTS)))->method('record')
			->willReturn(['accepted' => true, 'duplicate' => false, 'profile_version' => 9]);

		$events = [];
		foreach (InterestService::CONTEXTS as $index => $context) {
			$events[] = [
				'event_id' => sprintf('%08x-69e6-4bb8-b7fc-854df448d310', $index + 1),
				'generation' => 7,
				'kind' => 'open',
				'context' => $context,
				'status_id' => '123',
			];
		}

		$result = $this->service->recordEvents($this->viewer(), $events);

		$this->assertSame(count(InterestService::CONTEXTS), $result['accepted']);
		$this->assertSame(0, $result['discarded']);
	}

	public function testPassiveDwellIsDiscardedBeforeReadingThePost(): void {
		$this->streams->expects($this->never())->method('getVisibleByNids');
		$this->signals->expects($this->never())->method('record');

		$events = [];
		foreach ([1200, 30000] as $index => $milliseconds) {
			$events[] = [
				'event_id' => ($index === 0 ? '1' : '2') . 'd9cfd49-4e69-4bb8-b7fc-854df448d310',
				'generation' => 7,
				'kind' => 'dwell',
				'context' => 'interests',
				'status_id' => '123',
				'ms' => $milliseconds,
			];
		}

		$result = $this->service->recordEvents($this->viewer(), $events);

		$this->assertSame(0, $result['accepted']);
		$this->assertSame(2, $result['discarded']);
	}

	public function testSignalGenerationMustBeAnInteger(): void {
		$this->streams->expects($this->never())->method('getVisibleByNids');
		$this->signals->expects($this->never())->method('record');

		$result = $this->service->recordEvents($this->viewer(), [
			[
				'event_id' => '1d9cfd49-4e69-4bb8-b7fc-854df448d310', 'generation' => '7',
				'kind' => 'open', 'context' => 'interests', 'status_id' => '123',
			],
			[
				'event_id' => '2d9cfd49-4e69-4bb8-b7fc-854df448d310', 'generation' => 7.8,
				'kind' => 'open', 'context' => 'interests', 'status_id' => '123',
			],
		]);

		$this->assertSame(0, $result['accepted']);
		$this->assertSame(2, $result['discarded']);
	}

	public function testQuickSkipRemainsANegativeTopicSignal(): void {
		$this->streams->expects($this->once())->method('getVisibleByNids')->with(['123'])->willReturn(['123' => $this->parentPost()]);
		$projected = [];
		$this->signals->expects($this->once())->method('record')->willReturnCallback(
			static function (string $actor, string $eventId, int $generation, string $kind, array $signals) use (&$projected): array {
				$projected = $signals;
				return ['accepted' => true, 'duplicate' => false, 'profile_version' => 9];
			}
		);

		$this->service->recordEvents($this->viewer(), [[
			'event_id' => '1d9cfd49-4e69-4bb8-b7fc-854df448d310', 'generation' => 7,
			'kind' => 'skip', 'context' => 'interests', 'status_id' => '123',
		]]);

		$topicDeltas = array_column(array_values(array_filter(
			$projected,
			static fn (array $signal): bool => $signal['dimension'] === 'topics'
		)), 'delta');
		$this->assertNotEmpty($topicDeltas);
		$this->assertSame(array_fill(0, count($topicDeltas), -0.08), $topicDeltas);
	}

	public function testVideoWatchRatioUsesActualPlaybackFractionAndOnlyForVideoPosts(): void {
		$video = $this->parentPost()->setAttachments([(new MediaAttachment())->setType('video')]);
		$this->streams->expects($this->once())->method('getVisibleByNids')->with(['123'])->willReturn(['123' => $video]);
		$projected = [];
		$this->signals->expects($this->once())->method('record')->willReturnCallback(
			static function (string $actor, string $eventId, int $generation, string $kind, array $signals) use (&$projected): array {
				$projected = $signals;
				return ['accepted' => true, 'duplicate' => false, 'profile_version' => 9];
			}
		);

		$result = $this->service->recordEvents($this->viewer(), [[
			'event_id' => '1d9cfd49-4e69-4bb8-b7fc-854df448d310', 'generation' => 7,
			'kind' => 'video_watch_ratio', 'context' => 'shorts', 'status_id' => '123', 'ratio' => 0.75,
		]]);

		$this->assertSame(1, $result['accepted']);
		$this->assertSame([0.15, 0.15], array_column(array_values(array_filter(
			$projected, static fn (array $signal): bool => $signal['dimension'] === 'topics'
		)), 'delta'));
	}

	public function testVideoWatchRatioRejectsInvalidFractionsAndNonVideoPosts(): void {
		$this->streams->expects($this->once())->method('getVisibleByNids')->with(['123'])->willReturn(['123' => $this->parentPost()]);
		$this->signals->expects($this->never())->method('record');
		$result = $this->service->recordEvents($this->viewer(), [
			[
				'event_id' => '1d9cfd49-4e69-4bb8-b7fc-854df448d310', 'generation' => 7,
				'kind' => 'video_watch_ratio', 'context' => 'shorts', 'status_id' => '123', 'ratio' => '0.75',
			],
			[
				'event_id' => '2d9cfd49-4e69-4bb8-b7fc-854df448d310', 'generation' => 7,
				'kind' => 'video_watch_ratio', 'context' => 'shorts', 'status_id' => '123', 'ratio' => 1.1,
			],
			[
				'event_id' => '3d9cfd49-4e69-4bb8-b7fc-854df448d310', 'generation' => 7,
				'kind' => 'video_watch_ratio', 'context' => 'shorts', 'status_id' => '123', 'ratio' => 0.95,
			],
		]);

		$this->assertSame(3, $result['discarded']);
	}

	public function testRepeatedImpressionsInOneSessionIncreaseTheExposurePenaltyWithoutCountingEachBand(): void {
		$post = $this->parentPost();
		$this->streams->expects($this->never())->method('getVisibleByNids');
		$featureExtractor = new RecommendationFeatureExtractor(new RecommendationTopicCatalog());
		$features = $featureExtractor->extract($post, true);
		$keys = RecommendationStoryFingerprint::keys(
			$features['story_terms'],
			'test-server-secret',
			'alice',
			$features['story_headline_terms'],
		);
		$topicId = (new RecommendationFeatureExtractor(new RecommendationTopicCatalog()))->exposureTopics(array_keys($features['topics']))[0];
		$topicKey = RecommendationTopicFingerprint::key($topicId, 'test-server-secret', 'alice');
		$this->assertNotNull($topicKey);
		$keys[] = $topicKey;
		$session = 'f7f454f1-d33d-4fb5-a132-335837049d23';
		$exposures = [];
		foreach ($keys as $key) {
			$exposures[$key] = [$session => ['impressions' => 2, 'updated_at' => 2_000_000_000]];
		}
		$this->signals->expects($this->once())->method('exposures')->with(
			'alice', 7, $keys, 1_997_408_000
		)->willReturn($exposures);

		$this->assertEqualsWithDelta(['123' => 0.5], $this->service->exposurePenalties($this->viewer(), [$post]), 0.00001);
	}

	public function testShortExactStoriesUseTheirHistoricalFirstKeyForExposureFatigue(): void {
		$post = (new Note())
			->setNid('124')
			->setId('https://remote.test/posts/124')
			->setAttributedTo('https://remote.test/users/bob')
			->setContent('<p>Annual community picnic returns</p>');
		$this->streams->expects($this->never())->method('getVisibleByNids');
		$featureExtractor = new RecommendationFeatureExtractor(new RecommendationTopicCatalog());
		$features = $featureExtractor->extract($post, true);
		$keys = RecommendationStoryFingerprint::keys(
			$features['story_terms'],
			'test-server-secret',
			'alice',
			$features['story_headline_terms'],
		);
		$topicId = $featureExtractor->exposureTopics(array_keys($features['topics']))[0] ?? '';
		$topicKey = RecommendationTopicFingerprint::key($topicId, 'test-server-secret', 'alice');
		if ($topicKey !== null) {
			$keys[] = $topicKey;
		}
		$this->assertCount(RecommendationStoryFingerprint::MINHASH_BAND_COUNT + ($topicKey === null ? 1 : 2), $keys);
		$this->signals->expects($this->once())->method('exposures')->with(
			'alice', 7, $keys, 1_997_408_000
		)->willReturn([
			$keys[0] => ['f7f454f1-d33d-4fb5-a132-335837049d23' => ['impressions' => 2, 'updated_at' => 2_000_000_000]],
		]);

		$this->assertEqualsWithDelta(['124' => 0.5], $this->service->exposurePenalties($this->viewer(), [$post]), 0.00001);
	}

	public function testShortPHPHeadlineVariantInheritsExposureFromTheSameStory(): void {
		$previous = (new Note())->setContent('<p>PHP security critical patch</p>');
		$current = (new Note())
			->setNid('125')
			->setId('https://remote.test/posts/125')
			->setAttributedTo('https://remote.test/users/bob')
			->setContent('<p>PHP security critical exploit</p>');
		$this->streams->expects($this->never())->method('getVisibleByNids');
		$features = new RecommendationFeatureExtractor(new RecommendationTopicCatalog());
		$previousKeys = RecommendationStoryFingerprint::keys($features->extract($previous, true)['story_terms'], 'test-server-secret', 'alice');
		$currentFeatures = $features->extract($current, true);
		$currentKeys = RecommendationStoryFingerprint::keys($currentFeatures['story_terms'], 'test-server-secret', 'alice');
		$topicId = $features->exposureTopics(array_keys($currentFeatures['topics']))[0];
		$currentKeys[] = RecommendationTopicFingerprint::key($topicId, 'test-server-secret', 'alice');
		$currentKeys = array_values(array_filter($currentKeys));
		$matchedKeys = array_values(array_intersect(array_slice($previousKeys, 1), array_slice($currentKeys, 1)));
		$this->assertGreaterThanOrEqual(RecommendationStoryFingerprint::SHORT_STORY_MATCH_COUNT, count($matchedKeys), 'the two short headlines share enough MinHash samples');
		$session = 'f7f454f1-d33d-4fb5-a132-335837049d23';
		$exposures = [];
		foreach ($matchedKeys as $key) {
			$exposures[$key] = [$session => ['impressions' => 3, 'updated_at' => 2_000_000_000]];
		}
		$this->signals->expects($this->once())->method('exposures')->with(
			'alice', 7, $currentKeys, 2_000_000_000 - 30 * 86400
		)->willReturn($exposures);

		$this->assertEqualsWithDelta(['125' => 0.6], $this->service->exposurePenalties($this->viewer(), [$current]), 0.00001);
	}

	public function testShortStoryExposureNeedsSixMatchingSamples(): void {
		$current = (new Note())
			->setNid('126')
			->setId('https://remote.test/posts/126')
			->setAttributedTo('https://remote.test/users/bob')
			->setContent('<p>PHP security critical exploit</p>');
		$this->streams->expects($this->never())->method('getVisibleByNids');
		$features = new RecommendationFeatureExtractor(new RecommendationTopicCatalog());
		$currentFeatures = $features->extract($current, true);
		$keys = RecommendationStoryFingerprint::keys($currentFeatures['story_terms'], 'test-server-secret', 'alice');
		$topicId = $features->exposureTopics(array_keys($currentFeatures['topics']))[0];
		$keys[] = RecommendationTopicFingerprint::key($topicId, 'test-server-secret', 'alice');
		$keys = array_values(array_filter($keys));
		$session = 'f7f454f1-d33d-4fb5-a132-335837049d23';
		$exposures = [];
		foreach (array_slice($keys, 1, RecommendationStoryFingerprint::SHORT_STORY_MATCH_COUNT - 1) as $key) {
			$exposures[$key] = [$session => ['impressions' => 3, 'updated_at' => 2_000_000_000]];
		}
		$this->signals->expects($this->once())->method('exposures')->with(
			'alice', 7, $keys, 2_000_000_000 - 30 * 86400
		)->willReturn($exposures);

		$this->assertSame([], $this->service->exposurePenalties($this->viewer(), [$current]), 'five one-row matches do not count as a repeated story');
	}

	public function testLongStoryExposureMatchesARepeatedHeadlineAcrossDifferentBodies(): void {
		$opening = array_map(static fn (int $i): string => 'headline' . $i, range(1, 20));
		$previous = (new Note())->setContent('<p>' . implode(' ', $opening) . '. ' . implode(' ', array_map(static fn (int $i): string => 'previousbody' . $i, range(1, 40))));
		$current = (new Note())
			->setNid('127')
			->setId('https://remote.test/posts/127')
			->setAttributedTo('https://remote.test/users/bob')
			->setContent('<p>' . implode(' ', $opening) . '. ' . implode(' ', array_map(static fn (int $i): string => 'currentbody' . $i, range(1, 40))));
		$this->streams->expects($this->never())->method('getVisibleByNids');
		$features = new RecommendationFeatureExtractor(new RecommendationTopicCatalog());
		$previousFeatures = $features->extract($previous, true);
		$currentFeatures = $features->extract($current, true);
		$previousKeys = RecommendationStoryFingerprint::keys(
			$previousFeatures['story_terms'], 'test-server-secret', 'alice', $previousFeatures['story_headline_terms']
		);
		$currentKeys = RecommendationStoryFingerprint::keys(
			$currentFeatures['story_terms'], 'test-server-secret', 'alice', $currentFeatures['story_headline_terms']
		);
		$headlineKeys = array_values(array_intersect(
			array_slice($previousKeys, RecommendationStoryFingerprint::MINHASH_BAND_COUNT),
			array_slice($currentKeys, RecommendationStoryFingerprint::MINHASH_BAND_COUNT),
		));
		$this->assertCount(RecommendationStoryFingerprint::MINHASH_BAND_COUNT, $headlineKeys);
		$session = 'f7f454f1-d33d-4fb5-a132-335837049d23';
		$exposures = [];
		foreach ($headlineKeys as $key) {
			$exposures[$key] = [$session => ['impressions' => 3, 'updated_at' => 2_000_000_000]];
		}
		$this->signals->expects($this->once())->method('exposures')->with(
			'alice', 7, $this->callback(static fn (array $keys): bool => count($keys) === count($currentKeys) && array_diff($currentKeys, $keys) === []),
			2_000_000_000 - 30 * 86400,
		)->willReturn($exposures);

		$this->assertEqualsWithDelta(['127' => 0.6], $this->service->exposurePenalties($this->viewer(), [$current]), 0.00001);
	}

	public function testLearningOffDoesNotReadStoredExposureHistory(): void {
		$this->mode = 'off';
		$this->streams->expects($this->never())->method('getVisibleByNids');
		$this->signals->expects($this->never())->method('exposures');

		$this->assertSame([], $this->service->exposurePenalties($this->viewer(), [$this->parentPost()]));
	}
}
