<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20221118000002;
use OCA\Social\Migration\Version1000Date20261008000502;
use OCP\DB\IResult;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * `social_stream_media`: the table, and the local posts that predate it,
 * linked from their stored attachment copies against a table held in PHP.
 */
#[AllowMockObjectsWithoutExpectations]
class StreamMediaTableTest extends TestCase {
	/** @var array<int, array{local: string, id_prim: string, attachments: string}> nid => row */
	private array $rows = [];
	/** @var array<string, true> "doc_nid stream_id_prim" => the link exists */
	private array $links = [];
	private int $pages = 0;

	/** @return array<string, object> */
	private function stand(): array {
		return [IAppConfig::class => $this->createStub(IAppConfig::class)];
	}

	public function testTheTableIsKeyedOnTheDocumentAndIndexedOnThePost(): void {
		$schema = MigrationReplay::run([Version1000Date20221118000002::class], $this->stand());
		MigrationReplay::run([Version1000Date20261008000502::class], [], $schema);

		$table = $schema->getTable('social_stream_media');
		$this->assertSame(['doc_nid', 'stream_id_prim'], $table->addedPrimaryKey());
		$this->assertTrue($table->hasIndex('social_sm_stream'));
	}

	public function testRunTwiceItAsksForNothingTheSecondTime(): void {
		$schema = MigrationReplay::run([Version1000Date20221118000002::class], $this->stand());
		MigrationReplay::run([Version1000Date20261008000502::class], [], $schema);
		$once = $schema->shape();

		MigrationReplay::run([Version1000Date20261008000502::class], [], $schema);

		$this->assertSame($once, $schema->shape());
	}

	public function answer(string $sql, array $params): IResult {
		$this->pages++;
		if ($this->pages > 10) {
			$this->fail('the backfill keeps asking for pages: it does not advance');
		}

		[$local, $after] = $params;
		preg_match('/LIMIT (\d+)/', $sql, $limit);
		ksort($this->rows);
		$page = [];
		foreach ($this->rows as $nid => $row) {
			if ($row['local'] !== $local || $nid <= (int)$after) {
				continue;
			}
			$page[] = ['nid' => (string)$nid, 'id_prim' => $row['id_prim'], 'attachments' => $row['attachments']];
			if (count($page) >= (int)$limit[1]) {
				break;
			}
		}

		$result = $this->createStub(IResult::class);
		$result->method('fetchAll')->willReturn($page);

		return $result;
	}

	public function link(string $table, array $values): int {
		$this->assertSame('social_stream_media', $table);
		$key = $values['doc_nid'] . ' ' . $values['stream_id_prim'];
		if (isset($this->links[$key])) {
			return 0;
		}
		$this->links[$key] = true;

		return 1;
	}

	private function backfill(): void {
		$test = $this;
		$connection = new class($test) extends FakeConnection {
			public function __construct(
				private StreamMediaTableTest $test,
			) {
				parent::__construct();
			}

			public function executeQuery(string $sql, ?array $params = null, $types = null): IResult {
				return $this->test->answer($sql, $params ?? []);
			}

			public function insertIgnoreConflict(string $table, array $values): int {
				return $this->test->link($table, $values);
			}
		};

		(new Version1000Date20261008000502($connection))
			->postSchemaChange($this->createStub(IOutput::class), fn () => null, []);
	}

	public function testEveryLocalPostIsLinkedToTheDocumentsItCarries(): void {
		for ($i = 1; $i <= 503; $i++) {
			$this->rows[$i] = ['local' => '1', 'id_prim' => md5((string)$i), 'attachments' => '[]'];
		}
		$this->rows[600] = [
			'local' => '1',
			'id_prim' => md5('600'),
			'attachments' => json_encode([['id' => '7', 'type' => 'video'], ['id' => '9', 'type' => 'image'], ['id' => '7']]),
		];
		// a remote post names the origin's files, not ours
		$this->rows[700] = ['local' => '0', 'id_prim' => md5('700'), 'attachments' => json_encode([['id' => '8']])];

		$this->backfill();
		$this->pages = 0;
		// a second run finds the links of the first and keeps them
		$this->backfill();

		$this->assertSame(['7 ' . md5('600') => true, '9 ' . md5('600') => true], $this->links);
	}
}
