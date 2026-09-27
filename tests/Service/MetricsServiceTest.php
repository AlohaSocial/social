<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MetricsService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * What the admin metrics answer, and what they refuse.
 *
 * The counts go through `IDBConnection`, which this suite cannot even mock —
 * DBAL is not loadable in it — so the query methods are doubled and what is
 * checked here is everything above them: which window is read, which shape
 * comes back, and which of Mastodon's keys this instance will not pretend to
 * have an answer for.
 */
#[AllowMockObjectsWithoutExpectations]
class MetricsServiceTest extends TestCase {
	/** 2026-09-01 and 2026-09-08, both midnight UTC. */
	private const START = 1788220800;
	private const END = 1788825600;

	private ConfigService|Stub $configService;

	/** @var array<string, int> Y-m-d => the value each doubled query answers */
	private array $perDay = [];
	/** @var array<string, int> host => how many, for the ranked dimensions */
	private array $hosts = [];
	/** @var array<int, string[]> the actor ids of each cohort read */
	private array $cohorts = [];
	/** @var array<int, array<string, mixed>> what each query was asked for */
	private array $asked = [];

	protected function setUp(): void {
		$this->configService = $this->createStub(ConfigService::class);
		$this->configService->method('getAppValue')->willReturn('0.16.3');
		$this->configService->method('getSystemValue')->willReturn('35.0.0');
	}

	private function service(): MetricsService|MockObject {
		$service = $this->getMockBuilder(MetricsService::class)
			->disableOriginalConstructor()
			->onlyMethods([
				'activeUsersPerDay', 'newUsersPerDay', 'interactionsPerDay', 'reportsPerDay',
				'instanceAccountsPerDay', 'instanceStatusesPerDay', 'instanceReportsPerDay',
				'tagUsesPerDay', 'tagAccountsPerDay', 'serversByStatuses', 'serversByTag',
				'localActorsCreatedBetween', 'databaseProvider',
			])
			->getMock();

		foreach ([
			'activeUsersPerDay', 'newUsersPerDay', 'interactionsPerDay',
			'instanceAccountsPerDay', 'instanceStatusesPerDay', 'instanceReportsPerDay',
			'tagUsesPerDay', 'tagAccountsPerDay',
		] as $method) {
			$service->method($method)->willReturnCallback(
				function (int $startAt, int $endAt, string $extra = '') use ($method): array {
					$this->asked[] = compact('method', 'startAt', 'endAt', 'extra');

					return $this->perDay;
				}
			);
		}

		$service->method('reportsPerDay')->willReturnCallback(
			function (int $startAt, int $endAt, string $column): array {
				$this->asked[] = ['method' => 'reportsPerDay', 'startAt' => $startAt,
					'endAt' => $endAt, 'extra' => $column];

				return $this->perDay;
			}
		);

		$service->method('serversByStatuses')->willReturnCallback(
			function (int $startAt, int $endAt, int $limit): array {
				$this->asked[] = ['method' => 'serversByStatuses', 'startAt' => $startAt,
					'endAt' => $endAt, 'extra' => (string)$limit];

				return $this->hosts;
			}
		);
		$service->method('serversByTag')->willReturnCallback(
			fn (int $startAt, int $endAt, int $limit, string $tag): array => $this->hosts
		);

		$service->method('localActorsCreatedBetween')->willReturnCallback(
			function (int $cohortStart, int $cohortEnd, ?int $from = null, ?int $to = null): array {
				$key = $from === null ? $cohortStart : $cohortStart . ':' . $from;

				return $this->cohorts[$key] ?? [];
			}
		);

		(new \ReflectionProperty(MetricsService::class, 'configService'))
			->setValue($service, $this->configService);
		$service->method('databaseProvider')->willReturn('mysql');

		return $service;
	}

	// measures

	/** A chart with holes in it is a chart nobody can read. */
	public function testAMeasureCarriesEveryDayOfTheWindow(): void {
		$measure = $this->service()->measures(['new_users'], self::START, self::END)[0];

		$this->assertCount(7, $measure['data']);
		$this->assertSame('2026-09-01T00:00:00.000Z', $measure['data'][0]['date']);
		$this->assertSame('2026-09-07T00:00:00.000Z', $measure['data'][6]['date']);
		$this->assertSame(['0', '0', '0', '0', '0', '0', '0'], array_column($measure['data'], 'value'));
	}

	public function testADayWithSomethingOnItCarriesIt(): void {
		$this->perDay = ['2026-09-03' => 4];

		$measure = $this->service()->measures(['new_users'], self::START, self::END)[0];

		$this->assertSame('4', $measure['data'][2]['value']);
		$this->assertSame('4', $measure['total']);
	}

	/** The arrow on the chart is drawn from the span before this one. */
	public function testThePreviousTotalIsTheSameSpanEndingWhereThisOneStarts(): void {
		$this->perDay = ['2026-09-03' => 4];

		$service = $this->service();
		$service->measures(['new_users'], self::START, self::END);

		$windows = array_map(
			static fn (array $call): array => [$call['startAt'], $call['endAt']], $this->asked
		);
		$this->assertSame([
			[self::START, self::END],
			[self::START - (self::END - self::START), self::START],
		], $windows);
	}

