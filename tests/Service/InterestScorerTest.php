<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Model\Interest;
use OCA\Social\Service\InterestScorer;
use PHPUnit\Framework\TestCase;

/** The arithmetic of For you, rule by rule. */
class InterestScorerTest extends TestCase {
	private const NOW = 1790000000;
	private const DAY = 86400;

	private function scorer(float $halfLife = 30, float $threshold = 3, int $cap = 30): InterestScorer {
		return new InterestScorer($halfLife, $threshold, $cap);
	}

	public function testTheExpectedReadingTimeGrowsWithTextAndPicturesAndStops(): void {
		$scorer = $this->scorer();

		$this->assertSame(1200, $scorer->expectedDwellMs(0, 0));
		$this->assertSame(1200 + 35 * 100, $scorer->expectedDwellMs(100, 0));
		$this->assertSame(1200 + 1500 * 2, $scorer->expectedDwellMs(0, 2));
		$this->assertSame(20000, $scorer->expectedDwellMs(5000, 4), 'nobody is expected to read all of a long post');
	}

	public function testAVideoTakesAsLongAsItRunsUpToTheCapOnOneLook(): void {
		$scorer = $this->scorer();

		$this->assertSame(8000, $scorer->expectedWatchMs(8000));
		$this->assertSame(InterestScorer::EXPECTED_WATCH_CAP_MS, $scorer->expectedWatchMs(600000));
		$this->assertSame(1, $scorer->expectedWatchMs(0));
	}

	public function testWatchingIsJudgedByHowMuchOfTheVideoWasSeen(): void {
		$scorer = $this->scorer();

		$this->assertSame(InterestScorer::SIGNAL_LONG_DWELL, $scorer->classifyWatch(10000, 10000), 'all of it');
		$this->assertSame(InterestScorer::SIGNAL_LONG_DWELL, $scorer->classifyWatch(9000, 10000), 'as good as all of it');
		$this->assertSame(InterestScorer::SIGNAL_DWELL, $scorer->classifyWatch(5000, 10000), 'half');
		$this->assertSame(0.0, $scorer->classifyWatch(2000, 10000), 'a moment says nothing');
	}

	public function testALookIsJudgedAgainstTheReadersOwnPace(): void {
		$scorer = $this->scorer();

		// the same four seconds on a post that takes two to read
		[$slowReader] = $scorer->classifyDwell(4000, 2000, 2.0);
		[$fastReader] = $scorer->classifyDwell(4000, 2000, 0.5);

		$this->assertSame(InterestScorer::SIGNAL_DWELL, $slowReader, 'twice the time is just their pace');
		$this->assertSame(InterestScorer::SIGNAL_LONG_DWELL, $fastReader, 'four times their pace held them');
	}

	public function testAGlanceTeachesNothing(): void {
		[$signal] = $this->scorer()->classifyDwell(300, 2000, 1.0);

		$this->assertSame(0.0, $signal);
	}

	public function testThePaceMovesALittleTowardsEveryLookAndIgnoresALunchBreak(): void {
		$scorer = $this->scorer();

		[, $after] = $scorer->classifyDwell(4000, 2000, 1.0);
		$this->assertEqualsWithDelta(0.95 + 0.05 * 2, $after, 1e-9);

		[, $lunch] = $scorer->classifyDwell(30000, 1200, 1.0);
		$this->assertEqualsWithDelta(0.95 + 0.05 * 10, $lunch, 1e-9, 'a ratio is capped at ten');
	}

	public function testAPostsSignalIsSharedEvenlyAmongItsTagsOnceEach(): void {
		$shares = $this->scorer()->share(3.0, ['Photography', '#photography', 'analog', 'film']);

		$this->assertSame(['photography' => 1.0, 'analog' => 1.0, 'film' => 1.0], $shares);
	}

