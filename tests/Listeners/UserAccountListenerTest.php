<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Listeners;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Listeners\UserAccountListener;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCP\Accounts\UserUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class UserAccountListenerTest extends TestCase {
	/** @var ActorsRequest&MockObject */
	private $actorsRequest;
	/** @var AccountService&MockObject */
	private $accountService;
	/** @var LoggerInterface&MockObject */
	private $logger;
	private UserAccountListener $listener;

	protected function setUp(): void {
		$this->actorsRequest = $this->createMock(ActorsRequest::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new UserAccountListener(
			$this->actorsRequest, $this->accountService, $this->logger
		);
	}

	/** The account of a user, under a handle that need not be their user id. */
	private function actor(string $handle, string $userId): Person {
		$actor = new Person();
		$actor->setPreferredUsername($handle);
		$actor->setUserId($userId);
		$this->actorsRequest->method('getFromUserId')->with($userId)->willReturn($actor);

		return $actor;
	}

	private function userUpdated(string $uid): UserUpdatedEvent {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return new UserUpdatedEvent($user, ['displayname' => 'New Name']);
	}

	public function testAccountUpdateRefreshesTheCachedLocalActor(): void {
		$this->actor('alice', 'alice');
		$this->accountService->expects($this->once())->method('cacheLocalActorByUsername')->with('alice');
		$this->logger->expects($this->never())->method('warning');

		$this->listener->handle($this->userUpdated('alice'));
	}

	/**
	 * A user id that is not a valid handle — an address, an id with a space, a
	 * non-latin one — or one that collided with a handle already taken got a
	 * derived handle. Asking for the cached actor under the *user id* found
	 * nothing, and the failure was swallowed: the display name, picture and
	 * profile changes of exactly those accounts were never republished.
	 */
	public function testTheActorIsFoundByUserIdRatherThanByHandle(): void {
		$this->actor('jane_doe_2', 'jane.doe@example.org');
		$this->accountService->expects($this->once())
			->method('cacheLocalActorByUsername')->with('jane_doe_2');

		$this->listener->handle($this->userUpdated('jane.doe@example.org'));
	}

	public function testOtherEventsAreIgnored(): void {
		$this->accountService->expects($this->never())->method('cacheLocalActorByUsername');

		$this->listener->handle(new Event());
	}

	/** A user who never opened Social has nothing here to republish. */
	public function testAUserWithoutASocialAccountIsNotAnError(): void {
		$this->actorsRequest->method('getFromUserId')
			->willThrowException(new ActorDoesNotExistException('never opened it'));
		$this->accountService->expects($this->never())->method('cacheLocalActorByUsername');
		$this->logger->expects($this->never())->method('warning');

		$this->listener->handle($this->userUpdated('bob'));
	}

	public function testALookupFailureIsLoggedNotThrown(): void {
		$this->actorsRequest->method('getFromUserId')
			->willThrowException(new \RuntimeException('database busy'));
		$this->logger->expects($this->once())->method('warning')
			->with('could not look up the Social account of an updated user', $this->anything());

		$this->listener->handle($this->userUpdated('bob'));
	}

	public function testRefreshFailuresAreLoggedNotThrown(): void {
		$this->actor('bob', 'bob');
		$exception = new AccountDoesNotExistException('no social account');
		$this->accountService->method('cacheLocalActorByUsername')->willThrowException($exception);
		$this->logger->expects($this->once())->method('warning')
			->with('issue while updating user account', ['exception' => $exception]);

		$this->listener->handle($this->userUpdated('bob'));
	}
}
