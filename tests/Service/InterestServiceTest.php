<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use InvalidArgumentException;
use OCA\Social\Db\FeaturedTagsRequest;
use OCA\Social\Db\FollowedTagsRequest;
use OCA\Social\Db\InterestsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InterestNotRemovableException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Interest;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\InterestScorer;
use OCA\Social\Service\InterestService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Who My interests learns from, what it keeps, and what the reader can do to
 * it — against in-memory tables, so every rule is about behaviour rather than
 * about which query ran.
 */
class InterestServiceTest extends TestCase {
	private const NOW = 1790000000;
	private const ME = 'https://cloud.example/users/alice';
	private const OTHER = 'https://remote.example/users/bob';

	/** @var array<string, string> app config */
	private array $app = [];
	/** @var array<string, string> alice's user config */
	private array $user = [];
	/** @var array<string, Interest> alice's rows by tag */
	private array $rows = [];
	/** @var array<string, true> */
	private array $hidden = [];
	/** @var array<string, Note> what alice may see, by nid */
	private array $visible = [];
	/** @var string[] */
	private array $followed = [];
	private array $trending = [];
	private array $cache = [];
	/** @var string[][] every set of nids asked for */
	private array $asked = [];
	private int $now = self::NOW;
	/** makes the interests table fail, as a database that went away would */
	private bool $broken = false;

	private StreamRequest|Stub $streamRequest;
	private InterestsRequest|Stub $interestsRequest;

	private function actor(): Person {
		return (new Person())->setId(self::ME)->setUserId('alice');
	}

	private function post(string $nid, array $tags, string $author = self::OTHER, string $content = 'hello'): Note {
		$note = new Note();
		$note->setNid($nid);
		$note->setId('https://remote.example/posts/' . $nid);
		$note->setAttributedTo($author);
		$note->setHashtags($tags);
		$note->setContent($content);
		$this->visible[$nid] = $note;

		return $note;
	}

	protected function setUp(): void {
		$this->app = [
			ConfigService::SOCIAL_INTERESTS => '1',
			ConfigService::SOCIAL_INTERESTS_DEFAULT => '1',
			ConfigService::SOCIAL_INTERESTS_HALF_LIFE => '30',
			ConfigService::SOCIAL_INTERESTS_THRESHOLD => '3',
			ConfigService::SOCIAL_INTERESTS_CAP => '30',
			ConfigService::SOCIAL_INTERESTS_WINDOW => '7',
		];
	}

