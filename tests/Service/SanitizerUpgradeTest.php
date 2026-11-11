<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Security\HtmlSanitizer;
use OCA\Social\Service\SanitizerUpgrade;
use OCA\Social\Tests\Migration\FakeConnection;
use OCA\Social\Tests\Migration\FakeQueryBuilder;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** The walk that brings stored text up to the current sanitizer stamp. */
class SanitizerUpgradeTest extends TestCase {
	private const STREAM = CoreRequestBuilder::TABLE_STREAM;
	private const ACTORS = CoreRequestBuilder::TABLE_CACHE_ACTORS;

	/** @var array<string, string> */
	private array $config = [];

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

	/** @return list<FakeQueryBuilder> the updates that ran */
	private function updates(FakeConnection $connection): array {
		return array_values(array_filter($connection->writes(), static fn (FakeQueryBuilder $q): bool => $q->sets !== []));
	}

	public function testOnlyRowsUnderAnotherStampAreRewrittenAndTheCursorMoves(): void {
		$version = HtmlSanitizer::version();
		$connection = new FakeConnection([[
			['nid' => '10', 'id' => 'https://remote.example/1', 'content' => '<p onclick="x()">a</p>', 'sanitized' => 0],
			['nid' => '11', 'id' => 'https://remote.example/2', 'content' => '<p>b</p>', 'sanitized' => $version],
		]]);
		$upgrade = new SanitizerUpgrade($connection, $this->appConfig(), new NullLogger());

		$this->assertSame(1, $upgrade->page(self::STREAM));

		$updates = $this->updates($connection);
		$this->assertCount(1, $updates);
		$this->assertSame(self::STREAM, $updates[0]->table);
		$this->assertSame('<p>a</p>', $updates[0]->sets['content']);
		$this->assertSame($version, $updates[0]->sets['sanitized']);
		$this->assertContains('sanitized = 0', $updates[0]->wheres, 'a newer write is not overwritten');
		// a short page is the end of the table
		$this->assertSame($version . ':done', $this->config[SanitizerUpgrade::CURSORS[self::STREAM]]);
		$this->assertNull($upgrade->page(self::STREAM), 'a finished walk reads nothing');
	}

	public function testABioIsWrittenToItsOwnColumnFromTheSummary(): void {
		$connection = new FakeConnection([[
			['nid' => 3, 'id' => 'https://remote.example/users/bob', 'summary' => '<p>bio<script>x</script></p>', 'sanitized' => 0],
		]]);

		(new SanitizerUpgrade($connection, $this->appConfig(), new NullLogger()))->page(self::ACTORS);

		$update = $this->updates($connection)[0];
		$this->assertSame(self::ACTORS, $update->table);
		$this->assertSame('<p>bio</p>', $update->sets['description']);
		$this->assertArrayNotHasKey('summary', $update->sets, 'the bio as it arrived is kept');
	}

	public function testAFullPageLeavesTheCursorAfterItsLastRow(): void {
		$rows = [];
		for ($nid = 1; $nid <= SanitizerUpgrade::BATCH; $nid++) {
			$rows[] = ['nid' => (string)$nid, 'id' => '', 'content' => '', 'sanitized' => HtmlSanitizer::version()];
		}
		$connection = new FakeConnection([$rows, []]);
		$upgrade = new SanitizerUpgrade($connection, $this->appConfig(), new NullLogger());

		$this->assertSame(0, $upgrade->page(self::STREAM));
		$this->assertSame(HtmlSanitizer::version() . ':' . SanitizerUpgrade::BATCH, $this->config[SanitizerUpgrade::CURSORS[self::STREAM]]);

		$this->assertNull($upgrade->page(self::STREAM), 'the next page is past the end');
		$this->assertContains('nid > ' . SanitizerUpgrade::BATCH, $connection->queries[1]->wheres);
	}

	/** A walk finished for an older stamp starts over for the new one. */
	public function testANewStampStartsTheWalkAgain(): void {
		$this->config[SanitizerUpgrade::CURSORS[self::STREAM]] = (HtmlSanitizer::version() - 2) . ':done';
		$connection = new FakeConnection([[]]);

		$this->assertNull((new SanitizerUpgrade($connection, $this->appConfig(), new NullLogger()))->page(self::STREAM));

		$this->assertContains('nid > 0', $connection->queries[0]->wheres);
		$this->assertSame(HtmlSanitizer::version() . ':done', $this->config[SanitizerUpgrade::CURSORS[self::STREAM]]);
	}

	/** A row that cannot be written is passed over, and the walk goes on. */
	public function testARowThatCannotBeWrittenDoesNotHoldTheWalk(): void {
		$connection = new FakeConnection([[
			['nid' => 1, 'id' => '', 'content' => '<b>x</b>', 'sanitized' => 0],
			['nid' => 2, 'id' => '', 'content' => '<b>y</b>', 'sanitized' => 0],
		]], static function (FakeQueryBuilder $query): void {
			if (($query->sets['content'] ?? '') === '<b>x</b>') {
				throw new \RuntimeException('the row is locked');
			}
		});
		$logger = $this->createMock(\Psr\Log\LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$this->assertSame(1, (new SanitizerUpgrade($connection, $this->appConfig(), $logger))->page(self::STREAM));
		$this->assertSame(HtmlSanitizer::version() . ':done', $this->config[SanitizerUpgrade::CURSORS[self::STREAM]]);
	}

	public function testARunWalksBothTablesAndStopsAtTheEndOfEach(): void {
		$connection = new FakeConnection([
			[['nid' => 1, 'id' => '', 'content' => '<b>x</b>', 'sanitized' => 0]],
			[['nid' => 1, 'id' => '', 'summary' => '<b>y</b>', 'sanitized' => 0]],
		]);

		$this->assertSame(2, (new SanitizerUpgrade($connection, $this->appConfig(), new NullLogger()))->run(5));
		$this->assertCount(4, $connection->queries, 'one read and one write per table');
	}
}
