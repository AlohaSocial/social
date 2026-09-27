<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Actor;

use OCA\Social\Cron\ActorCleanup;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\Actor\PersonInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Activity\Update;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\StreamDest;
use OCA\Social\Service\SignatureService;

require_once __DIR__ . '/ActorInterfaceTestCase.php';

class PersonInterfaceTest extends ActorInterfaceTestCase {
	private const CAROL = 'https://other.example/users/carol';

	protected function createHandler(): PersonInterface {
		return new PersonInterface(
			$this->cacheActorsRequest,
			$this->streamRequest,
			$this->streamDestRequest,
			$this->actorService,
			$this->actorCascadeService,
			$this->jobList,
		);
	}

	protected function createActor(): Person {
		return new Person();
	}

	/** An Update of bob's profile, signed by bob's server at the given time. */
	private function update(Person $bob, int $creationTime, string $origin = self::REMOTE_HOST): ACore {
		$update = $this->incoming(Update::TYPE, self::BOB . '#updates/1', self::BOB, $bob);
		$update->setOrigin($origin, SignatureService::ORIGIN_HEADER, $creationTime);

		return $update;
	}

	private function cachedBob(int $creation): Person {
		$cached = $this->bob();
		$cached->setCreation($creation);

		return $cached;
	}

	private int $destId = 0;

	private function dest(string $streamId, string $subtype, string $type = 'recipient'): StreamDest {
		$dest = new StreamDest();
		$dest->setId(++$this->destId);
		$dest->setStreamId($streamId)
			->setActorId(self::BOB)
			->setType($type)
			->setSubtype($subtype);

		return $dest;
	}

	/** @param Stream[] $streams */
	private function storedStreams(array $streams): void {
		$byId = [];
		foreach ($streams as $stream) {
			$byId[$stream->getId()] = $stream;
		}
		$this->streamRequest->method('getStream')->willReturnCallback(function (string $id) use ($byId): Stream {
			if (!isset($byId[$id])) {
				throw new StreamNotFoundException();
			}

			return $byId[$id];
		});
	}

	public function testGetItemIsNotSupported(): void {
		$this->expectException(ItemNotFoundException::class);

		$this->handler->getItem($this->bob());
	}

	public function testUpdateNewerThanTheCachedProfileRefreshesIt(): void {
		$bob = $this->bob();
		$this->cacheActorsRequest->method('getFromId')->with(self::BOB)->willReturn($this->cachedBob(1000));

		$this->cacheActorsRequest->expects($this->once())->method('update')->with($this->identicalTo($bob));
		$this->cacheActorsRequest->expects($this->never())->method('save');

		$this->handler->activity($this->update($bob, 2000), $bob);

		$this->assertSame(2000, $bob->getCreation());
	}

	public function testUpdateOlderThanTheCachedProfileIsIgnored(): void {
		$bob = $this->bob();
		$this->cacheActorsRequest->method('getFromId')->willReturn($this->cachedBob(3000));

		$this->cacheActorsRequest->expects($this->never())->method('update');
		$this->cacheActorsRequest->expects($this->never())->method('save');

		$this->handler->activity($this->update($bob, 2000), $bob);
	}

	public function testUpdateOfAnActorNotCachedYetCachesIt(): void {
		$this->nothingCached();
		$bob = $this->bob();

		$this->cacheActorsRequest->expects($this->once())->method('save')->with($this->identicalTo($bob));
		$this->cacheActorsRequest->expects($this->never())->method('update');

		$this->handler->activity($this->update($bob, 2000), $bob);
	}

	public function testUpdateNotComingFromTheActorsServerIsRefused(): void {
		$bob = $this->bob();

		$this->cacheActorsRequest->expects($this->never())->method('update');
		$this->cacheActorsRequest->expects($this->never())->method('save');

		$this->expectException(InvalidOriginException::class);

		$this->handler->activity($this->update($bob, 2000, 'evil.example'), $bob);
	}

	public function testDeleteNotComingFromTheActorsServerIsRefused(): void {
		$bob = $this->bob();
		$delete = $this->incoming(Delete::TYPE, self::BOB . '#delete', self::BOB, $bob, 'evil.example');

		$this->cacheActorsRequest->expects($this->never())->method('deleteCacheById');

		$this->expectException(InvalidOriginException::class);

		$this->handler->activity($delete, $bob);
	}

	public function testDeleteRemovesTheActorFromTheRecipientsOfRemainingStreams(): void {
		$bob = $this->bob();

		$addressed = new Note();
		$addressed->setId(self::REMOTE_URL . '/notes/addressed');
		$addressed->setToArray([self::BOB, self::CAROL]);

		$copied = new Note();
		$copied->setId(self::REMOTE_URL . '/notes/copied');
		$copied->setCcArray([$bob->getFollowers(), self::CAROL]);

		$unrelated = new Note();
		$unrelated->setId(self::REMOTE_URL . '/notes/unrelated');
		$unrelated->setToArray([self::CAROL]);

		$this->storedStreams([$addressed, $copied, $unrelated]);
		$this->streamDestRequest->method('getRelatedToActor')->with($this->identicalTo($bob))->willReturn([
			$this->dest($addressed->getId(), 'to'),
			$this->dest($copied->getId(), 'cc'),
			$this->dest($unrelated->getId(), 'to'),
			$this->dest(self::REMOTE_URL . '/notes/gone', 'to'),
			$this->dest($addressed->getId(), 'x', 'hashtag'),
		]);

		$updated = [];
		$this->streamRequest->method('update')->willReturnCallback(function (Stream $stream) use (&$updated): void {
			$updated[] = $stream->getId();
		});
		$this->streamRequest->expects($this->never())->method('deleteById');

		$this->handler->delete($bob);

		$this->assertSame([self::CAROL], array_values($addressed->getToArray()));
		$this->assertSame([self::CAROL], array_values($copied->getCcArray()));
		$this->assertSame([self::CAROL], $unrelated->getToArray());
		$this->assertEqualsCanonicalizing([$addressed->getId(), $copied->getId()], $updated);
	}

