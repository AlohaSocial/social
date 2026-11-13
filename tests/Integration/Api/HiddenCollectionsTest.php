<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Controller\AccountApiController;
use OCA\Social\Service\HiddenCollectionsService;
use OCP\Server;

/**
 * Mastodon's `hide_collections` through the client API: set by
 * `update_credentials`, reported in `source`, and honoured by the follower
 * and following lists, which other accounts read empty while the counts stay.
 */
class HiddenCollectionsTest extends ApiTestCase {
	protected function tearDown(): void {
		Server::get(HiddenCollectionsService::class)->setHides(self::PREFIX . 'alice', false);
		parent::tearDown();
	}

	public function testOthersReadTheListsEmptyAndTheAccountItselfDoesNot(): void {
		$this->follow('bob', 'alice');
		$this->follow('alice', 'carol');
		$alice = ['account' => (string)$this->account('alice')->getNid()];

		$set = $this->call(AccountApiController::class, 'updateCredentials', [], ['hide_collections' => 'true'], 'alice', 'PATCH');
		$this->assertSame(200, $set->status);
		$this->assertTrue($set->body->source->hide_collections);

		$this->assertSame([], $this->call(AccountApiController::class, 'accountFollowers', $alice, [], 'bob')->body);
		$this->assertSame([], $this->call(AccountApiController::class, 'accountFollowing', $alice, [], 'bob')->body);
		$this->assertSame([], $this->call(AccountApiController::class, 'accountFollowers', $alice, [], null)->body);
		$this->assertCount(1, $this->call(AccountApiController::class, 'accountFollowers', $alice, [], 'alice')->body);
		$this->assertCount(1, $this->call(AccountApiController::class, 'accountFollowing', $alice, [], 'alice')->body);

		$seen = $this->call(AccountApiController::class, 'accountGet', ['id' => $alice['account']], [], 'bob')->body;
		$this->assertSame(1, $seen->followers_count, 'the counts stay');
		$this->assertTrue($seen->hide_collections);
	}
}
