<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\HiddenCollectionsService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Mastodon's `hide_collections`, kept per Nextcloud user. */
#[AllowMockObjectsWithoutExpectations]
class HiddenCollectionsServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example/users/alice';

	/** @var array<string, string> user id => stored value */
	private array $stored = [];
	private ActorsRequest|MockObject $actorsRequest;
	private HiddenCollectionsService $service;

	protected function setUp(): void {
		$this->stored = [];
		$config = $this->createStub(ConfigService::class);
		$config->method('getUserValue')->willReturnCallback(
			fn (string $key, string $userId): string => $key === HiddenCollectionsService::USER_KEY ? ($this->stored[$userId] ?? '') : ''
		);
		$config->method('setValueForUser')->willReturnCallback(
			function (string $userId, string $key, string $value): void {
				$this->stored[$userId] = $value;
			}
		);
		$this->actorsRequest = $this->createMock(ActorsRequest::class);
		$this->service = new HiddenCollectionsService($config, $this->actorsRequest, new NullLogger());
	}

	private function actor(string $id, bool $local, string $userId = ''): Person {
		$actor = new Person();
		$actor->setId($id);
		$actor->setLocal($local);
		$actor->setUserId($userId);

		return $actor;
	}

	public function testNobodyHidesAnythingUntilTheyChooseTo(): void {
		$this->assertFalse($this->service->hides('alice'));
		$this->assertFalse($this->service->hides(''));

		$this->service->setHides('alice', true);
		$this->assertTrue($this->service->hides('alice'));
		$this->assertSame('1', $this->stored['alice']);

		$this->service->setHides('alice', false);
		$this->assertFalse($this->service->hides('alice'));
	}

	/** A cached copy of a local actor carries no user id; the actor row does. */
	public function testACachedLocalActorIsLookedUpByItsId(): void {
		$this->stored['alice'] = '1';
		$this->actorsRequest->expects($this->once())->method('getFromId')->with(self::ALICE)
			->willReturn($this->actor(self::ALICE, true, 'alice'));

		$cached = $this->actor(self::ALICE, true);
		$this->assertTrue($this->service->hidesFor($cached));
		$this->assertTrue($this->service->hidesFor($cached), 'asked once per request');
	}

	public function testARemoteAccountDecidesOnItsOwnServer(): void {
		$this->actorsRequest->expects($this->never())->method('getFromId');

		$this->assertFalse($this->service->hidesFor($this->actor('https://remote.example/users/bob', false)));
	}

	public function testAnActorWithNoAccountBehindItHidesNothing(): void {
		$this->actorsRequest->method('getFromId')->willThrowException(new ActorDoesNotExistException());

		$this->assertFalse($this->service->hidesFor($this->actor(self::ALICE, true)));
	}

	public function testTheAccountItselfStillSeesItsLists(): void {
		$this->stored['alice'] = '1';
		$alice = $this->actor(self::ALICE, true, 'alice');

		$this->assertTrue($this->service->mayList($alice, $alice));
		$this->assertFalse($this->service->mayList($alice, $this->actor('https://cloud.example/users/bob', true, 'bob')));
		$this->assertFalse($this->service->mayList($alice, null));
	}

	public function testOnlyALocalAccountReportsTheFlag(): void {
		$this->stored['alice'] = '1';
		$remote = $this->actor('https://remote.example/users/bob', false);

		$this->assertTrue($this->service->annotate($this->actor(self::ALICE, true, 'alice'))->getHideCollections());
		$this->assertNull($this->service->annotate($remote)->getHideCollections());
	}
}
