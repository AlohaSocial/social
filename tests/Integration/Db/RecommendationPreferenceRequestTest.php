<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\RecommendationFeedbackRequest;
use OCA\Social\Db\RecommendationPreferenceRequest;
use OCA\Social\Db\RecommendationSignalRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\RecommendationPreferenceService;
use OCA\Social\Service\RecommendationSignalService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/** Revision races, generation changes and owner separation on the real DB. */
class RecommendationPreferenceRequestTest extends TestCase {
	private RecommendationPreferenceService $preferences;
	private RecommendationPreferenceRequest $request;
	private RecommendationFeedbackRequest $feedback;
	private RecommendationSignalRequest $signals;
	private IDBConnection $connection;
	private string $owner;
	private string $otherOwner;
	private bool $recommendationsEnabledBeforeTest;

	protected function setUp(): void {
		parent::setUp();
		$this->preferences = Server::get(RecommendationPreferenceService::class);
		$this->recommendationsEnabledBeforeTest = $this->preferences->isEnabled();
		$this->preferences->setEnabled(true);
		$this->request = Server::get(RecommendationPreferenceRequest::class);
		$this->feedback = Server::get(RecommendationFeedbackRequest::class);
		$this->signals = Server::get(RecommendationSignalRequest::class);
		$this->connection = Server::get(IDBConnection::class);
		$suffix = bin2hex(random_bytes(8));
		$this->owner = 'rec-test-' . $suffix;
		$this->otherOwner = 'rec-test-other-' . $suffix;
	}

	protected function tearDown(): void {
		$this->request->purge($this->owner);
		$this->request->purge($this->otherOwner);
		$this->preferences->setEnabled($this->recommendationsEnabledBeforeTest);
		parent::tearDown();
	}

	public function testConcurrentStaleWritesCannotOverwriteAndResetInvalidatesTheGeneration(): void {
		$initial = $this->preferences->get($this->owner);
		$this->assertSame(0, $initial['revision']);
		$this->assertSame('off', $initial['learning_mode']);

		$saved = $this->preferences->setLearningMode($this->owner, 0, 'on');
		$this->assertNotNull($saved);
		$this->assertSame(1, $saved['revision']);
		$this->assertSame($initial['generation'] + 1, $saved['generation']);
		$this->assertSame('on', $saved['learning_mode']);
		$this->assertNull($this->preferences->setLearningMode($this->owner, 0, 'off'));
		$this->assertSame('on', $this->preferences->get($this->owner)['learning_mode']);

		$reset = $this->preferences->reset($this->owner, 1);
		$this->assertNotNull($reset);
		$this->assertSame(2, $reset['revision']);
		$this->assertSame($saved['generation'] + 1, $reset['generation']);
		$this->assertSame($reset, $this->preferences->reset($this->owner, 1), 'repeating the same reset is idempotent');
	}

	public function testLearningConsentIsOwnerScoped(): void {
		$this->preferences->setLearningMode($this->owner, 0, 'on');

		$this->assertSame('off', $this->preferences->get($this->otherOwner)['learning_mode']);
		$this->assertSame(0, $this->preferences->get($this->otherOwner)['revision']);
	}

	public function testInstanceLearningSwitchAdvancesOneGlobalEpochWithoutRewritingProfiles(): void {
		$owner = $this->preferences->setLearningMode($this->owner, 0, 'on');
		$other = $this->preferences->setLearningMode($this->otherOwner, 0, 'on');
		self::assertNotNull($owner);
		self::assertNotNull($other);
		$staleGeneration = $owner['generation'];

		$this->request->invalidateAllLearningGenerations();

		$ownerAfter = $this->preferences->get($this->owner);
		$otherAfter = $this->preferences->get($this->otherOwner);
		self::assertSame($owner['revision'], $ownerAfter['revision']);
		self::assertSame($other['revision'], $otherAfter['revision']);
		self::assertSame(RecommendationPreferenceRequest::GENERATION_EPOCH_STRIDE, $ownerAfter['generation'] - $owner['generation']);
		self::assertSame(RecommendationPreferenceRequest::GENERATION_EPOCH_STRIDE, $otherAfter['generation'] - $other['generation']);
		self::assertNotSame($staleGeneration, $ownerAfter['generation']);
		self::assertSame('on', $this->preferences->get($this->owner)['learning_mode']);

		$staleEvent = [
			'event_id' => 'abf8e00c-97b8-45a1-9438-7794ce65edbc',
			'generation' => $staleGeneration,
			'kind' => 'like',
			'context' => 'home',
			'status_id' => '123',
		];
		$result = Server::get(RecommendationSignalService::class)->recordEvents(
			(new Person())->setId('https://local.test/users/' . $this->owner)->setUserId($this->owner),
			[$staleEvent],
		);
		self::assertSame(1, $result['discarded'], 'events from before the instance epoch change are rejected');
	}

