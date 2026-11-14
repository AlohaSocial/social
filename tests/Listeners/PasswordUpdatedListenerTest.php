<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Listeners;

use OCA\Social\Db\AtprotoOAuthRequest;
use OCA\Social\External\ExternalUserBackend;
use OCA\Social\Listeners\PasswordUpdatedListener;
use OCA\Social\Service\ClientService;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\User\Backend\ISetPasswordBackend;
use OCP\User\Events\PasswordUpdatedEvent;
use OCP\User\Events\UserChangedEvent;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/** A changed or reset password signs every OAuth app out of the account. */
#[AllowMockObjectsWithoutExpectations]
class PasswordUpdatedListenerTest extends TestCase {
	private ClientService|MockObject $clientService;
	private AtprotoOAuthRequest|MockObject $atprotoOAuth;
	private LoggerInterface|MockObject $logger;
	private PasswordUpdatedListener $listener;

	protected function setUp(): void {
		$this->clientService = $this->createMock(ClientService::class);
		$this->atprotoOAuth = $this->createMock(AtprotoOAuthRequest::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new PasswordUpdatedListener($this->clientService, $this->atprotoOAuth, $this->logger);
	}

	private function user(string $uid): IUser {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}

	public function testAPasswordChangeRevokesTheMastodonAndBlueskyApps(): void {
		$this->clientService->expects($this->once())->method('revokeAllOf')->with('alice')->willReturn(2);
		$this->atprotoOAuth->expects($this->once())->method('deleteByUser')->with('alice');
		$this->logger->expects($this->once())->method('info');

		$this->listener->handle(new PasswordUpdatedEvent($this->user('alice'), 'n3w-passw0rd'));
	}

	public function testOtherEventsAreIgnored(): void {
		$this->clientService->expects($this->never())->method('revokeAllOf');
		$this->atprotoOAuth->expects($this->never())->method('deleteByUser');

		$this->listener->handle(new UserChangedEvent($this->user('alice'), 'avatar', ''));
		$this->listener->handle(new Event());
	}

	public function testABlueskyFailureDoesNotKeepTheMastodonAppsSignedIn(): void {
		$this->atprotoOAuth->method('deleteByUser')->willThrowException(new RuntimeException('db'));
		$this->clientService->expects($this->once())->method('revokeAllOf')->with('alice');
		$this->logger->expects($this->once())->method('error');

		$this->listener->handle(new PasswordUpdatedEvent($this->user('alice'), 'n3w-passw0rd'));
	}

	public function testAMastodonFailureStillSignsTheBlueskyAppsOut(): void {
		$this->clientService->method('revokeAllOf')->willThrowException(new RuntimeException('db'));
		$this->atprotoOAuth->expects($this->once())->method('deleteByUser')->with('alice');
		$this->logger->expects($this->once())->method('error');

		$this->listener->handle(new PasswordUpdatedEvent($this->user('alice'), 'n3w-passw0rd'));
	}

	/**
	 * A Social-only account changes and resets its password through
	 * Nextcloud's own forms, which reach `IUser::setPassword()`; that routes
	 * to the backend and dispatches `PasswordUpdatedEvent` only for a backend
	 * that sets passwords.
	 */
	public function testTheSocialOnlyBackendTakesPasswordChangesThroughNextcloud(): void {
		$this->assertTrue(is_subclass_of(ExternalUserBackend::class, ISetPasswordBackend::class));
	}
}
