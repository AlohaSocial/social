<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCA\Social\Cron\StreamAuthorHosts;
use OCA\Social\Service\ConfigService;
use OCA\Social\Tests\Migration\FakeConnection;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\DB\IResult;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The backfill of `social_stream.author_host`, against a table held in PHP.
 */
#[AllowMockObjectsWithoutExpectations]
class StreamAuthorHostsTest extends TestCase {
	/** @var array<int, array{attributed_to: string, author_host: ?string}> nid => row */
	private array $rows = [];
	public int $updates = 0;
	/** @var array<string, string> */
	private array $stored = [];
	/** @var array<int, mixed> arguments the job re-queued itself with */
	private array $queued = [];

	public function answer(string $sql, array $params): IResult {
		preg_match('/LIMIT (\d+)/', $sql, $limit);
		ksort($this->rows);
		$page = [];
		foreach ($this->rows as $nid => $row) {
			if ($nid <= (int)$params[0]) {
				continue;
			}
			$page[] = ['nid' => (string)$nid] + $row;
			if (count($page) >= (int)$limit[1]) {
				break;
			}
		}

		$result = $this->createStub(IResult::class);
		$result->method('fetchAll')->willReturn($page);

		return $result;
	}

	public function apply(string $sql, array $params): int {
		$this->updates++;
		$host = array_shift($params);
		foreach ($params as $nid) {
			if ($this->rows[(int)$nid]['author_host'] === null) {
				$this->rows[(int)$nid]['author_host'] = $host;
			}
		}

		return count($params);
	}

	private function job(): StreamAuthorHosts {
		$test = $this;
		$connection = new class($test) extends FakeConnection {
			public function __construct(
				private StreamAuthorHostsTest $test,
			) {
				parent::__construct();
			}

			public function executeQuery(string $sql, ?array $params = null, $types = null): IResult {
				return $this->test->answer($sql, $params ?? []);
			}

			public function executeStatement($sql, ?array $params = null, ?array $types = null): int {
				return $this->test->apply((string)$sql, $params ?? []);
			}
		};
		$config = $this->createStub(ConfigService::class);
		$config->method('setAppValue')->willReturnCallback(function (string $key, string $value): void {
			$this->stored[$key] = $value;
		});
		$jobList = $this->createStub(IJobList::class);
		$jobList->method('add')->willReturnCallback(function (string $class, $argument): void {
			$this->queued[] = $argument;
		});

		return new StreamAuthorHosts($this->createStub(ITimeFactory::class), $connection, $config, $jobList, new NullLogger());
	}

	public function testEveryRowGetsItsHostOneStatementPerHost(): void {
		for ($i = 1; $i <= 10; $i++) {
			$this->rows[$i] = ['attributed_to' => 'https://' . (($i % 2) ? 'A.example' : 'b.example') . '/users/' . $i, 'author_host' => null];
		}
		$this->rows[11] = ['attributed_to' => 'local-notification', 'author_host' => null];
		// written by saveStream() since the column exists: left as it is
		$this->rows[12] = ['attributed_to' => 'https://c.example/users/x', 'author_host' => 'c.example'];

		$this->assertNull($this->job()->fill('0', 5));

		$this->assertSame(3, $this->updates);
		$this->assertSame('a.example', $this->rows[1]['author_host']);
		$this->assertSame('b.example', $this->rows[2]['author_host']);
		$this->assertSame('', $this->rows[11]['author_host']);
		$this->assertSame('c.example', $this->rows[12]['author_host']);
	}

	public function testARunThatDoesNotReachTheEndQueuesItselfWhereItStopped(): void {
		$count = StreamAuthorHosts::PAGE * StreamAuthorHosts::PAGES_PER_RUN + 1;
		for ($i = 1; $i <= $count; $i++) {
			$this->rows[$i] = ['attributed_to' => 'https://a.example/users/' . $i, 'author_host' => null];
		}

		$job = $this->job();
		(new \ReflectionMethod(StreamAuthorHosts::class, 'run'))->invoke($job, ['after' => '0']);

		$this->assertSame([['after' => (string)($count - 1)]], $this->queued);
		$this->assertArrayNotHasKey(ConfigService::SOCIAL_STREAM_AUTHOR_HOSTS_FILLED, $this->stored);
		$this->assertNull($this->rows[$count]['author_host']);

		(new \ReflectionMethod(StreamAuthorHosts::class, 'run'))->invoke($job, $this->queued[0]);

		$this->assertSame('a.example', $this->rows[$count]['author_host']);
		$this->assertSame('1', $this->stored[ConfigService::SOCIAL_STREAM_AUTHOR_HOSTS_FILLED]);
		$this->assertCount(1, $this->queued);
	}
}