	public function testResetPurgesExplicitFeedbackAtomicallyWithThePreferenceGeneration(): void {
		$this->preferences->get($this->owner);
		$eventId = '792b7610-e146-422c-9c87-d703412e9874';
		$this->feedback->record($this->owner, '0f6f07ce-f9d0-4a0d-90f2-9f1ebc629072', $eventId, 'post', '123', 'HIDE_POST', 2_000_000_000);
		$this->assertCount(1, $this->feedback->current($this->owner));

		$this->preferences->reset($this->owner, 0);

		$this->assertSame([], $this->feedback->current($this->owner));
	}

	public function testLearningSignalsAreIdempotentAndLateGenerationsCannotRestoreResetData(): void {
		$initial = $this->request->get($this->owner);
		$enabled = $this->request->setLearningMode($this->owner, $initial['revision'], 'on');
		self::assertNotNull($enabled);
		$generation = $enabled['generation'];
		$eventId = '7c5bc6c7-787f-4df7-a5bd-973cf942c55c';
		$signal = ['dimension' => 'topics', 'entity_key' => 'tech.apple', 'delta' => 0.35];

		$recorded = $this->signals->record($this->owner, $eventId, $generation, 'like', [$signal], null, 2_000_000_000);
		self::assertTrue($recorded['accepted']);
		self::assertFalse($recorded['duplicate']);
		self::assertSame($enabled['revision'], $recorded['profile_version']);
		self::assertSame($enabled['revision'], $this->request->get($this->owner)['revision'], 'learning a signal does not change the consent/CAS revision or expire open timeline cursors');
		$duplicate = $this->signals->record($this->owner, $eventId, $generation, 'like', [$signal], null, 2_000_000_001);
		self::assertTrue($duplicate['duplicate']);
		self::assertSame(1, count($this->signals->profile($this->owner)['topics']));

		$reset = $this->request->resetLearning($this->owner, $duplicate['profile_version']);
		self::assertNotNull($reset);
		self::assertSame([], $this->signals->profile($this->owner)['topics']);
		$late = $this->signals->record($this->owner, '5c5bc6c7-787f-4df7-a5bd-973cf942c55c', $generation, 'like', [$signal], null, 2_000_000_002);
		self::assertFalse($late['accepted']);
		self::assertSame([], $this->signals->profile($this->owner)['topics']);
	}