	public function testTagSpamTeachesNothing(): void {
		$tags = array_map(static fn (int $i): string => 'tag' . $i, range(1, InterestScorer::MAX_TAGS + 1));

		$this->assertSame([], $this->scorer()->share(3.0, $tags));
		$this->assertCount(InterestScorer::MAX_TAGS, $this->scorer()->share(3.0, array_slice($tags, 0, InterestScorer::MAX_TAGS)));
	}

	public function testAScoreHalvesEveryHalfLife(): void {
		$scorer = $this->scorer(30);

		$this->assertEqualsWithDelta(5.0, $scorer->decayed(10.0, self::NOW - 30 * self::DAY, self::NOW), 1e-9);
		$this->assertEqualsWithDelta(2.5, $scorer->decayed(10.0, self::NOW - 60 * self::DAY, self::NOW), 1e-9);
		$this->assertSame(10.0, $scorer->decayed(10.0, 0, self::NOW), 'a row never scored has nothing to decay from');
	}

	public function testFoldingDecaysTheOldScoreBeforeAddingAndClamps(): void {
		$scorer = $this->scorer(30);
		$row = new Interest('cats', 10.0, self::NOW - 30 * self::DAY);

		$scorer->fold($row, 1.0, self::NOW);
		$this->assertEqualsWithDelta(6.0, $row->getScore(), 1e-9);
		$this->assertSame(self::NOW, $row->getScoredAt());

		$scorer->fold($row, 1000.0, self::NOW);
		$this->assertSame(InterestScorer::SCORE_MAX, $row->getScore());
		$scorer->fold($row, -1000.0, self::NOW);
		$this->assertSame(InterestScorer::SCORE_MIN, $row->getScore());
	}

	public function testAPinnedOrManualScoreDoesNotDecay(): void {
		$scorer = $this->scorer(30);

		$this->assertSame(10.0, $scorer->current(new Interest('a', 10.0, self::NOW - 90 * self::DAY, true), self::NOW));
		$this->assertSame(10.0, $scorer->current(new Interest('b', 10.0, self::NOW - 90 * self::DAY, false, 2), self::NOW));
	}

	public function testANewWeekKeepsWhereTheLastOneEndedForTheTrend(): void {
		$scorer = $this->scorer(30);
		$row = new Interest('cats', 10.0, self::NOW - 8 * self::DAY);

		$scorer->fold($row, 1.0, self::NOW);

		$this->assertEqualsWithDelta(10.0 * 2 ** (-8 / 30), $row->getScoreWeek(), 1e-9);
	}

	public function testAPauseMovesTheClocksOnSoNothingDecaysDuringIt(): void {
		$rows = [new Interest('a', 5.0, self::NOW - 10), new Interest('b', 5.0, 0)];

		$this->scorer()->shift($rows, 100);

		$this->assertSame(self::NOW + 90, $rows[0]->getScoredAt());
		$this->assertSame(0, $rows[1]->getScoredAt(), 'an unscored row stays unscored');
	}

	public function testTheListIsTheLearnedTagsAboveTheThresholdBestFirst(): void {
		$assembled = $this->scorer(30, 3)->assemble([
			new Interest('weak', 2.0, self::NOW),
			new Interest('strong', 9.0, self::NOW),
			new Interest('middle', 5.0, self::NOW),
			new Interest('disliked', -4.0, self::NOW),
		], [], self::NOW);

		$this->assertSame(['strong', 'middle'], array_column($assembled['listed'], 'tag'));
		$this->assertSame([0, 1], array_column($assembled['listed'], 'rank'));
		$this->assertSame([['tag' => 'weak', 'score' => 2.0]], $assembled['candidates']);
		$this->assertSame(['disliked'], $assembled['negative']);
		$this->assertTrue($assembled['thin'], 'two interests is still learning');
	}

	public function testFollowedAndManualTagsAreListedWithAtLeastTheThreshold(): void {
		$assembled = $this->scorer(30, 3)->assemble([
			new Interest('mine', 0.0, self::NOW, true),
			new Interest('learned', 4.0, self::NOW),
		], ['nextcloud'], self::NOW);

		$byTag = array_column($assembled['listed'], null, 'tag');
		$this->assertSame('manual', $byTag['mine']['source']);
		$this->assertSame('followed', $byTag['nextcloud']['source']);
		$this->assertSame(3.0, $byTag['nextcloud']['score']);
		$this->assertSame('learned', $assembled['listed'][0]['tag'], 'the floor is a floor, not a boost');
	}

