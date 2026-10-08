<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\AP;
use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\StreamActionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\StreamActionDoesNotExistException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\StreamAction;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\NotificationService;
use OCA\Social\Service\PollService;
use OCA\Social\Service\SignatureService;
use OCA\Social\Service\StreamActionService;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

#[AllowMockObjectsWithoutExpectations]
class PollServiceTest extends TestCase {
	private const VIEWER = 'https://cloud.example/@viewer';
	private const AUTHOR = 'https://mastodon.social/users/alice';
	private const POLL_ID = self::AUTHOR . '/statuses/1';

	private StreamRequest|MockObject $streamRequest;
	private CacheActorService|MockObject $cacheActorService;
	private ActivityService|MockObject $activityService;
	private StreamActionService|MockObject $streamActionService;
	private StreamActionsRequest|Stub $streamActionsRequest;
	private NotificationService|Stub $notificationService;
	private ConfigService|Stub $configService;

	/** @var array<int, array<string, mixed>> the polls that were announced closed */
	private array $announced = [];
	/** @var array<string, string> the app values the sweep reads and writes */
	private array $stored = [];
	private ActionsRequest|Stub $actionsRequest;
	private AccountService|MockObject $accountService;
	private PollService $service;

	protected function setUp(): void {
		$this->bootActivityPub();
		\OC::$server->register(IURLGenerator::class, $this->createStub(IURLGenerator::class));

		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->activityService = $this->createMock(ActivityService::class);
		$this->streamActionService = $this->createMock(StreamActionService::class);
		$this->streamActionsRequest = $this->createStub(StreamActionsRequest::class);
		$this->streamActionsRequest->method('getAction')
			->willThrowException(new StreamActionDoesNotExistException());

		$this->actionsRequest = $this->createStub(ActionsRequest::class);
		$this->actionsRequest->method('getAction')
			->willThrowException(new ActionDoesNotExistException());
		$this->accountService = $this->createMock(AccountService::class);
		$this->notificationService = $this->createStub(NotificationService::class);
		$this->notificationService->method('onPollClosed')->willReturnCallback(
			function (Question $poll, array $voters): void {
				$this->announced[] = ['poll' => $poll->getId(), 'voters' => $voters];
			}
		);
		$this->configService = $this->createStub(ConfigService::class);
		$this->configService->method('getAppValue')->willReturnCallback(
			fn (string $key): string => $this->stored[$key] ?? ''
		);
		$this->configService->method('setAppValue')->willReturnCallback(
			function (string $key, string $value): void {
				$this->stored[$key] = $value;
			}
		);

		$this->service = new PollService(
			$this->streamRequest,
			$this->actionsRequest,
			$this->accountService,
			$this->cacheActorService,
			$this->activityService,
			$this->createStub(SignatureService::class),
			$this->streamActionService,
			$this->streamActionsRequest,
			$this->notificationService,
			$this->configService,
			new NullLogger()
		);
	}

	protected function tearDown(): void {
		AP::set(null);
		\OC::$server->reset();
	}

	/** A real AP registry over mocked interfaces, so vote notes get built for real. */
	private function bootActivityPub(): void {
		$args = [];
		foreach ((new ReflectionClass(AP::class))->getConstructor()->getParameters() as $parameter) {
			$class = $parameter->getType()->getName();
			$mock = $this->createStub($class);
			if ($class === ConfigService::class) {
				$mock->method('getCloudUrl')->willReturn('https://cloud.example');
			}
			$args[] = $mock;
		}
		AP::set(new AP(...$args));
	}

	private function viewer(): Person {
		$viewer = new Person();
		$viewer->setId(self::VIEWER);
		$viewer->setLocal(true);

		return $viewer;
	}

	private function poll(array $extra = [], bool $local = false): Question {
		$question = new Question();
		$question->importFromDatabase([
			'id' => self::POLL_ID,
			'nid' => 42,
			'attributed_to' => self::AUTHOR,
			'local' => $local ? 1 : 0,
			'source' => json_encode(array_merge([
				'type' => 'Question',
				'endTime' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
				'oneOf' => [
					['name' => 'Cats', 'replies' => ['totalItems' => 1]],
					['name' => 'Dogs', 'replies' => ['totalItems' => 2]],
				],
			], $extra)),
		]);
		$this->streamRequest->method('getStreamByNid')->with(42)->willReturn($question);

		return $question;
	}

	private function author(): Person {
		$author = new Person();
		$author->setId(self::AUTHOR);
		$author->setInbox(self::AUTHOR . '/inbox');
		$this->cacheActorService->method('getFromId')->with(self::AUTHOR)->willReturn($author);

		return $author;
	}