	public function testNewTopicsCanReplaceProfileEntriesThatHaveFullyDecayed(): void {
		$initial = $this->request->get($this->owner);
		$enabled = $this->request->setLearningMode($this->owner, $initial['revision'], 'on');
		self::assertNotNull($enabled);
		$now = time();
		$staleAt = $now - 181 * 86400;
		for ($index = 0; $index < 300; $index++) {
			$qb = $this->connection->getQueryBuilder();
			$qb->insert(RecommendationSignalRequest::TABLE_SIGNAL)
				->setValue('actor_key', $qb->createNamedParameter($this->owner))
				->setValue('dimension', $qb->createNamedParameter('topics'))
				->setValue('entity_key', $qb->createNamedParameter(sprintf('stale.topic.%03d', $index)))
				->setValue('fast_score', $qb->createNamedParameter(0.5))
				->setValue('slow_score', $qb->createNamedParameter(0.5))
				->setValue('updated_at', $qb->createNamedParameter($staleAt + $index, IQueryBuilder::PARAM_INT))
				->setValue('evidence_count', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
				->executeStatement();
		}

		$recorded = $this->signals->record(
			$this->owner,
			'9d7e037b-4496-46bb-9709-44ce71c6e7a4',
			$enabled['generation'],
			'open',
			[['dimension' => 'topics', 'entity_key' => 'topic.current', 'delta' => 0.35]],
			null,
			$now,
		);

		self::assertTrue($recorded['accepted']);
		$profile = $this->signals->profile($this->owner);
		self::assertCount(300, $profile['topics']);
		self::assertArrayHasKey('topic.current', $profile['topics']);
		self::assertArrayNotHasKey('stale.topic.000', $profile['topics']);

		$refresh = $this->connection->getQueryBuilder();
		$refresh->update(RecommendationSignalRequest::TABLE_SIGNAL)
			->set('updated_at', $refresh->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($refresh->expr()->eq('actor_key', $refresh->createNamedParameter($this->owner)))
			->andWhere($refresh->expr()->eq('dimension', $refresh->createNamedParameter('topics')))
			->executeStatement();
		$this->signals->record(
			$this->owner,
			'a0e90273-3508-4e07-bfad-816c6af4d0cc',
			$enabled['generation'],
			'open',
			[['dimension' => 'topics', 'entity_key' => 'topic.not-yet', 'delta' => 0.35]],
			null,
			$now + 1,
		);

		$profile = $this->signals->profile($this->owner);
		self::assertCount(300, $profile['topics']);
		self::assertArrayHasKey('topic.current', $profile['topics']);
		self::assertArrayNotHasKey('topic.not-yet', $profile['topics']);
	}

	public function testStoryExposureBandsAreOwnerScopedAndPurgedWithTheLearningGeneration(): void {
		$initial = $this->request->get($this->owner);
		$enabled = $this->request->setLearningMode($this->owner, $initial['revision'], 'on');
		self::assertNotNull($enabled);
		$session = 'c8bb7f51-2e1e-4e92-b1a9-1cab0f3a0e10';
		$keys = array_map(static fn (int $band): string => hash('sha256', 'story-band-' . $band), range(0, 7));
		$impression = ['post_keys' => $keys, 'session_key' => $session];
		$eventId = 'daafc587-f141-4e0b-9ab3-20615915a22e';

		$first = $this->signals->record($this->owner, $eventId, $enabled['generation'], 'impression', [], $impression, 2_000_000_000);
		self::assertTrue($first['accepted']);
		self::assertFalse($first['duplicate']);
		$duplicate = $this->signals->record($this->owner, $eventId, $enabled['generation'], 'impression', [], $impression, 2_000_000_001);
		self::assertTrue($duplicate['duplicate']);
		self::assertCount(8, $this->signals->exposures($this->owner, $enabled['generation'], $keys, 2_000_000_000 - 30 * 86400));
		self::assertSame([], $this->signals->exposures($this->otherOwner, $enabled['generation'], $keys, 2_000_000_000 - 30 * 86400));

		$this->request->resetLearning($this->owner, $duplicate['profile_version']);
		self::assertSame([], $this->signals->exposures($this->owner, $enabled['generation'], $keys, 2_000_000_000 - 30 * 86400));
	}

	public function testTurningLearningOffAndBackOnInvalidatesEventsFromTheEarlierConsentPeriod(): void {
		$initial = $this->request->get($this->owner);
		$enabled = $this->request->setLearningMode($this->owner, $initial['revision'], 'on');
		self::assertNotNull($enabled);
		$oldGeneration = $enabled['generation'];

		$disabled = $this->request->setLearningMode($this->owner, $enabled['revision'], 'off');
		self::assertNotNull($disabled);
		$resumed = $this->request->setLearningMode($this->owner, $disabled['revision'], 'on');
		self::assertNotNull($resumed);
		self::assertGreaterThan($oldGeneration, $resumed['generation']);

		$late = $this->signals->record(
			$this->owner,
			'32b22ad8-23a7-4d4b-94e4-b00def1e1953',
			$oldGeneration,
			'like',
			[['dimension' => 'topics', 'entity_key' => 'tech.apple', 'delta' => 0.35]],
			null,
			2_000_000_000,
		);
		self::assertFalse($late['accepted']);
		self::assertSame([], $this->signals->profile($this->owner)['topics']);
	}

	public function testReenabledConsentResetsExposureInTheSameBrowserSession(): void {
		$initial = $this->request->get($this->owner);
		$enabled = $this->request->setLearningMode($this->owner, $initial['revision'], 'on');
		self::assertNotNull($enabled);
		$postKey = hash('sha256', 'story-exposure');
		$session = 'c8bb7f51-2e1e-4e92-b1a9-1cab0f3a0e10';
		$oldImpression = ['post_keys' => [$postKey], 'session_key' => $session];
		$this->signals->record(
			$this->owner,
			'4526584d-e4d7-4b75-99ce-d9c36e6069a1',
			$enabled['generation'],
			'impression',
			[],
			$oldImpression,
			2_000_000_000,
		);

		$disabled = $this->request->setLearningMode($this->owner, $enabled['revision'], 'off');
		self::assertNotNull($disabled);
		$resumed = $this->request->setLearningMode($this->owner, $disabled['revision'], 'on');
		self::assertNotNull($resumed);
		$this->signals->record(
			$this->owner,
			'7ef34f61-34ad-47ae-b29d-1d93a4a939c8',
			$resumed['generation'],
			'impression',
			[],
			$oldImpression,
			2_000_000_002,
		);

		self::assertSame([], $this->signals->exposures(
			$this->owner,
			$enabled['generation'],
			[$postKey],
			2_000_000_000 - 30 * 86400,
		));
		$exposures = $this->signals->exposures(
			$this->owner,
			$resumed['generation'],
			[$postKey],
			2_000_000_000 - 30 * 86400,
		);
		self::assertSame(1, $exposures[$postKey][$session]['impressions'] ?? null);
	}
}
