<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use InvalidArgumentException;
use OCA\Social\Db\TrendReviewRequest;
use OCA\Social\Service\TrendReviewService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Keeping something out of what is trending.
 *
 * Two decisions hold this together and both are asserted here: that a
 * rejection hides rather than un-counts, so lifting it puts the thing back
 * with the number it would have had; and that what is rejected is hidden while
 * everything else trends, rather than the other way round — the opposite
 * default would empty the Explore page of every instance on upgrade.
 */
#[AllowMockObjectsWithoutExpectations]
class TrendReviewServiceTest extends TestCase {
	private TrendReviewRequest|MockObject $trendReviewRequest;
	private TrendReviewService $service;

	/** @var array<string, string[]> kind => the refs rejected */
	private array $rejected = [];
	/** @var array<int, array{string, string, bool, string}> every decision recorded */
	private array $decided = [];
	/** @var array<int, array{string, string}> every decision forgotten */
	private array $forgotten = [];

	protected function setUp(): void {
		parent::setUp();

		$this->trendReviewRequest = $this->createMock(TrendReviewRequest::class);
		$this->trendReviewRequest->method('rejected')
			->willReturnCallback(fn (string $kind): array => $this->rejected[$kind] ?? []);
		$this->trendReviewRequest->method('decide')
			->willReturnCallback(function (string $kind, string $ref, bool $approved, string $who): void {
				$this->decided[] = [$kind, $ref, $approved, $who];
			});
		$this->trendReviewRequest->method('forget')
			->willReturnCallback(function (string $kind, string $ref): bool {
				$this->forgotten[] = [$kind, $ref];

				return true;
			});

		$this->service = new TrendReviewService($this->trendReviewRequest);
	}

	/** @param string[] $refs */
	private function reject(string $kind, array $refs): void {
		$this->rejected[$kind] = $refs;
	}

	/**
	 * A moderator pastes what they see, and what they see has a `#` on it;
	 * the counters store the bare word.
	 */
	public function testAHashtagIsTheSameHashtagHoweverItWasPasted(): void {
		$this->service->decide('tag', '  #Brunch ', false, 'alice');

		$this->assertSame([['tag', 'Brunch', false, 'alice']], $this->decided);
	}

	/**
	 * The only thing ever compared against a status id is another copy of the
	 * same id, so it is left exactly as it arrived.
	 */
	public function testAStatusIdIsLeftExactlyAsItIs(): void {
		$this->service->decide('status', 'https://remote.example/users/bob/statuses/1', false, 'alice');

		$this->assertSame('https://remote.example/users/bob/statuses/1', $this->decided[0][1]);
	}

	public function testAKindThisAppDoesNotReviewIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->decide('account', 'alice', false, 'alice');
	}

	public function testADecisionAboutNothingIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('nothing named here');
		$this->service->decide('tag', '  #  ', false, 'alice');
	}

	public function testForgettingADecisionNormalisesTheSameWay(): void {
		$this->assertTrue($this->service->forget('TAG', '#Brunch'));
		$this->assertSame([['tag', 'Brunch']], $this->forgotten);
	}

	public function testARejectedHashtagIsTakenOutOfWhatIsTrending(): void {
		$this->reject(TrendReviewRequest::KIND_TAG, ['brunch']);

		$kept = $this->service->filterTags([
			['hashtag' => 'Brunch', 'count' => 90],
			['hashtag' => 'climbing', 'count' => 12],
		]);

		$this->assertSame([['hashtag' => 'climbing', 'count' => 12]], $kept);
	}

	/** A tag is the same tag however it was typed, on both sides of the check. */
	public function testTheMatchIgnoresHowEitherSideWasCapitalised(): void {
		$this->reject(TrendReviewRequest::KIND_TAG, ['BRUNCH']);

		$this->assertSame([], $this->service->filterTags([['hashtag' => 'brunch']]));
	}

	/**
	 * Everything trends unless it was rejected: the other arrangement would
	 * leave every instance's Explore page empty until somebody found the panel.
	 */
	public function testEverythingElseTrendsWithoutAnybodyApprovingIt(): void {
		$this->reject(TrendReviewRequest::KIND_TAG, ['brunch']);

		$kept = $this->service->filterTags([['hashtag' => 'climbing'], ['hashtag' => 'jazz']]);

		$this->assertCount(2, $kept);
	}

	public function testAnInstanceThatHasRejectedNothingKeepsItsListUntouched(): void {
		$rows = [['hashtag' => 'brunch'], ['hashtag' => 'climbing']];

		$this->assertSame($rows, $this->service->filterTags($rows));
		$this->assertSame($rows, $this->service->filterLinks($rows));
	}

	public function testALinkIsNamedByItsUrl(): void {
		$this->reject(TrendReviewRequest::KIND_LINK, ['https://example.org/story']);

		$kept = $this->service->filterLinks([
			['url' => 'https://example.org/story'],
			['url' => 'https://example.org/other'],
		]);

		$this->assertSame([['url' => 'https://example.org/other']], $kept);
	}

	/** The rows are handed back as a list, because they are sent as a JSON array. */
	public function testTheFilteredListIsStillAList(): void {
		$this->reject(TrendReviewRequest::KIND_TAG, ['brunch']);

		$kept = $this->service->filterTags([['hashtag' => 'brunch'], ['hashtag' => 'jazz']]);

		$this->assertSame([0], array_keys($kept));
	}

	public function testAStatusKeptOutOfTrendingSaysSo(): void {
		$this->reject(TrendReviewRequest::KIND_STATUS, ['https://remote.example/statuses/1']);

		$this->assertTrue($this->service->statusIsRejected('https://remote.example/statuses/1'));
		$this->assertFalse($this->service->statusIsRejected('https://remote.example/statuses/2'));
	}

	/**
	 * `usable` and `listable` describe a tag row that can be disabled for
	 * posting and for search; a hashtag here exists because a post carries it,
	 * so true is the honest answer rather than a placeholder.
	 */
	public function testATagEntitySaysWhatThisAppCanActuallyDecide(): void {
		$this->reject(TrendReviewRequest::KIND_TAG, ['brunch']);

		$entity = $this->service->tagEntity('#Brunch');

		$this->assertSame('Brunch', $entity['name']);
		$this->assertSame('Brunch', $entity['id']);
		$this->assertFalse($entity['trendable']);
		$this->assertTrue($entity['usable']);
		$this->assertTrue($entity['listable']);
	}

	public function testATagNobodyDecidedAboutIsTrendable(): void {
		$this->assertTrue($this->service->tagEntity('climbing')['trendable']);
	}

	public function testTheDecisionsOfOneKindAreAskedForByThatKind(): void {
		$this->trendReviewRequest->expects($this->once())
			->method('decisions')
			->with(TrendReviewRequest::KIND_LINK)
			->willReturn([]);

		$this->service->decisions('Link');
	}

	public function testReadingDecisionsOfAKindThisAppDoesNotReviewIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->decisions('account');
	}
}
