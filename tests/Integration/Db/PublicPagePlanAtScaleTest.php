<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Command\Benchmark;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\ConfigService;
use OCA\Social\Tests\Integration\QueryCounter;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The public page's plan where the planner has a real choice to make.
 *
 * `QueryPlanTest` asks `EXPLAIN` over a few hundred seeded rows, and a plan
 * that is index-driven at that size says nothing about what the cost model
 * chooses at a hundred thousand: the joined public page was a twenty-entry
 * index walk on one copy of an instance and a full read of `social_stream`
 * with a sort on another, for the same SQL. This seeds fifty thousand posts
 * with `occ social:debug:benchmark`, takes the statistics, and asks for the plans
 * of the page queries the public timeline and its Photos narrowing run —
 * each must be one index range that stops at the limit, with no sort, no
 * temporary table and no full read.
 *
 * Slow, so it runs only with `SOCIAL_BENCHMARK=1`, on MySQL/MariaDB and
 * PostgreSQL, and removes what it seeded afterwards.
 */
class PublicPagePlanAtScaleTest extends TestCase {
	private const NOTES = 50000;

	private static string $platform = '';
	private static ?Person $viewer = null;
	/** @var array<string, string> the app settings this test changes, as they were */
	private static array $settingsBefore = [];

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if (getenv('SOCIAL_BENCHMARK') !== '1') {
			return;
		}

		$connection = Server::get(IDBConnection::class);
		self::$platform = match ($connection->getDatabaseProvider()) {
			IDBConnection::PLATFORM_MYSQL, IDBConnection::PLATFORM_MARIADB => 'mysql',
			IDBConnection::PLATFORM_POSTGRES => 'pgsql',
			default => '',
		};
		self::$viewer = Server::get(ActorsRequest::class)->getAny();
		if (self::$platform === '' || self::$viewer === null) {
			return;
		}

		$config = Server::get(ConfigService::class);
		foreach ([ConfigService::SOCIAL_DEST_NID_FILLED, ConfigService::SOCIAL_STREAM_MEDIA_KIND_FILLED] as $key) {
			self::$settingsBefore[$key] = (string)$config->getAppValue($key);
			$config->setAppValue($key, '1');
		}

		$tester = self::benchmark();
		$tester->execute([
			'--actors' => '500', '--notes' => (string)self::NOTES, '--follows' => '50',
			'--viewer' => self::$viewer->getPreferredUsername(), '--seed-only' => true, '--force' => true,
		], ['interactive' => false]);
	}

	public static function tearDownAfterClass(): void {
		if (self::$platform !== '' && self::$viewer !== null) {
			self::benchmark()->execute(['--clean' => true], ['interactive' => false]);
			$config = Server::get(ConfigService::class);
			foreach (self::$settingsBefore as $key => $value) {
				$config->setAppValue($key, $value);
			}
		}
		parent::tearDownAfterClass();
	}

	private static function benchmark(): CommandTester {
		$application = new Application();
		$application->add(Server::get(Benchmark::class));

		return new CommandTester($application->find('social:debug:benchmark'));
	}

	protected function setUp(): void {
		parent::setUp();
		if (getenv('SOCIAL_BENCHMARK') !== '1') {
			$this->markTestSkipped('slow: seeds ' . self::NOTES . ' posts; run with SOCIAL_BENCHMARK=1');
		}
		if (self::$platform === '' || self::$viewer === null) {
			$this->markTestSkipped('asserted on MySQL/MariaDB and PostgreSQL, with a local account to read as');
		}
		$request = Server::get(StreamRequest::class);
		(new ReflectionProperty(StreamRequest::class, 'recipientNidsFilled'))->setValue($request, null);
		(new ReflectionProperty(CoreRequestBuilder::class, 'mediaKindFilled'))->setValue($request, null);
	}

	/** @return array<string, array{array<string, mixed>, string}> */
	public static function pages(): array {
		return [
			'the public page' => [[], '/^SELECT [`"]?sd[`"]?\.[`"]?nid[`"]? FROM [`"]?\w*social_stream_dest[`"]? [`"]?sd[`"]? WHERE/'],
			'its Photos' => [
				['setOnlyMedia' => true, 'setMediaType' => 'image'],
				'/^SELECT [`"]?s[`"]?\.[`"]?nid[`"]? FROM [`"]?\w*social_stream[`"]? [`"]?s[`"]? WHERE [`"]?s[`"]?\.[`"]?media_kind[`"]? =/',
			],
		];
	}

	/** @param array<string, mixed> $narrowing */
	#[DataProvider('pages')]
	public function testThePageQueryIsOneIndexRangeAtScale(array $narrowing, string $pattern): void {
		$run = QueryCounter::record(static function () use ($narrowing): void {
			$request = Server::get(StreamRequest::class);
			$request->setViewer(self::$viewer);
			$options = new ProbeOptions();
			$options->setFormat(ACore::FORMAT_LOCAL)->setProbe(ProbeOptions::PUBLIC)->setLimit(20);
			foreach ($narrowing as $setter => $value) {
				$options->$setter($value);
			}
			$request->getTimeline($options);
		});
		$pages = $run->matching($pattern);
		$this->assertNotEmpty($pages, 'the page query was not run; ' . $run->summary());

		$connection = QueryCounter::doctrineConnection();
		foreach ($pages as $query) {
			$rows = $connection->executeQuery('EXPLAIN ' . $query['sql'], $query['params'], $query['types'])
				->fetchAllAssociative();
			$plan = $query['sql'] . "\n" . implode("\n", array_map(
				static fn (array $row): string => implode(' | ', array_map(static fn ($v): string => (string)$v, $row)), $rows
			));

			$this->assertStringNotContainsString('JOIN', strtoupper($query['sql']), $plan);
			if (self::$platform === 'mysql') {
				$this->assertCount(1, $rows, $plan);
				$this->assertNotContains($rows[0]['type'] ?? '', ['ALL', 'index'], 'read in full: ' . $plan);
				$this->assertNotEmpty($rows[0]['key'] ?? '', 'read without a key: ' . $plan);
				$this->assertDoesNotMatchRegularExpression('/Using (filesort|temporary)/', (string)($rows[0]['Extra'] ?? ''), $plan);
			} else {
				$this->assertDoesNotMatchRegularExpression('/Seq Scan|\bSort\b/', $plan, $plan);
				$this->assertMatchesRegularExpression('/Index (Only )?Scan/', $plan, $plan);
			}
		}
	}
}
