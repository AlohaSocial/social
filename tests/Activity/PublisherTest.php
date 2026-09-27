<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Activity;

use OCA\Social\Activity\Publisher;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCP\Activity\IEvent;
use OCP\Activity\IManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class PublisherTest extends TestCase {
	private IManager|Stub $activityManager;
	private Publisher $publisher;
	/** @var array<string, mixed> */
	private array $event = [];
	private int $published = 0;

	protected function setUp(): void {
		$this->activityManager = $this->createStub(IManager::class);
		$this->activityManager->method('generateEvent')->willReturnCallback(fn (): IEvent => $this->recorder());
		$this->activityManager->method('publish')->willReturnCallback(function (): void {
			$this->published++;
		});

		$this->publisher = new Publisher($this->activityManager, new NullLogger());
	}

	public function testAnEntryCarriesWhatTheProviderNeeds(): void {
		$bob = (new Person())->setName('Bob')->setAccount('bob@remote.example')->setAvatar('https://remote.example/bob.png');

		$this->publisher->publish('alice', 'favourite', $bob, 'https://remote.example/users/bob', 'https://cloud.example/apps/social/@alice/42', 'Hello world', 501);

		$this->assertSame(1, $this->published);
		$this->assertSame('social', $this->event['app']);
		$this->assertSame('social', $this->event['type']);
		$this->assertSame('alice', $this->event['affectedUser']);
		$this->assertSame(['social_notification', 501], $this->event['object']);
		$this->assertSame('https://cloud.example/apps/social/@alice/42', $this->event['link']);
		$this->assertSame('favourite', $this->event['subject']);
		$this->assertSame([
			'account' => 'Bob',
			'acct' => 'bob@remote.example',
			'user' => '',
			'actor' => 'https://remote.example/users/bob',
			'link' => 'https://cloud.example/apps/social/@alice/42',
			'avatar' => 'https://remote.example/bob.png',
			'excerpt' => 'Hello world',
		], $this->event['parameters']);
		$this->assertArrayNotHasKey('author', $this->event, 'a remote actor is no Nextcloud user');
		$this->assertEqualsWithDelta(time(), $this->event['timestamp'], 5);
	}

	public function testALocalActorIsTheAuthor(): void {
		$carol = (new Person())->setDisplayName('Carol')->setAccount('carol@cloud.example')->setUserId('carol');

		$this->publisher->publish('alice', 'follow', $carol, 'https://cloud.example/users/carol', 'link', '', 502);

		$this->assertSame('carol', $this->event['author']);
		$this->assertSame('carol', $this->event['parameters']['user']);
		$this->assertSame('Carol', $this->event['parameters']['account'], 'the display name when there is no name');
	}

	public function testAnActorNotCachedIsNamedById(): void {
		$this->publisher->publish('alice', 'mention', null, 'https://remote.example/users/x', 'link', '', 503);

		$this->assertSame('https://remote.example/users/x', $this->event['parameters']['account']);
		$this->assertSame('', $this->event['parameters']['acct']);
	}

	public function testTheActivityAppBeingAwayIsNotTheCallersProblem(): void {
		$manager = $this->createStub(IManager::class);
		$manager->method('generateEvent')->willThrowException(new \RuntimeException('no activity app'));

		(new Publisher($manager, new NullLogger()))->publish('alice', 'follow', null, 'x', 'link', '', 504);

		$this->assertSame(0, $this->published);
	}

	public function testTheLabelIsTheNameThenTheDisplayNameThenTheHandle(): void {
		$this->assertSame('Bob', Publisher::labelOf((new Person())->setName(' Bob ')->setDisplayName('Robert')->setAccount('bob@x')));
		$this->assertSame('Robert', Publisher::labelOf((new Person())->setDisplayName('Robert')->setAccount('bob@x')));
		$this->assertSame('bob', Publisher::labelOf((new Person())->setPreferredUsername('bob')->setAccount('bob@x')), 'the display name falls back to the username');
		$this->assertSame('bob@x', Publisher::labelOf((new Person())->setAccount('bob@x')));
	}

	private function recorder(): IEvent {
		$event = $this->createMock(IEvent::class);
		foreach (['setApp' => 'app', 'setType' => 'type', 'setAffectedUser' => 'affectedUser', 'setAuthor' => 'author', 'setLink' => 'link'] as $method => $field) {
			$event->method($method)->willReturnCallback(function (string $value) use ($event, $field): IEvent {
				$this->event[$field] = $value;

				return $event;
			});
		}
		$event->method('setTimestamp')->willReturnCallback(function (int $value) use ($event): IEvent {
			$this->event['timestamp'] = $value;

			return $event;
		});
		$event->method('setObject')->willReturnCallback(function (string $type, $id) use ($event): IEvent {
			$this->event['object'] = [$type, $id];

			return $event;
		});
		$event->method('setSubject')->willReturnCallback(function (string $subject, array $parameters = []) use ($event): IEvent {
			$this->event['subject'] = $subject;
			$this->event['parameters'] = $parameters;

			return $event;
		});

		return $event;
	}
}
