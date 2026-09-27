<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\External;

use OCA\Social\External\ExternalDavGuard;
use OCA\Social\External\ExternalUserBackend;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class ExternalDavGuardTest extends TestCase {
	private function user(bool $external): IUser {
		$user = $this->createStub(IUser::class);
		$user->method('getBackend')->willReturn($external ? $this->createStub(ExternalUserBackend::class) : null);

		return $user;
	}

	private function guard(string $script, ?IUser $session = null, string $authorization = '', array $users = [], array $byEmail = []): ExternalDavGuard {
		$request = $this->createStub(IRequest::class);
		$request->method('getScriptName')->willReturn($script);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => ($name === 'Authorization') ? $authorization : ''
		);
		$userSession = $this->createStub(IUserSession::class);
		$userSession->method('getUser')->willReturn($session);
		$userManager = $this->createStub(IUserManager::class);
		$userManager->method('get')->willReturnCallback(static fn (string $uid): ?IUser => $users[$uid] ?? null);
		$userManager->method('getByEmail')->willReturnCallback(static fn (string $email): array => $byEmail[$email] ?? []);

		return new ExternalDavGuard($request, $userSession, $userManager);
	}

	public function testOnlyRemotePhpIsGuarded(): void {
		$this->assertFalse($this->guard('/index.php', $this->user(true))->refuses());
		$this->assertFalse($this->guard('/ocs/v2.php', $this->user(true))->refuses());
		$this->assertFalse($this->guard('/public.php', $this->user(true))->refuses());
	}

	public function testABrowserSessionOfAnExternalUserIsRefused(): void {
		$this->assertTrue($this->guard('/remote.php', $this->user(true))->refuses());
		$this->assertTrue($this->guard('/nextcloud/remote.php', $this->user(true))->refuses());
		$this->assertFalse($this->guard('/remote.php', $this->user(false))->refuses());
	}

	public function testBasicCredentialsNamingAnExternalUserAreRefused(): void {
		$basic = 'Basic ' . base64_encode('alice:app-password');

		$this->assertTrue($this->guard('/remote.php', null, $basic, ['alice' => $this->user(true)])->refuses());
		$this->assertFalse($this->guard('/remote.php', null, $basic, ['alice' => $this->user(false)])->refuses());
		$this->assertFalse($this->guard('/remote.php', null, $basic)->refuses());
	}

	public function testALoginByEmailIsResolvedToItsUser(): void {
		$basic = 'Basic ' . base64_encode('alice@example.org:secret');

		$this->assertTrue($this->guard('/remote.php', null, $basic, [], ['alice@example.org' => [$this->user(true)]])->refuses());
	}

	public function testAnythingButBasicCredentialsIsLeftToTheDavServer(): void {
		$this->assertFalse($this->guard('/remote.php', null, 'Bearer abc')->refuses());
		$this->assertFalse($this->guard('/remote.php', null, 'Basic !!!not-base64')->refuses());
		$this->assertFalse($this->guard('/remote.php', null, 'Basic ' . base64_encode('no-colon'))->refuses());
		$this->assertFalse($this->guard('/remote.php')->refuses());
	}
}
