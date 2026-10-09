<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Identity;

use InvalidArgumentException;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\PresenceService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\AtprotoOAuthRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Tests\Atproto\Client\InMemoryClientRequest;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A person's own switch for their presence on Bluesky: off deactivates the
 * identity and signs every Bluesky app out, on activates it again and
 * remembers when, so what was written while off stays off.
 */
#[AllowMockObjectsWithoutExpectations]
class PresenceServiceTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const NOW = 1760000000;

	private Person $alice;
	private ?Identity $identity;
	/** @var list<string> what was asked of the identity: deactivate or activate */
	private array $switched = [];
	/** @var array<string, string> the user's stored settings */
	private array $stored = [];
	private InMemoryClientRequest $clients;
	/** @var AtprotoOAuthRequest&MockObject */
	private AtprotoOAuthRequest $oauth;

	protected function setUp(): void {
		$this->alice = new Person();
		$this->alice->setId('https://social.test/@alice');
		$this->alice->setUserId('alice');
		$this->alice->setLocal(true);
		$this->identity = self::identity(Identity::STATE_ACTIVE);
		$this->clients = (new \ReflectionClass(InMemoryClientRequest::class))->newInstanceWithoutConstructor();
		$this->oauth = $this->createMock(AtprotoOAuthRequest::class);
	}

	private static function identity(string $state): Identity {
		return new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', 'sealed', '', '', $state, '', 0);
	}

	private function presence(): PresenceService {
		$identities = $this->createMock(IdentityService::class);
		$identities->method('forActor')->willReturnCallback(fn (Person $actor, bool $create = true): ?Identity => $this->identity);
		$identities->method('getByDid')->willReturnCallback(fn (): Identity => $this->identity ?? throw new \RuntimeException('none'));
		$identities->method('deactivate')->willReturnCallback(function (Identity $identity): void {
			$this->switched[] = 'deactivate';
			$this->identity = self::identity(Identity::STATE_DEACTIVATED);
		});
		$identities->method('activate')->willReturnCallback(function (Identity $identity): void {
			$this->switched[] = 'activate';
			$this->identity = self::identity(Identity::STATE_ACTIVE);
		});
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(fn (string $user, string $app, string $key, $default = ''): string => $this->stored[$user . '/' . $key] ?? $default);
		$config->method('setUserValue')->willReturnCallback(function (string $user, string $app, string $key, $value): void {
			$this->stored[$user . '/' . $key] = (string)$value;
		});
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		$actors = $this->createMock(ActorsRequest::class);
		$actors->method('getFromId')->willReturnCallback(fn (string $id): Person => $id === $this->alice->getId() ? $this->alice : throw new \RuntimeException('no such account'));

		return new PresenceService($identities, $this->clients, $this->oauth, $config, $time, new NullLogger(), $actors);
	}

	private static function post(int $published): Note {
		$post = new Note();
		$post->setPublishedTime($published);

		return $post;
	}

	public function testOffDeactivatesAndSignsEveryBlueskyAppOut(): void {
		$this->clients->addSession('j1', 'alice', self::DID, 1, self::NOW + 3600);
		$this->clients->addSession('j2', 'bob', 'did:plc:bob', 2, self::NOW + 3600);
		$this->oauth->expects($this->once())->method('deleteByUser')->with('alice');

		$identity = $this->presence()->switchOff($this->alice);

		$this->assertSame(['deactivate'], $this->switched);
		$this->assertFalse($identity->isActive());
		$this->assertSame(['j2'], array_keys($this->clients->sessions), 'only alice\'s apps are signed out');
		$this->assertSame([], $this->stored, 'nothing remembered while off');
	}

	public function testOnActivatesAndRemembersWhenSoWhatWasWrittenWhileOffStaysOff(): void {
		$this->identity = self::identity(Identity::STATE_DEACTIVATED);
		$presence = $this->presence();
		$this->assertFalse($presence->writtenWhileOff($this->alice, self::post(self::NOW - 60)), 'never switched back on: nothing held back');

		$identity = $presence->switchOn($this->alice);

		$this->assertSame(['activate'], $this->switched);
		$this->assertTrue($identity->isActive());
		$this->assertSame((string)self::NOW, $this->stored['alice/' . PresenceService::ON_SINCE]);
		$this->assertTrue($presence->writtenWhileOff($this->alice, self::post(self::NOW - 60)), 'written before it was on again');
		$this->assertFalse($presence->writtenWhileOff($this->alice, self::post(self::NOW)));
		$this->assertFalse($presence->writtenWhileOff($this->alice, self::post(self::NOW + 60)));
		$this->assertFalse($presence->writtenWhileOff($this->alice, self::post(0)), 'a post without a time is not guessed at');
	}

	public function testAPostByTheCachedActorIsHeldBackAsTheAccountsWouldBe(): void {
		$this->identity = self::identity(Identity::STATE_DEACTIVATED);
		$presence = $this->presence();
		$presence->switchOn($this->alice);
		// what the publisher has: the cached actor, which carries no user
		$cached = (new Person())->setId($this->alice->getId())->setLocal(true);

		$this->assertTrue($presence->writtenWhileOff($cached, self::post(self::NOW - 60)));
		$this->assertFalse($presence->writtenWhileOff((new Person())->setId('https://social.test/@nobody')->setLocal(true), self::post(self::NOW - 60)), 'an account not found is not held back');
	}

	public function testOnWhileOnChangesNothing(): void {
		$this->presence()->switchOn($this->alice);

		$this->assertSame([], $this->switched);
		$this->assertSame([], $this->stored, 'the time is that of a real switch only');
	}

	public function testAnAccountWithoutAnIdentityOrOneThatMovedAwayIsNotSwitched(): void {
		$refused = 0;
		foreach ([null, self::identity(Identity::STATE_MOVED_AWAY), self::identity(Identity::STATE_TOMBSTONED)] as $identity) {
			$this->identity = $identity;
			foreach ([fn () => $this->presence()->switchOff($this->alice), fn () => $this->presence()->switchOn($this->alice)] as $switch) {
				try {
					$switch();
				} catch (InvalidArgumentException) {
					$refused++;
				}
			}
		}

		$this->assertSame(6, $refused);
		$this->assertSame([], $this->switched);
	}
}
