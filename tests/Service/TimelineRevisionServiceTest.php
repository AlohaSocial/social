<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\TimelineRevisionService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * The number the home timeline's `ETag` carries beside the newest post id.
 *
 * The id answers "has anything arrived"; this answers "has the reader changed
 * what they are shown" — a follow, a block, a mute, a keyword filter, a
 * followed hashtag — none of which moves an id. Without it a reader who
 * unfollows an account is answered `304` on every poll and goes on being shown
 * the posts they just asked to stop seeing.
 */
#[AllowMockObjectsWithoutExpectations]
class TimelineRevisionServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example/users/alice';

	private ActorsRequest|MockObject $actorsRequest;
	private ConfigService|Stub $configService;
	private TimelineRevisionService $service;

	/** @var array<string, string> the stored user values, by user */
	private array $stored = [];

	protected function setUp(): void {
		parent::setUp();

		$this->actorsRequest = $this->createMock(ActorsRequest::class);
		$this->configService = $this->createStub(ConfigService::class);
		$this->configService->method('getValueForUser')->willReturnCallback(
			fn (string $userId, string $key): string => $this->stored[$userId . '/' . $key] ?? ''
		);
		$this->configService->method('setValueForUser')->willReturnCallback(
			function (string $userId, string $key, string $value): void {
				$this->stored[$userId . '/' . $key] = $value;
			}
		);

		$this->service = new TimelineRevisionService($this->actorsRequest, $this->configService);
	}

	/** An account that has never changed anything is at zero, not at nothing. */
	public function testAnAccountThatNeverChangedAnythingIsAtZero(): void {
		$this->assertSame(0, $this->service->of('alice'));
	}

	public function testABumpMovesTheNumber(): void {
		$this->service->bump('alice');
		$this->assertSame(1, $this->service->of('alice'));

		$this->service->bump('alice');
		$this->assertSame(2, $this->service->of('alice'));
	}

	/** One reader's decision is not another reader's. */
	public function testABumpIsOneAccountsOwn(): void {
		$this->service->bump('alice');

		$this->assertSame(0, $this->service->of('bob'));
	}

	/** Nobody signed in has no timeline to key. */
	public function testAnEmptyUserIsLeftAlone(): void {
		$this->service->bump('');

		$this->assertSame(0, $this->service->of(''));
		$this->assertSame([], $this->stored);
	}

	/** The request classes hold an actor; the number is kept per account. */
	public function testAnActorIsResolvedToTheAccountBehindIt(): void {
		$actor = new Person();
		$actor->setId(self::ALICE);
		$actor->setUserId('alice');
		$this->actorsRequest->method('getFromId')->with(self::ALICE)->willReturn($actor);

		$this->service->bumpForActor(self::ALICE);

		$this->assertSame(1, $this->service->of('alice'));
	}

	/**
	 * A remote actor has no account here — which is the common case on the
	 * inbox path, where a follow arrives for somebody else's follower list.
	 */
	public function testAnActorWithNoAccountHereCostsNothing(): void {
		$this->actorsRequest->method('getFromId')
			->willThrowException(new ActorDoesNotExistException());

		$this->service->bumpForActor('https://remote.example/users/bob');

		$this->assertSame([], $this->stored);
	}
}
