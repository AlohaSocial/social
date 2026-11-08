<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Atproto;

use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Db\AtprotoIdentityRequest;
use OCA\Social\Db\AtprotoWatchRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * What the Bluesky chat poller keeps against the real database: the cursor
 * saved part-way through a log that then failed, beside the failure count,
 * and the one-query signature of the active identities that tells it when
 * to enrol them again.
 */
class ChatPollerStorageTest extends TestCase {
	private const TABLE = CoreRequestBuilder::TABLE_ATPROTO_CHAT_CURSOR;

	private string $did;

	protected function setUp(): void {
		$this->did = 'did:plc:' . substr(str_shuffle(str_repeat('abcdefghijklmnopqrstuvwxyz234567', 2)), 0, 24);
	}

	protected function tearDown(): void {
		Server::get(AtprotoWatchRequest::class)->remove($this->did, self::TABLE);
		Server::get(AtprotoIdentityRequest::class)->deleteByDid($this->did);
		parent::tearDown();
	}

	public function testACheckpointMovesTheCursorAndLeavesTheFailuresToFailed(): void {
		$cursors = Server::get(AtprotoWatchRequest::class);
		$cursors->add($this->did, 'alice.test', self::TABLE);
		$cursors->synced($this->did, '3k005', 1790000000, 1790000120, self::TABLE);
		$cursors->failed($this->did, 'earlier', 1790000240, self::TABLE);

		$cursors->checkpoint($this->did, '3k007', self::TABLE);
		$cursors->failed($this->did, 'database unavailable', 1790000480, self::TABLE);

		$watch = $cursors->getByDid($this->did, self::TABLE);
		$this->assertSame('3k007', $watch?->cursor);
		$this->assertSame(2, $watch->failures);
		$this->assertSame(1790000480, $watch->nextSync);
	}

	public function testTheActiveSignatureChangesWithANewOrSwitchedOffIdentity(): void {
		$identities = Server::get(AtprotoIdentityRequest::class);
		$before = $identities->activeSignature();
		$this->assertMatchesRegularExpression('/^\d+:\d+$/', $before);

		$identities->create('https://social.test/users/sig-' . $this->did, $this->did, substr($this->did, 8) . '.social.test', 'sealed', 'did:key:z', '');
		$added = $identities->activeSignature();
		$this->assertNotSame($before, $added);
		$this->assertSame($added, $identities->activeSignature(), 'stable while nothing changes');

		$identities->setState($this->did, Identity::STATE_DEACTIVATED);
		$this->assertNotSame($added, $identities->activeSignature());
	}
}
