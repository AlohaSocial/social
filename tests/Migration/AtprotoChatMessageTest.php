<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20261028000001;
use PHPUnit\Framework\TestCase;

class AtprotoChatMessageTest extends TestCase {
	public function testMessageMappingsHaveNoExpiryAndMigrationCanBeRepeated(): void {
		$schema = MigrationReplay::run([Version1000Date20261028000001::class]);
		MigrationReplay::run([Version1000Date20261028000001::class], [], $schema);
		$table = $schema->getTable('social_atp_chat_message');
		foreach (['stream_id_prim', 'did', 'convo_id', 'message_id'] as $column) {
			$this->assertTrue($table->hasColumn($column));
		}
		$this->assertFalse($table->hasColumn('expires'));
	}
}
