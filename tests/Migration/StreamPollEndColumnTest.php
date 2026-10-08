<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20221118000002;
use OCA\Social\Migration\Version1000Date20261008000501;
use OCP\DB\IResult;
use OCP\DB\Types;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * `social_stream.poll_ends_at`: the column, its index, and the polls that
 * predate it, filled in from their wire objects against a table held in PHP.
 */
#[AllowMockObjectsWithoutExpectations]
class StreamPollEndColumnTest extends TestCase {
	/** @var array<string, array{type: string, source: string, poll_ends_at: ?int}> nid => row */
	private array $rows = [];
	private int $pages = 0;

	/** @return array<string, object> */
	private function stand(): array {
		return [IAppConfig::class => $this->createStub(IAppConfig::class)];
	}

	public function testTheColumnIsAnIndexedNullableTime(): void {
		$schema = MigrationReplay::run([Version1000Date20221118000002::class], $this->stand());
		MigrationReplay::run([Version1000Date20261008000501::class], [], $schema);

		$table = $schema->getTable('social_stream');
		[$type, $options] = $table->addedColumn('poll_ends_at');
		$this->assertSame(Types::BIGINT, $type);
		$this->assertFalse($options['notnull']);
		$this->assertTrue($table->hasIndex('social_s_pend'));
	}

	public function testRunTwiceItAsksForNothingTheSecondTime(): void {
		$schema = MigrationReplay::run([Version1000Date20221118000002::class], $this->stand());
		MigrationReplay::run([Version1000Date20261008000501::class], [], $schema);
		$once = $schema->shape();

		MigrationReplay::run([Version1000Date20261008000501::class], [], $schema);

		$this->assertSame($once, $schema->shape());
	}

	public function answer(string $sql, array $params): IResult {
		$this->pages++;
		if ($this->pages > 10) {
			$this->fail('the backfill keeps asking for pages: it does not advance');
		}

		[$type, $after] = $params;
		preg_match('/LIMIT (\d+)/', $sql, $limit);
		ksort($this->rows);
		$page = [];
		foreach ($this->rows as $nid => $row) {
			if ($row['type'] !== $type || $row['poll_ends_at'] !== null || $nid <= (int)$after) {
				continue;
			}
			$page[] = ['nid' => (string)$nid, 'source' => $row['source']];
			if (count($page) >= (int)$limit[1]) {
				break;
			}
		}

		$result = $this->createStub(IResult::class);
		$result->method('fetchAll')->willReturn($page);

		return $result;
	}

	public function apply(string $sql, array $params): int {
		[$ends, $nid] = $params;
		$this->rows[(int)$nid]['poll_ends_at'] = $ends;

		return 1;
	}

	private function backfill(): void {
		$test = $this;
		$connection = new class($test) extends FakeConnection {
			public function __construct(
				private StreamPollEndColumnTest $test,
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

		(new Version1000Date20261008000501($connection))
			->postSchemaChange($this->createStub(IOutput::class), fn () => null, []);
	}

	public function testEveryExistingPollGetsItsEndAndNothingElseIsTouched(): void {
		for ($i = 1; $i <= 503; $i++) {
			$this->rows[$i] = [
				'type' => 'Question',
				'source' => json_encode(['type' => 'Question', 'endTime' => '2026-01-01T00:00:00Z']),
				'poll_ends_at' => null,
			];
		}
		$this->rows[1000] = ['type' => 'Note', 'source' => '{}', 'poll_ends_at' => null];
		// a poll with no end is passed over, not read for ever
		$this->rows[2000] = ['type' => 'Question', 'source' => '{"type":"Question"}', 'poll_ends_at' => null];

		$this->backfill();

		foreach ($this->rows as $nid => $row) {
			$expected = ($nid < 1000) ? 1767225600 : null;
			$this->assertSame($expected, $row['poll_ends_at'], (string)$nid);
		}
		$this->assertLessThanOrEqual(2, $this->pages);
	}
}
