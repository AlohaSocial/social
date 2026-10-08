<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\SocialQueryBuilder;
use OCA\Social\Model\ActivityPub\Actor\Person;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * How a post's tags are compared with the tag a reader asked for.
 *
 * `LOWER(st.hashtag) = LOWER(:tag)` cannot be answered by `social_st_ht`: the
 * tag was a filter over every tag row of every candidate post, walked
 * newest-first until twenty matched (`EXPLAIN`: `st range sh … Using where;
 * Using temporary; Using filesort`). The rows are stored normalised now, so
 * both reads compare the column as it stands.
 */
#[AllowMockObjectsWithoutExpectations]
class HashtagComparisonTest extends TestCase {
	public function testTheFollowedTagsJoinComparesTheStoredTagAsItStands(): void {
		$on = [];
		$qb = $this->getMockBuilder(SocialQueryBuilder::class)
			->disableOriginalConstructor()
			->onlyMethods(['hasViewer', 'getViewer', 'expr', 'func', 'createNamedParameter', 'innerJoin', 'getDefaultSelectAlias', 'prim'])
			->getMock();
		$viewer = new Person();
		$viewer->setId('https://cloud.example/@alice');
		$qb->method('hasViewer')->willReturn(true);
		$qb->method('getViewer')->willReturn($viewer);
		$qb->method('expr')->willReturn(new FakeExpressions());
		$qb->method('func')->willThrowException(new \LogicException('no function over a tag column'));
		$qb->method('getDefaultSelectAlias')->willReturn('s');
		$qb->method('prim')->willReturnCallback(static fn (string $id): string => md5($id));
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value): string => ':' . $value);
		$qb->method('innerJoin')->willReturnCallback(function ($from, $table, $alias, $condition) use (&$on, $qb) {
			$on[$alias] = (string)$condition;

			return $qb;
		});

		$qb->limitToFollowedTags('ft_st', 'ft');

		$this->assertSame('ft_st.stream_id = s.id_prim', $on['ft_st']);
		$this->assertSame(
			'(ft.actor_id_prim = :' . md5('https://cloud.example/@alice') . ' AND ft.hashtag = ft_st.hashtag)',
			$on['ft']
		);
	}

	public function testTheHashtagTimelineComparesTheNormalisedTagExactly(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../lib/Db/StreamTimelines.php');
		$body = preg_split('/function hashtagTimelineNids\(/', $source, 2)[1] ?? '';
		$body = preg_split('/\n\t\}\n/', $body, 2)[0];

		$this->assertStringContainsString("'st.hashtag', \$page->createNamedParameter(FollowedTagsRequest::normalise(", $body);
		$this->assertStringNotContainsString("exprLimitToDBField('hashtag'", $body);
	}

	/**
	 * For you reads its candidates through the tag index too: the reader's
	 * interests are normalised tags, so the stored column is compared with
	 * them as it stands.
	 */
	public function testForYouComparesTheStoredTagAsItStands(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../lib/Db/StreamInterests.php');
		$body = preg_split('/function interestCandidates\(/', $source, 2)[1] ?? '';
		$body = preg_split('/\n\t\}\n/', $body, 2)[0];

		$this->assertMatchesRegularExpression('/\$expr->in\(\s+\'st\.hashtag\',/', $body);
		$this->assertStringNotContainsString("lower('st.hashtag')", $body);
	}
}
