<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use PHPUnit\Framework\TestCase;

/**
 * How the candidates of For you are narrowed to photos or videos.
 *
 * A WHERE clause needs a database, which the unit suite does not have (see
 * `StreamReadPredicatesTest`), so the predicates are read out of the source
 * here and the rows they select are exercised by
 * `Integration\Db\StreamInterestsTest`. What matters is that each kind uses
 * the same filter as the Photos and Videos timelines, on the indexed
 * `media_kind` column, rather than a notion of its own.
 */
class StreamInterestsMediaTest extends TestCase {
	private function body(): string {
		$file = (string)file_get_contents(__DIR__ . '/../../lib/Db/StreamInterests.php');
		$body = preg_split('/function interestCandidates\(/', $file, 2)[1] ?? '';

		return preg_split('/\n\t\}\n/', $body, 2)[0] ?? '';
	}

	/** For you is built from tags, and an unlisted post is kept off the tag timelines. */
	public function testTheCandidatesAreAddressedToThePublicCollection(): void {
		$this->assertStringContainsString(
			"limitToViewer('sd', 'f', true, false, SocialCoreQueryBuilder::HIDDEN_TIMELINE, 'to')",
			$this->body(),
			'the feed has to ask for the `to` recipient row, or unlisted posts are suggested'
		);
	}

	public function testEachKindIsTheFilterItsTimelineUses(): void {
		$body = $this->body();

		$this->assertStringContainsString("string \$media = ''", $body, 'the whole feed is the default');
		$this->assertMatchesRegularExpression("/'photos'\) \{\s+\\\$qb->limitToMediaType\('image'\);/", $body);
		$this->assertMatchesRegularExpression("/'videos'\) \{\s+\\\$qb->limitToVideo\(\);/", $body);
		$this->assertMatchesRegularExpression("/'media'\) \{\s+\\\$qb->limitToMedia\(\);/", $body);
	}
}
