<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Tools\Nid;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * `nid` is the primary key of social_stream and the status id Mastodon clients
 * are given. It is drawn, not allocated, so two posts in the same second can
 * draw the same number.
 *
 * What save() does when they do needs a database, which the unit suite has
 * none of: `Integration/Db/StreamSaveAtomicityTest` makes the collision happen.
 * Here the arithmetic properties are checked directly, and the draw is read
 * out of the source the way StreamSensitiveColumnTest does.
 */
class StreamNidTest extends TestCase {
	private const SOURCE = __DIR__ . '/../../lib/Db/StreamRequest.php';

	private function constant(string $name): int {
		return (int)(new ReflectionClass(StreamRequest::class))->getConstant($name);
	}

	public function testTheIdSpaceIsWideEnoughToMakeACollisionRare(): void {
		// birthday bound: an even chance of a collision arrives at about
		// sqrt(2 * limit * ln 2) posts sharing one second. At 1e6 that is ~1,200,
		// which a busy instance can reach.
		$limit = $this->constant('NID_LIMIT');
		$evenOdds = sqrt(2 * $limit * M_LN2);

		$this->assertGreaterThanOrEqual(
			1000000000,
			$limit,
			'the random half of a nid is too narrow to rely on'
		);
		$this->assertGreaterThan(30000, $evenOdds);
	}

	public function testNidsStayOrderedByPublicationTimeAcrossTheWidening(): void {
		// cursor pagination orders on nid, so ids issued under the old 1e6 width
		// must still sort before ids issued under the new one.
		$old = static fn (int $t, int $r): string => Nid::fromPublishedTime($t, $r, 1000000);
		$new = fn (int $t, int $r): string => Nid::fromPublishedTime($t, $r, $this->constant('NID_LIMIT'));

		$earlier = $old(1_700_000_000, 999_999);
		$later = $new(1_700_000_001, 1);

		$this->assertSame(-1, Nid::compare($earlier, $later));
		$this->assertSame(-1, Nid::compare($new(1_700_000_001, 999_999_999), $new(1_700_000_002, 1)));
	}

	public function testTheTopOfTheIdSpaceStillFitsABigint(): void {
		// published_time * NID_LIMIT must not overflow the BIGINT column, with
		// room left for dates well beyond now.
		$highest = Nid::fromPublishedTime(2_000_000_000, $this->constant('NID_LIMIT') - 1, $this->constant('NID_LIMIT'));

		$this->assertSame(-1, Nid::compare($highest, '9223372036854775807'));
		$this->assertSame(1, Nid::compare($highest, '2147483647'));
	}

	public function testTheDrawIsNotPredictable(): void {
		$source = (string)file_get_contents(self::SOURCE);

		$this->assertStringContainsString(
			'random_int(1, self::NID_LIMIT - 1)',
			$source,
			'the nid is drawn with rand(), which is seeded per process and predictable'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/[^_a-zA-Z]rand\(/',
			$source,
			'rand() is still used somewhere in this file'
		);
	}

	/**
	 * Every timeline is ordered by nid, and a remote post states its own
	 * `published`: one dated in the future sat above everything stored until
	 * that date, and one past 2262 overflowed the BIGINT.
	 */
	public function testAPostDatedInTheFutureIsNumberedAsOfNow(): void {
		$now = 1_800_000_000;

		$this->assertSame($now, StreamRequest::nidTime(1_900_000_000, $now));
		$this->assertSame($now, StreamRequest::nidTime(PHP_INT_MAX, $now));
		$this->assertSame(1_700_000_000, StreamRequest::nidTime(1_700_000_000, $now), 'the past keeps its place');
		$this->assertSame(0, StreamRequest::nidTime(-86400, $now), 'and before 1970 is not an error');
		$this->assertSame(-1, Nid::compare(
			Nid::fromPublishedTime(StreamRequest::nidTime(PHP_INT_MAX), $this->constant('NID_LIMIT') - 1, $this->constant('NID_LIMIT')),
			'9223372036854775807'
		));
	}
}
