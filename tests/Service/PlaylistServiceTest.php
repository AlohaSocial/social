<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CollectionsRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\Collection;
use OCA\Social\Service\PlaylistService;
use OCA\Social\Service\StreamService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * PeerTube's playlists, as this app's collections.
 *
 * They are the same thing, which is why there is no table for them. What is
 * held still here is that the document is the **whole truth** — a video taken
 * out of a playlist there has to leave this copy too — and that a playlist of
 * videos nobody here has seen is not a reason to go and fetch forty videos.
 */
#[AllowMockObjectsWithoutExpectations]
class PlaylistServiceTest extends TestCase {
	private const CHANNEL = 'https://peertube.example/video-channels/news';
	private const ONE = 'https://peertube.example/videos/watch/one';
	private const TWO = 'https://peertube.example/videos/watch/two';

	private CollectionsRequest|MockObject $collectionsRequest;
	private StreamService|Stub $streamService;
	private PlaylistService $service;

	protected function setUp(): void {
		$this->collectionsRequest = $this->createMock(CollectionsRequest::class);
		$this->streamService = $this->createStub(StreamService::class);
		$cacheActorsRequest = $this->createStub(CacheActorsRequest::class);
		$cacheActorsRequest->method('getFromId')->willReturnCallback(
			static fn (string $id): Person => (new Person())->setId($id)
		);
		$this->service = new PlaylistService(
			$this->collectionsRequest, $this->streamService, new NullLogger(), $cacheActorsRequest
		);
	}

	private function holding(string ...$ids): void {
		$this->streamService->method('getStreamById')
			->willReturnCallback(static function (string $id) use ($ids): Note {
				if (!in_array($id, $ids, true)) {
					throw new StreamNotFoundException();
				}

				$note = new Note();
				$note->setId($id);

				return $note;
			});
	}

	private function collection(int $id = 3, string $title = 'A series'): Collection {
		$collection = new Collection();
		$collection->setId($id)->setOwnerId(self::CHANNEL)->setTitle($title);

		return $collection;
	}

	private function wire(array $items, string $name = 'A series'): array {
		return [
			'type' => 'Playlist',
			'id' => 'https://peertube.example/video-playlists/1',
			'name' => $name,
			'content' => 'Everything in order',
			'orderedItems' => $items,
		];
	}

	public function testAPlaylistBecomesACollectionOfTheChannel(): void {
		$this->holding(self::ONE, self::TWO);
		$this->collectionsRequest->method('getByActor')->willReturn([]);
		$this->collectionsRequest->method('save')->willReturnCallback(
			static fn (Collection $c): Collection => $c->setId(3)
		);

		$added = [];
		$this->collectionsRequest->method('addItem')
			->willReturnCallback(static function (Collection $c, string $id, int $position) use (&$added): void {
				$added[$position] = $id;
			});

		$this->assertTrue($this->service->receive($this->wire([
			['type' => 'PlaylistElement', 'position' => 1, 'object' => self::ONE],
			['type' => 'PlaylistElement', 'position' => 2, 'object' => self::TWO],
		]), self::CHANNEL, self::CHANNEL));

		$this->assertSame([self::ONE, self::TWO], [$added[0], $added[1]]);
	}

	/**
	 * The same playlist twice is one collection: a redelivery is an update
	 * rather than a duplicate, matched on the title of the same owner.
	 */
	public function testTheSamePlaylistAgainUpdatesTheOneThatIsThere(): void {
		$this->holding(self::ONE);
		$this->collectionsRequest->method('getByActor')->willReturn([$this->collection()]);
		$this->collectionsRequest->expects($this->never())->method('save');
		$this->collectionsRequest->expects($this->once())->method('update');

		$this->service->receive($this->wire([['object' => self::ONE]]), self::CHANNEL, self::CHANNEL);
	}

	/**
	 * The document is the whole truth about the playlist: a video taken out of
	 * it there has to leave this copy too, which a merge cannot express.
	 */
	public function testWhatArrivesReplacesWhatWasThere(): void {
		$this->holding(self::ONE);
		$this->collectionsRequest->method('getByActor')->willReturn([$this->collection()]);
		$this->collectionsRequest->expects($this->once())->method('clearItems');

		$this->service->receive($this->wire([['object' => self::ONE]]), self::CHANNEL, self::CHANNEL);
	}

	/**
	 * A playlist naming forty videos nobody here has seen is not a reason to
	 * fetch forty videos: what arrived by following the channel is what it
	 * shows, and it fills in as the rest arrives.
	 */
	public function testOnlyTheVideosThisInstanceHoldsAreInIt(): void {
		$this->holding(self::ONE);
		$this->collectionsRequest->method('getByActor')->willReturn([]);
		$this->collectionsRequest->method('save')->willReturnCallback(
			static fn (Collection $c): Collection => $c->setId(3)
		);
		$this->collectionsRequest->expects($this->once())->method('addItem')
			->with($this->anything(), self::ONE, 0);

		$this->service->receive($this->wire([
			['object' => self::ONE],
			['object' => self::TWO],
		]), self::CHANNEL, self::CHANNEL);
	}

	/** An empty page with a title on it is worse than nothing. */
	public function testAPlaylistOfNothingWeHoldIsNotStored(): void {
		$this->holding();
		$this->collectionsRequest->expects($this->never())->method('save');

		$this->assertFalse($this->service->receive($this->wire([['object' => self::ONE]]), self::CHANNEL, self::CHANNEL));
	}

	public function testAPlaylistWithNoNameOrNoOwnerIsNotStored(): void {
		$this->assertFalse($this->service->receive($this->wire([], name: ''), self::CHANNEL, self::CHANNEL));
		$this->assertFalse($this->service->receive($this->wire([]), '', self::CHANNEL));
	}

	// --- the other direction ---------------------------------------------

	/**
	 * A playlist belongs to the thing a reader subscribes to, which is the
	 * channel — the same way a video's `attributedTo` does.
	 */
	public function testACollectionIsPublishedAsAPlaylistOfTheChannel(): void {
		$playlist = PlaylistService::asPlaylist(
			$this->collection(),
			'https://cloud.example/apps/social/@alice/playlists/3',
			self::CHANNEL,
			[self::ONE, self::TWO]
		);

		$this->assertSame('Playlist', $playlist['type']);
		$this->assertSame('A series', $playlist['name']);
		$this->assertSame(self::CHANNEL, $playlist['attributedTo']);
		$this->assertSame(2, $playlist['totalItems']);
		$this->assertSame('PlaylistElement', $playlist['orderedItems'][0]['type']);
		$this->assertSame(1, $playlist['orderedItems'][0]['position']);
		$this->assertSame(self::ONE, $playlist['orderedItems'][0]['object']);
	}
}
