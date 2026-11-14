<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20221118000002;
use OCA\Social\Migration\Version1000Date20261113200001;
use OCP\DB\Types;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/** `social_stream.source_digest`, NULL until a post has one. */
class StreamSourceDigestColumnTest extends TestCase {
	public function testTheDigestIsANullableTextAddedOnce(): void {
		$schema = MigrationReplay::run(
			[Version1000Date20221118000002::class],
			[IAppConfig::class => $this->createStub(IAppConfig::class)]
		);
		MigrationReplay::run([Version1000Date20261113200001::class], [], $schema);

		[$type, $options] = $schema->getTable('social_stream')->addedColumn('source_digest');
		$this->assertSame(Types::TEXT, $type);
		$this->assertFalse($options['notnull'], 'NULL is what says a row has not been digested');
		$this->assertNull($options['default']);

		$once = $schema->shape();
		MigrationReplay::run([Version1000Date20261113200001::class], [], $schema);
		$this->assertSame($once, $schema->shape(), 'run twice, nothing asked the second time');
	}

	public function testItDoesNothingWhereTheTableIsNotThere(): void {
		$schema = MigrationReplay::run([Version1000Date20261113200001::class]);

		$this->assertArrayNotHasKey('social_stream', $schema->shape());
	}
}
