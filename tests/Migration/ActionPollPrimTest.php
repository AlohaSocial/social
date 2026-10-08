<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20221118000002;
use OCA\Social\Migration\Version1000Date20261008000500;
use OCP\DB\IResult;
use OCP\DB\Types;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * `social_action.poll_prim`: the column, its index, and the votes that
 * predate it, filled in against a table held in PHP.
 */
#[AllowMockObjectsWithoutExpectations]
class ActionPollPrimTest extends TestCase {
	/** @var array<string, array{type: string, object_id: string, poll_prim: string}> id_prim => row */
	private array $rows = [];
	private int $pages = 0;

	/** @return array<string, object> */
	private function stand(): array {
		return [IAppConfig::class => $this->createStub(IAppConfig::class)];
	}

	public function testTheColumnIsAnIndexedPrim(): void {
		$schema = MigrationReplay::run([Version1000Date20221118000002::class], $this->stand());
		MigrationReplay::run([Version1000Date20261008000500::class], [], $schema);

		$table = $schema->getTable('social_action');
		[$type, $options] = $table->addedColumn('poll_prim');
		$this->assertSame(Types::STRING, $type);
		$this->assertSame(32, $options['length']);
		$this->assertTrue($table->hasIndex('social_a_poll'));
	}

	public function testRunTwiceItAsksForNothingTheSecondTime(): void {
		$schema = MigrationReplay::run([Version1000Date20221118000002::class], $this->stand());
		MigrationReplay::run([Version1000Date20261008000500::class], [], $schema);
		$once = $schema->shape();

		MigrationReplay::run([Version1000Date20261008000500::class], [], $schema);

		$this->assertSame($once, $schema->shape());
	}

	public function answer(string $sql, array $params): IResult {
		$this->pages++;
		if ($this->pages > 10) {
			$this->fail('the backfill keeps asking for pages: it does not advance');
		}

		[$type, $after] = $params;
		preg_match('/LIMIT (\d+)/', $sql, $limit);
		ksort($this->rows, SORT_STRING);
		$page = [];
		foreach ($this->rows as $prim => $row) {
			if ($row['type'] !== $type || $row['poll_prim'] !== '' || strcmp((string)$prim, (string)$after) <= 0) {
				continue;
			}
			$page[] = ['id_prim' => (string)$prim, 'object_id' => $row['object_id']];
			if (count($page) >= (int)$limit[1]) {
				break;
			}
		}

		$result = $this->createStub(IResult::class);
		$result->method('fetchAll')->willReturn($page);

		return $result;
	}

	public function apply(string $sql, array $params): int {
		$cases = substr_count($sql, 'WHEN ?');
		for ($i = 0; $i < $cases; $i++) {
			$this->rows[(string)$params[$i * 2]]['poll_prim'] = (string)$params[$i * 2 + 1];
		}

		return $cases;
	}

	private function backfill(): void {
		$test = $this;
		$connection = new class($test) extends FakeConnection {
			public function __construct(
				private ActionPollPrimTest $test,
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

		(new Version1000Date20261008000500($connection))
			->postSchemaChange($this->createStub(IOutput::class), fn () => null, []);
	}

	public function testEveryExistingVoteGetsItsPollAndNothingElseIsTouched(): void {
		$poll = 'https://cloud.example/@alice/1';
		for ($i = 0; $i < 2003; $i++) {
			$this->rows[sprintf('v%031d', $i)] = ['type' => 'Vote', 'object_id' => $poll . '#option-' . ($i % 3), 'poll_prim' => ''];
		}
		$this->rows['l' . str_repeat('0', 31)] = ['type' => 'Like', 'object_id' => $poll, 'poll_prim' => ''];
		// a vote whose object names no poll is passed over, not read for ever
		$this->rows['u' . str_repeat('0', 31)] = ['type' => 'Vote', 'object_id' => 'nothing', 'poll_prim' => ''];

		$this->backfill();

		foreach ($this->rows as $prim => $row) {
			$expected = ($row['type'] === 'Vote' && $row['object_id'] !== 'nothing') ? md5($poll) : '';
			$this->assertSame($expected, $row['poll_prim'], (string)$prim);
		}
		$this->assertLessThanOrEqual(2, $this->pages);
	}
}
