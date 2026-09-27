<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Listeners;

use OCA\Social\Listeners\ProfileSectionListener;
use OCP\Accounts\UserUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProfileSectionListenerTest extends TestCase {
	public function testIsAnEventListener(): void {
		$this->assertInstanceOf(IEventListener::class, new ProfileSectionListener());
	}

	/**
	 * Only the name of the event to build: a static provider cannot call
	 * `createMock()`, and `UserUpdatedEvent` needs an `IUser`.
	 *
	 * @return iterable<string, array{string}>
	 */
	public static function unrelatedEvents(): iterable {
		yield 'generic event' => ['generic'];
		yield 'account update' => ['user-updated'];
	}

	/**
	 * Registering the profile script needs the server's script registry, so an
	 * unrelated event must return before reaching it.
	 */
	#[DataProvider('unrelatedEvents')]
	public function testUnrelatedEventsAreIgnored(string $eventKind): void {
		$event = match ($eventKind) {
			'generic' => new Event(),
			'user-updated' => new UserUpdatedEvent($this->createStub(IUser::class), []),
		};

		(new ProfileSectionListener())->handle($event);

		$this->addToAssertionCount(1);
	}
}
