<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Model\Client\DirectorySource;
use OCA\Social\Model\Client\PeerTag;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\FediverseDirectoryService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\PeerTrendService;
use OCA\Social\Service\TrendReviewService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Asking other servers what they are talking about.
 *
 * What is mocked here is the network and nothing else. Everything asserted
 * below is something that can only go wrong once a stranger's server is
 * answering: a tag that is a sentence, a Mastodon history whose shape changed,
 * a server that names the same tag twice, one that does not answer at all.
 */
class PeerTrendServiceTest extends TestCase {
	private const LOCAL = 'cloud.example';

	private CurlService|Stub $curlService;
	private FediverseDirectoryService|Stub $directoryService;
	private HashtagService|Stub $hashtagService;
	private TrendReviewService|Stub $trendReviewService;
	private PeerTrendService $service;

	/** url fragment => what that request answers with, or a Throwable to raise */
	private array $answers = [];
	/** every URL actually requested, in order */
	private array $asked = [];
	/** the sources the directory offers */
	private array $sources = [];
	/** what this instance's own trend holds */
	private array $localTags = [];
	/** hashtags a moderator here has rejected */
	private array $rejected = [];

	protected function setUp(): void {
		parent::setUp();

		$this->curlService = $this->createStub(CurlService::class);
		$this->curlService->method('retrieveJson')
			->willReturnCallback(function (string $method, string $url): array {
				$this->asked[] = $url;
				foreach ($this->answers as $needle => $answer) {
					if (str_contains($url, (string)$needle)) {
						if ($answer instanceof \Throwable) {
							throw $answer;
						}

						return $answer;
					}
				}

				throw new RuntimeException('nothing answers ' . $url);
			});

		$this->directoryService = $this->createStub(FediverseDirectoryService::class);
		$this->directoryService->method('sources')->willReturnCallback(fn (): array => $this->sources);

		$this->hashtagService = $this->createStub(HashtagService::class);
		$this->hashtagService->method('getTrending')->willReturnCallback(fn (): array => $this->localTags);
		$this->hashtagService->method('searchHashtags')
			->willReturnCallback(function (string $term): array {
				return array_values(array_filter(
					$this->localTags,
					static fn (array $row): bool => str_contains((string)$row['hashtag'], $term)
				));
			});

		$this->trendReviewService = $this->createStub(TrendReviewService::class);
		$this->trendReviewService->method('filterTags')
			->willReturnCallback(function (array $rows): array {
				return array_values(array_filter(
					$rows,
					fn (array $row): bool => !in_array($row['hashtag'], $this->rejected, true)
				));
			});

		$this->service = $this->build($this->createStub(ICache::class));
	}

	private function build(ICache|MockObject $cache): PeerTrendService {
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		return new PeerTrendService(
			$this->curlService,
			$this->directoryService,
			$this->hashtagService,
			$this->trendReviewService,
			new NullLogger(),
			$factory
		);
	}

	private function source(string $host, string $kind, string $label = ''): DirectorySource {
		return new DirectorySource($host, $kind, $label === '' ? $host : $label);
	}

	private function local(): DirectorySource {
		return $this->source(self::LOCAL, DirectorySource::KIND_LOCAL);
	}

	/** A Mastodon trends page, as that API shapes one. */
	private function mastodonTrends(array $tags): array {
		$rows = [];
		foreach ($tags as $name => $uses) {
			$rows[] = [
				'name' => $name,
				'url' => 'https://example.org/tags/' . $name,
				'history' => [['day' => '1700000000', 'uses' => (string)$uses, 'accounts' => '3']],
			];
		}

		return $rows;
	}

	/** @return string[] */
	private function names(array $result): array {
		return array_map(static fn (PeerTag $tag): string => $tag->getName(), $result['tags']);
	}

