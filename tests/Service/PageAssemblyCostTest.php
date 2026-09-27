<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\PlacesRequest;
use OCA\Social\Db\StreamCardsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Place;
use OCA\Social\Service\LinkPreviewService;
use OCA\Social\Service\PlaceService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a page of posts costs, in queries.
 *
 * This app has a documented history of exactly one performance failure —
 * asking the database something per post rather than per page — and it is
 * invisible to every other kind of test: the answers are identical, the suite
 * is green, and the timeline goes from fifty milliseconds to a second and a
 * half on an instance with real data. `docs/Architecture.md` records the
 * 1,661 ms home timeline and what fixed it.
 *
 * A timing test would catch it and would also fail on a slow CI runner for no
 * reason. What is asserted here instead is the shape that makes the timing bad:
 * that the number of round trips does not grow with the number of posts. Ten
 * posts and a hundred posts cost the same one query, or the batch is not a
 * batch any more.
 */
class PageAssemblyCostTest extends TestCase {
	/** @return iterable<string, array{int}> */
	public static function pageSizes(): iterable {
		yield 'a small page' => [5];
		yield 'a full page' => [40];
		yield 'a page nobody should ask for' => [200];
	}

	/** @return Stream[] */
	private function page(int $size): array {
		$posts = [];
		for ($i = 1; $i <= $size; $i++) {
			$post = new Note();
			$post->setId('https://remote.example/notes/' . $i);
			$post->setPlaceId(($i % 3) + 1);
			$posts[] = $post;
		}

		return $posts;
	}

	#[DataProvider('pageSizes')]
	public function testTheLinkCardsOfAPageCostOneQuery(int $size): void {
		$asked = 0;
		$request = $this->createStub(StreamCardsRequest::class);
		$request->method('getByStreamIds')->willReturnCallback(
			function (array $ids) use (&$asked): array {
				$asked++;

				return [];
			}
		);

		$service = new LinkPreviewService(
			$request,
			$this->createStub(\OCA\Social\Service\CurlService::class),
			new \Psr\Log\NullLogger(),
		);
		$service->attachCards($this->page($size));

		$this->assertSame(1, $asked, 'the cards were fetched post by post');
	}

	#[DataProvider('pageSizes')]
	public function testThePlacesOfAPageCostOneQuery(int $size): void {
		$asked = 0;
		$places = $this->createMock(PlacesRequest::class);
		$places->method('getByIds')->willReturnCallback(
			function (array $ids) use (&$asked): array {
				$asked++;

				return array_combine(
					$ids,
					array_map(static fn (int $id): Place => (new Place())->setId($id)->setName('there'), $ids)
				);
			}
		);
		$places->expects($this->never())->method('getById');

		$service = new PlaceService($places, $this->createStub(StreamRequest::class));
		$service->attachPlaces($this->page($size));

		$this->assertSame(1, $asked, 'the places were fetched post by post');
	}

	/**
	 * And the query it does make asks for each id once: a page whose posts
	 * share a place used to ask for that place once per post, which is the
	 * same fault one level down.
	 */
	public function testAPlaceSharedByManyPostsIsAskedForOnce(): void {
		$wanted = [];
		$places = $this->createStub(PlacesRequest::class);
		$places->method('getByIds')->willReturnCallback(
			function (array $ids) use (&$wanted): array {
				$wanted = $ids;

				return [];
			}
		);

		$service = new PlaceService($places, $this->createStub(StreamRequest::class));
		$service->attachPlaces($this->page(60));

		$this->assertSame(
			array_values(array_unique($wanted)),
			array_values($wanted),
			'the same place was asked for more than once in one query'
		);
	}
}
