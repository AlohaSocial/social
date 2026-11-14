<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20260927000010;
use OCA\Social\Migration\Version1000Date20260928000001;
use OCA\Social\Migration\Version1000Date20261114000002;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/** A registration from an app keeps its way back and its code through confirmation and approval. */
class ExternalSignupReturnColumnsTest extends TestCase {
	public function testPendingRegistrationsGetTheReturnAddressAndTheCode(): void {
		$schema = MigrationReplay::run([Version1000Date20260927000010::class, Version1000Date20260928000001::class]);
		MigrationReplay::run([Version1000Date20261114000002::class], [], $schema);

		$signup = $schema->getTable('social_ext_signup');
		$this->assertSame(Types::TEXT, $signup->addedColumn('return_to')[0]);
		$this->assertFalse($signup->addedColumn('return_to')[1]['notnull'], 'registrations made before have none');
		$this->assertSame(Types::STRING, $signup->addedColumn('code_hash')[0]);
		$this->assertSame(64, $signup->addedColumn('code_hash')[1]['length'], 'an HMAC-SHA256, never the code itself');
		$this->assertSame(0, $signup->addedColumn('code_attempts')[1]['default']);
	}

	public function testRunningItAgainChangesNothing(): void {
		$schema = MigrationReplay::run([Version1000Date20260927000010::class, Version1000Date20260928000001::class, Version1000Date20261114000002::class]);

		$this->assertNull(
			(new Version1000Date20261114000002())->changeSchema($this->createStub(IOutput::class), static fn (): FakeSchema => $schema, []),
			'a schema that has the columns is left alone'
		);
	}
}
