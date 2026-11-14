<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use PHPUnit\Framework\TestCase;

/**
 * No read of many rows from a hot table without a bound.
 *
 * `social_stream`, `social_stream_dest`, `social_follow`, `social_cache_actor`,
 * `social_action` and `social_req_queue` are the tables that grow with the
 * fediverse rather than with this instance, and a read of "all of them"
 * that is harmless on a test instance is the memory limit on a large one —
 * `occ social:check:install` read every post into PHP until it was made to
 * walk. Every method in `lib/Db` that selects from one of them and reads more
 * than one row has to say how many: a `LIMIT` (directly or through one of the
 * paging helpers below), or a filter on a list of keys it was handed.
 * Anything else is listed in UNBOUNDED_BY_DESIGN with the reason the result
 * cannot grow out of hand — so that the next one is a decision, not an
 * accident.
 *
 * Read from the source, as IndexCoverageTest reads the migrations: a method
 * is a body of code, and whether it bounds its read is visible in it.
 */
class BoundedQueriesTest extends TestCase {
	/** Selects from a hot table, through its builder or by name. */
	private const HOT_SELECT = '/get(?:Stream|StreamNids|StreamDest|Follows|CacheActors|Actions|RequestQueue)SelectSql\('
		. '|->(?:select\w*|from|innerJoin|leftJoin)\([^;]*TABLE_(?:STREAM|STREAM_DEST|FOLLOWS|CACHE_ACTORS|ACTIONS|REQUEST_QUEUE)\b(?!_)/s';

	/** Reads every row the statement returns. */
	private const MANY_ROWS = '/get(?:Streams|Nids|Follows|CacheActors|Actions|StreamDests)FromRequest\('
		. '|fetchAll\w*\(|fetchFirstColumn\(|while\s*\(\s*\$\w+\s*=\s*\$\w+->fetch\(\)/';

	/**
	 * What bounds a read: a limit, a filter on a list of keys, or one of the
	 * app's paging helpers — each of which testTheBoundingHelpersSetALimit()
	 * holds to its word.
	 */
	private const BOUNDED = '/setMaxResults\(|->in\(|PARAM_(?:STR|INT)_ARRAY|limitInArray\(|exprLimitInArray\('
		. '|limitResults\(|limitPaginate\(|->paginate\(|paginateOnRecipient\(|homeTimelineFilters\(|boundNidPage\(/';

	/** The paging helpers BOUNDED trusts, and the file each lives in. */
	private const PAGING_HELPERS = [
		'limitResults' => 'SocialLimitsQueryBuilder.php',
		'limitPaginate' => 'SocialLimitsQueryBuilder.php',
		'paginate' => 'SocialLimitsQueryBuilder.php',
		'paginateOnRecipient' => 'StreamTimelines.php',
		'homeTimelineFilters' => 'StreamTimelines.php',
		'boundNidPage' => 'StreamTimelines.php',
	];

	/** Reads of many rows with no limit, each with what keeps the result small. */
	private const UNBOUNDED_BY_DESIGN = [
		'CacheActorsRequest::getSharedInboxes' => 'one row per remote instance (DISTINCT shared_inbox), for the list `occ social:fediverse` prints',
		'StreamDestRequest::recipientPrims' => 'the original participants of one deleted direct message, all of whom need its removal event',
		'FollowsRequest::getByFollowId' => 'every accepted follower of one collection; nothing in the app calls it today',
		'FollowsRequest::getFollowerInboxes' => 'the audience of a delivery: one row per distinct inbox of the account\'s followers, and every one of them is delivered to',
		'InstanceStatsRequest::remoteHosts' => 'one row per remote instance (GROUP BY host), for the instance statistics and /api/v1/instance/peers',
		'RequestQueueRequest::countByStatus' => 'one row per delivery status (GROUP BY status): four at most',
		'RequestQueueRequest::getByObject' => 'the deliveries of one activity: one per inbox it went to',
		'RequestQueueRequest::getFromToken' => 'the deliveries of one activity: one per inbox it went to',
		'RequestQueueRequest::getHeldByObject' => 'the held deliveries of one activity: one per inbox it went to',
		'StreamMedia::updateLocalAttachmentCopies' => 'the local posts carrying one uploaded file',
		'StreamTrendBuckets::trendHoursStoredSince' => 'the public statuses stored since the previous trends pass, twelve minutes before: the `creation` window is the bound',
	];

