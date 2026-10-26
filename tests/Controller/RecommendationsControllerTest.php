<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\RecommendationsController;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\FilterService;
use OCA\Social\Service\IRecommendationSignalEvents;
use OCA\Social\Service\RecommendationFeedbackService;
use OCA\Social\Service\RecommendationPreferenceService;
use OCA\Social\Service\RecommendationTimelineService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Authentication and ownership boundaries for the recommendation API. */
#[AllowMockObjectsWithoutExpectations]
class RecommendationsControllerTest extends TestCase {
	private RecommendationPreferenceService $service;
	private RecommendationFeedbackService $feedbackService;
	private IRecommendationSignalEvents $signalService;
	private RecommendationTimelineService $timelineService;
	private FilterService $filterService;
	private bool $enabled = true;

	private function controller(): RecommendationsController {
		$request = $this->createStub(IRequest::class);
		$request->method('getHeader')->willReturn('');
		$request->method('passesCSRFCheck')->willReturn(true);
		\OC::$server->register(IRequest::class, $request);

		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$accounts = $this->createStub(AccountService::class);
		$accounts->method('getActorFromUserId')->willReturn((new Person())->setId('https://cloud.example/users/alice')->setUserId('alice'));

		return new RecommendationsController(
			$request,
			$session,
			new NullLogger(),
			$accounts,
			$this->createStub(ClientService::class),
			$this->service,
			$this->feedbackService,
			$this->signalService,
			$this->timelineService,
			$this->filterService,
		);
	}

	protected function setUp(): void {
		$this->service = $this->createMock(RecommendationPreferenceService::class);
		$this->service->method('isEnabled')->willReturnCallback(fn (): bool => $this->enabled);
		$this->feedbackService = $this->createMock(RecommendationFeedbackService::class);
		$this->signalService = $this->createMock(IRecommendationSignalEvents::class);
		$this->timelineService = $this->createMock(RecommendationTimelineService::class);
		$this->filterService = $this->createMock(FilterService::class);
		$this->filterService->method('apply')->willReturnArgument(0);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
		parent::tearDown();
	}

