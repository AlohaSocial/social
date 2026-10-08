<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Cron\SearchIndex;
use OCA\Social\Migration\Version1000Date20261008000401;
use OCA\Social\Tools\SearchTerms;
use OCP\BackgroundJob\IJobList;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/** The word index of the content search, and the job that fills it for the posts already stored. */
class SearchTermTableTest extends TestCase {
	/** @return array<string, object> */
	private function stand(): array {
		return [IJobList::class => $this->createStub(IJobList::class)];
	}

	public function testOneRowPerWordAndPostIndexedForTheSearchAndTheDelete(): void {
		$schema = MigrationReplay::run([Version1000Date20261008000401::class], $this->stand());
		$table = $schema->getTable('social_search_term');

		[$type, $options] = $table->addedColumn('term');
		$this->assertSame(Types::STRING, $type);
		$this->assertSame(SearchTerms::MAX_LENGTH, $options['length'], 'as wide as a stored word, so the index fits MySQL\'s key');
		$this->assertSame(SearchTerms::MIN_PREFIX, $table->addedColumn('head')[1]['length']);
		$this->assertSame(Types::BIGINT, $table->addedColumn('nid')[0]);
		$this->assertSame(['id'], $table->addedPrimaryKey());

		$indexes = [];
		foreach ($table->addedIndexes() as $index) {
			$indexes[(string)$index['name']] = [$index['columns'], $index['unique']];
		}
		$this->assertSame([
			'social_srch_tn' => [['term', 'nid'], true],
			'social_srch_hn' => [['head', 'nid'], false],
			'social_srch_sp' => [['stream_id_prim'], false],
		], $indexes);
	}

	public function testRunTwiceItAsksForNothingTheSecondTime(): void {
		$schema = MigrationReplay::run([Version1000Date20261008000401::class], $this->stand());
		$once = $schema->shape();

		MigrationReplay::run([Version1000Date20261008000401::class], $this->stand(), $schema);

		$this->assertSame($once, $schema->shape());
	}

	/** @return array{0: IJobList&\PHPUnit\Framework\MockObject\MockObject, 1: Version1000Date20261008000401} */
	private function step(bool $queued): array {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('has')->with(SearchIndex::class, null)->willReturn($queued);

		return [$jobList, new Version1000Date20261008000401($jobList)];
	}

	public function testTheBackfillIsQueuedRatherThanRunDuringTheUpgrade(): void {
		[$jobList, $step] = $this->step(false);
		$jobList->expects($this->once())->method('add')->with(SearchIndex::class);

		$step->postSchemaChange($this->createStub(IOutput::class), static fn () => null, []);
	}

	public function testItIsNotQueuedTwice(): void {
		[$jobList, $step] = $this->step(true);
		$jobList->expects($this->never())->method('add');

		$step->postSchemaChange($this->createStub(IOutput::class), static fn () => null, []);
	}
}