	/**
	 * Every method body in a file, by name, comments left out.
	 *
	 * @return array<string, string>
	 */
	private static function methodBodies(string $file): array {
		$tokens = token_get_all((string)file_get_contents($file));
		$count = count($tokens);
		$bodies = [];

		for ($i = 0; $i < $count; $i++) {
			if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
				continue;
			}
			$j = $i + 1;
			while ($j < $count && !(is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) && $tokens[$j] !== '(') {
				$j++;
			}
			if (!is_array($tokens[$j])) {
				// a closure
				continue;
			}
			$name = $tokens[$j][1];
			while ($j < $count && $tokens[$j] !== '{' && $tokens[$j] !== ';') {
				$j++;
			}
			if ($j >= $count || $tokens[$j] === ';') {
				// abstract or an interface's
				continue;
			}

			$depth = 0;
			$body = '';
			for ($k = $j; $k < $count; $k++) {
				$token = $tokens[$k];
				if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
					$depth++;
				} elseif ($token === '}') {
					$depth--;
				}
				if (!(is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))) {
					$body .= is_array($token) ? $token[1] : $token;
				}
				if ($depth === 0) {
					break;
				}
			}
			$bodies[$name] = $body;
			$i = $k;
		}

		return $bodies;
	}

	/**
	 * The methods of lib/Db that read many rows of a hot table, each with
	 * whether it bounds the read.
	 *
	 * @return array<string, bool> `Class::method` => bounded
	 */
	private static function hotReads(): array {
		$reads = [];
		foreach (glob(__DIR__ . '/../../lib/Db/*.php') ?: [] as $file) {
			foreach (self::methodBodies($file) as $method => $body) {
				if (preg_match(self::HOT_SELECT, $body) === 1 && preg_match(self::MANY_ROWS, $body) === 1) {
					$reads[basename($file, '.php') . '::' . $method] = preg_match(self::BOUNDED, $body) === 1;
				}
			}
		}
		ksort($reads);

		return $reads;
	}

	public function testEveryReadOfManyRowsFromAHotTableIsBounded(): void {
		$unbounded = array_keys(array_filter(self::hotReads(), static fn (bool $bounded): bool => !$bounded));

		$this->assertSame(
			[],
			array_values(array_diff($unbounded, array_keys(self::UNBOUNDED_BY_DESIGN))),
			'these read every row a hot table hands back: give them a LIMIT, a filter on a list of keys, '
			. 'or an entry in UNBOUNDED_BY_DESIGN saying why the result stays small'
		);
	}

	/** An entry for a method that has since been bounded, or removed, is taken out. */
	public function testEveryEntryOfTheListIsStillAnUnboundedRead(): void {
		$reads = self::hotReads();

		foreach (array_keys(self::UNBOUNDED_BY_DESIGN) as $method) {
			$this->assertArrayHasKey($method, $reads, $method . ' no longer reads a hot table; take it out of UNBOUNDED_BY_DESIGN');
			$this->assertFalse($reads[$method], $method . ' is bounded now; take it out of UNBOUNDED_BY_DESIGN');
		}
	}

	/** A helper trusted to bound a read has to set a limit itself. */
	public function testTheBoundingHelpersSetALimit(): void {
		foreach (self::PAGING_HELPERS as $helper => $file) {
			$bodies = self::methodBodies(__DIR__ . '/../../lib/Db/' . $file);
			$this->assertArrayHasKey($helper, $bodies, $helper . ' is not in ' . $file . ' any more');
			$this->assertMatchesRegularExpression(
				'/setMaxResults\(|->paginate\(/', $bodies[$helper], $helper . ' no longer sets a limit'
			);
		}
	}

	/** The reader itself: if it stops finding reads, every assertion above passes for nothing. */
	public function testTheScanFindsTheReadsItIsAbout(): void {
		$reads = self::hotReads();

		$this->assertGreaterThan(30, count($reads));
		$this->assertTrue($reads['StreamTimelines::directTimelineNids'] ?? false, 'the direct-message page is found, and bounded through its paging helper');
		$this->assertTrue($reads['CacheActorsRequest::searchAccounts'] ?? false, 'the account search is found, and bounded');
	}
}