	private function service(): InterestService {
		$config = $this->createStub(ConfigService::class);
		$config->method('getAppValue')->willReturnCallback(fn ($key) => $this->app[$key] ?? '');
		$config->method('getAppValueInt')->willReturnCallback(fn (string $key): int => (int)($this->app[$key] ?? 0));
		$config->method('getAppValueBool')->willReturnCallback(fn (string $key): bool => ($this->app[$key] ?? '0') !== '0');
		$config->method('setAppValue')->willReturnCallback(function ($key, $value): void {
			$this->app[$key] = (string)$value;
		});
		$config->method('getValueForUser')->willReturnCallback(fn ($userId, $key): string => $this->user[$key] ?? '');
		$config->method('setValueForUser')->willReturnCallback(function ($userId, $key, $value): void {
			$this->user[$key] = (string)$value;
		});

		$this->interestsRequest = $this->createStub(InterestsRequest::class);
		$this->interestsRequest->method('getByActor')->willReturnCallback(function (): array {
			if ($this->broken) {
				throw new RuntimeException('database gone');
			}

			return array_map(static fn (Interest $row): Interest => clone $row, array_values($this->rows));
		});
		$this->interestsRequest->method('save')->willReturnCallback(function (string $actorId, Interest $row): void {
			$this->rows[$row->getHashtag()] = clone $row;
		});
		$this->interestsRequest->method('deleteTags')->willReturnCallback(function (string $actorId, array $tags): void {
			foreach ($tags as $tag) {
				unset($this->rows[$tag]);
			}
		});
		$this->interestsRequest->method('deleteRelatedId')->willReturnCallback(function (): void {
			$this->rows = [];
			$this->hidden = [];
		});
		$this->interestsRequest->method('hide')->willReturnCallback(function (string $actorId, string $nid): void {
			$this->hidden[$nid] = true;
		});
		$this->interestsRequest->method('unhide')->willReturnCallback(function (string $actorId, string $nid): void {
			unset($this->hidden[$nid]);
		});
		$this->interestsRequest->method('isHidden')->willReturnCallback(fn (string $actorId, string $nid): bool => isset($this->hidden[$nid]));
		$this->interestsRequest->method('getHiddenSince')->willReturnCallback(fn (): array => array_map('strval', array_keys($this->hidden)));

		$this->streamRequest = $this->createStub(StreamRequest::class);
		$this->streamRequest->method('getVisibleByNids')->willReturnCallback(function (array $nids): array {
			$this->asked[] = $nids;

			return array_intersect_key($this->visible, array_flip($nids));
		});

		$followedTags = $this->createStub(FollowedTagsRequest::class);
		$followedTags->method('getByActor')->willReturnCallback(fn (): array => array_map(
			static fn (string $tag): array => ['id' => 1, 'hashtag' => $tag, 'creation' => 0], $this->followed
		));

		$featured = $this->createStub(FeaturedTagsRequest::class);
		$featured->method('getByActor')->willReturn([]);

		$hashtags = $this->createStub(HashtagService::class);
		$hashtags->method('getTrending')->willReturnCallback(fn (): array => $this->trending);

		$cache = $this->createStub(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key) => $this->cache[$key] ?? null);
		$cache->method('set')->willReturnCallback(function (string $key, $value): bool {
			$this->cache[$key] = $value;

			return true;
		});
		$cacheFactory = $this->createStub(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return new InterestService(
			$config, $this->interestsRequest, $this->streamRequest, $followedTags, $featured, $hashtags,
			$cacheFactory, $time, new NullLogger()
		);
	}

	/** @return array<string, float> tag => score, rounded */
	private function scores(): array {
		$scores = [];
		foreach ($this->rows as $tag => $row) {
			$scores[$tag] = round($row->getScore(), 3);
		}
		ksort($scores);

		return $scores;
	}

	public function testALongLookIsSharedAmongThePostsTags(): void {
		$this->post('100', ['Cats', 'dogs']);

		$this->service()->recordEvents($this->actor(), [
			['status_id' => '100', 'kind' => 'dwell', 'ms' => 30000, 'context' => 'home'],
		]);

		$this->assertSame(['cats' => 0.5, 'dogs' => 0.5], $this->scores());
		$this->assertNotSame('', $this->user['interests_baseline'] ?? '', 'the reader\'s pace moved');
	}

	public function testEachKindOfSignalWeighsWhatTheSpecSays(): void {
		foreach (['1' => 'skip', '2' => 'open', '3' => 'media', '4' => 'link', '5' => 'mute'] as $nid => $kind) {
			$this->post((string)$nid, ['t' . $nid]);
		}

		$this->service()->recordEvents($this->actor(), array_map(
			static fn (string $nid, string $kind): array => ['status_id' => $nid, 'kind' => $kind],
			['1', '2', '3', '4', '5'], ['skip', 'open', 'media', 'link', 'mute']
		));

		$this->assertSame(
			['t1' => InterestScorer::SIGNAL_SKIP, 't2' => InterestScorer::SIGNAL_OPEN, 't3' => InterestScorer::SIGNAL_MEDIA,
				't4' => InterestScorer::SIGNAL_LINK, 't5' => InterestScorer::SIGNAL_MUTE],
			$this->scores()
		);
	}

