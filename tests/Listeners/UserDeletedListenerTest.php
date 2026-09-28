<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Listeners;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Listeners\UserDeletedListener;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ConfigService;
use OCP\EventDispatcher\Event;
use OCP\IConfig;
use OCP\IUser;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Deleting the Nextcloud account has to take the Fediverse one with it. Left
 * behind, the actor still resolves over WebFinger, the posts stay readable and
 * remote servers keep delivering to an inbox nobody owns.
 */
#[AllowMockObjectsWithoutExpectations]
class UserDeletedListenerTest extends TestCase {
	private ActorsRequest|MockObject $actorsRequest;
	private AccountService|MockObject $accountService;
	private IConfig|MockObject $config;
	private LoggerInterface|MockObject $logger;
	private UserDeletedListener $listener;

	protected function setUp(): void {
		$this->actorsRequest = $this->createMock(ActorsRequest::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->config = $this->createMock(IConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->listener = new UserDeletedListener(
			$this->actorsRequest, $this->accountService, $this->config, $this->logger
		);
	}

	private function deletionOf(string $uid): UserDeletedEvent {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return new UserDeletedEvent($user);
	}

	private function actor(string $username): Person {
		$actor = new Person();
		$actor->setPreferredUsername($username);
		$actor->setId('https://cloud.example/apps/social/@' . $username);

		return $actor;
	}

	public function testDeletingAUserDeletesTheirSocialAccount(): void {
		$this->actorsRequest->method('getFromUserId')->with('alice')
			->willReturn($this->actor('alice'));

		// deleteActor() marks the actor gone, drops what belongs to it and
		// federates a Delete so cached copies elsewhere go too
		$this->accountService->expects($this->once())->method('deleteActor')->with('alice');

		$this->listener->handle($this->deletionOf('alice'));
	}

	public function testTheActorsOwnHandleIsUsedRatherThanTheUserId(): void {
		// the two are not always the same, and deleteActor() takes the handle
		$this->actorsRequest->method('getFromUserId')->with('user-42')
			->willReturn($this->actor('alice'));

		$this->accountService->expects($this->once())->method('deleteActor')->with('alice');

		$this->listener->handle($this->deletionOf('user-42'));
	}

	public function testAUserWhoNeverOpenedSocialIsNotAProblem(): void {
		$this->actorsRequest->method('getFromUserId')
			->willThrowException(new ActorDoesNotExistException());

		$this->accountService->expects($this->never())->method('deleteActor');
		$this->logger->expects($this->never())->method('error');

		$this->listener->handle($this->deletionOf('bob'));
	}

	public function testAccountDeletionAlsoPurgesTheSignupAcknowledgementRecord(): void {
		$this->actorsRequest->method('getFromUserId')->willThrowException(new ActorDoesNotExistException());
		$deleted = [];
		$this->config->expects($this->exactly(3))->method('deleteUserValue')->willReturnCallback(
			static function (string $uid, string $app, string $key) use (&$deleted): void {
				$deleted[] = [$uid, $app, $key];
			}
		);

		$this->listener->handle($this->deletionOf('alice'));

		$this->assertSame([
			['alice', 'social', ConfigService::USER_EXTERNAL_SIGNUP_NOTICE_VERSION],
			['alice', 'social', ConfigService::USER_EXTERNAL_SIGNUP_NOTICE_ACCEPTED],
			['alice', 'social', ConfigService::USER_EXTERNAL_SIGNUP_NOTICE_SNAPSHOT],
		], $deleted);
	}

	public function testFailureToClearNoticeRecordDoesNotStopSocialCleanup(): void {
		$this->config->method('deleteUserValue')->willThrowException(new \RuntimeException('database busy'));
		$this->actorsRequest->method('getFromUserId')->willReturn($this->actor('alice'));
		$this->accountService->expects($this->once())->method('deleteActor')->with('alice');
		$this->logger->expects($this->once())->method('warning');

		$this->listener->handle($this->deletionOf('alice'));
	}

	public function testAFailureToDeleteIsLoggedRatherThanThrown(): void {
		$this->actorsRequest->method('getFromUserId')->willReturn($this->actor('alice'));
		$this->accountService->method('deleteActor')
			->willThrowException(new \RuntimeException('the queue is on fire'));

		// the Nextcloud user is already gone; throwing here would leave the
		// deletion half-applied with nobody able to finish it
		$this->logger->expects($this->once())->method('error');

		$this->listener->handle($this->deletionOf('alice'));
	}

	public function testALookupFailureIsLoggedAndStopsThere(): void {
		$this->actorsRequest->method('getFromUserId')
			->willThrowException(new \RuntimeException('database gone'));

		$this->accountService->expects($this->never())->method('deleteActor');
		$this->logger->expects($this->once())->method('error');

		$this->listener->handle($this->deletionOf('alice'));
	}

	public function testItIgnoresEventsThatAreNotADeletion(): void {
		$this->accountService->expects($this->never())->method('deleteActor');

		$this->listener->handle(new Event());
	}
}
