<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20260927000010;
use OCA\Social\Migration\Version1000Date20260928000001;
use OCP\DB\Types;
use PHPUnit\Framework\TestCase;

/** The registration trail survives approval and account promotion. */
class ExternalSignupAuditColumnsTest extends TestCase {
	public function testExistingExternalUsersCanRecordVerification(): void {
		$schema = MigrationReplay::run([Version1000Date20260927000010::class]);
		MigrationReplay::run([Version1000Date20260928000001::class], [], $schema);

		[, $options] = $schema->getTable('social_ext_user')->addedColumn('email_verified');
		$this->assertSame(Types::SMALLINT, $schema->getTable('social_ext_user')->addedColumn('email_verified')[0]);
		$this->assertSame(-1, $options['default'], 'existing registrations remain unknown, not falsely marked unverified');
	}

	public function testPendingSignupsKeepTheNoticeSnapshotUntilApproval(): void {
		$schema = MigrationReplay::run([Version1000Date20260927000010::class]);
		MigrationReplay::run([Version1000Date20260928000001::class], [], $schema);

		$signup = $schema->getTable('social_ext_signup');
		foreach (['email_verified', 'notice_version', 'notice_accepted', 'notice_snapshot'] as $column) {
			$this->assertTrue($signup->hasColumn($column), $column . ' is required to carry the acceptance through email confirmation and moderation');
		}
		$this->assertSame(Types::TEXT, $signup->addedColumn('notice_snapshot')[0]);
	}
}