	public function testVoteFederatesTheChoiceAndRemembersIt(): void {
		$this->poll();
		$this->author();

		$sent = [];
		$this->activityService->method('request')
			->willReturnCallback(function (ACore $activity) use (&$sent): string {
				$sent[] = $activity;

				return 'token';
			});
		$this->streamActionService->expects($this->once())
			->method('setAction')
			->with(self::VIEWER, self::POLL_ID, StreamAction::POLL_VOTES, json_encode([1]));

		$result = $this->service->vote($this->viewer(), 42, [1]);

		$this->assertCount(1, $sent);
		$this->assertInstanceOf(Create::class, $sent[0]);
		/** @var Note $vote */
		$vote = $sent[0]->getObject();
		$this->assertSame('Dogs', $vote->getName());
		$this->assertSame(self::POLL_ID, $vote->getInReplyTo());
		$this->assertSame(self::AUTHOR, $vote->getTo());
		$this->assertSame(self::AUTHOR . '/inbox', $sent[0]->getInstancePaths()[0]->getUri());

		$poll = $result->exportAsLocal()['poll'];
		$this->assertTrue($poll['voted']);
		$this->assertSame([1], $poll['own_votes']);
	}

	public function testASingleChoicePollRefusesMultipleChoices(): void {
		$this->poll();
		$this->activityService->expects($this->never())->method('request');

		$this->expectException(InvalidActionException::class);

		$this->service->vote($this->viewer(), 42, [0, 1]);
	}

	public function testAMultipleChoicePollAcceptsSeveralChoices(): void {
		$this->poll(['anyOf' => [
			['name' => 'Cats', 'replies' => ['totalItems' => 1]],
			['name' => 'Dogs', 'replies' => ['totalItems' => 2]],
		], 'oneOf' => null]);
		$this->author();
		$sent = 0;
		$this->activityService->method('request')->willReturnCallback(function () use (&$sent): string {
			$sent++;

			return 'token';
		});

		$this->service->vote($this->viewer(), 42, [0, 1]);

		$this->assertSame(2, $sent, 'one vote note per choice');
	}

	public function testAnInvalidOptionIndexIsRefused(): void {
		$this->poll();

		$this->expectException(InvalidActionException::class);

		$this->service->vote($this->viewer(), 42, [7]);
	}

	public function testAnExpiredPollRefusesVotes(): void {
		$this->poll(['endTime' => gmdate('Y-m-d\TH:i:s\Z', time() - 60)]);

		$this->expectException(InvalidActionException::class);

		$this->service->vote($this->viewer(), 42, [0]);
	}

	public function testALocalPollCountsTheVoteHereAndFederatesTheNewCounts(): void {
		$poll = $this->poll([], true);
		$author = new Person();
		$author->setId(self::AUTHOR);
		$this->accountService->method('getFromId')->with(self::AUTHOR)->willReturn($author);

		// nothing is sent to an origin server: this instance is the origin
		$this->activityService->expects($this->never())->method('request');
		$this->streamRequest->expects($this->once())->method('update')->with($this->identicalTo($poll));
		$this->activityService->expects($this->once())
			->method('updateActivity')
			->with($this->identicalTo($author), $this->identicalTo($poll));

		$result = $this->service->vote($this->viewer(), 42, [1]);

		$this->assertSame(3, $result->getOptions()[1]['votes_count']);
		$this->assertSame(1, $result->getVotersCount());
		$this->assertStringContainsString('"totalItems":3', $result->getSource());

		$exported = $result->exportAsLocal()['poll'];
		$this->assertTrue($exported['voted']);
		$this->assertSame([1], $exported['own_votes']);
	}

	public function testVotingTwiceIsRefused(): void {
		$question = $this->poll();
		$action = new StreamAction(self::VIEWER, self::POLL_ID);
		$action->updateValue(StreamAction::POLL_VOTES, json_encode([0]));
		$question->setAction($action);
		$this->activityService->expects($this->never())->method('request');

		$this->expectException(InvalidActionException::class);

		$this->service->vote($this->viewer(), 42, [1]);
	}

	// handleIncomingVote()

	private function localPoll(): Question {
		$question = new Question();
		$question->setId(self::POLL_ID);
		$question->setAttributedTo(self::AUTHOR);
		$question->setLocal(true);
		$question->setPollData(['Cats', 'Dogs'], false, 3600);
		$this->streamRequest->method('getStreamById')->with(self::POLL_ID)->willReturn($question);

		return $question;
	}