	public function testEveryKeyAskedForIsAnswered(): void {
		$measures = $this->service()->measures(
			['new_users', 'interactions', 'active_users'], self::START, self::END
		);

		$this->assertSame(
			['new_users', 'interactions', 'active_users'], array_column($measures, 'key')
		);
	}

	/** The two report measures are the same table read on two columns. */
	public function testTheTwoReportMeasuresAreReadOnDifferentColumns(): void {
		$service = $this->service();
		$service->measures(['opened_reports', 'resolved_reports'], self::START, self::END);

		$columns = array_values(array_unique(array_column(
			array_filter($this->asked, static fn (array $c): bool => $c['method'] === 'reportsPerDay'),
			'extra'
		)));
		$this->assertSame(['creation', 'action_taken_at'], $columns);
	}

	/**
	 * Answering 0 to "how many accounts signed up through an invite" reads as
	 * "none did", which is a different claim from "this instance has no
	 * invites" — and it is the reading an admin acts on.
	 */
	#[DataProvider('provideKeysThisInstanceCannotAnswer')]
	public function testAKeyThisInstanceCannotAnswerIsRefused(string $key): void {
		$this->expectException(InvalidResourceException::class);
		$this->expectExceptionMessage('cannot answer "' . $key . '"');

		$this->service()->measures([$key], self::START, self::END);
	}

	public static function provideKeysThisInstanceCannotAnswer(): iterable {
		// every one of these describes a sign-up, an invite, an email address
		// or a media store this app does not own
		yield 'sign-ups' => ['sign_ups'];
		yield 'instance media' => ['instance_media_attachments'];
		yield 'instance follows' => ['instance_follows'];
		yield 'nonsense' => ['whatever'];
	}

	/** The refusal names what this instance does have. */
	public function testTheRefusalSaysWhatCanBeAsked(): void {
		try {
			$this->service()->measures(['sign_ups'], self::START, self::END);
			$this->fail('a key this instance cannot answer was answered');
		} catch (InvalidResourceException $e) {
			$this->assertStringContainsString('new_users', $e->getMessage());
			$this->assertStringContainsString('active_users', $e->getMessage());
		}
	}

	#[DataProvider('provideWindowsThatWillNotBeRead')]
	public function testAWindowThatMakesNoSenseIsRefused(int $startAt, int $endAt): void {
		$this->expectException(InvalidResourceException::class);

		$this->service()->measures(['new_users'], $startAt, $endAt);
	}

	public static function provideWindowsThatWillNotBeRead(): iterable {
		yield 'backwards' => [self::END, self::START];
		yield 'empty' => [self::START, self::START];
		// a window silently rounded to "the epoch until now" is a query nobody
		// asked for over every row there is
		yield 'no start' => [0, self::END];
		yield 'no end' => [self::START, 0];
		yield 'longer than a year' => [self::START, self::START + 400 * 86400];
	}

	/** Whatever hour the client asked from, every point names a whole day. */
	public function testTheWindowIsSnappedToWholeDays(): void {
		$service = $this->service();
		$service->measures(['new_users'], self::START + 3600, self::END - 3600);

		$this->assertSame(self::START, $this->asked[0]['startAt']);
		$this->assertSame(self::END, $this->asked[0]['endAt']);
	}

	/** A measure about one instance needs to be told which. */
	public function testAnInstanceMeasureWithoutAnInstanceIsRefused(): void {
		$service = $this->service();
		$service->method('instanceStatusesPerDay')->willReturnCallback(
			static function (int $s, int $e, string $instance): array {
				throw new InvalidResourceException('name it in "instance"');
			}
		);

		$this->expectException(InvalidResourceException::class);

		$service->measures(['instance_statuses'], self::START, self::END);
	}

	public function testAnInstanceMeasureIsGivenTheInstance(): void {
		$service = $this->service();
		$service->measures(['instance_statuses'], self::START, self::END, 'remote.example');

		$this->assertSame('remote.example', $this->asked[0]['extra']);
	}

	/**
	 * A fediverse host may be a single label with no dot in it — every
	 * instance on an internal network is — and refusing those would leave an
	 * admin unable to ask about the server next to this one.
	 */
	#[DataProvider('provideInstanceNames')]
	public function testWhichInstanceNamesAreAccepted(string $instance, ?string $asked): void {
		// the constructor takes an IDBConnection, which cannot be built here,
		// and the rule under test touches neither it nor anything else
		$service = $this->service();
		$assertInstance = new \ReflectionMethod(MetricsService::class, 'assertInstance');

		if ($asked === null) {
			$this->expectException(InvalidResourceException::class);
			$assertInstance->invoke($service, $instance);

			return;
		}

		$this->assertSame($asked, $assertInstance->invoke($service, $instance));
	}

