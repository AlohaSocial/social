<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20221118000002;
use OCA\Social\Migration\Version1000Date20261009000710;
use OCP\DB\Types;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/** When the posts with a followed hashtag were last read from beyond this server. */
class FollowedTagFilledColumnTest extends TestCase {
	private function schema(): FakeSchema {
		$schema = MigrationReplay::run([Version1000Date20221118000002::class], [IAppConfig::class => $this->createStub(IAppConfig::class)]);
		MigrationReplay::run([Version1000Date20261009000710::class], [], $schema);

		return $schema;
	}

	public function testEveryTagStartsAsNeverRead(): void {
		[$type, $options] = $this->schema()->getTable('social_followed_tag')->addedColumn('filled');

		$this->assertSame(Types::BIGINT, $type);
		$this->assertSame(0, $options['default']);
		$this->assertTrue($options['notnull']);
	}

	public function testTheTagAndWhenItWasReadAreOneIndex(): void {
		$indexes = $this->schema()->shape()['social_followed_tag']['indexes'];

		$this->assertContains(['columns' => ['hashtag', 'filled'], 'name' => 'social_ft_hf', 'unique' => false], $indexes);
	}

	public function testRunTwiceItAsksForNothingTheSecondTime(): void {
		$schema = $this->schema();
		$once = $schema->shape();

		MigrationReplay::run([Version1000Date20261009000710::class], [], $schema);

		$this->assertSame($once, $schema->shape());
	}

	public function testItDoesNothingWhereTheTableIsNotThere(): void {
		$schema = MigrationReplay::run([Version1000Date20261009000710::class]);

		$this->assertArrayNotHasKey('social_followed_tag', $schema->shape());
	}
}
