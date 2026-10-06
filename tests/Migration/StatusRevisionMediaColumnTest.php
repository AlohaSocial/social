<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20221118000002;
use OCA\Social\Migration\Version1000Date20261006000010;
use OCP\DB\Types;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/** Where a revision of a status records the attachments of that version. */
class StatusRevisionMediaColumnTest extends TestCase {
	/** @return array<string, object> */
	private function stand(): array {
		return [IAppConfig::class => $this->createStub(IAppConfig::class)];
	}

	public function testTheColumnIsNullableText(): void {
		$schema = MigrationReplay::run([Version1000Date20221118000002::class], $this->stand());
		MigrationReplay::run([Version1000Date20261006000010::class], [], $schema);

		[$type, $options] = $schema->getTable('social_stream_rev')->addedColumn('media');

		$this->assertSame(Types::TEXT, $type);
		// a revision from before the column has no record of its attachments
		$this->assertFalse($options['notnull']);
	}

	public function testRunTwiceItAsksForNothingTheSecondTime(): void {
		$schema = MigrationReplay::run([Version1000Date20221118000002::class], $this->stand());
		MigrationReplay::run([Version1000Date20261006000010::class], [], $schema);
		$once = $schema->shape();

		MigrationReplay::run([Version1000Date20261006000010::class], [], $schema);

		$this->assertSame($once, $schema->shape());
	}

	public function testItDoesNothingWhereTheTableIsNotThere(): void {
		$schema = MigrationReplay::run([Version1000Date20261006000010::class]);

		$this->assertArrayNotHasKey('social_stream_rev', $schema->shape());
	}
}