	public function testNothingIsLearnedFromOwnPostsUntaggedPostsOrPostsTheReaderCannotSee(): void {
		$this->post('1', ['mine'], self::ME);
		$this->post('2', []);
		// '3' is not visible to alice: the stream request does not hand it back

		$this->service()->recordEvents($this->actor(), [
			['status_id' => '1', 'kind' => 'open'],
			['status_id' => '2', 'kind' => 'open'],
			['status_id' => '3', 'kind' => 'open'],
		]);

		$this->assertSame([], $this->rows);
	}

	public function testTheSamePostCountsOncePerKindAndDay(): void {
		$this->post('1', ['cats']);
		$service = $this->service();

		$service->recordEvents($this->actor(), [['status_id' => '1', 'kind' => 'open'], ['status_id' => '1', 'kind' => 'open']]);
		$service->recordEvents($this->actor(), [['status_id' => '1', 'kind' => 'open'], ['status_id' => '1', 'kind' => 'media']]);

		$this->assertSame(['cats' => InterestScorer::SIGNAL_OPEN + InterestScorer::SIGNAL_MEDIA], $this->scores());
	}

	public function testMalformedEventsAreDroppedAndABatchIsCapped(): void {
		$this->post('1', ['cats']);
		$events = [
			['status_id' => '1', 'kind' => 'teleport'],
			['status_id' => 'abc', 'kind' => 'open'],
			'not an event',
		];
		foreach (range(1, 150) as $i) {
			$events[] = ['status_id' => (string)(1000 + $i), 'kind' => 'open'];
		}

		$this->service()->recordEvents($this->actor(), $events);

		$this->assertCount(1, $this->asked);
		$this->assertLessThanOrEqual(InterestService::MAX_EVENTS, count($this->asked[0]));
		$this->assertNotContains('abc', $this->asked[0]);
	}

	public function testNothingIsLearnedWhileOffPausedOrDisabled(): void {
		$this->post('1', ['cats']);
		$event = [['status_id' => '1', 'kind' => 'open']];

		$this->app[ConfigService::SOCIAL_INTERESTS] = '0';
		$this->service()->recordEvents($this->actor(), $event);
		$this->app[ConfigService::SOCIAL_INTERESTS] = '1';

		$this->user['interests_learning'] = '0';
		$this->service()->recordEvents($this->actor(), $event);
		$this->user['interests_learning'] = '1';

		$this->user['interests_paused_at'] = (string)self::NOW;
		$this->service()->recordEvents($this->actor(), $event);

		$this->assertSame([], $this->rows);
	}

	public function testTheAdministratorsDefaultAppliesUntilTheReaderChooses(): void {
		$this->app[ConfigService::SOCIAL_INTERESTS_DEFAULT] = '0';
		$this->assertFalse($this->service()->settingsFor('alice')['learning']);

		$this->user['interests_learning'] = '1';
		$this->assertTrue($this->service()->settingsFor('alice')['learning']);
	}

	public function testAnActionCountsWhereverItHappensAndNeverThrows(): void {
		$post = $this->post('1', ['cats', 'dogs']);
		$service = $this->service();

		$service->recordAction($this->actor(), $post, InterestService::ACTION_BOOST);
		$this->assertSame(['cats' => 1.5, 'dogs' => 1.5], $this->scores());

		// a failure in learning must not become a failed boost
		$this->broken = true;
		$service->recordAction($this->actor(), $this->post('2', ['x']), InterestService::ACTION_FAVOURITE);
		$this->assertArrayNotHasKey('x', $this->rows);
	}

	public function testLessLikeThisHidesThePostAndUndoGivesBackExactlyWhatItTook(): void {
		$this->post('7', ['cats', 'dogs']);
		$service = $this->service();

		$service->lessLikeThis($this->actor(), '7');
		$this->assertSame(['7' => true], $this->hidden);
		$this->assertSame(['cats' => -1.5, 'dogs' => -1.5], $this->scores());

		$service->undoLessLikeThis($this->actor(), '7');
		$service->undoLessLikeThis($this->actor(), '7');
		$this->assertSame([], $this->hidden);
		$this->assertSame([], $this->rows, 'a second undo cannot pump the tags up');
	}