	public function testLearningStatusDoesNotExposeTopicPreferences(): void {
		$state = ['revision' => 2, 'generation' => 1, 'learning_mode' => 'off', 'learning' => false,
			'topics' => [['topic_id' => 'tech.apple', 'mode' => 'PRIORITISE']], 'effective_topics' => ['tech.apple' => 'PRIORITISE']];
		$this->service->expects($this->once())->method('get')->with('alice')->willReturn($state);

		$response = $this->controller()->preferences();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['revision' => 2, 'generation' => 1, 'learning_mode' => 'off', 'learning' => false, 'learning_active' => false], $response->getData());
	}

	public function testGlobalLearningPauseIsReflectedInClientLearningAvailability(): void {
		$this->service->method('learningPaused')->willReturn(true);
		$this->service->method('get')->willReturn(['revision' => 2, 'generation' => 1, 'learning_mode' => 'on', 'learning' => true]);

		$response = $this->controller()->preferences();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['learning']);
		$this->assertFalse($response->getData()['learning_active']);
	}

	public function testReaderCannotSetOrRequestAnIndividualTopic(): void {
		$this->assertFalse(method_exists(RecommendationsController::class, 'catalog'));
		$this->assertFalse(method_exists(RecommendationsController::class, 'savePreferences'));
		$this->assertFalse(method_exists(RecommendationsController::class, 'candidates'));
	}

	public function testLearningModeIsOwnedByTheAuthenticatedReader(): void {
		$this->service->expects($this->once())->method('setLearningMode')->with('alice', 4, 'on')->willReturn([
			'revision' => 5, 'generation' => 2, 'learning_mode' => 'on', 'learning' => true, 'topics' => [],
		]);

		$response = $this->controller()->learning('on', 4);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('on', $response->getData()['learning_mode']);
		$this->assertTrue($response->getData()['learning_active']);
	}

	public function testSignalEventsAreBoundToTheAuthenticatedViewer(): void {
		$result = ['accepted' => 1, 'duplicates' => 0, 'discarded' => 0, 'profile_version' => 5];
		$this->signalService->expects($this->once())->method('recordEvents')->with(
			$this->callback(static fn (Person $viewer): bool => $viewer->getUserId() === 'alice'),
			[['event_id' => '1d9cfd49-4e69-4bb8-b7fc-854df448d310']]
		)->willReturn($result);

		$response = $this->controller()->signals([['event_id' => '1d9cfd49-4e69-4bb8-b7fc-854df448d310']]);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($result, $response->getData());
	}

	public function testTheInstanceKillSwitchDisablesV4BeforePreferenceAccess(): void {
		$this->enabled = false;
		$this->service->expects($this->never())->method('get');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->preferences()->getStatus());
	}

	public function testRankedTimelineUsesOnlyTheAuthenticatedReader(): void {
		$result = [
			'items' => [], 'session_id' => null, 'next_cursor' => null, 'mode' => 'RANKED',
			'algorithm_version' => 1, 'profile_version' => 2, 'cursor_expired' => false,
			'coverage' => ['state' => 'UNDERFILLED', 'known_eligible' => 0, 'returned' => 0, 'remote_refresh_queued' => false],
		];
		$this->timelineService->expects($this->once())->method('page')->with(
			$this->callback(static fn (Person $viewer): bool => $viewer->getUserId() === 'alice'), 12, 'opaque-cursor', 'photos'
		)->willReturn($result);

		$response = $this->controller()->timeline(12, 'opaque-cursor', 'photos');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($result, $response->getData());
	}

	public function testRankedTimelineAppliesPublicHideFiltersAndScansPastFilteredStatuses(): void {
		$hidden = (new \OCA\Social\Model\ActivityPub\Object\Note())->setNid('10');
		$visible = (new \OCA\Social\Model\ActivityPub\Object\Note())->setNid('20');
		$firstPage = [
			'items' => [$hidden], 'session_id' => 'snapshot', 'next_cursor' => 'cursor-2', 'mode' => 'RANKED',
			'algorithm_version' => 1, 'profile_version' => 2, 'cursor_expired' => false,
			'coverage' => ['state' => 'GOOD', 'known_eligible' => 2, 'returned' => 1, 'remote_refresh_queued' => false],
		];
		$secondPage = $firstPage;
		$secondPage['items'] = [$visible];
		$secondPage['next_cursor'] = null;
		$this->timelineService->expects($this->exactly(2))->method('page')->willReturnOnConsecutiveCalls($firstPage, $secondPage);
		$this->filterService = $this->createMock(FilterService::class);
		$this->filterService->expects($this->exactly(2))->method('apply')->willReturnOnConsecutiveCalls([], [$visible]);

		$response = $this->controller()->timeline(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([$visible], $response->getData()['items']);
		$this->assertSame(1, $response->getData()['coverage']['returned']);
	}

	public function testFeedbackUsesTheAuthenticatedOwnerAndTheNamedPost(): void {
		$result = ['feedback_id' => '0f6f07ce-f9d0-4a0d-90f2-9f1ebc629072', 'operation' => 'HIDE_POST'];
		$this->feedbackService->expects($this->once())->method('record')->with(
			$this->callback(static fn (Person $viewer): bool => $viewer->getUserId() === 'alice'),
			'792b7610-e146-422c-9c87-d703412e9874', '1150000123', 'HIDE_POST'
		)->willReturn($result);

		$response = $this->controller()->feedback('792b7610-e146-422c-9c87-d703412e9874', '1150000123', 'HIDE_POST');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($result, $response->getData());
	}

	public function testFeedbackContextUsesTheAuthenticatedReaderAndPost(): void {
		$context = ['similarity_available' => false, 'format' => 'text'];
		$this->feedbackService->expects($this->once())->method('context')->with(
			$this->callback(static fn (Person $viewer): bool => $viewer->getUserId() === 'alice'), '1150000123'
		)->willReturn($context);

		$response = $this->controller()->feedbackContext('1150000123');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($context, $response->getData());
	}

	public function testFeedbackUndoIsBoundToAuthenticatedOwner(): void {
		$this->feedbackService->expects($this->once())->method('undo')->with(
			$this->callback(static fn (Person $viewer): bool => $viewer->getUserId() === 'alice'),
			'0f6f07ce-f9d0-4a0d-90f2-9f1ebc629072'
		)->willReturn(['undone' => true, 'expired' => false, 'feedback_version' => 2]);

		$response = $this->controller()->undoFeedback('0f6f07ce-f9d0-4a0d-90f2-9f1ebc629072');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['undone']);
	}

	public function testExportIncludesTheAuthenticatedOwnersCurrentFeedback(): void {
		$this->service->expects($this->once())->method('get')->with('alice')->willReturn(['revision' => 1, 'generation' => 0, 'learning_mode' => 'off', 'learning' => false, 'topics' => [['topic_id' => 'tech.apple', 'mode' => 'HIDE']]]);
		$this->feedbackService->expects($this->once())->method('export')->with('alice')->willReturn([]);

		$response = $this->controller()->export();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData()['feedback']);
		$this->assertSame(['revision' => 1, 'generation' => 0, 'learning_mode' => 'off', 'learning' => false, 'learning_active' => false], $response->getData()['preferences']);
	}
}
