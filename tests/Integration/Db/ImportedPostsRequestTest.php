<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\ImportedPostsRequest;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * What an account brought over from an export, so a second run of the same
 * archive writes nothing twice and a reply finds the post it answers.
 */
class ImportedPostsRequestTest extends TestCase {
	private const ALICE = 'https://itest.example/users/import-alice';
	private const BOB = 'https://itest.example/users/import-bob';

	private ImportedPostsRequest $imported;

	protected function setUp(): void {
		parent::setUp();
		$this->imported = Server::get(ImportedPostsRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		$this->imported->deleteByActor(self::ALICE);
		$this->imported->deleteByActor(self::BOB);
	}

	public function testAnImportedPostIsFoundByItsOriginalId(): void {
		$this->imported->remember(self::ALICE, 'https://old.example/statuses/1', 'https://itest.example/posts/new-1');
		$this->imported->remember(self::ALICE, 'https://old.example/statuses/2', 'https://itest.example/posts/new-2');

		$known = $this->imported->knownAmong(self::ALICE, ['https://old.example/statuses/1', 'https://old.example/statuses/9', '']);

		$this->assertSame(['https://old.example/statuses/1' => md5('https://itest.example/posts/new-1')], $known);
		$this->assertSame(2, $this->imported->countFor(self::ALICE));
	}

	/** a second run of the same archive is not an error, and adds nothing */
	public function testRememberingTheSamePostTwiceKeepsOneRow(): void {
		$this->imported->remember(self::ALICE, 'https://old.example/statuses/1', 'https://itest.example/posts/new-1');
		$this->imported->remember(self::ALICE, 'https://old.example/statuses/1', 'https://itest.example/posts/new-1b');

		$this->assertSame(1, $this->imported->countFor(self::ALICE));
	}

	public function testWhatWasImportedIsEachAccountsOwn(): void {
		$this->imported->remember(self::ALICE, 'https://old.example/statuses/1', 'https://itest.example/posts/new-1');

		$this->assertSame([], $this->imported->knownAmong(self::BOB, ['https://old.example/statuses/1']));
		$this->assertSame(0, $this->imported->countFor(self::BOB));
		$this->assertSame([], $this->imported->knownAmong(self::ALICE, []));
	}
}
