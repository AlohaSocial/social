<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\StreamCard;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\StatisticsService;
use OCA\Social\Tests\Helper\InMemoryDurableCacheRequest;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StatisticsServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example/apps/social/@alice';
	private const BOB = 'https://remote.example/users/bob';
	private const CAROL = 'https://remote.example/users/carol';

	private StreamRequest|MockObject $streamRequest;
	private FollowsRequest|MockObject $followsRequest;
	private CacheActorsRequest|MockObject $cacheActorsRequest;
	private StatisticsService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->followsRequest = $this->createMock(FollowsRequest::class);
		$this->cacheActorsRequest = $this->createMock(CacheActorsRequest::class);
		// an instance with no memcache, and a table of its own per test, so each
		// test counts rather than reading what the one before it left behind
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('isAvailable')->willReturn(false);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(static fn (): int => time());

		$this->service = new StatisticsService(
			$this->streamRequest,
			$this->followsRequest,
			$this->cacheActorsRequest,
			new DurableCache($cacheFactory, new InMemoryDurableCacheRequest(), $time),
		);
	}

	private function alice(): Person {
		$actor = new Person();
		$actor->setId(self::ALICE)
			->setPreferredUsername('alice');
		$actor->setCreation(strtotime('2024-03-17T09:00:00Z'));

		return $actor;
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function post(int $nid, array $values = []): Note {
		$post = new Note();
		$post->setNid($nid);
		$post->setId(self::ALICE . '/' . $nid);
		$post->setContent($values['content'] ?? '<p>hello</p>');
		$post->setPublished($values['published'] ?? '2026-08-03T21:30:34Z');
		$post->setPublishedTime(strtotime($values['published'] ?? '2026-08-03T21:30:34Z'));
		$post->setVisibility($values['visibility'] ?? Stream::TYPE_PUBLIC);
		$post->setInReplyTo($values['in_reply_to'] ?? '');
		$post->setDetailInt('likes', $values['likes'] ?? 0);
		$post->setDetailInt('boosts', $values['boosts'] ?? 0);
		$post->setDetailInt('replies', $values['replies'] ?? 0);
		$post->setHashtags($values['hashtags'] ?? []);
		if ($values['media'] ?? false) {
			$post->setAttachments([new MediaAttachment()]);
		}
		if (isset($values['images'])) {
			$attachments = [];
			foreach ($values['images'] as $description) {
				$image = new MediaAttachment();
				$image->setType('image')->setDescription((string)$description);
				$attachments[] = $image;
			}
			$post->setAttachments($attachments);
		}
		if (isset($values['language'])) {
			$post->setLanguage((string)$values['language']);
		}
		if (isset($values['link'])) {
			$card = new StreamCard();
			$card->setUrl((string)$values['link']);
			$post->setCard($card);
		}
		if (isset($values['delivered'])) {
			foreach ($values['delivered'] as $inbox) {
				$post->addInstancePath(
					new InstancePath((string)$inbox, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_MEDIUM)
				);
			}
		}

		return $post;
	}

	/**
	 * @param Stream[] $posts
	 */
	private function answering(array $posts): void {
		// answered a page at a time, the size the caller asked for, so the walk
		// is exercised rather than handed everything at once
		$remaining = $posts;
		$this->streamRequest->method('getTimeline')
			->willReturnCallback(static function (ProbeOptions $options) use (&$remaining): array {
				return array_splice($remaining, 0, max(1, $options->getLimit()));
			});
	}

	/**
	 * The page is the most expensive read the app has. Kept in the distributed
	 * cache alone, an instance with no memcache counted it again on every load.
	 */
	public function testWithoutAMemcacheASecondLoadIsReadRatherThanCounted(): void {
		$this->streamRequest->expects($this->once())->method('getTimeline')->willReturn([]);

		$first = $this->service->cachedForAccount($this->alice());
		$second = $this->service->cachedForAccount($this->alice());

		$this->assertFalse($first['window']['cached']);
		$this->assertTrue($second['window']['cached']);
	}

	public function testAskingForAFreshPageCountsAgain(): void {
		$this->streamRequest->expects($this->exactly(2))->method('getTimeline')->willReturn([]);

		$this->service->cachedForAccount($this->alice());
		$fresh = $this->service->cachedForAccount($this->alice(), 0, true);

		$this->assertFalse($fresh['window']['cached']);
	}

	public function testItCountsWhatTheAccountWroteAndWhatCameBack(): void {
		$this->answering([
			$this->post(3, ['likes' => 9, 'boosts' => 4, 'replies' => 2, 'hashtags' => ['nextcloud']]),
			$this->post(2, ['likes' => 1, 'in_reply_to' => self::ALICE . '/1', 'hashtags' => ['nextcloud', 'a11y']]),
			$this->post(1, ['media' => true, 'visibility' => Stream::TYPE_FOLLOWERS]),
		]);
		$this->followsRequest->method('countFollowers')->willReturn(26);
		$this->followsRequest->method('countFollowing')->willReturn(24);

		$stats = $this->service->forAccount($this->alice());

		$this->assertSame(3, $stats['posts']['total']);
		$this->assertSame(2, $stats['posts']['originals']);
		$this->assertSame(1, $stats['posts']['replies']);
		$this->assertSame(1, $stats['posts']['with_media']);
		$this->assertSame(10, $stats['engagement']['likes']);
		$this->assertSame(4, $stats['engagement']['boosts']);
		$this->assertSame(2, $stats['engagement']['replies']);
		// per post the account wrote, which is all three of these
		$this->assertSame(3.3, $stats['engagement']['likes_per_post']);
		$this->assertSame(2, $stats['visibility'][Stream::TYPE_PUBLIC]);
		$this->assertSame(1, $stats['visibility'][Stream::TYPE_FOLLOWERS]);
		$this->assertSame(26, $stats['account']['followers']);
		$this->assertSame(24, $stats['account']['following']);
	}

	/** A boost is something the account did, not something it wrote. */
	public function testABoostIsCountedButItsLikesAreNotTheAccountsOwn(): void {
		$boost = new Stream();
		$boost->setNid(2);
		$boost->setId(self::ALICE . '/2');
		$boost->setType('Announce');
		$boost->setPublishedTime(strtotime('2026-08-04T10:00:00Z'));
		$boost->setDetailInt('likes', 100);

		$this->answering([$boost, $this->post(1, ['likes' => 3])]);

		$stats = $this->service->forAccount($this->alice());

		$this->assertSame(2, $stats['posts']['total']);
		$this->assertSame(1, $stats['posts']['boosts']);
		$this->assertSame(1, $stats['posts']['originals']);
		// the hundred likes belong to whoever wrote the boosted post
		$this->assertSame(3, $stats['engagement']['likes']);
	}

	public function testTheBestPostsAreTheMostAnsweredOnesInOrder(): void {
		$this->answering([
			$this->post(3, ['likes' => 1, 'boosts' => 1, 'content' => '<p>quiet</p>']),
			$this->post(2, ['likes' => 9, 'boosts' => 4, 'content' => '<p>loud</p>']),
			$this->post(1, ['likes' => 5, 'boosts' => 0, 'content' => '<p>middling</p>']),
		]);

		$stats = $this->service->forAccount($this->alice());

		$this->assertSame(['loud', 'middling', 'quiet'], array_column($stats['best'], 'excerpt'));
		$this->assertSame('2', $stats['best'][0]['id']);
	}

	/** The excerpt is a post's text, not its markup. */
	public function testAnExcerptCarriesNoMarkupAndIsCutShort(): void {
		$this->answering([
			$this->post(1, ['content' => '<p>a <a href="https://example.org">link</a> &amp; some <b>bold</b></p>']),
			$this->post(2, ['content' => '<p>' . str_repeat('long ', 40) . '</p>']),
		]);

		$stats = $this->service->forAccount($this->alice());
		$excerpts = array_column($stats['best'], 'excerpt');

		$this->assertContains('a link & some bold', $excerpts);
		foreach ($excerpts as $excerpt) {
			$this->assertStringNotContainsString('<', $excerpt);
			$this->assertLessThanOrEqual(90, mb_strlen($excerpt));
		}
	}

	/** Twelve columns whether or not anything was posted in them. */
	public function testTheMonthsAreAlwaysTwelveAndAlwaysInOrder(): void {
		$this->answering([$this->post(1, ['published' => gmdate('Y-m-01\TH:i:s\Z')])]);

		$stats = $this->service->forAccount($this->alice());

		$this->assertCount(12, $stats['by_month']);
		$this->assertSame(array_keys($stats['by_month']), array_keys($stats['by_month']));
		$this->assertSame(1, $stats['by_month'][gmdate('Y-m')]);
		$this->assertCount(24, $stats['by_hour']);
	}

	public function testTheHashtagsAreTheMostUsedOnesLowercased(): void {
		$this->answering([
			$this->post(1, ['hashtags' => ['NextCloud', 'a11y']]),
			$this->post(2, ['hashtags' => ['nextcloud']]),
		]);

		$stats = $this->service->forAccount($this->alice());

		$this->assertSame('nextcloud', $stats['hashtags'][0]['name']);
		$this->assertSame(2, $stats['hashtags'][0]['count']);
	}

	/** The four rates an agency reports on, and the two that keep them honest. */
	public function testItReportsTheRatesAndNotOnlyTheTotals(): void {
		$this->answering([
			$this->post(4, ['likes' => 20, 'boosts' => 8, 'replies' => 2]),
			$this->post(3, ['likes' => 2]),
			$this->post(2),
			$this->post(1),
		]);
		$this->followsRequest->method('countFollowers')->willReturn(100);

		$stats = $this->service->forAccount($this->alice());

		$this->assertSame(32, $stats['rates']['total']);
		$this->assertSame(8.0, $stats['rates']['per_post']);
		$this->assertSame(5.5, $stats['rates']['applause']);
		$this->assertSame(2.0, $stats['rates']['amplification']);
		$this->assertSame(0.5, $stats['rates']['conversation']);
		// 8 engagement per post against 100 followers
		$this->assertSame(8.0, $stats['rates']['per_follower']);
		// the mean is 8 and the middle post got 1: which is the point of both
		$this->assertSame(1.0, $stats['rates']['median']);
		$this->assertSame(30, $stats['rates']['best']);
		$this->assertSame(2, $stats['rates']['silent']);
		$this->assertSame(50.0, $stats['rates']['silent_share']);
	}

	/** A rate needs a denominator, and an account with no followers has none. */
	public function testTheEngagementRateIsNotADivisionByNothing(): void {
		$this->answering([$this->post(1, ['likes' => 5])]);
		$this->followsRequest->method('countFollowers')->willReturn(0);

		$stats = $this->service->forAccount($this->alice());

		$this->assertSame(0.0, $stats['rates']['per_follower']);
	}

	public function testItSaysWhichKindOfPostDoesBetter(): void {
		$this->answering([
			$this->post(3, ['likes' => 10, 'media' => true]),
			$this->post(2, ['likes' => 2, 'hashtags' => ['a11y']]),
			$this->post(1, ['likes' => 0]),
		]);

		$stats = $this->service->forAccount($this->alice());
		$byKey = array_column($stats['content'], null, 'key');

		$this->assertSame(10.0, $byKey['with_media']['average']);
		$this->assertSame(1.0, $byKey['text_only']['average']);
		$this->assertSame(2.0, $byKey['with_hashtag']['average']);
		// a kind with no posts is absent rather than reported as zero
		$this->assertArrayNotHasKey('reply', $byKey);
	}

	/** One lucky post at four in the morning is not a time of day that works. */
	public function testOneGoodPostDoesNotMakeAnHourTheBestOne(): void {
		$this->answering([
			$this->post(3, ['published' => '2026-08-03T04:00:00Z', 'likes' => 100]),
			$this->post(2, ['published' => '2026-08-04T09:00:00Z', 'likes' => 5]),
			$this->post(1, ['published' => '2026-08-05T09:00:00Z', 'likes' => 5]),
		]);

		$stats = $this->service->forAccount($this->alice());

		$this->assertNull($stats['best_hour']['hour']);
	}

	/** Three in the same hour is enough to name it. */
	public function testTheBestHourIsNamedOnceThereAreEnoughPostsInIt(): void {
		$this->answering([
			$this->post(3, ['published' => '2026-08-03T09:00:00Z', 'likes' => 5]),
			$this->post(2, ['published' => '2026-08-04T09:00:00Z', 'likes' => 5]),
			$this->post(1, ['published' => '2026-08-05T09:00:00Z', 'likes' => 5]),
		]);

		$stats = $this->service->forAccount($this->alice());

		$this->assertSame(9, $stats['best_hour']['hour']);
		$this->assertSame(5.0, $stats['best_hour']['average']);
		$this->assertSame(3, $stats['best_hour']['posts']);
	}

	/** The tags worth using are not the same list as the tags used most. */
	public function testHashtagPerformanceIgnoresATagUsedOnce(): void {
		$this->answering([
			$this->post(3, ['likes' => 100, 'hashtags' => ['lucky']]),
			$this->post(2, ['likes' => 4, 'hashtags' => ['steady']]),
			$this->post(1, ['likes' => 6, 'hashtags' => ['steady']]),
		]);

		$stats = $this->service->forAccount($this->alice());

		$this->assertSame([['name' => 'steady', 'posts' => 2, 'average' => 5.0]], $stats['hashtag_performance']);
		// it is still in the list of what the account writes about
		$this->assertContains('lucky', array_column($stats['hashtags'], 'name'));
	}

	public function testTheAudienceIsWhereTheFollowersAre(): void {
		$this->followsRequest->method('countFollowers')->willReturn(3);
		$this->followsRequest->method('getFollowerOrigins')->willReturn([
			['actor_id' => 'https://remote.example/users/bob', 'creation' => gmdate('Y-m-d H:i:s')],
			['actor_id' => 'https://remote.example/users/carol', 'creation' => gmdate('Y-m-d H:i:s')],
			['actor_id' => 'https://cloud.example/apps/social/@dave', 'creation' => gmdate('Y-m-d H:i:s')],
		]);
		$this->answering([]);

		$stats = $this->service->forAccount($this->alice());

		$this->assertSame(
			[['host' => 'remote.example', 'count' => 2], ['host' => 'cloud.example', 'count' => 1]],
			$stats['audience']['instances']
		);
		// alice is on cloud.example, so one of the three is a neighbour
		$this->assertSame(33.3, $stats['audience']['local_share']);
		$this->assertSame(3, $stats['audience']['by_month'][gmdate('Y-m')]);
	}

	/** The walk stops, and says that it did. */
	public function testTheWalkIsBoundedAndTheAnswerSaysSo(): void {
		// newest first, which is the order a timeline answers in and the order
		// the keyset walk depends on
		$posts = [];
		for ($nid = StatisticsService::MAX_POSTS + 100; $nid >= 1; $nid--) {
			$posts[] = $this->post($nid);
		}
		$this->answering($posts);

		$stats = $this->service->forAccount($this->alice());

		$this->assertSame(StatisticsService::MAX_POSTS, $stats['window']['counted']);
		$this->assertTrue($stats['window']['capped']);
	}

	/** An account that has posted nothing is a page of zeroes, not a crash. */
	public function testAnAccountWithNoPostsDividesByNothing(): void {
		$this->answering([]);

		$stats = $this->service->forAccount($this->alice());

		$this->assertSame(0, $stats['posts']['total']);
		$this->assertSame(0.0, $stats['engagement']['likes_per_post']);
		$this->assertSame([], $stats['best']);
		$this->assertSame([], $stats['hashtags']);
		$this->assertFalse($stats['window']['capped']);
	}

	/** An ISO date a post can be published at, so many days back. */
	private function daysAgo(int $days, int $hour = 12): string {
		$midnight = (int)strtotime(gmdate('Y-m-d') . ' 00:00:00 UTC');

		return gmdate('Y-m-d\TH:i:s\Z', $midnight - ($days * 86400) + ($hour * 3600));
	}

	/** Thirty days beside the thirty before them, which is the whole point. */
	public function testItComparesTheLastThirtyDaysWithTheThirtyBefore(): void {
		$this->answering([
			$this->post(4, ['published' => $this->daysAgo(2), 'likes' => 6, 'boosts' => 2, 'replies' => 1]),
			$this->post(3, ['published' => $this->daysAgo(20), 'likes' => 4]),
			$this->post(2, ['published' => $this->daysAgo(40), 'likes' => 5]),
			// older than both windows: in the totals above, in neither period
			$this->post(1, ['published' => $this->daysAgo(200), 'likes' => 90]),
		]);
		$this->followsRequest->method('countFollowers')->willReturn(10);

		$periods = $this->service->forAccount($this->alice())['periods'];

		$this->assertSame(30, $periods['days']);
		$this->assertSame(2, $periods['current']['posts']);
		$this->assertSame(1, $periods['previous']['posts']);
		$this->assertSame(13, $periods['current']['interactions']);
		$this->assertSame(5, $periods['previous']['interactions']);
		$this->assertSame(10, $periods['current']['likes']);
		// 13 against 5
		$this->assertSame(160.0, $periods['change']['interactions']);
		$this->assertSame(100.0, $periods['change']['posts']);
	}

	/** Everything is infinitely more than nothing, which is not a percentage. */
	public function testAnEmptyWindowBeforeItLeavesNothingToComparAgainst(): void {
		$this->answering([$this->post(1, ['published' => $this->daysAgo(2), 'likes' => 4])]);

		$periods = $this->service->forAccount($this->alice())['periods'];

		$this->assertSame(4, $periods['current']['interactions']);
		$this->assertSame(0, $periods['previous']['interactions']);
		$this->assertNull($periods['change']['interactions']);
	}

	/** A post is counted on the day it went out, in both windows alike. */
	public function testTheDailySeriesPutsAPostOnTheDayItWentOut(): void {
		$this->answering([
			$this->post(2, ['published' => $this->daysAgo(0, 0), 'likes' => 3]),
			$this->post(1, ['published' => $this->daysAgo(29, 0), 'likes' => 1]),
		]);

		$series = $this->service->forAccount($this->alice())['periods']['current']['series'];

		$this->assertCount(30, $series['likes']);
		// day one of the window is thirty days back; today is the last column
		$this->assertSame(1, $series['likes'][0]);
		$this->assertSame(3, $series['likes'][29]);
		$this->assertSame(4, array_sum($series['interactions']));
	}

	/** Reach is the account's own audience plus everybody who passed it on. */
	public function testReachIsTheAccountsFollowersPlusItsBoostersAudiences(): void {
		$this->answering([
			$this->post(2, ['published' => $this->daysAgo(1), 'boosts' => 2]),
			$this->post(1, ['published' => $this->daysAgo(3)]),
		]);
		$this->followsRequest->method('countFollowers')->willReturn(40);
		$this->streamRequest->method('boostersOf')->willReturn([
			self::ALICE . '/2' => ['https://remote.example/users/bob', 'https://remote.example/users/carol'],
		]);
		$this->cacheActorsRequest->method('followerCountsOf')->willReturn([
			'https://remote.example/users/bob' => 300,
			'https://remote.example/users/carol' => 60,
		]);

		$stats = $this->service->forAccount($this->alice());
		$byId = array_column($stats['timeline'], null, 'id');

		$this->assertSame(400, $byId['2']['reach']);
		// nobody boosted the other one, so it reached the account's own people
		$this->assertSame(40, $byId['1']['reach']);
		$this->assertSame(440, $stats['periods']['current']['reach']);
		$this->assertSame(2, $stats['reach']['known_boosters']);
		$this->assertSame(0, $stats['reach']['unknown_boosters']);
	}

	/** A booster nothing is known about is a gap, and the page is told so. */
	public function testABoosterWithNoKnownAudienceIsAGapRatherThanAZero(): void {
		$this->answering([$this->post(1, ['published' => $this->daysAgo(1), 'boosts' => 1])]);
		$this->followsRequest->method('countFollowers')->willReturn(12);
		$this->streamRequest->method('boostersOf')->willReturn([
			self::ALICE . '/1' => ['https://remote.example/users/stranger'],
		]);
		$this->cacheActorsRequest->method('followerCountsOf')->willReturn([]);

		$stats = $this->service->forAccount($this->alice());

		$this->assertSame(12, $stats['timeline'][0]['reach']);
		$this->assertSame(0, $stats['reach']['known_boosters']);
		$this->assertSame(1, $stats['reach']['unknown_boosters']);
	}

	/** A direct message is not published to the followers. */
	public function testADirectMessageReachesNobodyByFollowing(): void {
		$this->answering([
			$this->post(1, ['published' => $this->daysAgo(1), 'visibility' => Stream::TYPE_DIRECT]),
		]);
		$this->followsRequest->method('countFollowers')->willReturn(90);

		$stats = $this->service->forAccount($this->alice());

		$this->assertSame(0, $stats['timeline'][0]['reach']);
	}

	/** No boosts anywhere in the window means no lookup at all. */
	public function testAWindowWithoutABoostAsksTheDatabaseNothingExtra(): void {
		$this->answering([$this->post(1, ['published' => $this->daysAgo(1), 'likes' => 2])]);
		$this->streamRequest->expects($this->never())->method('boostersOf');
		$this->cacheActorsRequest->expects($this->never())->method('followerCountsOf');

		$this->service->forAccount($this->alice());
	}

	/** The window's posts, one by one, newest first and bounded. */
	public function testTheWindowsPostsAreListedNewestFirstAndBounded(): void {
		$posts = [];
		for ($nid = StatisticsService::TIMELINE_POSTS + 10; $nid >= 1; $nid--) {
			$posts[] = $this->post($nid, ['published' => $this->daysAgo(1, 0), 'content' => '<p>#' . $nid . '</p>']);
		}
		// one from before the window, which the list leaves out
		$posts[] = $this->post(0, ['published' => $this->daysAgo(45)]);
		$this->answering($posts);

		$timeline = $this->service->forAccount($this->alice())['timeline'];

		$this->assertCount(StatisticsService::TIMELINE_POSTS, $timeline);
		$this->assertSame((string)(StatisticsService::TIMELINE_POSTS + 10), $timeline[0]['id']);
		$this->assertNotContains('0', array_column($timeline, 'id'));
		// what the list draws a row out of
		$this->assertSame(
			['id', 'url', 'published_at', 'excerpt', 'likes', 'boosts', 'replies', 'score', 'media', 'visibility', 'reach'],
			array_keys($timeline[0])
		);
	}

	/** The walk asks for the account's own posts and nothing else. */
	public function testItAsksForTheAccountsOwnTimeline(): void {
		$seen = null;
		$this->streamRequest->method('getTimeline')
			->willReturnCallback(static function (ProbeOptions $options) use (&$seen): array {
				$seen ??= $options;

				return [];
			});

		$this->service->forAccount($this->alice());

		$this->assertSame(ProbeOptions::ACCOUNT, $seen?->getProbe());
		$this->assertSame(self::ALICE, $seen?->getAccountId());
	}

	/**
	 * A picture nobody can see is a picture with no description.
	 *
	 * Counted per picture rather than per post: one post with four pictures
	 * and a single alt text is not three-quarters done.
	 */
	public function testAltTextIsCountedPerPictureRatherThanPerPost(): void {
		$this->answering([
			$this->post(1, ['images' => ['a cat', '', '', '']]),
			$this->post(2, ['images' => ['a dog']]),
		]);

		$page = $this->service->forAccount($this->alice());

		$this->assertSame(5, $page['media']['images']);
		$this->assertSame(2, $page['media']['described']);
		$this->assertSame(40.0, $page['media']['described_share']);
	}

	public function testAnAccountWithNoPicturesIsNotJudgedOnThem(): void {
		$this->answering([$this->post(1)]);

		$page = $this->service->forAccount($this->alice());

		$this->assertSame(0, $page['media']['images']);
		$this->assertSame(0.0, $page['media']['described_share']);
	}

	public function testWhatTheAccountWritesInAndLinksTo(): void {
		$this->answering([
			$this->post(1, ['language' => 'de', 'link' => 'https://heise.de/news/1']),
			$this->post(2, ['language' => 'DE', 'link' => 'https://heise.de/news/2']),
			$this->post(3, ['language' => 'en', 'link' => 'https://example.org/x']),
		]);

		$page = $this->service->forAccount($this->alice());

		$this->assertSame([['name' => 'de', 'count' => 2], ['name' => 'en', 'count' => 1]], $page['languages']);
		$this->assertSame('heise.de', $page['domains'][0]['name']);
		$this->assertSame(2, $page['domains'][0]['count']);
	}

	/**
	 * Measured, beside the estimate rather than instead of it: a post written
	 * before the instance paths were stored has none and would read as zero.
	 */
	public function testTheServersThePostsWereActuallySentTo(): void {
		$this->answering([
			$this->post(1, ['delivered' => ['https://a.example/inbox', 'https://b.example/inbox']]),
			$this->post(2, ['delivered' => ['https://b.example/inbox']]),
		]);

		$page = $this->service->forAccount($this->alice());

		$this->assertSame(2, $page['reach']['instances']);
	}

	/**
	 * Every other figure is about what came back. This is the one about what
	 * the account did — which is what moves all the others.
	 */
	public function testWhatTheAccountDidMonthByMonth(): void {
		$this->answering([
			$this->post(1, ['published' => '2026-08-03T10:00:00Z']),
			$this->post(2, ['published' => '2026-08-04T10:00:00Z', 'in_reply_to' => 'https://x/1']),
		]);

		$page = $this->service->forAccount($this->alice());

		$this->assertSame(1, $page['activity']['originals']['2026-08']);
		$this->assertSame(1, $page['activity']['replies']['2026-08']);
	}

	public function testHowRegularlyItPosts(): void {
		$this->answering([
			$this->post(1, ['published' => '2026-08-01T10:00:00Z']),
			$this->post(2, ['published' => '2026-08-02T10:00:00Z']),
			$this->post(3, ['published' => '2026-08-03T10:00:00Z']),
			$this->post(4, ['published' => '2026-08-10T10:00:00Z']),
		]);

		$page = $this->service->forAccount($this->alice());

		$this->assertSame(4, $page['consistency']['active_days']);
		$this->assertSame(10, $page['consistency']['span_days']);
		$this->assertSame(3, $page['consistency']['streak']);
		// the six days between the third and the fourth
		$this->assertSame(6, $page['consistency']['longest_gap']);
	}

	/** Two posts on one day is one active day, not two. */
	public function testTwoPostsInADayAreOneDay(): void {
		$this->answering([
			$this->post(1, ['published' => '2026-08-01T09:00:00Z']),
			$this->post(2, ['published' => '2026-08-01T21:00:00Z']),
		]);

		$this->assertSame(1, $this->service->forAccount($this->alice())['consistency']['active_days']);
	}

	/**
	 * The window is a choice the reader makes, and the answer says which one
	 * it used — the numbers used to mean "your most recent two thousand
	 * posts" and only admitted it in a footnote.
	 */
	public function testTheWindowIsReportedWithTheNumbersItProduced(): void {
		$this->answering([$this->post(1)]);

		$page = $this->service->forAccount($this->alice(), 90);

		$this->assertSame(90, $page['window']['days']);
		$this->assertSame([0, 30, 90, 365], $page['window']['choices']);
	}

	/** A window nobody offers is read as everything, not as nothing. */
	public function testAWindowThatIsNotOnOfferIsEverything(): void {
		$this->answering([$this->post(1)]);

		$this->assertSame(0, $this->service->forAccount($this->alice(), 7)['window']['days']);
	}

	/**
	 * The two directions are asked for separately and reported separately: the
	 * people who answer an account and the people it answers are different
	 * lists, and a page that merged them would say neither.
	 */
	public function testItNamesWhoTheAccountTalksWithInBothDirections(): void {
		$this->answering([$this->post(1)]);
		$this->followsRequest->method('countFollowers')->willReturn(5);
		$this->streamRequest->method('countConversationPartners')
			->willReturnCallback(static function (string $id, int $direction): array {
				return ($direction === StreamRequest::PARTNERS_INBOUND)
					? [['id' => self::BOB, 'account' => 'bob@remote.example', 'replies' => 7]]
					: [['id' => self::CAROL, 'account' => 'carol@remote.example', 'replies' => 3]];
			});
		$this->followsRequest->method('getBetweenMany')->willReturn(['following' => [], 'followedBy' => []]);

		$partners = $this->service->forAccount($this->alice())['partners'];

		$this->assertSame('bob@remote.example', $partners['inbound'][0]['account']);
		$this->assertSame(7, $partners['inbound'][0]['replies']);
		$this->assertSame('carol@remote.example', $partners['outbound'][0]['account']);
	}

	/**
	 * The interesting half: of the people who replied, how many the account
	 * never asked to hear from.
	 */
	public function testItSaysHowManyOfTheRepliersTheAccountDoesNotFollow(): void {
		$this->answering([$this->post(1)]);
		$this->followsRequest->method('countFollowers')->willReturn(5);
		$this->streamRequest->method('countConversationPartners')
			->willReturnCallback(static function (string $id, int $direction): array {
				return ($direction === StreamRequest::PARTNERS_INBOUND) ? [
					['id' => self::BOB, 'account' => 'bob@remote.example', 'replies' => 7],
					['id' => self::CAROL, 'account' => 'carol@remote.example', 'replies' => 2],
				] : [];
			});
		$this->followsRequest->method('getBetweenMany')->willReturn([
			'following' => [self::BOB => new Follow()],
			'followedBy' => [],
		]);

		$partners = $this->service->forAccount($this->alice())['partners'];

		$this->assertTrue($partners['inbound'][0]['followed']);
		$this->assertFalse($partners['inbound'][1]['followed']);
		// one of the two
		$this->assertSame(50.0, $partners['not_followed_share']);
	}

	/** Nobody has replied yet, so nothing is claimed about who they were. */
	public function testAnAccountNobodyHasAnsweredHasNoPartners(): void {
		$this->answering([$this->post(1)]);
		$this->followsRequest->method('countFollowers')->willReturn(5);
		$this->followsRequest->expects($this->never())->method('getBetweenMany');

		$partners = $this->service->forAccount($this->alice())['partners'];

		$this->assertSame([], $partners['inbound']);
		$this->assertSame([], $partners['outbound']);
		$this->assertSame(0.0, $partners['not_followed_share']);
	}
}