	private function voteNote(string $option = 'Dogs'): Note {
		$note = new Note();
		$note->setId('https://remote.example/users/bob/vote/1');
		$note->setName($option);
		$note->setInReplyTo(self::POLL_ID);
		$note->setAttributedTo('https://remote.example/users/bob');

		return $note;
	}

	public function testAnIncomingVoteIsCountedStoredAndFederated(): void {
		$poll = $this->localPoll();
		$author = new Person();
		$author->setId(self::AUTHOR);
		$this->accountService->method('getFromId')->with(self::AUTHOR)->willReturn($author);

		$saved = [];
		$this->actionsRequest->method('save')->willReturnCallback(function ($vote) use (&$saved): void {
			$saved[] = $vote;
		});
		$this->activityService->expects($this->once())
			->method('updateActivity')
			->with($this->identicalTo($author), $this->identicalTo($poll));
		$this->streamRequest->expects($this->once())->method('update')->with($this->identicalTo($poll));

		$this->assertTrue($this->service->handleIncomingVote($this->voteNote()));

		$this->assertSame(1, $poll->getOptions()[1]['votes_count']);
		$this->assertSame(1, $poll->getVotersCount());
		$this->assertCount(1, $saved);
		$this->assertSame('Vote', $saved[0]->getType());
		$this->assertStringContainsString('"totalItems":1', $poll->getSource(), 'counts snapshot into the source');
	}

	public function testADuplicateVoteIsConsumedButNotCounted(): void {
		$poll = $this->localPoll();
		// every dedupe lookup finds an existing vote row
		$this->actionsRequest = $this->createStub(ActionsRequest::class);
		$this->notificationService = $this->createStub(NotificationService::class);
		$this->notificationService->method('onPollClosed')->willReturnCallback(
			function (Question $poll, array $voters): void {
				$this->announced[] = ['poll' => $poll->getId(), 'voters' => $voters];
			}
		);
		$this->configService = $this->createStub(ConfigService::class);
		$this->configService->method('getAppValue')->willReturnCallback(
			fn (string $key): string => $this->stored[$key] ?? ''
		);
		$this->configService->method('setAppValue')->willReturnCallback(
			function (string $key, string $value): void {
				$this->stored[$key] = $value;
			}
		);

		$this->service = new PollService(
			$this->streamRequest, $this->actionsRequest, $this->accountService,
			$this->cacheActorService, $this->activityService,
			$this->createStub(SignatureService::class),
			$this->streamActionService, $this->streamActionsRequest,
			$this->notificationService, $this->configService, new NullLogger()
		);
		$this->actionsRequest->method('getAction')->willReturn(new Note());
		$this->streamRequest->expects($this->never())->method('update');

		$this->assertTrue($this->service->handleIncomingVote($this->voteNote()));
		$this->assertSame(0, $poll->getVotersCount());
	}

	public function testAVoteForAnUnknownOptionIsConsumedSilently(): void {
		$poll = $this->localPoll();
		$this->streamRequest->expects($this->never())->method('update');

		$this->assertTrue($this->service->handleIncomingVote($this->voteNote('Fish')));
		$this->assertSame(0, $poll->getOptions()[0]['votes_count']);
	}

	public function testAnOrdinaryReplyIsNotAVote(): void {
		$note = $this->voteNote();
		$note->setName('');

		$this->assertFalse($this->service->handleIncomingVote($note));
	}

	public function testAReplyToARemotePollIsNotConsumed(): void {
		$question = new Question();
		$question->setId(self::POLL_ID);
		$question->setLocal(false);
		$question->setPollData(['Cats', 'Dogs'], false, 3600);
		$this->streamRequest->method('getStreamById')->willReturn($question);

		$this->assertFalse($this->service->handleIncomingVote($this->voteNote()));
	}

	/** An ActionsRequest that only knows about the votes named here. */
	private function votesAlreadyCast(array $options): void {
		$this->actionsRequest = $this->createStub(ActionsRequest::class);
		$this->notificationService = $this->createStub(NotificationService::class);
		$this->notificationService->method('onPollClosed')->willReturnCallback(
			function (Question $poll, array $voters): void {
				$this->announced[] = ['poll' => $poll->getId(), 'voters' => $voters];
			}
		);
		$this->configService = $this->createStub(ConfigService::class);
		$this->configService->method('getAppValue')->willReturnCallback(
			fn (string $key): string => $this->stored[$key] ?? ''
		);
		$this->configService->method('setAppValue')->willReturnCallback(
			function (string $key, string $value): void {
				$this->stored[$key] = $value;
			}
		);

		$this->service = new PollService(
			$this->streamRequest, $this->actionsRequest, $this->accountService,
			$this->cacheActorService, $this->activityService,
			$this->createStub(SignatureService::class),
			$this->streamActionService, $this->streamActionsRequest,
			$this->notificationService, $this->configService, new NullLogger()
		);
		$this->actionsRequest->method('getAction')
			->willReturnCallback(function (string $actorId, string $objectId) use ($options) {
				foreach ($options as $option) {
					if ($objectId === self::POLL_ID . '#option-' . $option) {
						return new Note();
					}
				}

				throw new ActionDoesNotExistException();
			});
	}

