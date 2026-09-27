<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Listeners;

use OCA\DAV\CardDAV\SyncService;
use OCA\DAV\Events\CardCreatedEvent;
use OCA\DAV\Events\CardUpdatedEvent;
use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\Social\External\ExternalUserBackend;
use OCA\Social\Listeners\ExternalAddressBookListener;
use OCA\Social\Listeners\ExternalDavListener;
use OCA\Social\Listeners\ExternalFirstLoginListener;
use OCA\Social\Listeners\ExternalNavigationListener;
use OCA\Social\Listeners\ExternalUserStatusListener;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Navigation\Events\NavigationEntriesFilterEvent;
use OCP\User\Events\UserEnumerationFilterEvent;
use OCP\User\Events\UserFirstTimeLoggedInEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Server;

class ExternalListenersTest extends TestCase {
	protected function tearDown(): void {
		\OC::$server->reset();
	}

	private function user(bool $external): IUser {
		$user = $this->createStub(IUser::class);
		$user->method('getBackend')->willReturn($external ? $this->createStub(ExternalUserBackend::class) : null);

		return $user;
	}

	private function session(?IUser $user): IUserSession {
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return $session;
	}

	private function system(): array {
		return ['principaluri' => 'principals/system/system', 'uri' => 'system'];
	}

	public function testAnExternalUsersCardLeavesTheSystemAddressBookAsSoonAsItIsWritten(): void {
		$sync = new SyncService();
		\OC::$server->register(SyncService::class, $sync);
		$listener = new ExternalAddressBookListener(new NullLogger());

		$listener->handle(new CardCreatedEvent(1, $this->system(), [], ['uri' => 'Social:alice.vcf']));
		$listener->handle(new CardUpdatedEvent(1, $this->system(), [], ['uri' => 'Social:alice.vcf']));

		$this->assertSame(['Social:alice.vcf', 'Social:alice.vcf'], $sync->deleted);
	}

	public function testEveryOtherCardIsLeftAlone(): void {
		$sync = new SyncService();
		\OC::$server->register(SyncService::class, $sync);
		$listener = new ExternalAddressBookListener(new NullLogger());

		$listener->handle(new CardCreatedEvent(1, $this->system(), [], ['uri' => 'Database:bob.vcf']));
		$listener->handle(new CardCreatedEvent(2, ['principaluri' => 'principals/users/bob', 'uri' => 'contacts'], [], ['uri' => 'Social:alice.vcf']));

		$this->assertSame([], $sync->deleted);
	}

	public function testExternalUsersAreTakenOutOfUserLists(): void {
		$backend = $this->createStub(ExternalUserBackend::class);
		$backend->method('userExists')->willReturnCallback(static fn (string $uid): bool => $uid === 'alice');
		$event = new UserEnumerationFilterEvent(['alice', 'bob', 'carol']);

		(new ExternalUserStatusListener($backend))->handle($event);

		$this->assertSame(['bob', 'carol'], $event->getUsers());
	}

	public function testOtherAppsNeverSeeTheFirstLoginOfAnExternalUser(): void {
		$external = new UserFirstTimeLoggedInEvent($this->user(true));
		$local = new UserFirstTimeLoggedInEvent($this->user(false));
		$listener = new ExternalFirstLoginListener();

		$listener->handle($external);
		$listener->handle($local);

		$this->assertTrue($external->isPropagationStopped());
		$this->assertFalse($local->isPropagationStopped());
	}

	public function testAnExternalUsersNavigationIsSocialTheirSettingsAndLoggingOut(): void {
		$entries = [
			'files' => ['id' => 'files', 'app' => 'files'],
			'social' => ['id' => 'social', 'app' => 'social'],
			'spreed' => ['id' => 'spreed', 'app' => 'spreed'],
			'settings_personal' => ['id' => 'settings_personal'],
			'accessibility_settings' => ['id' => 'accessibility_settings'],
			'help' => ['id' => 'help'],
			'profile' => ['id' => 'profile'],
			'logout' => ['id' => 'logout'],
		];
		$external = new NavigationEntriesFilterEvent($entries);
		$local = new NavigationEntriesFilterEvent($entries);

		(new ExternalNavigationListener($this->session($this->user(true))))->handle($external);
		(new ExternalNavigationListener($this->session($this->user(false))))->handle($local);

		$this->assertSame(['social', 'settings_personal', 'accessibility_settings', 'logout'], array_keys($external->getEntries()));
		$this->assertSame(array_keys($entries), array_keys($local->getEntries()));
		$this->assertSame(NavigationEntriesFilterEvent::class, ExternalNavigationListener::EVENT);
	}

	public function testDavRefusesAnExternalUserAfterAuthentication(): void {
		$server = new Server();
		(new ExternalDavListener($this->session($this->user(true))))->handle(new SabrePluginAddEvent($server));

		[$callback, $priority] = $server->listeners['beforeMethod:*'][0];
		// after Sabre's own authentication, which runs at 10
		$this->assertGreaterThan(10, $priority);

		$this->expectException(Forbidden::class);
		$callback();
	}

	public function testDavLetsEverybodyElseThrough(): void {
		$listener = new ExternalDavListener($this->session($this->user(false)));

		$listener->check();
		(new ExternalDavListener($this->session(null)))->check();

		$this->addToAssertionCount(1);
	}
}