	public function testAPinHoldsItsRankAndTheRestCloseUpAroundIt(): void {
		$assembled = $this->scorer()->assemble([
			new Interest('a', 9.0, self::NOW),
			new Interest('b', 8.0, self::NOW),
			new Interest('c', 7.0, self::NOW),
			new Interest('d', 6.0, self::NOW),
			new Interest('dragged', 3.5, self::NOW, false, 1),
		], [], self::NOW);

		$this->assertSame(['a', 'dragged', 'b', 'c', 'd'], array_column($assembled['listed'], 'tag'));
		$this->assertTrue($assembled['listed'][1]['pinned']);
	}

	public function testAPinBeyondTheEndLandsAtTheEndAndTwoPinsDoNotCollide(): void {
		$assembled = $this->scorer()->assemble([
			new Interest('a', 9.0, self::NOW),
			new Interest('far', 3.0, self::NOW, false, 40),
			new Interest('first', 3.0, self::NOW, false, 0),
			new Interest('alsoFirst', 3.0, self::NOW, false, 0),
		], [], self::NOW);

		$this->assertSame(['alsofirst', 'first', 'a', 'far'], array_map('strtolower', array_column($assembled['listed'], 'tag')));
	}

	public function testTheCapLimitsWhatFloatsInAndTheOverflowIsFirstAmongTheCandidates(): void {
		$rows = [];
		foreach (range(1, 7) as $i) {
			$rows[] = new Interest('t' . $i, 10.0 - $i, self::NOW);
		}
		$rows[] = new Interest('below', 1.0, self::NOW);

		$assembled = $this->scorer(30, 3, 5)->assemble($rows, [], self::NOW);

		$this->assertSame(['t1', 't2', 't3', 't4', 't5'], array_column($assembled['listed'], 'tag'));
		$this->assertSame(['t6', 't7', 'below'], array_column($assembled['candidates'], 'tag'));
	}

	public function testATrendNeedsARealChangeOverTheWeek(): void {
		$assembled = $this->scorer()->assemble([
			new Interest('rising', 8.0, self::NOW, false, null, 5.0),
			new Interest('falling', 4.0, self::NOW, false, null, 8.0),
			new Interest('steady', 5.0, self::NOW, false, null, 4.9),
			new Interest('new', 5.0, self::NOW),
		], [], self::NOW);

		$this->assertSame(
			['rising' => 'up', 'new' => null, 'steady' => null, 'falling' => 'down'],
			array_column($assembled['listed'], 'trend', 'tag')
		);
	}

	public function testTheWeightFollowsTheRankNotTheScore(): void {
		$scorer = $this->scorer();

		$this->assertSame(1.0, $scorer->weight(0));
		$this->assertEqualsWithDelta(0.4878, $scorer->weight(7), 1e-4);
		$this->assertEqualsWithDelta(0.1818, $scorer->weight(30), 1e-4);
	}

	public function testRelevanceCountsThreeMatchesAtMostAndEveryDislike(): void {
		$scorer = $this->scorer();

		$this->assertEqualsWithDelta(2.4, $scorer->relevance([1.0, 0.8, 0.6, 0.5, 0.4]), 1e-9);
		$this->assertEqualsWithDelta(0.5, $scorer->relevance([1.0, -0.5]), 1e-9);
		$this->assertLessThan(0, $scorer->relevance([-0.5]));
	}

	public function testRecencyHalvesADay(): void {
		$scorer = $this->scorer();

		$this->assertSame(1.0, $scorer->recency(0));
		$this->assertEqualsWithDelta(0.5, $scorer->recency(self::DAY), 1e-9);
		$this->assertEqualsWithDelta(0.25, $scorer->recency(2 * self::DAY), 1e-9);
	}
}