	/**
	 * The rewrite runs inside the inbox request a peer is waiting on for its
	 * `Delete`. An account that has been addressed by more posts than one
	 * request can rewrite hands the rest to a job — because the alternative is
	 * the peer timing out and re-sending, which starts the whole walk again.
	 */
	public function testDeleteOfAWidelyAddressedActorFinishesInAJob(): void {
		$bob = $this->bob();
		// ten full pages and then the end: more than one request may rewrite,
		// but finite, so a walk with no bound would run to the end and be
		// caught by the two expectations below rather than by hanging
		$pages = 0;
		$this->streamDestRequest->method('getRelatedToActor')
			->willReturnCallback(function () use (&$pages): array {
				if (++$pages > 10) {
					return [];
				}

				return array_map(
					fn (int $i): StreamDest => $this->dest(self::REMOTE_URL . '/notes/' . $i, 'to'),
					range(1, 100)
				);
			});
		$this->streamRequest->method('getStream')->willThrowException(new StreamNotFoundException());
		// the rows stay until the job has finished with them
		$this->streamDestRequest->expects($this->never())->method('deleteRelatedToActor');
		$this->jobList->expects($this->once())
			->method('add')
			->with(ActorCleanup::class, ['actor' => self::BOB]);

		$this->handler->delete($bob);
	}

	/** An ordinary account is finished where it started, with no job at all. */
	public function testDeleteOfAnOrdinaryActorNeedsNoJob(): void {
		$bob = $this->bob();
		$this->streamDestRequest->method('getRelatedToActor')->willReturn([]);
		$this->streamDestRequest->expects($this->once())
			->method('deleteRelatedToActor')->with(self::BOB);
		$this->jobList->expects($this->never())->method('add');

		$this->handler->delete($bob);
	}

	/**
	 * Paging by offset would skip rows, because the walk deletes and rewrites
	 * the posts behind them as it goes and the set shrinks underneath it. Each
	 * page asks for what comes after the last id it saw.
	 */
	public function testTheWalkAsksForWhatComesAfterTheLastRowItSaw(): void {
		$bob = $this->bob();
		$afterIds = [];
		$page = 0;
		$this->streamDestRequest->method('getRelatedToActor')
			->willReturnCallback(function ($actor, $limit, $afterId) use (&$afterIds, &$page): array {
				$afterIds[] = $afterId;
				if (++$page > 2) {
					return [];
				}

				return array_map(
					fn (int $i): StreamDest => $this->dest(self::REMOTE_URL . '/notes/' . $i, 'to'),
					range(1, 100)
				);
			});
		$this->streamRequest->method('getStream')->willThrowException(new StreamNotFoundException());

		$this->handler->delete($bob);

		$this->assertSame(0, $afterIds[0], 'the first page starts at the beginning');
		$this->assertGreaterThan(0, $afterIds[1], 'the second page starts after the first');
		$this->assertGreaterThan($afterIds[1], $afterIds[2]);
	}

	public function testDeleteRemovesDirectMessagesSentToTheActor(): void {
		$bob = $this->bob();
		$direct = new Note();
		$direct->setId(self::REMOTE_URL . '/notes/direct');
		$direct->setTo(self::BOB);

		$this->storedStreams([$direct]);
		$this->streamDestRequest->method('getRelatedToActor')->willReturn([$this->dest($direct->getId(), 'to')]);

		$this->streamRequest->expects($this->once())->method('deleteById')->with($direct->getId());
		$this->streamRequest->expects($this->never())->method('update');

		$this->handler->delete($bob);
	}

	/**
	 * The filters, the lists, the scheduled posts and everything else that
	 * belongs to one account and to nobody else are removed from the one list
	 * `ActorCascadeService` holds; what is on that list is pinned by
	 * `ActorCascadeServiceTest`.
	 */
	public function testDeleteTakesEverythingTheAccountOwnedWithIt(): void {
		$bob = $this->bob();

		$this->actorCascadeService->expects($this->once())->method('purge')->with(self::BOB);

		$this->handler->delete($bob);
	}

	public function testDeleteIgnoresItemsThatAreNotActors(): void {
		$this->actorCascadeService->expects($this->never())->method('purge');
		$this->streamRequest->expects($this->never())->method('deleteByAuthor');

		$this->handler->delete($this->note(self::REMOTE_URL . '/notes/1', self::BOB));
	}
}