	public function testATagSeveralServersNameOutranksOneThatIsBusierOnASingleServer(): void {
		$this->sources = [
			$this->source('one.example', DirectorySource::KIND_MASTODON),
			$this->source('two.example', DirectorySource::KIND_MASTODON),
			$this->source('three.example', DirectorySource::KIND_MASTODON),
		];
		$this->answers = [
			'one.example' => $this->mastodonTrends(['photography' => 5, 'enormous' => 9000]),
			'two.example' => $this->mastodonTrends(['photography' => 4]),
			'three.example' => $this->mastodonTrends(['photography' => 3]),
		];

		$tags = $this->service->tags()['tags'];

		$this->assertSame('photography', $tags[0]->getName());
		$this->assertSame(3, $tags[0]->countServers());
		// the count is kept, it just does not decide the order
		$this->assertSame('enormous', $tags[1]->getName());
		$this->assertSame(9000, $tags[1]->getUses());
	}

	public function testAServerThatNamesATagTwiceStillCountsOnce(): void {
		$this->sources = [$this->source('one.example', DirectorySource::KIND_MASTODON)];
		$this->answers = ['one.example' => array_merge(
			$this->mastodonTrends(['photography' => 5]),
			$this->mastodonTrends(['Photography' => 7])
		)];

		$tags = $this->service->tags()['tags'];

		$this->assertCount(1, $tags);
		$this->assertSame(1, $tags[0]->countServers());
		$this->assertSame(5, $tags[0]->getUses(), 'the first answer, not both added together');
	}

	public function testWhatIsBusyHereIsMarkedAsHereAndNotAsElsewhere(): void {
		$this->sources = [$this->local(), $this->source('one.example', DirectorySource::KIND_MASTODON)];
		$this->localTags = [['hashtag' => 'nextcloud', 'trend' => ['1d' => 12]]];
		$this->answers = ['one.example' => $this->mastodonTrends(['berlin' => 4])];

		$tags = [];
		foreach ($this->service->tags()['tags'] as $tag) {
			$tags[$tag->getName()] = $tag;
		}

		$this->assertTrue($tags['nextcloud']->isLocal());
		$this->assertFalse($tags['nextcloud']->isRemote(), 'only this server named it');
		$this->assertFalse($tags['berlin']->isLocal());
		$this->assertTrue($tags['berlin']->isRemote());
	}

	public function testATagBothHereAndElsewhereIsBoth(): void {
		$this->sources = [$this->local(), $this->source('one.example', DirectorySource::KIND_MASTODON)];
		$this->localTags = [['hashtag' => 'berlin', 'trend' => ['1d' => 2]]];
		$this->answers = ['one.example' => $this->mastodonTrends(['berlin' => 4])];

		$tag = $this->service->tags()['tags'][0];

		$this->assertTrue($tag->isLocal());
		$this->assertTrue($tag->isRemote());
		$this->assertSame(2, $tag->countServers());
	}

	/**
	 * A peer's answer is a string this instance is about to show and offer to
	 * follow. Everything that is not shaped like a hashtag is somebody else's
	 * server answering a question that was not asked.
	 */
	public function testAnAnswerThatIsNotAHashtagIsDropped(): void {
		$this->sources = [$this->source('one.example', DirectorySource::KIND_MASTODON)];
		$this->answers = ['one.example' => $this->mastodonTrends([
			'fine' => 1,
			'not a tag' => 1,
			'<script>alert(1)</script>' => 1,
			'https://example.org/' => 1,
			str_repeat('x', 200) => 1,
		])];

		$this->assertSame(['fine'], $this->names($this->service->tags()));
	}

	public function testATagIsMergedRegardlessOfHowEachServerSpellsIt(): void {
		$this->sources = [
			$this->source('one.example', DirectorySource::KIND_MASTODON),
			$this->source('two.example', DirectorySource::KIND_MASTODON),
		];
		$this->answers = [
			'one.example' => $this->mastodonTrends(['CaturDay' => 3]),
			'two.example' => $this->mastodonTrends(['#caturday' => 2]),
		];

		$tags = $this->service->tags()['tags'];

		$this->assertSame(['caturday'], $this->names(['tags' => $tags]));
		$this->assertSame(2, $tags[0]->countServers());
	}

