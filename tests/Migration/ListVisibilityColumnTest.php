<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20221118000002;
use OCA\Social\Migration\Version1000Date20261009000720;
use OCA\Social\Model\Client\MastodonList;
use OCP\DB\Types;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * A list's visibility: private unless its owner makes it public, so every
 * list that was there before stays its owner's alone.
 */
class ListVisibilityColumnTest extends TestCase {
	public function testAListIsPrivateUnlessMadePublicAndTheColumnIsAddedOnce(): void {
		$schema = MigrationReplay::run(
			[Version1000Date20221118000002::class, Version1000Date20261009000720::class],
			[IAppConfig::class => $this->createStub(IAppConfig::class)]
		);
		$column = $schema->shape()['social_list']['columns']['visibility'];

		$this->assertSame(Types::STRING, $column['type']);
		$this->assertTrue($column['options']['notnull']);
		$this->assertSame(MastodonList::VISIBILITY_PRIVATE, $column['options']['default']);
		$this->assertGreaterThanOrEqual(strlen(MastodonList::VISIBILITY_PUBLIC), $column['options']['length']);

		$shape = $schema->shape();
		MigrationReplay::run([Version1000Date20261009000720::class], [], $schema);
		$this->assertSame($shape, $schema->shape(), 'run twice, nothing asked the second time');
	}
}
