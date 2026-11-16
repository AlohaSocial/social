<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20261013100500;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The `archived` backfill pages on the primary key from where the last page
 * ended. `archived` has no index, and asking for the first NULL rows again
 * each page walked past every row already filled: quadratic in the size of
 * the table.
 */
class ArchivedBackfillPagingTest extends TestCase {
	public function testEachPageStartsWhereTheLastOneEnded(): void {
		$page = [];
		for ($nid = 1; $nid <= 2500; $nid++) {
			$page[] = ['nid' => (string)(1000000 + $nid)];
		}
		// the first page, then nothing, then the media-kind probe finding none
		$connection = new FakeConnection([$page, [], []]);

		(new Version1000Date20261013100500($connection, $this->createStub(IAppConfig::class)))
			->fill($this->createStub(IOutput::class));

		$selects = array_values(array_filter($connection->queries, static fn (FakeQueryBuilder $q): bool => $q->selects !== [] && $q->sets === []));
		$this->assertContains('nid > 0', $selects[0]->wheres);
		$this->assertContains('nid > 1002500', $selects[1]->wheres, 'the second page starts after the last nid of the first');

		$updates = $connection->writes();
		$this->assertCount(3, $updates, 'a page is filled a thousand rows per statement');
		foreach ($updates as $update) {
			$this->assertFalse($update->sets['archived']);
		}
	}
}
