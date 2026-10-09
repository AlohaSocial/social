<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20261008000020;
use OCA\Social\Migration\Version1000Date20261009000200;
use PHPUnit\Framework\TestCase;

/**
 * Where each account's Bluesky direct messages were read up to: the same
 * shape as the notifications' cursor, so one request class keeps both.
 */
class AtprotoChatCursorTest extends TestCase {
	public function testTheChatCursorIsTheNotificationCursorsShapeAddedOnce(): void {
		$schema = MigrationReplay::run([Version1000Date20261008000020::class, Version1000Date20261009000200::class]);
		$shape = $schema->shape();

		$this->assertSame($shape['social_atproto_notify_cursor']['columns'], $shape['social_atproto_chat_cursor']['columns']);
		$this->assertSame(['id'], $shape['social_atproto_chat_cursor']['primary']);

		MigrationReplay::run([Version1000Date20261009000200::class], [], $schema);
		$this->assertSame($shape, $schema->shape(), 'run twice, nothing asked the second time');
	}
}
