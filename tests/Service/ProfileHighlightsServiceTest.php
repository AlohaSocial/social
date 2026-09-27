<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\ProfileHighlightsService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The little activity chart at the top of a profile.
 *
 * Two things matter here and nothing else does: that a post lands in the week
 * it was published in, and that a remote account — whose history this instance
 * only ever holds a part of — is reported as not chartable rather than as
 * quiet.
 */
#[AllowMockObjectsWithoutExpectations]
class ProfileHighlightsServiceTest extends TestCase {
	private const WEEK = 7 * 24 * 3600;

	/**
	 * A multiple of a week, so that the service's own week boundary
	 * (`now - now % WEEK`) falls exactly here and the buckets in these tests
	 * can be counted back from it without repeating that arithmetic.
	 */
	private const NOW = 1757548800;

	private StreamRequest&MockObject $streamRequest;
	private ProfileHighlightsService $service;

	protected function setUp(): void {
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->service = new ProfileHighlightsService($this->streamRequest);
	}

	private function localActor(int $creation = 0): Person {
		$actor = new Person();
		$actor->setId('https://cloud.example/users/alice');
		$actor->setLocal(true);
		$actor->setCreation($creation);

		return $actor;
	}

	private function remoteActor(): Person {
		$actor = new Person();
		$actor->setId('https://remote.example/users/bob');
		$actor->setLocal(false);

		return $actor;
	}

	public function testAWeekWithNoPostsIsAZeroRatherThanAGap(): void {
		$this->streamRequest->method('publishedTimesByAuthor')->willReturn([]);
		$this->streamRequest->method('topHashtagsByAuthor')->willReturn([]);

		$highlights = $this->service->forActor($this->localActor(), self::NOW);

		$this->assertTrue($highlights['available']);
		$this->assertCount(ProfileHighlightsService::WEEKS, $highlights['weeks']);
		$this->assertSame([0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], $highlights['weeks']);
	}

	public function testAPostLandsInTheWeekItWasPublishedIn(): void {
		$start = self::NOW - (ProfileHighlightsService::WEEKS - 1) * self::WEEK;

		$this->streamRequest->method('publishedTimesByAuthor')->willReturn([
			// the week in progress: NOW is its first moment, so an hour in
			self::NOW + 3600,
			// the week before
			$start + 10 * self::WEEK + 100,
			$start + 10 * self::WEEK + 200,
			// the oldest week in the window
			$start + 60,
		]);
		$this->streamRequest->method('topHashtagsByAuthor')->willReturn([]);

		$weeks = $this->service->forActor($this->localActor(), self::NOW)['weeks'];

		$this->assertSame(1, $weeks[0], 'the oldest bucket');
		$this->assertSame(2, $weeks[10]);
		$this->assertSame(1, $weeks[11], 'the week in progress');
	}

	public function testAPostOlderThanTheWindowIsNotCounted(): void {
		$start = self::NOW - (ProfileHighlightsService::WEEKS - 1) * self::WEEK;
		$this->streamRequest->method('publishedTimesByAuthor')->willReturn([$start - 1]);
		$this->streamRequest->method('topHashtagsByAuthor')->willReturn([]);

		$weeks = $this->service->forActor($this->localActor(), self::NOW)['weeks'];

		$this->assertSame(0, array_sum($weeks));
	}

	/**
	 * The query runs, and then a post is published: it is newer than the last
	 * bucket, and `$weeks[12]` does not exist.
	 */
	public function testAPostFromAfterTheWindowDoesNotRunOffTheEnd(): void {
		$this->streamRequest->method('publishedTimesByAuthor')->willReturn([self::NOW + self::WEEK]);
		$this->streamRequest->method('topHashtagsByAuthor')->willReturn([]);

		$highlights = $this->service->forActor($this->localActor(), self::NOW);

		$this->assertCount(ProfileHighlightsService::WEEKS, $highlights['weeks']);
		$this->assertSame(0, array_sum($highlights['weeks']));
	}

	public function testThePostingSinceDateIsTheAccountsOwn(): void {
		$highlights = $this->service->forActor($this->localActor(1600000000), self::NOW);

		$this->assertSame(1600000000, $highlights['since']);
	}

	public function testHashtagsComeThroughAsTheyWereCounted(): void {
		$this->streamRequest->method('publishedTimesByAuthor')->willReturn([]);
		$this->streamRequest->method('topHashtagsByAuthor')->willReturn([
			['name' => 'nextcloud', 'count' => 12],
			['name' => 'moss', 'count' => 3],
		]);

		$highlights = $this->service->forActor($this->localActor(), self::NOW);

		$this->assertSame('nextcloud', $highlights['hashtags'][0]['name']);
		$this->assertSame(12, $highlights['hashtags'][0]['count']);
	}

	/**
	 * This instance holds a remote account's posts only from whenever somebody
	 * here started following them. A chart of that would show a quiet year for
	 * an account that was busy, and nothing distinguishes the two.
	 */
	public function testARemoteAccountIsNotChartedAtAll(): void {
		$this->streamRequest->expects($this->never())->method('publishedTimesByAuthor');
		$this->streamRequest->expects($this->never())->method('topHashtagsByAuthor');

		$highlights = $this->service->forActor($this->remoteActor(), self::NOW);

		$this->assertFalse($highlights['available']);
		$this->assertSame([], $highlights['weeks']);
	}

	public function testAnActorWithNoIdIsNotCharted(): void {
		$actor = new Person();
		$actor->setLocal(true);

		$this->assertFalse($this->service->forActor($actor, self::NOW)['available']);
	}
}
