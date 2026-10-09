<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20261008000040;
use OCA\Social\Migration\Version1000Date20261009000100;
use OCP\DB\Types;
use PHPUnit\Framework\TestCase;

/**
 * Whether a Bluesky app password reaches the direct messages.
 */
class AtprotoAppPasswordPrivilegedTest extends TestCase {
	public function testPrivilegedIsAnOptInSmallintAddedOnce(): void {
		$schema = MigrationReplay::run([Version1000Date20261008000040::class, Version1000Date20261009000100::class]);

		[$type, $options] = $schema->getTable('social_atproto_app_password')->addedColumn('privileged');
		$this->assertSame(Types::SMALLINT, $type);
		$this->assertTrue($options['notnull']);
		$this->assertSame(0, $options['default'], 'off for every password made before, as Bluesky\'s are');

		$once = $schema->shape();
		MigrationReplay::run([Version1000Date20261009000100::class], [], $schema);
		$this->assertSame($once, $schema->shape(), 'run twice, nothing asked the second time');
	}
}
