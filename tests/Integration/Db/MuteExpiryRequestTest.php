<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\MuteExpiryRequest;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * When a timed mute ends, stored as a date and read back as the same second.
 */
class MuteExpiryRequestTest extends TestCase {
	private const ALICE = 'https://itest.example/users/mute-alice';
	private const BOB = 'https://itest.example/users/mute-bob';
	private const CAROL = 'https://itest.example/users/mute-carol';

	private MuteExpiryRequest $expiries;

	protected function setUp(): void {
		parent::setUp();
		$this->expiries = Server::get(MuteExpiryRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach ([self::ALICE, self::BOB, self::CAROL] as $actor) {
			$this->expiries->deleteRelatedId($actor);
		}
	}

	public function testAnExpiryIsReadBackToTheSecondAndAChangeReplacesIt(): void {
		$in = time() + 3600;
		$this->expiries->save(self::ALICE, self::BOB, $in);
		$this->assertSame($in, $this->expiries->getExpiry(self::ALICE, self::BOB));

		$later = $in + 86400;
		$this->expiries->save(self::ALICE, self::BOB, $later);
		$this->assertSame($later, $this->expiries->getExpiry(self::ALICE, self::BOB));
	}

	public function testAMuteWithoutAnEndReadsAsNone(): void {
		$this->assertSame(0, $this->expiries->getExpiry(self::ALICE, self::BOB));
		$this->assertSame([], $this->expiries->getExpiries(self::ALICE, []));
	}

	public function testManyAreReadAtOnceByTheIdsTheyWereAskedFor(): void {
		$this->expiries->save(self::ALICE, self::BOB, time() + 60);
		$this->expiries->save(self::ALICE, self::CAROL, time() + 120);

		$read = $this->expiries->getExpiries(self::ALICE, [self::BOB, self::CAROL, 'https://itest.example/users/nobody']);

		$this->assertSame([self::BOB, self::CAROL], array_keys($this->sorted($read)));
	}

	public function testDeletingEndsOneMuteAndDeletingByActorEndsThemAll(): void {
		$this->expiries->save(self::ALICE, self::BOB, time() + 60);
		$this->expiries->save(self::ALICE, self::CAROL, time() + 60);

		$this->expiries->delete(self::ALICE, self::BOB);
		$this->assertSame(0, $this->expiries->getExpiry(self::ALICE, self::BOB));
		$this->assertNotSame(0, $this->expiries->getExpiry(self::ALICE, self::CAROL));

		$this->expiries->deleteByActor(self::ALICE);
		$this->assertSame(0, $this->expiries->getExpiry(self::ALICE, self::CAROL));
	}

	/** @param array<string, int> $read */
	private function sorted(array $read): array {
		ksort($read);

		return $read;
	}
}