	public function testASecondIncomingVoteOnASingleChoicePollIsNotCounted(): void {
		$poll = $this->localPoll();
		$this->votesAlreadyCast([1]);
		$this->streamRequest->expects($this->never())->method('update');

		$this->assertTrue($this->service->handleIncomingVote($this->voteNote('Cats')));

		$this->assertSame(0, $poll->getOptions()[0]['votes_count']);
		$this->assertSame(0, $poll->getVotersCount());
	}

	public function testASecondIncomingVoteOnAMultipleChoicePollIsCounted(): void {
		$poll = $this->localPoll();
		$poll->setPollData(['Cats', 'Dogs'], true, 3600);
		$this->votesAlreadyCast([1]);
		$author = new Person();
		$author->setId(self::AUTHOR);
		$this->accountService->method('getFromId')->willReturn($author);
		$this->streamRequest->expects($this->once())->method('update');

		$this->assertTrue($this->service->handleIncomingVote($this->voteNote('Cats')));

		$this->assertSame(1, $poll->getOptions()[0]['votes_count']);
	}

	public function testANonPollStatusIsNotFound(): void {
		$note = new Note();
		$this->streamRequest->method('getStreamByNid')->willReturn($note);

		$this->expectException(StreamNotFoundException::class);

		$this->service->getPoll(42);
	}

	// the closed-poll sweep

	/** The service again, over whatever the test has replaced. */
	private function rebuild(): void {
		$this->service = new PollService(
			$this->streamRequest, $this->actionsRequest, $this->accountService,
			$this->cacheActorService, $this->activityService,
			$this->createStub(SignatureService::class),
			$this->streamActionService, $this->streamActionsRequest,
			$this->notificationService, $this->configService, new NullLogger()
		);
	}

	private function closedPoll(string $id, int $endedAt): Question {
		$poll = new Question();
		$poll->setId($id)->setAttributedTo('https://cloud.example/users/alice');
		// the end time is set by setPollData() relative to now, which is the
		// only way in: a poll that ended a minute ago was given a lifetime of
		// minus a minute
		$poll->setPollData(['yes', 'no'], false, $endedAt - time());

		return $poll;
	}

	/**
	 * A poll closes by its end time passing, so nothing happens at the moment
	 * it does: without a sweep, a voter never learns the result arrived.
	 */
	public function testTheSweepAnnouncesEveryPollThatClosed(): void {
		$poll = $this->closedPoll('https://cloud.example/polls/1', time() - 60);
		$this->streamRequest->method('getPollsClosedSince')->willReturn([$poll]);
		$this->actionsRequest->method('votersOfPolls')->willReturn([
			'https://cloud.example/polls/1' => ['https://cloud.example/users/bob'],
		]);

		$this->assertSame(1, $this->service->announceClosedPolls());
		$this->assertSame([[
			'poll' => 'https://cloud.example/polls/1',
			'voters' => ['https://cloud.example/users/bob'],
		]], $this->announced);
	}

	/** How far the sweep got is remembered, so the next one starts there. */
	public function testTheSweepRecordsHowFarItGot(): void {
		$this->streamRequest->method('getPollsClosedSince')->willReturn([]);

		$this->service->announceClosedPolls();

		$this->assertGreaterThan(
			time() - 5, (int)$this->stored[\OCA\Social\Service\ConfigService::SOCIAL_POLLS_SWEPT]
		);
	}

	/**
	 * Without a floor, the first sweep on an instance that has never run one
	 * would announce every poll that ever closed, to everybody who ever voted.
	 */
	public function testAFirstSweepDoesNotReachBackForEver(): void {
		$asked = 0;
		$this->streamRequest->method('getPollsClosedSince')->willReturnCallback(
			function (int $since) use (&$asked): array {
				$asked = $since;

				return [];
			}
		);

		$this->service->announceClosedPolls();

		$this->assertGreaterThanOrEqual(time() - PollService::SWEEP_FLOOR - 5, $asked);
	}

