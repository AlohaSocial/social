<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\PlacesRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Place;
use OCA\Social\Service\PlaceService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Where a post was taken.
 *
 * Nothing in this service geocodes anything, and the tests are mostly about
 * the decisions it makes instead: what a stale place id costs a post, what a
 * page of posts costs in queries, and who may read a location.
 */
#[AllowMockObjectsWithoutExpectations]
class PlaceServiceTest extends TestCase {
	private PlacesRequest|MockObject $placesRequest;
	private StreamRequest|MockObject $streamRequest;
	private PlaceService $service;

	/** @var array<int, Place> the places this instance has seen */
	private array $known = [];
	/** @var Place[] everything findOrCreate() was handed */
	private array $created = [];
	/** @var array<int, int[]> how many times places were asked for, by call */
	private array $lookups = [];

	protected function setUp(): void {
		parent::setUp();

		$this->placesRequest = $this->createMock(PlacesRequest::class);
		$this->placesRequest->method('getById')->willReturnCallback(function (int $id): Place {
			$this->lookups[] = [$id];
			if (!isset($this->known[$id])) {
				throw new ItemNotFoundException('no such place');
			}

			return $this->known[$id];
		});
		$this->placesRequest->method('getByIds')->willReturnCallback(function (array $ids): array {
			$this->lookups[] = $ids;
			$found = [];
			foreach ($ids as $id) {
				if (isset($this->known[$id])) {
					$found[$id] = $this->known[$id];
				}
			}

			return $found;
		});
		$this->placesRequest->method('findOrCreate')->willReturnCallback(function (Place $place): Place {
			$this->created[] = $place;

			return $place->setId(99);
		});

		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->service = new PlaceService($this->placesRequest, $this->streamRequest);
	}

	private function place(int $id, string $name, string $country = 'PT'): Place {
		$place = (new Place())->setId($id)->setName($name)->setCountry($country);
		$this->known[$id] = $place;

		return $place;
	}

	private function post(int $placeId): Stream {
		return (new Stream())->setPlaceId($placeId);
	}

	public function testAPostCarriesAPlaceItWasGivenTheIdOf(): void {
		$lisbon = $this->place(3, 'Lisbon');

		$this->assertSame($lisbon, $this->service->resolve(3, '', '', '', ''));
	}

	/**
	 * A post is worth more than its location: refusing to publish one because
	 * the place id a client remembered has since gone is the wrong trade.
	 */
	public function testAStalePlaceIdCostsThePostItsLocationAndNothingElse(): void {
		$this->assertNull($this->service->resolve(404, '', '', '', ''));
		$this->assertSame([], $this->created, 'a place was invented for an id that was not there');
	}

	public function testAPlaceNamedOutrightIsKeptForTheNextPost(): void {
		$place = $this->service->resolve(0, 'Cais do Sodré', 'pt', '38.7057', '-9.1447');

		$this->assertNotNull($place);
		$this->assertSame('Cais do Sodré', $place->getName());
		$this->assertSame('PT', $place->getCountry());
		$this->assertSame('38.7057', $place->getLat());
		$this->assertCount(1, $this->created);
	}

	/** "Nowhere" is a name that is not there, not an error. */
	public function testAPlaceWithNoNameIsNowhere(): void {
		$this->assertNull($this->service->resolve(0, '   ', 'PT', '38.7', '-9.1'));
		$this->assertSame([], $this->created);
	}

	public function testAPageOfPostsCostsOneQueryRatherThanOnePerPost(): void {
		$this->place(3, 'Lisbon');
		$this->place(4, 'Porto');
		$posts = [$this->post(3), $this->post(4), $this->post(3), $this->post(0)];

		$this->service->attachPlaces($posts);

		$this->assertCount(1, $this->lookups, 'the places were asked for post by post');
		$this->assertSame('Lisbon', $posts[0]->getPlace()?->getName());
		$this->assertSame('Porto', $posts[1]->getPlace()?->getName());
		$this->assertSame('Lisbon', $posts[2]->getPlace()?->getName());
		$this->assertNull($posts[3]->getPlace());
	}

	/** Almost no post has a place, and a page of them should cost nothing. */
	public function testAPageWithNoPlacesOnItAsksNothing(): void {
		$posts = [$this->post(0), $this->post(0)];

		$this->service->attachPlaces($posts);

		$this->assertSame([], $this->lookups);
	}

	public function testAPostWhosePlaceHasBeenDeletedKeepsItsPlaceEmpty(): void {
		$posts = [$this->post(7)];

		$this->service->attachPlaces($posts);

		$this->assertNull($posts[0]->getPlace());
	}

	/**
	 * The place page is public, and a followers-only post's location is as
	 * private as the post it is on.
	 */
	public function testThePlacePageReadsOnlyPublicPosts(): void {
		$this->place(3, 'Lisbon');
		$this->streamRequest->expects($this->once())
			->method('getPublicByPlace')
			->with(3, 20, 0)
			->willReturn([$this->post(3)]);

		$posts = $this->service->posts(3);

		$this->assertCount(1, $posts);
		$this->assertSame('Lisbon', $posts[0]->getPlace()?->getName());
	}

	/** A place nobody named is a 404 before any post is read. */
	public function testAPlaceThatIsNotThereIsRefusedBeforeAnyPostIsRead(): void {
		$this->streamRequest->expects($this->never())->method('getPublicByPlace');

		$this->expectException(ItemNotFoundException::class);
		$this->service->posts(404);
	}

	/**
	 * The page size comes from a client, and a client may ask for anything.
	 */
	public function testAPageSizeIsHeldBetweenOneAndForty(): void {
		$this->place(3, 'Lisbon');
		$asked = [];
		$this->streamRequest->method('getPublicByPlace')
			->willReturnCallback(function (int $id, int $limit, int $maxId) use (&$asked): array {
				$asked[] = [$limit, $maxId];

				return [];
			});

		$this->service->posts(3, 5000, -12);
		$this->service->posts(3, 0, 0);

		$this->assertSame([[40, 0], [1, 0]], $asked);
	}

	public function testSearchingAsksForWhatWasTypedAndNothingMore(): void {
		$this->placesRequest->expects($this->once())
			->method('search')
			->with('lis', 20)
			->willReturn([$this->place(3, 'Lisbon')]);

		$this->assertCount(1, $this->service->search('lis', 20));
	}
}
