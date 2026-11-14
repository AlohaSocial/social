<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Migration\Version1000Date20261113200002;
use OCP\DB\Types;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/** The hourly hashtag buckets and the hours marked for a recount. */
class TagHourBucketsTest extends TestCase {
	public function testBothTablesAreCreatedOnceKeyedAsTheyAreRead(): void {
		$config = $this->createStub(IAppConfig::class);
		$schema = MigrationReplay::run([Version1000Date20261113200002::class], [IAppConfig::class => $config]);

		$buckets = $schema->shape()['social_tag_hour'];
		$this->assertSame(['hashtag', 'hour_start'], $buckets['primary']);
		$this->assertSame(Types::STRING, $buckets['columns']['hashtag']['type']);
		$this->assertSame(127, $buckets['columns']['hashtag']['options']['length'], 'as wide as social_stream_tag.hashtag');
		$this->assertSame(Types::BIGINT, $buckets['columns']['hour_start']['type']);
		$this->assertSame(Types::INTEGER, $buckets['columns']['uses']['type']);
		$this->assertContains(
			['columns' => ['hour_start'], 'name' => 'social_th_hour', 'unique' => false],
			$buckets['indexes'],
			'the window and the purge read by hour'
		);

		$this->assertSame(['hour_start'], $schema->shape()['social_tag_dirty']['primary']);

		$once = $schema->shape();
		MigrationReplay::run([Version1000Date20261113200002::class], [IAppConfig::class => $config], $schema);
		$this->assertSame($once, $schema->shape(), 'run twice, nothing asked the second time');
	}

	public function testTheBucketsAreBuiltByTheNextTrendsPass(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())->method('deleteKey')->with('social', StreamRequest::TREND_BUCKETS_MARK);

		(new Version1000Date20261113200002($config))->postSchemaChange(
			$this->createStub(IOutput::class), static fn () => null, []
		);
	}
}