	/** A sweep that has run before starts where it left off. */
	public function testALaterSweepStartsWhereTheLastOneStopped(): void {
		$this->stored[\OCA\Social\Service\ConfigService::SOCIAL_POLLS_SWEPT] = (string)(time() - 600);
		$asked = 0;
		$this->streamRequest->method('getPollsClosedSince')->willReturnCallback(
			function (int $since) use (&$asked): array {
				$asked = $since;

				return [];
			}
		);

		$this->service->announceClosedPolls();

		$this->assertEqualsWithDelta(time() - 600, $asked, 5);
	}

	/** One poll failing does not stop the rest of the sweep. */
	public function testOnePollThatCannotBeAnnouncedDoesNotStopTheOthers(): void {
		$first = $this->closedPoll('https://cloud.example/polls/1', time() - 60);
		$second = $this->closedPoll('https://cloud.example/polls/2', time() - 30);
		$this->streamRequest->method('getPollsClosedSince')->willReturn([$first, $second]);
		$this->actionsRequest->method('votersOfPolls')->willReturn([]);
		$this->notificationService = $this->createStub(NotificationService::class);
		$this->notificationService->method('onPollClosed')->willReturnCallback(
			static function (Question $poll): void {
				if (str_ends_with($poll->getId(), '/1')) {
					throw new \Exception('no notification to be had');
				}
			}
		);
		$this->rebuild();

		$this->assertSame(1, $this->service->announceClosedPolls());
	}

	/**
	 * The voters of every closed poll are read in one query rather than one
	 * per poll, and each poll is announced to its own.
	 */
	public function testTheVotersOfEveryPollAreReadAtOnce(): void {
		$first = $this->closedPoll('https://cloud.example/polls/1', time() - 60);
		$second = $this->closedPoll('https://cloud.example/polls/2', time() - 30);
		$this->streamRequest->method('getPollsClosedSince')->willReturn([$first, $second]);
		$actionsRequest = $this->createMock(ActionsRequest::class);
		$actionsRequest->expects($this->once())->method('votersOfPolls')
			->with(['https://cloud.example/polls/1', 'https://cloud.example/polls/2'])
			->willReturn([
				'https://cloud.example/polls/1' => ['https://cloud.example/users/bob'],
				'https://cloud.example/polls/2' => [],
			]);
		$this->actionsRequest = $actionsRequest;
		$this->rebuild();

		$this->assertSame(2, $this->service->announceClosedPolls());
		$this->assertSame([
			['poll' => 'https://cloud.example/polls/1', 'voters' => ['https://cloud.example/users/bob']],
			['poll' => 'https://cloud.example/polls/2', 'voters' => []],
		], $this->announced);
	}

	/** A sweep whose voters cannot be read is not recorded, so it is tried again. */
	public function testASweepThatCannotReadTheVotersIsTriedAgain(): void {
		$this->stored[\OCA\Social\Service\ConfigService::SOCIAL_POLLS_SWEPT] = (string)(time() - 600);
		$poll = $this->closedPoll('https://cloud.example/polls/1', time() - 60);
		$this->streamRequest->method('getPollsClosedSince')->willReturn([$poll]);
		$this->actionsRequest->method('votersOfPolls')->willThrowException(new \Exception('database gone'));

		try {
			$this->service->announceClosedPolls();
			$this->fail('the failure was swallowed');
		} catch (\Exception) {
		}

		$this->assertSame((string)(time() - 600), $this->stored[\OCA\Social\Service\ConfigService::SOCIAL_POLLS_SWEPT]);
		$this->assertSame([], $this->announced);
	}

	/**
	 * A sweep cut off at its limit starts the next one where it stopped, so
	 * the polls that closed after the last one it read are not skipped.
	 */
	public function testASweepCutOffAtItsLimitResumesAfterTheLastPollItRead(): void {
		$this->stored[\OCA\Social\Service\ConfigService::SOCIAL_POLLS_SWEPT] = (string)(time() - 3600);
		$first = $this->closedPoll('https://cloud.example/polls/1', time() - 1800);
		$second = $this->closedPoll('https://cloud.example/polls/2', time() - 1200);
		$this->streamRequest->method('getPollsClosedSince')->willReturn([$first, $second]);
		$this->actionsRequest->method('votersOfPolls')->willReturn([]);

		$this->service->announceClosedPolls(2);

		$this->assertSame(
			(string)($second->getEndTimestamp() - 1),
			$this->stored[\OCA\Social\Service\ConfigService::SOCIAL_POLLS_SWEPT]
		);
	}
}