	public static function provideInstanceNames(): iterable {
		yield 'a hostname' => ['remote.example', 'remote.example'];
		yield 'a single label' => ['devel', 'devel'];
		yield 'with a port' => ['remote.example:8443', 'remote.example:8443'];
		yield 'mixed case and padding' => ['  Remote.Example.  ', 'remote.example'];
		yield 'nothing' => ['', null];
		yield 'a sentence' => ['not a host', null];
		yield 'a url' => ['https://remote.example/', null];
	}

	public function testATagMeasureIsGivenTheTag(): void {
		$service = $this->service();
		$service->measures(['tag_uses'], self::START, self::END, '', 'nextcloud');

		$this->assertSame('nextcloud', $this->asked[0]['extra']);
	}

	// dimensions

	public function testADimensionIsARankedList(): void {
		$this->hosts = ['mastodon.social' => 40, 'chaos.social' => 12];

		$dimension = $this->service()->dimensions(['servers'], self::START, self::END)[0];

		$this->assertSame('servers', $dimension['key']);
		$this->assertSame([
			['key' => 'mastodon.social', 'human_key' => 'mastodon.social', 'value' => '40'],
			['key' => 'chaos.social', 'human_key' => 'chaos.social', 'value' => '12'],
		], $dimension['data']);
	}

	public function testTheLimitIsBounded(): void {
		$this->hosts = [];
		$service = $this->service();
		$service->dimensions(['servers'], self::START, self::END, 10_000);

		$this->assertSame('100', $this->asked[0]['extra']);
	}

	/** What an admin would be asked for in a bug report. */
	public function testTheSoftwareVersionsAreWhatThisRunsOn(): void {
		$dimension = $this->service()->dimensions(
			['software_versions'], self::START, self::END
		)[0];

		$keys = array_column($dimension['data'], 'key');
		$this->assertSame(['social', 'nextcloud', 'php', 'database'], $keys);
		$this->assertSame('0.16.3', $dimension['data'][0]['value']);
		$this->assertSame('35.0.0', $dimension['data'][1]['value']);
		$this->assertSame(PHP_VERSION, $dimension['data'][2]['value']);
		$this->assertSame('mysql', $dimension['data'][3]['value']);
	}

	#[DataProvider('provideDimensionsThisInstanceCannotAnswer')]
	public function testADimensionThisInstanceCannotAnswerIsRefused(string $key): void {
		$this->expectException(InvalidResourceException::class);

		$this->service()->dimensions([$key], self::START, self::END);
	}

	public static function provideDimensionsThisInstanceCannotAnswer(): iterable {
		// no sign-up, no per-status language column, no media store of its own
		yield 'sources' => ['sources'];
		yield 'languages' => ['languages'];
		yield 'space usage' => ['space_usage'];
		yield 'nonsense' => ['whatever'];
	}

	// retention

	public function testACohortIsAMonthOfSignUpsAndWhatBecameOfIt(): void {
		$september = (int)strtotime('2026-09-01T00:00:00Z');
		$this->cohorts = [
			$september => ['a', 'b', 'c', 'd'],
			$september . ':' . $september => ['a', 'b', 'c'],
		];

		$cohorts = $this->service()->retention($september, (int)strtotime('2026-09-30T00:00:00Z'));

		$this->assertCount(1, $cohorts);
		$this->assertSame('2026-09-01T00:00:00.000Z', $cohorts[0]['period']);
		$this->assertSame('month', $cohorts[0]['frequency']);
		$this->assertSame('3', $cohorts[0]['data'][0]['value']);
		$this->assertSame(0.75, $cohorts[0]['data'][0]['rate']);
	}

	/** An account that never posted is in the cohort and in none of its buckets. */
	public function testACohortNobodyPostedInIsARowOfZeroes(): void {
		$september = (int)strtotime('2026-09-01T00:00:00Z');
		$this->cohorts = [$september => ['a', 'b']];

		$cohorts = $this->service()->retention($september, (int)strtotime('2026-09-30T00:00:00Z'));

		$this->assertSame('0', $cohorts[0]['data'][0]['value']);
		$this->assertSame(0.0, $cohorts[0]['data'][0]['rate']);
	}

	/** A month nobody signed up in divides by nothing rather than by zero. */
	public function testAnEmptyCohortHasARateOfZero(): void {
		$september = (int)strtotime('2026-09-01T00:00:00Z');

		$cohorts = $this->service()->retention($september, (int)strtotime('2026-09-30T00:00:00Z'));

		$this->assertSame(0.0, $cohorts[0]['data'][0]['rate']);
	}

	public function testEachMonthOfTheWindowIsACohort(): void {
		$cohorts = $this->service()->retention(
			(int)strtotime('2026-07-01T00:00:00Z'), (int)strtotime('2026-09-15T00:00:00Z')
		);

		$this->assertSame([
			'2026-07-01T00:00:00.000Z',
			'2026-08-01T00:00:00.000Z',
			'2026-09-01T00:00:00.000Z',
		], array_column($cohorts, 'period'));
		// a cohort is followed to the end of the window and no further
		$this->assertCount(3, $cohorts[0]['data']);
		$this->assertCount(1, $cohorts[2]['data']);
	}
}
