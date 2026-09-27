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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * What the bell says about something that happened to a Social account.
 *
 * The subjects are the ones `NotificationService` raises, so the two are
 * asserted against each other here: a subject nothing renders reaches the user
 * as a thrown InvalidArgumentException and no notification at all.
 */
class NotifierSubjectsTest extends TestCase {
	private const APP_ICON = 'https://cloud.example/apps/social/img/social_dark.svg';
	private const POST = 'https://cloud.example/@alice/post-1';
	private const AVATAR = 'https://remote.example/avatars/bob.png';

	/** @var IFactory&MockObject */
	private $factory;
	/** @var IURLGenerator&MockObject */
	private $urlGenerator;
	private Notifier $notifier;

	/** @var array<string, string> what the notification was told to render */
	private array $rendered = [];

	protected function setUp(): void {
		$this->factory = $this->createMock(IFactory::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			fn (string $text, $params = []): string
				=> ((array)$params === []) ? $text : vsprintf($text, (array)$params)
		);
		$this->factory->method('get')->willReturn($l10n);

		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('imagePath')->willReturn('/apps/social/img/social_dark.svg');
		$this->urlGenerator->method('getAbsoluteURL')->willReturn(self::APP_ICON);

		$this->notifier = new Notifier(
			$this->createMock(IL10N::class),
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

	#[DataProvider('subjectProvider')]
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

		$this->assertSame(self::APP_ICON, $this->rendered['icon']);
	}

	public function testASubjectNothingRaisesIsStillRejected(): void {
		$this->expectException(\InvalidArgumentException::class);

		// `severed_relationships` reaches the client API but no bell is raised for it
		$this->notifier->prepare($this->notification('severed_relationships', $this->params()), 'en');
	}
}
