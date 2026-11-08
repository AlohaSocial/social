<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use OCA\Social\AP;
use OCA\Social\Atproto\Reader\AccountStatus;
use OCA\Social\Atproto\Reader\ActorMapper;
use OCA\Social\Db\AtprotoWatchRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\StreamDestRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Interfaces\Actor\PersonInterface;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class AccountStatusTest extends TestCase {
	private const DID = 'did:plc:bob';
	private const ACTOR = 'https://bsky.app/profile/did:plc:bob';
	private const NOW = 1760000000;

	private ?Person $actor = null;
	/** @var Note[] what the actor put in the stream, newest first */
	private array $posts = [];
	/** @var AtprotoWatchRequest&MockObject */
	private AtprotoWatchRequest $watches;
	/** @var CacheActorsRequest&MockObject */
	private CacheActorsRequest $cacheActors;
	/** @var StreamDestRequest&MockObject */
	private StreamDestRequest $dests;
	private AccountStatus $status;

	protected function setUp(): void {
		$this->watches = $this->createMock(AtprotoWatchRequest::class);
		$this->cacheActors = $this->createMock(CacheActorsRequest::class);
		$this->cacheActors->method('getFromId')->willReturnCallback(fn (string $id): Person => $id === self::ACTOR && $this->actor !== null ? $this->actor : throw new CacheActorDoesNotExistException());
		$streams = $this->createMock(StreamRequest::class);
		$streams->method('getByAuthor')->willReturnCallback(function (string $actorId, int $limit, int|string $before): array {
			$page = array_values(array_filter($this->posts, static fn (Note $note): bool => (int)$before === 0 || $note->getNid() < (int)$before));

			return array_slice($page, 0, $limit);
		});
		$this->dests = $this->createMock(StreamDestRequest::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$this->status = new AccountStatus($this->watches, $this->cacheActors, $streams, $this->dests, $time, new NullLogger());
	}

	protected function tearDown(): void {
		AP::set(null);
	}

	private function actor(array $details): Person {
		$this->actor = new Person();
		$this->actor->setId(self::ACTOR);
		$this->actor->setDetailArray(ActorMapper::DETAIL, array_merge(['did' => self::DID, 'labels' => []], $details));

		return $this->actor;
	}

	private function posts(int $count): void {
		$this->posts = [];
		for ($i = $count; $i >= 1; $i--) {
			$note = new Note();
			$note->setId(self::ACTOR . '/post/' . $i);
			$note->setNid($i);
			$this->posts[] = $note;
		}
	}

	public function testASwitchedOffAccountIsMarkedAndItsPostsLeaveEveryTimelineInPages(): void {
		$this->actor(['limited' => false]);
		$this->posts(AccountStatus::PAGE + 2);
		$removed = [];
		$this->dests->method('deleteByStreamIds')->willReturnCallback(static function (array $ids) use (&$removed): void {
			$removed[] = count($ids);
		});
		$this->dests->expects($this->never())->method('generateStreamDest');
		$this->cacheActors->expects($this->once())->method('updateDetails')->with($this->identicalTo($this->actor));

		$this->status->apply(self::DID, false, 'deactivated');

		$this->assertSame(['did' => self::DID, 'labels' => [], 'limited' => true, 'status' => 'deactivated'], $this->actor->getDetails(ActorMapper::DETAIL));
		$this->assertSame([AccountStatus::PAGE, 2], $removed, 'every post, a page at a time');
	}

	public function testAnUnknownInactiveStatusHidesRatherThanPurges(): void {
		$this->actor(['limited' => false]);
		$this->watches->expects($this->never())->method('remove');

		$this->status->apply(self::DID, false, '');

		$this->assertSame('deactivated', $this->actor->getDetails(ActorMapper::DETAIL)['status']);
	}

	public function testAnAccountActiveAgainGetsItsPostsBackAndIsReadSoon(): void {
		$this->actor(['limited' => true, 'status' => 'suspended', 'labels' => ['!warn']]);
		$this->posts(3);
		$restored = [];
		$this->dests->method('generateStreamDest')->willReturnCallback(static function (Note $note) use (&$restored): void {
			$restored[] = $note->getId();
		});
		$this->dests->expects($this->never())->method('deleteByStreamIds');
		$this->watches->expects($this->once())->method('wake')->with(self::DID, self::NOW);

		$this->status->apply(self::DID, true, '');

		$this->assertSame(['did' => self::DID, 'labels' => ['!warn'], 'limited' => false], $this->actor->getDetails(ActorMapper::DETAIL), 'the mark is gone and the labels do not limit it');
		$this->assertSame([self::ACTOR . '/post/3', self::ACTOR . '/post/2', self::ACTOR . '/post/1'], $restored);
	}

	public function testAnAccountActiveAgainStaysLimitedWhenItsLabelsSaySo(): void {
		$this->actor(['limited' => true, 'status' => 'deactivated', 'labels' => ['!hide']]);

		$this->status->restore(self::DID);

		$this->assertTrue($this->actor->getDetails(ActorMapper::DETAIL)['limited']);
		$this->assertArrayNotHasKey('status', $this->actor->getDetails(ActorMapper::DETAIL));
	}

	public function testAnActiveEventForAnAccountNeverSwitchedOffChangesNothing(): void {
		$this->actor(['limited' => false]);
		$this->cacheActors->expects($this->never())->method('updateDetails');
		$this->watches->expects($this->never())->method('wake');

		$this->status->apply(self::DID, true, '');
	}

	public function testADeletedAccountGoesThroughTheActorDeletion(): void {
		$this->actor(['limited' => false]);
		$interface = $this->createMock(PersonInterface::class);
		$interface->expects($this->once())->method('delete')->with($this->identicalTo($this->actor));
		$ap = $this->createMock(AP::class);
		$ap->method('getInterfaceFromType')->with(Person::TYPE)->willReturn($interface);
		AP::set($ap);
		$this->watches->expects($this->once())->method('remove')->with(self::DID);
		$this->dests->expects($this->never())->method('deleteByStreamIds');

		$this->status->apply(self::DID, false, 'deleted');
	}

	/**
	 * Bluesky takedowns are often reversed: the account is hidden with its
	 * posts and the follows of it kept, and a later restore finds them.
	 */
	public function testATakenDownAccountIsHiddenAndKeptSoARestoreFindsIt(): void {
		$this->actor(['limited' => false]);
		$this->posts(2);
		$interface = $this->createMock(PersonInterface::class);
		$interface->expects($this->never())->method('delete');
		$ap = $this->createMock(AP::class);
		$ap->method('getInterfaceFromType')->willReturn($interface);
		AP::set($ap);
		$this->watches->expects($this->never())->method('remove');
		$this->dests->expects($this->once())->method('deleteByStreamIds')->with([self::ACTOR . '/post/2', self::ACTOR . '/post/1']);
		$this->dests->expects($this->exactly(2))->method('generateStreamDest');
		$this->watches->expects($this->once())->method('wake')->with(self::DID, self::NOW);

		$this->status->apply(self::DID, false, 'takendown');
		$this->assertSame('takendown', $this->actor->getDetails(ActorMapper::DETAIL)['status']);
		$this->assertTrue($this->actor->getDetails(ActorMapper::DETAIL)['limited']);
		$this->assertFalse(AccountStatus::isGone('takendown'));

		$this->status->apply(self::DID, true, '');
		$this->assertArrayNotHasKey('status', $this->actor->getDetails(ActorMapper::DETAIL));
	}

	public function testAnAccountNeverSeenHereIsNothingToActOn(): void {
		$this->cacheActors->expects($this->never())->method('updateDetails');
		$this->watches->expects($this->once())->method('remove')->with(self::DID);

		$this->status->apply(self::DID, false, 'deactivated');
		$this->status->apply(self::DID, false, 'deleted');
	}
}