	public function testLessLikeThisOnAPostTheReaderCannotSeeIsNotFound(): void {
		$this->expectException(ItemNotFoundException::class);

		$this->service()->lessLikeThis($this->actor(), '404');
	}

	public function testAnAddedTagIsListedAndSomethingThatIsNotATagIsRefused(): void {
		$state = $this->service()->add($this->actor(), '#Analog');

		$this->assertSame([['tag' => 'analog', 'rank' => 0, 'source' => 'manual', 'pinned' => false, 'score' => 3.0, 'trend' => null]], $state['interests']);

		$this->expectException(InvalidArgumentException::class);
		$this->service()->add($this->actor(), 'two words');
	}

	public function testMovingATagPinsItThereAndPushesLaterPinsDown(): void {
		$this->rows = [
			'a' => new Interest('a', 9.0, self::NOW),
			'b' => new Interest('b', 8.0, self::NOW),
			'c' => new Interest('c', 7.0, self::NOW),
			'pinned' => new Interest('pinned', 3.0, self::NOW, false, 1),
		];

		$state = $this->service()->move($this->actor(), 'c', 1);

		$this->assertSame(['a', 'c', 'pinned', 'b'], array_column($state['interests'], 'tag'));
		$this->assertSame(1, $this->rows['c']->getPosition());
		$this->assertSame(2, $this->rows['pinned']->getPosition());
	}

	public function testATagThatIsNotListedCannotBeMoved(): void {
		$this->rows = ['faint' => new Interest('faint', 1.0, self::NOW)];

		$this->expectException(InvalidArgumentException::class);
		$this->service()->move($this->actor(), 'faint', 0);
	}

	public function testUnpinningLetsATagFloatAgain(): void {
		$this->rows = ['a' => new Interest('a', 9.0, self::NOW), 'b' => new Interest('b', 5.0, self::NOW, false, 0)];

		$state = $this->service()->unpin($this->actor(), 'b');

		$this->assertSame(['a', 'b'], array_column($state['interests'], 'tag'));
		$this->assertFalse($this->rows['b']->isPinned());
	}

	public function testRemovingForgetsATagButAFollowedOneStays(): void {
		$this->rows = ['cats' => new Interest('cats', 9.0, self::NOW)];
		$this->followed = ['nextcloud'];

		$state = $this->service()->remove($this->actor(), 'cats');
		$this->assertSame(['nextcloud'], array_column($state['interests'], 'tag'));
		$this->assertSame([], $this->rows);

		$this->expectException(InterestNotRemovableException::class);
		$this->service()->remove($this->actor(), 'nextcloud');
	}

	public function testResetForgetsEverythingButTheSwitches(): void {
		$this->rows = ['cats' => new Interest('cats', 9.0, self::NOW, true)];
		$this->hidden = ['1' => true];
		$this->user = ['interests_learning' => '1', 'interests_baseline' => '1.4'];

		$this->service()->reset($this->actor());

		$this->assertSame([], $this->rows);
		$this->assertSame([], $this->hidden);
		$this->assertSame('', $this->user['interests_baseline']);
		$this->assertSame('1', $this->user['interests_learning']);
	}

	public function testAPauseCostsNoDecay(): void {
		$this->rows = ['cats' => new Interest('cats', 8.0, self::NOW)];
		$service = $this->service();

		$service->saveSettings($this->actor(), ['paused' => true]);
		$this->assertTrue($service->settingsFor('alice')['paused']);

		$this->now = self::NOW + 30 * 86400;
		$state = $service->saveSettings($this->actor(), ['paused' => false]);

		$this->assertFalse($state['settings']['paused']);
		$this->assertSame(8.0, $state['interests'][0]['score'], 'thirty paused days did not halve it');
	}

