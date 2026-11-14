<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Cron\SourceDigests;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Migration\BackfillStreamPostFields;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\SourceDigestBackfill;
use OCA\Social\Tests\Migration\FakeConnection;
use OCA\Social\Tests\Migration\FakeQueryBuilder;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** The walk that gives the posts stored before it their `source_digest`. */
class SourceDigestBackfillTest extends TestCase {
	/** @var array<string, string> */
	private array $config = [BackfillStreamPostFields::MARKER => '1'];

	private function appConfig(): IAppConfig {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->config[$key] ?? $default
		);
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->config[$key] = $value;

			return true;
		});

		return $config;
	}

	/** @return list<FakeQueryBuilder> */
	private function updates(FakeConnection $connection): array {
		return array_values(array_filter($connection->writes(), static fn (FakeQueryBuilder $q): bool => $q->sets !== []));
	}

	public function testEachUndigestedRowGetsTheDigestOfItsSourceAndTheWalkEnds(): void {
		$wire = json_encode([
			'type' => 'Note',
			'tag' => [['type' => 'Emoji', 'name' => ':blob:', 'icon' => ['url' => 'https://remote.example/blob.png']]],
			'replies' => ['totalItems' => 4],
		]);
		$connection = new FakeConnection([[
			['nid' => '10', 'source' => $wire],
			['nid' => '11', 'source' => ''],
		]]);
		$backfill = new SourceDigestBackfill($connection, $this->appConfig(), new NullLogger());

		$this->assertSame(2, $backfill->page());

		$select = $connection->queries[0];
		$this->assertContains('source_digest IS NULL', $select->wheres, 'only rows without a digest are read');
		$updates = $this->updates($connection);
		$this->assertCount(2, $updates);
		$this->assertSame(CoreRequestBuilder::TABLE_STREAM, $updates[0]->table);
		$this->assertSame(Stream::sourceDigestOf($wire), $updates[0]->sets['source_digest']);
		$this->assertSame('{}', $updates[1]->sets['source_digest']);
		$this->assertContains('source_digest IS NULL', $updates[0]->wheres, 'a digest written since the read is kept');
		$this->assertSame('done', $this->config[SourceDigestBackfill::CURSOR]);
		$this->assertNull($backfill->page(), 'a finished walk reads nothing');
	}

	public function testAFullPageLeavesTheCursorAfterItsLastRow(): void {
		$rows = [];
		for ($nid = 1; $nid <= SourceDigestBackfill::BATCH; $nid++) {
			$rows[] = ['nid' => (string)$nid, 'source' => ''];
		}
		$connection = new FakeConnection([$rows, []]);
		$backfill = new SourceDigestBackfill($connection, $this->appConfig(), new NullLogger());

		$this->assertSame(SourceDigestBackfill::BATCH, $backfill->page());
		$this->assertSame((string)SourceDigestBackfill::BATCH, $this->config[SourceDigestBackfill::CURSOR]);
	}

	public function testItWaitsForTheFivePostColumnsToBeFilledIn(): void {
		$this->config = [];
		$connection = new FakeConnection([[['nid' => '1', 'source' => '{}']]]);

		$this->assertSame(0, (new SourceDigestBackfill($connection, $this->appConfig(), new NullLogger()))->run());
		$this->assertSame([], $connection->queries, 'a row given a digest is read without the column fallback');
	}

	public function testTheTimedJobTakesABoundedShareEveryFiveMinutes(): void {
		$backfill = $this->createMock(SourceDigestBackfill::class);
		$backfill->expects($this->once())->method('run')->with(20)->willReturn(0);
		$job = new SourceDigests($this->createStub(ITimeFactory::class), $backfill);

		(new \ReflectionMethod(SourceDigests::class, 'run'))->invoke($job, null);

		$this->assertSame(300, $job->getInterval());
	}
}
