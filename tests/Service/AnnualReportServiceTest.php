<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\AnnualReportService;
use OCA\Social\Service\ConfigService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * The year an account had.
 *
 * What is held still: the months are UTC and the same for everybody, a boost
 * is counted and then left out of what it did not write, the archetype is the
 * first rule that fits, and `share_url` is null rather than a link that 404s.
 */
#[AllowMockObjectsWithoutExpectations]
class AnnualReportServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example/apps/social/@alice';

	private StreamRequest|Stub $streamRequest;
	private FollowsRequest|Stub $followsRequest;
	private ConfigService|MockObject $configService;
	private AnnualReportService $service;

	protected function setUp(): void {
		$this->streamRequest = $this->createStub(StreamRequest::class);
		$this->followsRequest = $this->createStub(FollowsRequest::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->followsRequest->method('getFollowerOrigins')->willReturn([]);

		$this->service = new AnnualReportService(
			$this->streamRequest,
			$this->followsRequest,
			$this->configService,
		);
	}

	private function alice(): Person {
		$alice = new Person();
		$alice->setId(self::ALICE)->setNid(3)->setLocal(true);

		return $alice;
	}

	/**
	 * @param array<int, array{when: string, type?: string, reply?: bool, tags?: string[], likes?: int, boosts?: int, replies?: int}> $specs
	 */
	private function holding(array $specs): void {
		$posts = [];
		// newest first, so the newest carries the highest nid
		$nid = count($specs);
		foreach ($specs as $spec) {
			$note = new Note();
			$note->setId(self::ALICE . '/' . $nid)->setNid($nid--);
			$note->setAttributedTo(self::ALICE);
			$note->setPublishedTime((int)strtotime($spec['when'] . ' UTC'));
			if (($spec['type'] ?? '') !== '') {
				$note->setType($spec['type']);
			}
			if ($spec['reply'] ?? false) {
				$note->setInReplyTo(self::ALICE . '/0');
			}
			$note->setHashtags($spec['tags'] ?? []);
			$note->setDetailsAll([
				'likes' => $spec['likes'] ?? 0,
				'boosts' => $spec['boosts'] ?? 0,
				'replies' => $spec['replies'] ?? 0,
			]);
			$posts[] = $note;
		}

		// the walk is newest first and pages on nid; one page then nothing
		$first = true;
		$this->streamRequest->method('getTimeline')
			->willReturnCallback(static function () use (&$first, $posts): array {
				if ($first) {
					$first = false;

					return $posts;
				}

				return [];
			});
	}

	/** One post a month, in order, newest first. */
	private function aYearOf(int $count, string $month = '2025-06-01 12:00:00'): array {
		$specs = [];
		for ($i = 0; $i < $count; $i++) {
			$specs[] = ['when' => $month];
		}

		return $specs;
	}

	public function testTheYearsAreTheOnesTheAccountWroteIn(): void {
		$this->holding([
			['when' => '2026-02-01 10:00:00'],
			['when' => '2025-11-01 10:00:00'],
			['when' => '2025-03-01 10:00:00'],
		]);

		$this->assertSame([2026, 2025], $this->service->years($this->alice()));
	}

	public function testAYearTheAccountWroteNothingInHasNoReport(): void {
		$this->holding([['when' => '2025-06-01 10:00:00']]);

		$this->assertSame('available', $this->service->state($this->alice(), 2025));
		$this->assertSame('ineligible', $this->service->state($this->alice(), 2024));
	}

	/** A year that has not happened cannot have one either. */
	public function testAFutureYearIsIneligible(): void {
		$this->holding([]);

		$this->assertSame(
			'ineligible',
			$this->service->state($this->alice(), (int)gmdate('Y') + 1)
		);
	}

	/**
	 * The months a report draws have to be the same months whoever opens it
	 * and wherever from, and every timestamp it compares against is UTC.
	 */
	public function testTheMonthsAreTwelveAndTheyAreUtc(): void {
		$this->holding([
			['when' => '2025-03-15 10:00:00'],
			['when' => '2025-03-02 10:00:00'],
			['when' => '2025-01-01 00:30:00'],
		]);

		$series = $this->service->forYear($this->alice(), 2025)['data']['time_series'];

		$this->assertCount(12, $series);
		$this->assertSame(['month' => 1, 'statuses' => 1, 'followers' => 0], $series[0]);
		$this->assertSame(['month' => 3, 'statuses' => 2, 'followers' => 0], $series[2]);
	}

	public function testPostsOutsideTheYearAreLeftOut(): void {
		$this->holding([
			['when' => '2026-01-01 10:00:00'],
			['when' => '2025-06-01 10:00:00'],
			['when' => '2024-12-31 23:00:00'],
		]);

		$series = $this->service->forYear($this->alice(), 2025)['data']['time_series'];

		$this->assertSame(1, array_sum(array_column($series, 'statuses')));
	}

	public function testFollowersAreCountedIntoTheMonthTheyArrived(): void {
		$this->holding([['when' => '2025-06-01 10:00:00']]);
		$followers = $this->createStub(FollowsRequest::class);
		$followers->method('getFollowerOrigins')->willReturn([
			['actor_id' => 'https://remote.example/users/bob', 'creation' => '2025-04-10 09:00:00'],
			['actor_id' => 'https://remote.example/users/carol', 'creation' => '2025-04-11 09:00:00'],
			['actor_id' => 'https://remote.example/users/dave', 'creation' => '2024-04-11 09:00:00'],
		]);
		$service = new AnnualReportService($this->streamRequest, $followers, $this->configService);

		$series = $service->forYear($this->alice(), 2025)['data']['time_series'];

		$this->assertSame(2, $series[3]['followers'], 'April');
		$this->assertSame(0, $series[2]['followers'], 'March');
	}

	/**
	 * A boost is something the account did rather than something it wrote:
	 * counted in the month, and then left out of the hashtags and the best
	 * posts, which belong to whoever wrote them.
	 */
	public function testABoostIsCountedButItsHashtagsAreNotTheAccountsOwn(): void {
		$this->holding([
			['when' => '2025-06-01 10:00:00', 'type' => 'Announce', 'tags' => ['somebodyelse']],
			['when' => '2025-06-02 10:00:00', 'tags' => ['mine']],
		]);

		$data = $this->service->forYear($this->alice(), 2025)['data'];

		$this->assertSame(2, $data['time_series'][5]['statuses']);
		$this->assertSame([['name' => 'mine', 'count' => 1]], $data['top_hashtags']);
	}

	public function testTheBestPostsAreNamedByTheIdAClientAddresses(): void {
		$this->holding([
			['when' => '2025-06-01 10:00:00', 'boosts' => 9, 'likes' => 1, 'replies' => 0],
			['when' => '2025-06-02 10:00:00', 'boosts' => 1, 'likes' => 8, 'replies' => 4],
		]);

		$best = $this->service->forYear($this->alice(), 2025)['data']['top_statuses'];

		$this->assertSame('2', $best['by_reblogs']);
		$this->assertSame('1', $best['by_favourites']);
		$this->assertSame('1', $best['by_replies']);
	}

	public function testAYearWithNothingWorthNamingLeavesTheBestPostsNull(): void {
		$this->holding([['when' => '2025-06-01 10:00:00']]);

		$this->assertSame(
			['by_reblogs' => null, 'by_replies' => null, 'by_favourites' => null],
			$this->service->forYear($this->alice(), 2025)['data']['top_statuses']
		);
	}

	/** Mastodon's points at a public page of its own; this app has none. */
	public function testTheShareUrlIsNullRatherThanALinkThatWouldNotWork(): void {
		$this->holding([['when' => '2025-06-01 10:00:00']]);

		$report = $this->service->forYear($this->alice(), 2025);

		$this->assertNull($report['share_url']);
		$this->assertSame(1, $report['schema_version']);
		$this->assertSame('3', $report['account_id']);
	}

	// --- the archetype ----------------------------------------------------

	private function archetypeOf(array $specs): string {
		$this->holding($specs);

		return $this->service->forYear($this->alice(), 2025)['data']['archetype'];
	}

	public function testSomebodyWhoWroteAlmostNothingIsALurker(): void {
		$this->assertSame('lurker', $this->archetypeOf($this->aYearOf(3)));
	}

	public function testMostlyBoostingIsABooster(): void {
		$specs = $this->aYearOf(12);
		foreach (range(0, 6) as $index) {
			$specs[$index]['type'] = 'Announce';
		}

		$this->assertSame('booster', $this->archetypeOf($specs));
	}

	public function testMostlyAnsweringIsAReplier(): void {
		$specs = $this->aYearOf(12);
		foreach (range(0, 6) as $index) {
			$specs[$index]['reply'] = true;
		}

		$this->assertSame('replier', $this->archetypeOf($specs));
	}

	public function testAskingQuestionsIsAPollster(): void {
		$specs = $this->aYearOf(12);
		$specs[0]['type'] = 'Question';
		$specs[1]['type'] = 'Question';

		$this->assertSame('pollster', $this->archetypeOf($specs));
	}

	public function testWritingYourOwnPostsIsTheOracle(): void {
		$this->assertSame('oracle', $this->archetypeOf($this->aYearOf(20)));
	}

	// --- read ------------------------------------------------------------

	public function testMarkingAYearReadKeepsTheOnesAlreadyThere(): void {
		$this->configService->method('getUserValue')->willReturn('2023,2024');
		$this->configService->expects($this->once())->method('setUserValue')
			->with('annual_reports_read', '2023,2024,2025');

		$this->service->markRead('alice', 2025);
	}

	public function testMarkingAYearReadTwiceWritesNothingTheSecondTime(): void {
		$this->configService->method('getUserValue')->willReturn('2025');
		$this->configService->expects($this->never())->method('setUserValue');

		$this->service->markRead('alice', 2025);
	}
}