	public function testLanguagesMustBeLanguageCodes(): void {
		$state = $this->service()->saveSettings($this->actor(), ['languages' => ['de', 'en', 'de', 'pt-BR']]);
		$this->assertSame(['de', 'en', 'pt-BR'], $state['settings']['languages']);

		$this->expectException(InvalidArgumentException::class);
		$this->service()->saveSettings($this->actor(), ['languages' => ['<script>']]);
	}

	public function testTheFeedWeighsByRankAndCountsDislikesAgainst(): void {
		$this->rows = [
			'a' => new Interest('a', 9.0, self::NOW),
			'b' => new Interest('b', 8.0, self::NOW),
			'c' => new Interest('c', 7.0, self::NOW),
			'no' => new Interest('no', -4.0, self::NOW),
		];

		$profile = $this->service()->feedProfile($this->actor());

		$this->assertSame(1.0, $profile['weights']['a']);
		$this->assertEqualsWithDelta(1 / 1.3, $profile['weights']['c'], 1e-9);
		$this->assertSame(InterestScorer::NEGATIVE_WEIGHT, $profile['weights']['no']);
		$this->assertFalse($profile['thin']);
		$this->assertSame(['a', 'b', 'c'], $profile['top']);
	}

	public function testAThinListIsPaddedWithWhatIsTrendingAtHalfWeight(): void {
		$this->followed = ['nextcloud'];
		$this->trending = [['hashtag' => 'Fediverse', 'trend' => []], ['hashtag' => 'nextcloud', 'trend' => []]];

		$profile = $this->service()->feedProfile($this->actor());

		$this->assertTrue($profile['thin']);
		$this->assertSame(1.0, $profile['weights']['nextcloud'], 'a followed tag keeps its own weight');
		$this->assertSame('followed', $profile['reasons']['nextcloud']);
		$this->assertSame(0.5, $profile['weights']['fediverse']);
		$this->assertSame('trending', $profile['reasons']['fediverse']);
	}

	public function testAnExportComesBackAsItWent(): void {
		$this->rows = [
			'cats' => new Interest('cats', 9.5, self::NOW - 100),
			'mine' => new Interest('mine', 3.0, self::NOW, true, 2),
		];
		$this->user = ['interests_learning' => '0', 'interests_languages' => '["de"]', 'interests_baseline' => '1.2'];
		$export = $this->service()->export($this->actor());

		$this->rows = [];
		$this->user = [];
		$imported = $this->service()->import($this->actor(), json_decode(json_encode($export), true));

		$this->assertSame(2, $imported);
		$this->assertSame(9.5, $this->rows['cats']->getScore());
		$this->assertSame(self::NOW - 100, $this->rows['cats']->getScoredAt(), 'decay carries on rather than restarting');
		$this->assertSame(2, $this->rows['mine']->getPosition());
		$this->assertTrue($this->rows['mine']->isManual());
		$this->assertSame(['interests_learning' => '0', 'interests_languages' => '["de"]', 'interests_baseline' => '1.2'], $this->user);
	}

	public function testAnImportTakesNothingItCannotTrust(): void {
		$imported = $this->service()->import($this->actor(), [
			'settings' => ['learning' => 'yes please', 'languages' => ['<b>'], 'baseline' => 'x'],
			'interests' => [['hashtag' => ''], ['hashtag' => 'ok', 'score' => 1e9], 'nonsense'],
		]);

		$this->assertSame(1, $imported);
		$this->assertSame(InterestScorer::SCORE_MAX, $this->rows['ok']->getScore());
		$this->assertArrayNotHasKey('interests_learning', $this->user);
	}

	public function testTheAdministratorsNumbersAreRangeChecked(): void {
		$saved = $this->service()->saveAdminSettings(true, false, 14, 2.5, 40, 3);
		$this->assertSame(['enabled' => true, 'learningDefault' => false, 'halfLife' => 14, 'threshold' => 2.5, 'cap' => 40, 'window' => 3], $saved);

		$this->expectException(InvalidArgumentException::class);
		$this->service()->saveAdminSettings(true, true, 30, 3, 30, 90);
	}
}
