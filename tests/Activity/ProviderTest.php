<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Activity;

use OCA\Social\Activity\Provider;
use OCA\Social\Service\NotificationService;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ProviderTest extends TestCase {
	private IManager|Stub $activityManager;
	private Provider $provider;
	/** @var array<string, mixed> what the event under test was told */
	private array $told = [];

	protected function setUp(): void {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => '[de] ' . $text);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->with('social', 'de')->willReturn($l10n);

		$urlGenerator = $this->createStub(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $args): string => 'https://cloud.example/apps/social/@' . $args['username'] . '/'
		);
		$urlGenerator->method('imagePath')->willReturnCallback(static fn (string $app, string $file): string => '/apps/' . $app . '/img/' . $file);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://cloud.example' . $path);

		$this->activityManager = $this->createStub(IManager::class);

		$this->provider = new Provider($factory, $urlGenerator, $this->activityManager);
	}

	public function testALocalActorIsARichUser(): void {
		$event = $this->event('mention', ['account' => 'Carol', 'user' => 'carol', 'acct' => 'carol@cloud.example', 'excerpt' => 'Hi @alice']);

		$this->provider->parse('de', $event);

		$this->assertSame('[de] Carol mentioned you', $this->told['parsedSubject']);
		$this->assertSame('[de] {account} mentioned you', $this->told['richSubject']);
		$this->assertSame(['account' => ['type' => 'user', 'id' => 'carol', 'name' => 'Carol']], $this->told['richParameters']);
		$this->assertSame('Hi @alice', $this->told['parsedMessage']);
		$this->assertSame('https://cloud.example/apps/social/img/reply.svg', $this->told['icon']);
	}

	public function testARemoteActorIsAHighlightLinkingToTheProfilePageHere(): void {
		$event = $this->event('reblog', ['account' => 'Bob', 'user' => '', 'acct' => 'bob@remote.example', 'actor' => 'https://remote.example/users/bob']);

		$this->provider->parse('de', $event);

		$this->assertSame('[de] Bob boosted your post', $this->told['parsedSubject']);
		$this->assertSame([
			'account' => [
				'type' => 'highlight',
				'id' => 'bob@remote.example',
				'name' => 'Bob',
				'link' => 'https://cloud.example/apps/social/@bob@remote.example/',
			],
		], $this->told['richParameters']);
		$this->assertArrayNotHasKey('parsedMessage', $this->told, 'no post at hand, no message');
		$this->assertSame('https://cloud.example/apps/social/img/boost.svg', $this->told['icon']);
	}

	public function testAnActorKnownOnlyByIdIsStillNamed(): void {
		$event = $this->event('follow', ['account' => 'https://remote.example/users/x', 'user' => '', 'acct' => '', 'actor' => 'https://remote.example/users/x']);

		$this->provider->parse('de', $event);

		$this->assertSame(['type' => 'highlight', 'id' => 'https://remote.example/users/x', 'name' => 'https://remote.example/users/x'], $this->told['richParameters']['account']);
		$this->assertSame('https://cloud.example/apps/social/img/add_user.svg', $this->told['icon']);
	}

	public function testASubjectWithoutAnActorHasNoParameter(): void {
		$event = $this->event('poll', ['account' => 'Alice', 'user' => 'alice', 'excerpt' => 'Tea or coffee?']);

		$this->provider->parse('de', $event);

		$this->assertSame('[de] A poll you took part in has ended', $this->told['parsedSubject']);
		$this->assertSame([], $this->told['richParameters']);
		$this->assertSame('Tea or coffee?', $this->told['parsedMessage']);
	}

	public function testTheMailGetsABitmap(): void {
		$this->activityManager->method('getRequirePNG')->willReturn(true);

		$this->provider->parse('de', $this->event('favourite', ['account' => 'Bob', 'user' => '']));

		$this->assertSame('https://cloud.example/apps/social/img/nextcloud.png', $this->told['icon']);
	}

	public function testEverySubjectTheBellKnowsIsWorded(): void {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$templates = Provider::templates($l10n);
		foreach (NotificationService::SUBJECTS as $subject) {
			$this->assertArrayHasKey($subject, $templates, $subject . ' has no wording');
		}
		foreach (['poll', 'status'] as $subject) {
			$this->assertArrayHasKey($subject, $templates, $subject . ' — the bell is being taught it');
		}
	}

	public function testAnotherAppsEventIsNotOurs(): void {
		$event = $this->createStub(IEvent::class);
		$event->method('getApp')->willReturn('comments');

		$this->expectException(UnknownActivityException::class);
		$this->provider->parse('de', $event);
	}

	public function testAnUnknownSubjectIsNotWorded(): void {
		$this->expectException(UnknownActivityException::class);
		$this->provider->parse('de', $this->event('something_else', []));
	}

	private function event(string $subject, array $parameters): IEvent {
		$event = $this->createMock(IEvent::class);
		$event->method('getApp')->willReturn('social');
		$event->method('getSubject')->willReturn($subject);
		$event->method('getSubjectParameters')->willReturn($parameters);
		$event->method('setParsedSubject')->willReturnCallback(function (string $s) use ($event): IEvent {
			$this->told['parsedSubject'] = $s;

			return $event;
		});
		$event->method('setRichSubject')->willReturnCallback(function (string $s, array $p = []) use ($event): IEvent {
			$this->told['richSubject'] = $s;
			$this->told['richParameters'] = $p;

			return $event;
		});
		$event->method('setParsedMessage')->willReturnCallback(function (string $s) use ($event): IEvent {
			$this->told['parsedMessage'] = $s;

			return $event;
		});
		$event->method('setRichMessage')->willReturnCallback(function (string $s, array $p = []) use ($event): IEvent {
			$this->told['richMessage'] = $s;

			return $event;
		});
		$event->method('setIcon')->willReturnCallback(function (string $s) use ($event): IEvent {
			$this->told['icon'] = $s;

			return $event;
		});

		return $event;
	}
}
