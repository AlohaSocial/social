<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Notification;

use OCA\Social\Notification\Notifier;
use OCA\Social\Service\NotificationService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * What the bell says about something that happened to a Social account.
 *
 * The subjects are the ones `NotificationService` raises, so the two are
 * asserted against each other here: a subject nothing renders reaches the user
 * as a thrown InvalidArgumentException and no notification at all.
 */
#[AllowMockObjectsWithoutExpectations]
class NotifierSubjectsTest extends TestCase {
	private const APP_ICON = 'https://cloud.example/apps/social/img/social_dark.svg';
	private const POST = 'https://cloud.example/@alice/post-1';
	private const AVATAR = 'https://cloud.example/apps/social/media/bob-avatar';

	/** @var IFactory&Stub */
	private $factory;
	/** @var IURLGenerator&Stub */
	private $urlGenerator;
	private Notifier $notifier;

	/** @var array<string, string> what the notification was told to render */
	private array $rendered = [];

	protected function setUp(): void {
		$this->factory = $this->createStub(IFactory::class);
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			fn (string $text, $params = []): string
				=> ((array)$params === []) ? $text : vsprintf($text, (array)$params)
		);
		$this->factory->method('get')->willReturn($l10n);

		$this->urlGenerator = $this->createStub(IURLGenerator::class);
		$this->urlGenerator->method('imagePath')->willReturnCallback(
			static fn (string $app, string $file): string => '/apps/' . $app . '/img/' . $file
		);
		$this->urlGenerator->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => 'https://cloud.example' . $path
		);

		$this->notifier = new Notifier(
			$this->createStub(IL10N::class),
			$this->factory,
			$this->urlGenerator
		);
	}

	/** @return INotification&MockObject */
	private function notification(string $subject, array $params): INotification {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('social');
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($params);

		foreach (['setParsedSubject' => 'subject', 'setLink' => 'link', 'setIcon' => 'icon'] as $method => $field) {
			$notification->method($method)->willReturnCallback(
				function (string $value) use ($notification, $field): INotification {
					$this->rendered[$field] = $value;

					return $notification;
				}
			);
		}

		return $notification;
	}

	private function params(array $overrides = []): array {
		return array_merge(
			['account' => 'Bob', 'link' => self::POST, 'avatar' => self::AVATAR], $overrides
		);
	}

	public static function subjectProvider(): array {
		return [
			'mention' => ['mention', 'Bob mentioned you in a post'],
			'favourite' => ['favourite', 'Bob favourited your post'],
			'reblog' => ['reblog', 'Bob boosted your post'],
			'follow' => ['follow', 'Bob is now following you'],
			'follow_request' => ['follow_request', 'Bob wants to follow you'],
			'update' => ['update', 'Bob edited a post you boosted'],
		];
	}

	#[DataProvider('subjectProvider')]
	public function testEachSubjectSaysWhoDidWhat(string $subject, string $expected): void {
		$this->notifier->prepare($this->notification($subject, $this->params()), 'en');

		$this->assertSame($expected, $this->rendered['subject']);
	}

	/** @return iterable<string, array{string}> the same subjects, without what they say */
	public static function subjects(): iterable {
		foreach (self::subjectProvider() as $name => [$subject]) {
			yield $name => [$subject];
		}
	}

	#[DataProvider('subjects')]
	public function testEachSubjectPointsAtWhatItIsAbout(string $subject): void {
		$this->notifier->prepare($this->notification($subject, $this->params()), 'en');

		$this->assertSame(self::POST, $this->rendered['link']);
		$this->assertSame(self::AVATAR, $this->rendered['icon']);
	}

	public function testEverySubjectTheServiceRaisesIsRendered(): void {
		foreach (NotificationService::SUBJECTS as $subject) {
			$this->rendered = [];
			$this->notifier->prepare($this->notification($subject, $this->params()), 'en');

			$this->assertArrayHasKey(
				'subject',
				$this->rendered,
				$subject . ' is raised by NotificationService and worded nowhere'
			);
		}
	}

	public function testAnAccountThatCouldNotBeNamedStillSaysWhatHappened(): void {
		$this->notifier->prepare(
			$this->notification('favourite', $this->params(['account' => ''])), 'en'
		);

		$this->assertSame(' favourited your post', $this->rendered['subject']);
	}

	public static function nonUrlProvider(): array {
		return [
			'a scheme that is not the web' => ['javascript:alert(1)'],
			'a path' => ['/apps/social'],
			'nothing at all' => [''],
		];
	}

	#[DataProvider('nonUrlProvider')]
	public function testNothingButAWebUrlBecomesTheLink(string $link): void {
		$this->notifier->prepare(
			$this->notification('mention', $this->params(['link' => $link])), 'en'
		);

		$this->assertArrayNotHasKey('link', $this->rendered);
	}

	#[DataProvider('nonUrlProvider')]
	public function testNothingButAWebUrlBecomesTheIcon(string $avatar): void {
		$this->notifier->prepare(
			$this->notification('mention', $this->params(['avatar' => $avatar])), 'en'
		);

		$this->assertSame('https://cloud.example/apps/social/img/reply.svg', $this->rendered['icon']);
	}

	public static function actionIconProvider(): array {
		return [
			'mention' => ['mention', 'reply.svg'],
			'favourite' => ['favourite', 'favourite.svg'],
			'reblog' => ['reblog', 'boost.svg'],
			'follow' => ['follow', 'follow.svg'],
			'follow_request' => ['follow_request', 'follow_request.svg'],
			'poll' => ['poll', 'poll.svg'],
			'status' => ['status', 'notifications.svg'],
			'update' => ['update', 'edit.svg'],
			'report_new' => ['report_new', 'report.svg'],
			'moderation_warning' => ['moderation_warning', 'moderation.svg'],
		];
	}

	/** alohasocial/social#2483: what happened, when who did it has no picture here. */
	#[DataProvider('actionIconProvider')]
	public function testWithoutAnAvatarTheIconShowsWhatHappened(string $subject, string $file): void {
		$this->notifier->prepare($this->notification($subject, $this->params(['avatar' => ''])), 'en');

		$this->assertSame('https://cloud.example/apps/social/img/' . $file, $this->rendered['icon']);
		$this->assertFileExists(__DIR__ . '/../../img/' . $file);
	}

	public function testEverySubjectTheServiceRaisesHasAnActionIcon(): void {
		foreach (NotificationService::SUBJECTS as $subject) {
			$this->rendered = [];
			$this->notifier->prepare($this->notification($subject, $this->params(['avatar' => ''])), 'en');

			$this->assertNotSame(self::APP_ICON, $this->rendered['icon'], $subject . ' shows the app icon');
		}
	}

	public function testAnAvatarOnAnotherServerIsNotLoaded(): void {
		// a stored notification may name the picture on the account's own
		// server, which the web interface's content policy would refuse
		$this->notifier->prepare(
			$this->notification('favourite', $this->params(['avatar' => 'https://remote.example/avatars/bob.png'])), 'en'
		);

		$this->assertSame('https://cloud.example/apps/social/img/favourite.svg', $this->rendered['icon']);
	}

	public function testTheLocalAvatarRouteIsLoaded(): void {
		$avatar = 'https://cloud.example/index.php/avatar/bob/64';
		$this->notifier->prepare($this->notification('reblog', $this->params(['avatar' => $avatar])), 'en');

		$this->assertSame($avatar, $this->rendered['icon']);
	}

	public function testTheDigestKeepsTheAppIcon(): void {
		$this->notifier->prepare(
			$this->notification('digest', ['total' => 3, 'counts' => ['favourite' => 3], 'link' => self::POST]), 'en'
		);

		$this->assertSame(self::APP_ICON, $this->rendered['icon']);
	}

	public function testASubjectNothingRaisesIsStillRejected(): void {
		$this->expectException(\InvalidArgumentException::class);

		// `severed_relationships` reaches the client API but no bell is raised for it
		$this->notifier->prepare($this->notification('severed_relationships', $this->params()), 'en');
	}
}
