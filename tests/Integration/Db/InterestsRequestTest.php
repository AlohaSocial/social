<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use DateTime;
use OCA\Social\Db\InterestsRequest;
use OCA\Social\Model\Interest;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * What My interests has learnt about a reader, and the posts they hid from it.
 */
class InterestsRequestTest extends TestCase {
	private const ALICE = 'https://itest.example/users/interests-alice';
	private const BOB = 'https://itest.example/users/interests-bob';

	private InterestsRequest $interests;

	protected function setUp(): void {
		parent::setUp();
		$this->interests = Server::get(InterestsRequest::class);
		$this->interests->deleteRelatedId(self::ALICE);
		$this->interests->deleteRelatedId(self::BOB);
	}

	protected function tearDown(): void {
		$this->interests->deleteRelatedId(self::ALICE);
		$this->interests->deleteRelatedId(self::BOB);
		parent::tearDown();
	}

	/** @return array<string, Interest> */
	private function byTag(string $actor): array {
		$byTag = [];
		foreach ($this->interests->getByActor($actor) as $interest) {
			$byTag[$interest->getHashtag()] = $interest;
		}

		return $byTag;
	}

	public function testAnInterestIsKeptAndSavingItAgainUpdatesIt(): void {
		$this->interests->save(self::ALICE, new Interest('jazz', 1.5, time()));
		$this->interests->save(self::ALICE, new Interest('jazz', 3.25, time(), true, 1));

		$jazz = $this->byTag(self::ALICE)['jazz'] ?? null;
		$this->assertNotNull($jazz);
		$this->assertEqualsWithDelta(3.25, $jazz->getScore(), 0.0001);
		$this->assertTrue($jazz->isManual());
		$this->assertTrue($jazz->isPinned());
		$this->assertCount(1, $this->interests->getByActor(self::ALICE));
	}

	public function testInterestsAreEachReadersOwnAndCanBeDropped(): void {
		$this->interests->save(self::ALICE, new Interest('jazz', 1.0, time()));
		$this->interests->save(self::ALICE, new Interest('climbing', 1.0, time()));

		$this->assertSame([], $this->interests->getByActor(self::BOB));

		$this->interests->deleteTags(self::ALICE, ['jazz']);
		$this->assertSame(['climbing'], array_keys($this->byTag(self::ALICE)));
		$this->interests->deleteTags(self::ALICE, []);
		$this->assertCount(1, $this->interests->getByActor(self::ALICE));
	}

	public function testAHiddenPostStaysHiddenUntilItIsShownAgain(): void {
		$this->assertFalse($this->interests->isHidden(self::ALICE, '1234567890'));

		$this->interests->hide(self::ALICE, '1234567890');
		$this->interests->hide(self::ALICE, '1234567890');

		$this->assertTrue($this->interests->isHidden(self::ALICE, '1234567890'));
		$this->assertFalse($this->interests->isHidden(self::BOB, '1234567890'));
		$this->assertContains('1234567890', array_map('strval', $this->interests->getHiddenSince(self::ALICE, new DateTime('-1 hour'))));

		$this->interests->unhide(self::ALICE, '1234567890');
		$this->assertFalse($this->interests->isHidden(self::ALICE, '1234567890'));
	}

	public function testOldHidesAreSweptAndRecentOnesKept(): void {
		$this->interests->hide(self::ALICE, '1234567891');

		$this->interests->purgeHidesBefore(new DateTime('-1 hour'));
		$this->assertTrue($this->interests->isHidden(self::ALICE, '1234567891'), 'written just now');

		$this->interests->purgeHidesBefore(new DateTime('+1 hour'));
		$this->assertFalse($this->interests->isHidden(self::ALICE, '1234567891'));
	}
}