	public function testAServerThatDoesNotAnswerIsReportedRatherThanDropped(): void {
		$this->sources = [
			$this->source('up.example', DirectorySource::KIND_MASTODON),
			$this->source('down.example', DirectorySource::KIND_MASTODON),
		];
		$this->answers = [
			'up.example' => $this->mastodonTrends(['berlin' => 4]),
			'down.example' => new RuntimeException('no answer'),
		];

		$result = $this->service->tags();
		$status = [];
		foreach ($result['sources'] as $report) {
			$status[$report['host']] = $report['status'];
		}

		$this->assertSame(['berlin'], $this->names($result));
		$this->assertSame('ok', $status['up.example']);
		$this->assertSame('failed', $status['down.example']);
	}

	public function testAServerWithNoHashtagsIsSaidToHaveNoneRatherThanAsked(): void {
		$this->sources = [
			$this->source('lemmy.example', DirectorySource::KIND_LEMMY),
			$this->source('list.example', DirectorySource::KIND_WORDPRESS),
		];

		$result = $this->service->tags();

		$this->assertSame([], $result['tags']);
		$this->assertSame(
			['unsupported', 'unsupported'],
			array_column($result['sources'], 'status')
		);
		$this->assertSame([], $this->asked, 'no request was made to either');
	}

	public function testOnlySoManyServersAreAskedAndTheRestSaySo(): void {
		$this->sources = [$this->local()];
		for ($index = 0; $index < PeerTrendService::SOURCES + 3; $index++) {
			$host = 'peer' . $index . '.example';
			$this->sources[] = $this->source($host, DirectorySource::KIND_MASTODON);
			$this->answers[$host] = $this->mastodonTrends(['tag' . $index => 1]);
		}

		$status = array_count_values(array_column($this->service->tags()['sources'], 'status'));

		// the local source answers too, and costs nothing
		$this->assertSame(PeerTrendService::SOURCES + 1, $status['ok']);
		$this->assertSame(3, $status['skipped']);
		$this->assertCount(PeerTrendService::SOURCES, $this->asked);
	}

	public function testAModeratorsRejectionHereAppliesToWhatOtherServersSay(): void {
		$this->sources = [$this->source('one.example', DirectorySource::KIND_MASTODON)];
		$this->answers = ['one.example' => $this->mastodonTrends(['berlin' => 4, 'spam' => 900])];
		$this->rejected = ['spam'];

		$this->assertSame(['berlin'], $this->names($this->service->tags()));
	}

	public function testASearchNarrowsWhatTheServersSaidRatherThanAskingForEverything(): void {
		$this->sources = [$this->source('one.example', DirectorySource::KIND_MASTODON)];
		$this->answers = ['one.example' => $this->mastodonTrends(['photography' => 5, 'berlin' => 4])];

		$this->assertSame(['photography'], $this->names($this->service->tags('photo')));
		$this->assertStringContainsString('/api/v1/trends/tags', $this->asked[0]);
	}

	public function testALeadingHashOnTheQueryIsTheSameQuery(): void {
		$this->sources = [$this->source('one.example', DirectorySource::KIND_MASTODON)];
		$this->answers = ['one.example' => $this->mastodonTrends(['berlin' => 4])];

		$this->assertSame(['berlin'], $this->names($this->service->tags('#Berlin')));
	}

	public function testMisskeyIsAskedItsOwnEndpointsAndSearchesProperly(): void {
		$this->sources = [$this->source('misskey.example', DirectorySource::KIND_MISSKEY)];
		$this->answers = ['hashtags/search' => ['photography', 'photo']];

		$result = $this->service->tags('photo');

		$this->assertSame(['photo', 'photography'], $this->names($result));
		$this->assertStringContainsString('/api/hashtags/search', $this->asked[0]);
	}

