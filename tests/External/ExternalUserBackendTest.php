<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\External;

use OCA\Social\Db\ExternalUsersRequest;
use OCA\Social\External\ExternalUserBackend;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUser;
use OCP\Security\Events\ValidatePasswordPolicyEvent;
use OCP\Security\IHasher;
use OCP\User\Backend\ICreateUserBackend;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ExternalUserBackendTest extends TestCase {
	private ExternalUsersRequest&MockObject $request;
	private IHasher&MockObject $hasher;
	private IEventDispatcher&MockObject $dispatcher;
	private ExternalUserBackend $backend;

	protected function setUp(): void {
		$this->request = $this->createMock(ExternalUsersRequest::class);
		$this->hasher = $this->createMock(IHasher::class);
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->backend = new ExternalUserBackend($this->request, $this->hasher, $this->dispatcher);
	}

	private function row(string $uid = 'alice', string $hash = 'HASH', string $name = ''): array {
		return ['uid' => $uid, 'password' => $hash, 'displayname' => $name, 'origin' => 'open', 'creation' => 1];
	}

	public function testItIsNamedSocialAndCannotCreateUsers(): void {
		$this->assertSame('Social', $this->backend->getBackendName());
		// accounts come from the registration flow only, never from the Users page
		$this->assertNotInstanceOf(ICreateUserBackend::class, $this->backend);
	}

	public function testALookupIsMadeOncePerRequestMissesIncluded(): void {
		$this->request->expects($this->exactly(2))->method('get')
			->willReturnMap([['alice', $this->row()], ['bob', null]]);

		$this->assertTrue($this->backend->userExists('alice'));
		$this->assertTrue($this->backend->userExists('ALICE'));
		$this->assertFalse($this->backend->userExists('bob'));
		$this->assertFalse($this->backend->userExists('bob'));
	}

	public function testTheEmptyUserIdIsNobodyWithoutAQuery(): void {
		$this->request->expects($this->never())->method('get');

		$this->assertFalse($this->backend->userExists(''));
	}

	public function testTheRightPasswordLogsInUnderTheStoredSpelling(): void {
		$this->request->method('get')->willReturn($this->row('Alice'));
		$this->hasher->method('verify')->with('secret', 'HASH')->willReturn(true);

		$this->assertSame('Alice', $this->backend->checkPassword('alice', 'secret'));
	}

	public function testTheWrongPasswordDoesNot(): void {
		$this->request->method('get')->willReturn($this->row());
		$this->hasher->method('verify')->willReturn(false);

		$this->assertFalse($this->backend->checkPassword('alice', 'nope'));
	}

	public function testAnUnknownLoginDoesNotReachTheHasher(): void {
		$this->request->method('get')->willReturn(null);
		$this->hasher->expects($this->never())->method('verify');

		$this->assertFalse($this->backend->checkPassword('nobody', 'secret'));
	}

	public function testAnOutdatedHashIsReplacedOnLogin(): void {
		$this->request->method('get')->willReturn($this->row());
		$this->hasher->method('verify')->willReturnCallback(
			static function (string $message, string $hash, &$newHash = null): bool {
				$newHash = 'NEWHASH';

				return true;
			}
		);
		$this->request->expects($this->once())->method('setPasswordHash')->with('alice', 'NEWHASH');

		$this->assertSame('alice', $this->backend->checkPassword('alice', 'secret'));
	}

	public function testANewPasswordPassesThePolicyAndIsStoredHashed(): void {
		$this->request->method('get')->willReturn($this->row());
		$this->dispatcher->expects($this->once())->method('dispatchTyped')
			->with($this->isInstanceOf(ValidatePasswordPolicyEvent::class));
		$this->hasher->method('hash')->with('new secret')->willReturn('H2');
		$this->hasher->method('validate')->with('H2')->willReturn(true);
		$this->request->expects($this->once())->method('setPasswordHash')->with('alice', 'H2')->willReturn(true);

		$this->assertTrue($this->backend->setPassword('alice', 'new secret'));
	}

	public function testAHashTheServerCannotVerifyIsRefused(): void {
		$this->hasher->method('validate')->willReturn(false);
		$this->request->expects($this->never())->method('setPasswordHash');

		$this->expectException(\InvalidArgumentException::class);
		$this->backend->setPasswordHash('alice', 'not a hash');
	}

	public function testThePasswordHashIsHandedOverForPromotion(): void {
		$this->request->method('get')->willReturn($this->row());

		$this->assertSame('HASH', $this->backend->getPasswordHash('alice'));
	}

	public function testTheDisplayNameFallsBackToTheUserId(): void {
		$this->request->method('get')->willReturnMap([['alice', $this->row()], ['bob', $this->row('bob', 'H', 'Bob B')]]);

		$this->assertSame('alice', $this->backend->getDisplayName('alice'));
		$this->assertSame('Bob B', $this->backend->getDisplayName('bob'));
	}

	public function testAnEmptyOrOverlongDisplayNameIsRefused(): void {
		$this->request->method('get')->willReturn($this->row());
		$this->request->expects($this->never())->method('setDisplayName');

		$this->assertFalse($this->backend->setDisplayName('alice', '   '));
		$this->assertFalse($this->backend->setDisplayName('alice', str_repeat('x', 65)));
	}

	public function testListingsAreTheSearchOfTheTable(): void {
		$this->request->method('search')->with('al', 10, 0)->willReturn([$this->row(), $this->row('alan', 'H', 'Alan')]);

		$this->assertSame(['alice', 'alan'], $this->backend->getUsers('al', 10, 0));
		$this->assertSame(['alice' => 'alice', 'alan' => 'Alan'], $this->backend->getDisplayNames('al', 10, 0));
		$this->assertTrue($this->backend->hasUserListings());
	}

	public function testDeletingForgetsTheCachedRow(): void {
		$this->request->method('get')->willReturnOnConsecutiveCalls($this->row(), null);
		$this->request->expects($this->once())->method('delete')->with('alice')->willReturn(true);

		$this->assertTrue($this->backend->userExists('alice'));
		$this->assertTrue($this->backend->deleteUser('alice'));
		$this->assertFalse($this->backend->userExists('alice'));
	}

	public function testAnExternalUserIsOneOnThisBackend(): void {
		$external = $this->createStub(IUser::class);
		$external->method('getBackend')->willReturn($this->backend);
		$local = $this->createStub(IUser::class);
		$local->method('getBackend')->willReturn(null);

		$this->assertTrue(ExternalUserBackend::isExternal($external));
		$this->assertFalse(ExternalUserBackend::isExternal($local));
		$this->assertFalse(ExternalUserBackend::isExternal(null));
	}
}
