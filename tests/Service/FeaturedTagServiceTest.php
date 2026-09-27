<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\FeaturedTagsRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\Client\FeaturedTag;
use OCA\Social\Service\FeaturedTagService;
use OCA\Social\Service\HashtagService;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * The hashtags an account pins to its profile.
 *
 * What is checked here is the form a tag is stored in, the ceiling the
 * instance advertises, and that the counts beside a featured tag come from the
 * posts rather than from a column somebody has to remember to update.
 */
class FeaturedTagServiceTest extends TestCase {
	private const ACTOR = 'https://cloud.example/users/alice';

	private FeaturedTagsRequest|Stub $featuredTagsRequest;
	private FeaturedTagService $service;

	/** @var array<int, FeaturedTag> the stored rows, by id */
	private array $stored = [];
	/** @var array<string, array{count: int, last: string}> usage by hashtag */
	private array $usage = [];
	/** @var array<string, int> the account's most used hashtags */
	private array $mostUsed = [];
	/** @var string[] the hashtags handed to the store */
	private array $created = [];
	/** @var int[] the ids the store was asked to delete */
	private array $deleted = [];
	private int $nextId = 1;

	protected function setUp(): void {
		$this->featuredTagsRequest = $this->createStub(FeaturedTagsRequest::class);

		$this->featuredTagsRequest->method('getByActor')
			->willReturnCallback(fn (): array => array_values($this->stored));
		$this->featuredTagsRequest->method('countByActor')
			->willReturnCallback(fn (): int => count($this->stored));
		$this->featuredTagsRequest->method('countUsage')
			->willReturnCallback(fn (): array => $this->usage);
		$this->featuredTagsRequest->method('mostUsed')
			->willReturnCallback(fn (): array => $this->mostUsed);

		$this->featuredTagsRequest->method('getOwnedByHashtag')
			->willReturnCallback(function (string $actorId, string $hashtag): FeaturedTag {
				foreach ($this->stored as $tag) {
					if ($tag->getHashtag() === $hashtag) {
						return $tag;
					}
				}

				throw new ItemNotFoundException('Record not found');
			});
		$this->featuredTagsRequest->method('getOwnedById')
			->willReturnCallback(function (string $actorId, int $id): FeaturedTag {
				if (!isset($this->stored[$id])) {
					throw new ItemNotFoundException('Record not found');
				}

				return $this->stored[$id];
			});
		$this->featuredTagsRequest->method('create')
			->willReturnCallback(function (FeaturedTag $tag): FeaturedTag {
				$this->created[] = $tag->getHashtag();
				$tag->setId($this->nextId++);
				$this->stored[$tag->getId()] = $tag;

				return $tag;
			});
		$this->featuredTagsRequest->method('delete')
			->willReturnCallback(function (FeaturedTag $tag): void {
				$this->deleted[] = $tag->getId();
				unset($this->stored[$tag->getId()]);
			});

		$urlGenerator = $this->createStub(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')
			->willReturnCallback(
				static fn (string $route, array $args): string
					=> 'https://cloud.example/apps/social/' . ($args['path'] ?? '')
			);

		$hashtagService = $this->createStub(HashtagService::class);
		$hashtagService->method('tagEntity')
			->willReturnCallback(static fn (string $hashtag): array => ['name' => $hashtag]);

		$this->service = new FeaturedTagService(
			$this->featuredTagsRequest, $hashtagService, $urlGenerator
		);
	}

	/** The form a tag is stored in, which is the form posts are tagged in. */
	public function testAHashtagIsStoredWithoutItsHashAndLowercased(): void {
		$this->service->feature(self::ACTOR, '#NowPlaying');

		$this->assertSame(['nowplaying'], $this->created);
	}

	public function testAHashtagThatIsNotOneIsRefused(): void {
		$this->expectException(InvalidResourceException::class);

		$this->service->feature(self::ACTOR, 'not a tag');
	}

	public function testABlankNameIsRefused(): void {
		$this->expectException(InvalidResourceException::class);

		$this->service->feature(self::ACTOR, '   ');
	}

	/**
	 * Featuring a tag that is already featured is the state the client asked
	 * for, so it is answered with the existing row rather than a failure.
	 */
	public function testFeaturingTheSameTagTwiceIsNotAnError(): void {
		$first = $this->service->feature(self::ACTOR, 'photography');
		$second = $this->service->feature(self::ACTOR, '#Photography');

		$this->assertSame($first->getId(), $second->getId());
		$this->assertSame(['photography'], $this->created);
	}

	/**
	 * The number the instance advertises as `accounts.max_featured_tags` is
	 * the number enforced here; a client that pre-checked against it and got
	 * it wrong is told, not quietly refused.
	 */
	public function testTheAdvertisedCeilingIsEnforced(): void {
		for ($i = 1; $i <= FeaturedTagService::MAX_FEATURED_TAGS; $i++) {
			$this->service->feature(self::ACTOR, 'tag' . $i);
		}

		$this->expectException(InvalidResourceException::class);
		$this->service->feature(self::ACTOR, 'onetoomany');
	}

	/** An existing tag does not count against the ceiling a second time. */
	public function testTheCeilingDoesNotRefuseARetryOfTheLastTag(): void {
		for ($i = 1; $i <= FeaturedTagService::MAX_FEATURED_TAGS; $i++) {
			$this->service->feature(self::ACTOR, 'tag' . $i);
		}

		$this->assertSame(
			'tag1',
			$this->service->feature(self::ACTOR, 'tag1')->getHashtag()
		);
	}

	public function testTheCountsComeFromThePosts(): void {
		$this->service->feature(self::ACTOR, 'photography');
		$this->usage = ['photography' => ['count' => 12, 'last' => '2026-09-10']];

		$tag = $this->service->featured(self::ACTOR)[0]->jsonSerialize();

		$this->assertSame(12, $tag['statuses_count']);
		$this->assertSame('2026-09-10', $tag['last_status_at']);
	}

	/**
	 * A tag pinned before anything was posted with it has no date, and `null`
	 * is what a client can render — an empty string is not a date.
	 */
	public function testATagWithNoPostsReportsNoDate(): void {
		$this->service->feature(self::ACTOR, 'photography');

		$tag = $this->service->featured(self::ACTOR)[0]->jsonSerialize();

		$this->assertSame(0, $tag['statuses_count']);
		$this->assertNull($tag['last_status_at']);
	}

	public function testTheEntityIsAFeaturedTag(): void {
		$this->service->feature(self::ACTOR, 'photography');

		$this->assertSame(
			['id', 'name', 'url', 'statuses_count', 'last_status_at'],
			array_keys($this->service->featured(self::ACTOR)[0]->jsonSerialize())
		);
	}

	public function testTheTagLinksToThisInstancesTagTimeline(): void {
		$tag = $this->service->feature(self::ACTOR, 'photography');

		$this->assertSame('https://cloud.example/apps/social/tags/photography', $tag->getUrl());
	}

	public function testUnfeaturingRemovesTheRow(): void {
		$tag = $this->service->feature(self::ACTOR, 'photography');

		$this->service->unfeature(self::ACTOR, $tag->getId());

		$this->assertSame([$tag->getId()], $this->deleted);
		$this->assertSame([], $this->service->featured(self::ACTOR));
	}

	public function testUnfeaturingSomethingThatIsNotThereIsNotFound(): void {
		$this->expectException(ItemNotFoundException::class);

		$this->service->unfeature(self::ACTOR, 4242);
	}

	/** A suggestion the account has already taken is not a suggestion. */
	public function testASuggestionIsNeverAnAlreadyFeaturedTag(): void {
		$this->service->feature(self::ACTOR, 'photography');
		$this->mostUsed = ['photography' => 40, 'cycling' => 12];

		$this->assertSame(
			[['name' => 'cycling']], $this->service->suggestions(self::ACTOR)
		);
	}

	public function testTheSuggestionListIsBounded(): void {
		$this->mostUsed = array_fill_keys(
			array_map(static fn (int $i): string => 'tag' . $i, range(1, 50)), 1
		);

		$this->assertCount(
			FeaturedTagService::SUGGESTIONS_LIMIT, $this->service->suggestions(self::ACTOR)
		);
	}

	/** An empty list, not an error, for an account that never used a hashtag. */
	public function testAnAccountWithNoHashtagsHasNoSuggestions(): void {
		$this->assertSame([], $this->service->suggestions(self::ACTOR));
	}

	public function testNormalisationKeepsUnicodeTags(): void {
		$this->assertSame('café', FeaturedTagsRequest::normaliseHashtag('#Café'));
		$this->assertSame('', FeaturedTagsRequest::normaliseHashtag('two words'));
		$this->assertSame('', FeaturedTagsRequest::normaliseHashtag('#'));
	}
}