	public function testMisskeyTrendsCarryTheirOwnCount(): void {
		$this->sources = [$this->source('misskey.example', DirectorySource::KIND_MISSKEY)];
		$this->answers = ['hashtags/trend' => [
			['tag' => 'berlin', 'usersCount' => 12],
			['tag' => 'art', 'usersCount' => 3],
		]];

		$tags = $this->service->tags()['tags'];

		$this->assertSame(['berlin', 'art'], $this->names(['tags' => $tags]));
		$this->assertSame(12, $tags[0]->getUses());
	}

	/**
	 * Mastodon reports uses as a per-day history and the days it carries have
	 * changed between releases. A server answering a shape this did not expect
	 * must cost its tag a count, never the whole page.
	 */
	public function testAHistoryInAShapeThisDoesNotKnowCostsTheCountAndNotTheTag(): void {
		$this->sources = [$this->source('one.example', DirectorySource::KIND_MASTODON)];
		$this->answers = ['one.example' => [
			['name' => 'berlin', 'history' => 'not a list'],
			['name' => 'art'],
			['name' => 'music', 'history' => []],
		]];

		$result = $this->service->tags();

		$this->assertSame(['art', 'berlin', 'music'], $this->names($result));
		foreach ($result['tags'] as $tag) {
			$this->assertSame(0, $tag->getUses());
		}
	}

	public function testOneServerCanBeAskedOnItsOwn(): void {
		$this->sources = [
			$this->source('one.example', DirectorySource::KIND_MASTODON),
			$this->source('two.example', DirectorySource::KIND_MASTODON),
		];
		$this->answers = [
			'one.example' => $this->mastodonTrends(['berlin' => 4]),
			'two.example' => $this->mastodonTrends(['vienna' => 4]),
		];

		$result = $this->service->tags('', 'two.example');

		$this->assertSame(['vienna'], $this->names($result));
		$this->assertCount(1, $this->asked);
	}

	public function testAnAnswerIsKeptSoTypingDoesNotHammerFourServers(): void {
		$cache = $this->createStub(ICache::class);
		$held = [];
		// a closure and not an arrow function: an arrow function captures
		// `$held` by value when it is created, which is while it is still empty
		$cache->method('get')->willReturnCallback(
			function (string $key) use (&$held) {
				return $held[$key] ?? null;
			}
		);
		$cache->method('set')->willReturnCallback(function (string $key, $value) use (&$held): bool {
			$held[$key] = $value;

			return true;
		});

		$service = $this->build($cache);
		$this->sources = [$this->source('one.example', DirectorySource::KIND_MASTODON)];
		$this->answers = ['one.example' => $this->mastodonTrends(['berlin' => 4])];

		$first = $service->tags();
		$second = $service->tags();

		$this->assertSame($this->names($first), $this->names($second));
		$this->assertCount(1, $this->asked, 'the second visit asked nobody');
	}

	public function testTheLocalTrendIsAnsweredWithoutAskingAnybody(): void {
		$this->sources = [$this->local()];
		$this->localTags = [['hashtag' => 'nextcloud', 'trend' => ['1h' => 2, '1d' => 9]]];

		$tags = $this->service->tags()['tags'];

		$this->assertSame(['nextcloud'], $this->names(['tags' => $tags]));
		$this->assertSame(9, $tags[0]->getUses(), 'the busiest window it was counted in');
		$this->assertSame([], $this->asked);
	}

	public function testThePageIsCutToWhatWasAskedFor(): void {
		$this->sources = [$this->source('one.example', DirectorySource::KIND_MASTODON)];
		$this->answers = ['one.example' => $this->mastodonTrends(
			array_combine(
				array_map(static fn (int $index): string => 'tag' . $index, range(1, 15)),
				array_fill(0, 15, 1)
			)
		)];

		$this->assertCount(4, $this->service->tags('', '', 4)['tags']);
	}
}
