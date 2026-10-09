<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\ActivityPub;

use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Details;
use PHPUnit\Framework\TestCase;

/**
 * A post's counters read back from their columns rather than from `details`,
 * where the JSON keys stopped moving once the columns took over.
 */
class StreamCounterColumnsTest extends TestCase {
	private const ID = 'https://cloud.example/apps/social/@alice/1';

	/** @param array<string, mixed> $row */
	private function read(array $row): Note {
		$note = new Note();
		$note->importFromDatabase(array_merge(['id' => self::ID, 'type' => 'Note'], $row));

		return $note;
	}

	public function testTheColumnsAreWhatTheClientIsShown(): void {
		$note = $this->read([
			'details' => json_encode(['replies' => 1, 'likes' => 2, 'boosts' => 3]),
			'count_replies' => '4',
			'count_likes' => '5',
			'count_boosts' => '6',
			'count_dislikes' => '0',
		]);

		$status = $note->exportAsLocal();

		$this->assertSame(4, $status['replies_count']);
		$this->assertSame(5, $status['favourites_count']);
		$this->assertSame(6, $status['reblogs_count']);
	}

	/** An unlike that took a counter to zero is zero, whatever the JSON says. */
	public function testAColumnAtZeroOverridesAStaleKey(): void {
		$note = $this->read(['details' => json_encode(['likes' => 3]), 'count_likes' => 0]);

		$this->assertSame(0, $note->getDetailInt(Details::LIKES));
		$this->assertSame(0, $note->getDetailsAll()['likes']);
	}

	/**
	 * A post nobody has interacted with reads exactly as it did: the
	 * `details` it hands out gains no keys holding zero.
	 */
	public function testAColumnAtZeroAddsNoKey(): void {
		$note = $this->read([
			'details' => json_encode(['mentions' => []]),
			'count_replies' => 0, 'count_likes' => 0, 'count_boosts' => 0, 'count_dislikes' => 0,
		]);

		$this->assertSame(['mentions' => []], $note->getDetailsAll());
	}

	/** A select that does not carry the columns leaves the JSON as it was. */
	public function testARowWithoutTheColumnsKeepsTheJson(): void {
		$note = $this->read(['details' => json_encode(['likes' => 3, 'dislikes' => 1])]);

		$this->assertSame(3, $note->getDetailInt(Details::LIKES));
		$this->assertSame(1, $note->getDetailInt(Details::DISLIKES));
	}

	/** The origin's halves are not columns and are read from the JSON as before. */
	public function testTheOriginsHalvesStayInTheJson(): void {
		$note = $this->read(['details' => json_encode(['remote_likes' => 7]), 'count_likes' => 9]);

		$this->assertSame(7, $note->getDetailInt(Details::REMOTE_LIKES));
		$this->assertSame(9, $note->getDetailInt(Details::LIKES));
	}

	/** When the post's own network was last asked for its counts comes with the row. */
	public function testWhenTheCountsWereAskedForIsReadWithTheRow(): void {
		$this->assertSame((new \DateTime('2026-10-09 12:00:00'))->getTimestamp(), $this->read(['counts_at' => '2026-10-09 12:00:00'])->getCountsAt());
		$this->assertNull($this->read(['counts_at' => null])->getCountsAt(), 'never asked');
		$this->assertNull($this->read([])->getCountsAt());
	}
}
