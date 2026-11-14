<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20221118000002;
use OCA\Social\Migration\Version1000Date20261113500001;
use OCP\DB\Types;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/** The refresh token of an authorization, and the ones already spent. */
class RefreshTokenColumnsTest extends TestCase {
	private function schema(): FakeSchema {
		$schema = MigrationReplay::run(
			[Version1000Date20221118000002::class],
			[IAppConfig::class => $this->createStub(IAppConfig::class)]
		);
		MigrationReplay::run([Version1000Date20261113500001::class], [], $schema);

		return $schema;
	}

	public function testAnAuthorizationFromBeforeHasNoRefreshTokenAndWasNeverRefreshed(): void {
		$table = $this->schema()->getTable('social_client_auth');

		[$type, $options] = $table->addedColumn('refresh_token');
		$this->assertSame(Types::STRING, $type);
		$this->assertSame('', $options['default']);
		[$type, $options] = $table->addedColumn('token_creation');
		$this->assertSame(Types::DATETIME, $type);
		$this->assertFalse($options['notnull'], 'a row from before counts its lifetime from the grant');
		[$type, $options] = $table->addedColumn('refresh_count');
		$this->assertSame(0, $options['default']);
		$this->assertTrue($table->hasIndex('social_ca_rtok'));
	}

	public function testASpentTokenIsLookedUpByItsDigestAndOnceOnly(): void {
		$shape = $this->schema()->shape();

		$this->assertArrayHasKey('social_client_refresh_spent', $shape);
		$this->assertSame(['id'], $shape['social_client_refresh_spent']['primary']);
	}

	public function testRunTwiceItAsksForNothingTheSecondTime(): void {
		$schema = $this->schema();
		$once = $schema->shape();

		MigrationReplay::run([Version1000Date20261113500001::class], [], $schema);

		$this->assertSame($once, $schema->shape());
	}
}
