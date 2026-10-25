<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\RecommendationFeatureExtractor;
use OCA\Social\Service\RecommendationTopicCatalog;
use OCA\Social\Service\RecommendationTrendCandidates;
use OCA\Social\Service\TrendService;
use PHPUnit\Framework\TestCase;

class RecommendationTrendCandidatesTest extends TestCase {
	public function testTrendingRecallIsBoundedAndRecheckedForViewerAndExplicitFilters(): void {
		$viewer = (new Person())->setId('https://example.test/users/alice')->setUserId('alice')->setLocal(true);
		$own = (new Note())->setNid(101)->setAttributedTo($viewer->getId());
		$hidden = (new Note())->setNid(102)->setAttributedTo('https://remote.example/users/hidden')->setContent('Apple iPhone');
		$visible = (new Note())->setNid(103)->setAttributedTo('https://remote.example/users/visible')->setLanguage('de')->setContent('Apple iPhone');
		$inaccessible = (new Note())->setNid(104)->setAttributedTo('https://remote.example/users/private')->setContent('Apple iPhone');

		$trends = $this->createMock(TrendService::class);
		$trends->expects($this->once())->method('trendingStatuses')
			->with('1d', RecommendationTrendCandidates::MAX_CANDIDATES, 0, true, 'short')
			->willReturn([$own, $hidden, $visible, $inaccessible]);
		$streams = $this->createMock(StreamRequest::class);
		$streams->expects($this->once())->method('getVisibleByNids')->with(['103', '104'])
			->willReturn(['103' => $visible]);

		$source = new RecommendationTrendCandidates(
			$trends,
			$streams,
			new RecommendationFeatureExtractor(new RecommendationTopicCatalog()),
		);
		$result = $source->forViewer($viewer, 5, ['102'], ['de'], ['tech.apple'], 'shorts');

		$this->assertSame(['103' => $visible], $result);
	}

	public function testZeroBudgetDoesNotQueryTrendsOrStreams(): void {
		$trends = $this->createMock(TrendService::class);
		$trends->expects($this->never())->method('trendingStatuses');
		$streams = $this->createMock(StreamRequest::class);
		$streams->expects($this->never())->method('getVisibleByNids');

		$source = new RecommendationTrendCandidates(
			$trends,
			$streams,
			new RecommendationFeatureExtractor(new RecommendationTopicCatalog()),
		);

		$this->assertSame([], $source->forViewer(new Person(), 0));
	}
}
