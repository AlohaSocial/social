<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Model\Moderation;
use OCA\Social\Service\AuditService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use PHPUnit\Framework\TestCase;

/**
 * What a moderator did, in a log the server keeps.
 *
 * `admin_audit` renders the message with `vsprintf` over the parameters in the
 * order they were given, so a message whose `%s` count does not match its
 * parameters is a fatal in core's listener rather than a mangled line here —
 * which is why every one of these asserts on the rendered sentence.
 */
class AuditServiceTest extends TestCase {
	/** @var CriticalActionPerformedEvent[] */
	private array $events = [];

	private function service(?string $uid): AuditService {
		$this->events = [];
		$dispatcher = $this->createStub(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(function (Event $event): void {
			$this->assertInstanceOf(CriticalActionPerformedEvent::class, $event);
			$this->events[] = $event;
		});

		$userSession = $this->createStub(IUserSession::class);
		if ($uid === null) {
			$userSession->method('getUser')->willReturn(null);
		} else {
			$user = $this->createStub(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$userSession->method('getUser')->willReturn($user);
		}

		return new AuditService($dispatcher, $userSession);
	}

	/** The line `admin_audit` would write. */
	private function line(): string {
		$this->assertCount(1, $this->events);
		$event = $this->events[0];

		return vsprintf($event->getLogMessage(), array_values($event->getParameters()));
	}

	public function testASuspensionNamesTheAccountAndTheModerator(): void {
		$this->service('carol')->accountDecided('https://spam.example/users/spammer', Moderation::SUSPEND);

		$this->assertSame(
			'Social: the account "https://spam.example/users/spammer" was suspended by "carol"',
			$this->line()
		);
	}

	public function testASilenceSaysSilenceRatherThanNamingTheLevel(): void {
		$this->service('carol')->accountDecided('https://spam.example/users/spammer', Moderation::SILENCE);

		$this->assertStringContainsString('was silenced by "carol"', $this->line());
	}

	/**
	 * The end of a decision is worth as much as the decision: a suspension
	 * nobody can see the end of cannot be told from one still in force.
	 */
	public function testALiftIsRecordedAsWellAsTheDecisionItEnds(): void {
		$this->service('carol')->accountLifted('https://spam.example/users/spammer');

		$this->assertSame(
			'Social: what stood against the account "https://spam.example/users/spammer" was lifted by "carol"',
			$this->line()
		);
	}

	public function testATakedownNamesThePost(): void {
		$this->service('carol')->postTakenDown('https://spam.example/p/1');

		$this->assertSame(
			'Social: the post "https://spam.example/p/1" was taken down by "carol"',
			$this->line()
		);
	}

	/**
	 * The same list means opposite things in the two access modes, so the
	 * sentence does too — "blocked" against an allow list would be exactly
	 * backwards.
	 */
	public function testTheAccessListReadsAsWhateverTheModeMakesIt(): void {
		$this->service('carol')->accessListChanged('noisy.test', true, true);
		$this->assertSame('Social: the instance "noisy.test" was blocked by "carol"', $this->line());

		$this->service('carol')->accessListChanged('noisy.test', false, true);
		$this->assertSame(
			'Social: the block on the instance "noisy.test" was lifted by "carol"', $this->line()
		);

		$this->service('carol')->accessListChanged('friend.test', true, false);
		$this->assertStringContainsString('added to the federation allow list', $this->line());

		$this->service('carol')->accessListChanged('friend.test', false, false);
		$this->assertStringContainsString('removed from the federation allow list', $this->line());
	}

	/** An occ command has no session, and an empty pair of quotes reads like a lost name. */
	public function testSomethingTakenFromTheCommandLineSaysSo(): void {
		$this->service(null)->accountLifted('https://spam.example/users/spammer');

		$this->assertStringContainsString('lifted by "the command line"', $this->line());
	}
}
