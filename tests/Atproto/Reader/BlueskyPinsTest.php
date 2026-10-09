<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use OCA\Social\Atproto\Reader\BlueskyPins;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Interfaces\Activity\FeaturedCollection;
use OCA\Social\Model\ActivityPub\Actor\Person;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class BlueskyPinsTest extends TestCase {
	private const URI = 'at://did:plc:bob/app.bsky.feed.post/3kpin';
	private const POST = 'https://bsky.app/profile/did:plc:bob/post/3kpin';

	private array $known = [];
	private array $matched = [];

	private function pins(): BlueskyPins {
		$posts = $this->createMock(PostStore::class);
		$posts->method('isKnown')->willReturnCallback(fn (string $id): bool => in_array($id, $this->known, true));
		$posts->method('storeByUri')->willReturnCallback(function (string $uri): bool {
			$this->known[] = self::POST;

			return true;
		});
		$featured = $this->createMock(FeaturedCollection::class);
		$featured->method('match')->willReturnCallback(function (Person $actor, array $postIds): int {
			$this->matched[] = $postIds;

			return count($postIds);
		});

		return new BlueskyPins($posts, $featured, new NullLogger());
	}

	public function testThePinnedPostIsFetchedAndPinnedAndNoPinTakesThePinsDown(): void {
		$this->pins()->keep(new Person(), self::URI);
		$this->pins()->keep(new Person(), '');

		$this->assertSame([[self::POST], []], $this->matched);
		$this->assertSame([self::POST], $this->known, 'fetched once it was not here');
	}
}
