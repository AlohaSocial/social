<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\AccountNotesRequest;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The private notes one account keeps on others, against the real database:
 * one row per pair, written twice as an update, and gone with either account.
 */
class AccountNotesRequestTest extends TestCase {
	private const ALICE = 'https://itest.example/users/notes-alice';
	private const BOB = 'https://itest.example/users/notes-bob';
	private const CAROL = 'https://itest.example/users/notes-carol';

	private AccountNotesRequest $notes;

	protected function setUp(): void {
		parent::setUp();
		$this->notes = Server::get(AccountNotesRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach ([self::ALICE, self::BOB, self::CAROL] as $actor) {
			$this->notes->deleteRelatedId($actor);
		}
	}

	public function testANoteIsKeptAndWritingItAgainReplacesIt(): void {
		$this->assertSame('', $this->notes->getNote(self::ALICE, self::BOB));

		$this->notes->save(self::ALICE, self::BOB, 'met at the conference');
		$this->notes->save(self::ALICE, self::BOB, 'met at the conference, likes jazz');

		$this->assertSame('met at the conference, likes jazz', $this->notes->getNote(self::ALICE, self::BOB));
		$this->assertSame(
			[self::BOB => 'met at the conference, likes jazz'],
			$this->notes->getNotes(self::ALICE, [self::BOB, self::CAROL])
		);
	}

	public function testANoteIsTheWritersAlone(): void {
		$this->notes->save(self::ALICE, self::BOB, 'from alice');

		$this->assertSame('', $this->notes->getNote(self::CAROL, self::BOB));
		$this->assertSame([], $this->notes->getNotes(self::CAROL, [self::BOB]));
		$this->assertSame([], $this->notes->getNotes(self::ALICE, []));
	}

	public function testDeletingRemovesOnlyThatPair(): void {
		$this->notes->save(self::ALICE, self::BOB, 'b');
		$this->notes->save(self::ALICE, self::CAROL, 'c');

		$this->notes->delete(self::ALICE, self::BOB);

		$this->assertSame([self::CAROL => 'c'], $this->notes->getNotes(self::ALICE, [self::BOB, self::CAROL]));
	}

	/** an account that goes takes the notes it wrote and the notes about it */
	public function testAnAccountThatGoesTakesItsNotesBothWays(): void {
		$this->notes->save(self::ALICE, self::BOB, 'about bob');
		$this->notes->save(self::BOB, self::CAROL, 'by bob');
		$this->notes->save(self::ALICE, self::CAROL, 'unrelated');

		$this->notes->deleteRelatedId(self::BOB);

		$this->assertSame('', $this->notes->getNote(self::ALICE, self::BOB));
		$this->assertSame('', $this->notes->getNote(self::BOB, self::CAROL));
		$this->assertSame('unrelated', $this->notes->getNote(self::ALICE, self::CAROL));
	}
}
