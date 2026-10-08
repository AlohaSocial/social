<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use PHPUnit\Framework\TestCase;

/**
 * Which rows each read is allowed to return.
 *
 * Every one of these is a WHERE clause, and a WHERE clause needs a database to
 * exercise: the unit suite has none, and it cannot even build the query —
 * `IExpressionBuilder` is declared in terms of a Doctrine class the standalone
 * suite does not carry (see tests/Helper/doctrine-parameter-types.php), so a
 * mocked query builder cannot hand one back. The predicates are therefore read
 * out of the source, the way `StreamPostFieldsWriteTest` and `StreamNidTest`
 * read theirs, and the rows they select are exercised by the integration suite.
 */
class StreamReadPredicatesTest extends TestCase {
	/**
	 * Where a stream read may be written. `StreamRequest` is one class across
	 * several files — each concern is a trait — so a method is looked for in
	 * all of them rather than in whichever one it happened to be in when the
	 * test was written.
	 */
	private const SOURCE = [
		__DIR__ . '/../../lib/Db/StreamRequest.php',
		__DIR__ . '/../../lib/Db/StreamTimelines.php',
		__DIR__ . '/../../lib/Db/StreamThreads.php',
		__DIR__ . '/../../lib/Db/StreamStatistics.php',
	];
	private const TAGS_SOURCE = [__DIR__ . '/../../lib/Db/StreamTagsRequest.php'];

	/** The body of one method, from its signature to the next one at the same indentation. */
	private function methodBody(array $sources, string $name): string {
		foreach ($sources as $source) {
			$file = (string)file_get_contents($source);
			if (preg_match('/function ' . preg_quote($name, '/') . '\(/', $file) !== 1) {
				continue;
			}

			$body = preg_split('/function ' . preg_quote($name, '/') . '\(/', $file, 2)[1] ?? '';

			return preg_split('/\n\t\}\n/', $body, 2)[0] ?? '';
		}

		$this->fail($name . '() is gone; this test is about what it selects');
	}

	/**
	 * A direct message's recipient row is keyed on the viewer's own id, not on
	 * the public collection, so a descendants query that allows only public
	 * rows can never match one: `GET /statuses/{id}/context` on a direct thread
	 * answered with the ancestors and no replies at all, and every reply sent
	 * to the viewer vanished from the conversation in every client. The single
	 * status and the ancestor walk have always allowed them.
	 */
	public function testTheRepliesOfAThreadAreReadTheWayASingleStatusIs(): void {
		$this->assertStringContainsString(
			"limitToViewer('sd', 'f', true, true)",
			$this->methodBody(self::SOURCE, 'getRepliesTo'),
			'the descendants of a direct thread cannot match a public-only predicate'
		);
	}

	/**
	 * An accepted follower reads a followers-only post in their home timeline;
	 * the author's profile used to deny it exists, so
	 * `/api/v1/accounts/{id}/statuses` looked emptier than home and a pinned
	 * followers-only post was unreachable.
	 */
	public function testAProfileIsReadAsTheViewerRatherThanAsTheAnonymousInternet(): void {
		$body = $this->methodBody(self::SOURCE, 'accountTimelineNids');

		$this->assertStringContainsString(
			"limitToViewer('sd', 'f', true, true, SocialCoreQueryBuilder::HIDDEN_DIRECT)",
			$body,
			'a profile read by somebody else forces the public collection'
		);
		$this->assertStringNotContainsString(
			'ACore::CONTEXT_PUBLIC',
			$body,
			'the public collection is still forced somewhere in this page'
		);
		$this->assertStringContainsString(
			'$this->getStreamNidsSelectSql($this->viewer !== null)',
			$body,
			'a post matches several recipient rows for a reader, so the page has to be DISTINCT'
		);
	}

	/**
	 * An unlisted post is addressed to the author's followers with the public
	 * collection in `cc`: anybody may read it, and its author chose to keep it
	 * off the tag timelines. Both tag reads matched the public recipient row
	 * whatever its subtype, so an unlisted post surfaced on the hashtag page
	 * and in the home timeline of everybody following one of its tags.
	 */
	public function testTheTagTimelinesListOnlyPostsAddressedToThePublicCollection(): void {
		$this->assertStringContainsString(
			"limitToViewer('sd', 'f', true, false, SocialCoreQueryBuilder::HIDDEN_TIMELINE, 'to')",
			$this->methodBody(self::SOURCE, 'hashtagTimelineNids'),
			'the hashtag timeline has to ask for the `to` recipient row, or unlisted posts are listed'
		);
		$this->assertStringContainsString(
			"limitToDest(ACore::CONTEXT_PUBLIC, 'recipient', 'to', 'ft_sd')",
			$this->methodBody(self::SOURCE, 'followedTagNids'),
			'the followed-tags half of home has to ask for the `to` recipient row too'
		);
	}

	/** The profile and the home timeline are not tag timelines: unlisted posts stay in them. */
	public function testTheOtherTimelinesStillReadEveryPublicRecipientRow(): void {
		foreach (['accountTimelineNids', 'homeTimelineNids'] as $method) {
			$this->assertStringNotContainsString(
				"'to')", $this->methodBody(self::SOURCE, $method), $method . '() must not narrow to `to`'
			);
		}
	}

	/**
	 * The profile highlights are drawn for whoever opens the profile. The
	 * weekly chart reads public posts through the recipient join; the top
	 * hashtags counted every post of the author, so a tag used only in
	 * followers-only posts or direct messages was named on the public profile
	 * with how often it was used.
	 */
	public function testTheProfilesTopHashtagsAreCountedOverPublicPostsOnly(): void {
		$body = $this->methodBody(self::SOURCE, 'topHashtagsByAuthor');

		$this->assertStringContainsString(
			"\$qb->selectDestFollowing('sd', '');
		\$qb->innerJoinStreamDest('recipient', 'id_prim', 'sd', 's');
		\$qb->limitToDest(ACore::CONTEXT_PUBLIC, 'recipient', '', 'sd');",
			$body,
			'the hashtag count has to join the public recipient row the way publishedTimesByAuthor() does'
		);
		$this->assertStringContainsString(
			"\$qb->limitToDest(ACore::CONTEXT_PUBLIC, 'recipient', '', 'sd');",
			$this->methodBody(self::SOURCE, 'publishedTimesByAuthor'),
			'the chart the count is drawn beside reads public posts only'
		);
	}

	/**
	 * A tag used only inside a private team thread was written into the trend
	 * counters and surfaced in `/api/v1/trends/tags`, `tagHistory()` and search
	 * with its usage count. `HashtagsRequest::related()` restricts to public
	 * posts for exactly this reason.
	 */
	public function testTrendsAreCountedOverPublicPostsOnly(): void {
		$body = $this->methodBody(self::SOURCE, 'countHashtagsInWindows');

		$this->assertStringContainsString(
			"->andWhere(\$expr->eq(\n\t\t\t\t's.visibility', \$qb->createNamedParameter(Stream::TYPE_PUBLIC)\n\t\t\t))",
			$body,
			'a followers-only post or a direct message still counts towards the trends'
		);
		$this->assertStringContainsString(
			'$qb->limitToStatusTypes()',
			$body,
			'anything with a tag row counts, not only a status'
		);
	}

	/**
	 * The five trend windows are the same rows at different cut-offs: one
	 * grouped read of the widest, with a conditional sum per window, rather
	 * than one scan of the tag table each.
	 */
	public function testTheTrendWindowsAreCountedInOnePass(): void {
		$body = $this->methodBody(self::SOURCE, 'countHashtagsInWindows');

		$this->assertSame(1, substr_count($body, 'executeQuery()'));
		$this->assertStringContainsString("'SUM(CASE WHEN '", $body);
		$this->assertStringContainsString('min($windows)', $body, 'the scan is bounded by the widest window');
	}

	/**
	 * A poll is a `Question`, which extends `Note` and carries hashtags,
	 * attachments and a media kind like any other post. Comparing the type name
	 * stored a poll with all of them at their defaults, so `#election who
	 * wins?` reached neither the tag timeline nor a followed-tag home page.
	 */
	public function testEveryKindOfStatusIsStoredWithItsNoteFields(): void {
		foreach ([...self::SOURCE, ...self::TAGS_SOURCE] as $source) {
			$this->assertDoesNotMatchRegularExpression(
				'/getType\(\)\s*[!=]==\s*Note::TYPE/',
				(string)file_get_contents($source),
				'a poll is judged by its type name, so its hashtags and attachments are dropped'
			);
		}

		$this->assertStringContainsString(
			'if ($stream instanceof Note) {',
			$this->methodBody(self::SOURCE, 'save'),
			'save() does not write the note fields of a poll'
		);
	}

	/**
	 * `social_stream_tag` is what puts a post in a hashtag timeline, and an
	 * edit rewrites the `hashtags` column without it: a tag added by an edit
	 * rendered as dead text and reached nobody, and a tag removed left the post
	 * in that timeline for ever. Remote edits come through the same method.
	 */
	public function testAnEditRewritesTheTagRowsInTheSameStatementGroup(): void {
		$body = $this->methodBody(self::SOURCE, 'update');

		$this->assertStringContainsString('$this->dbConnection->beginTransaction()', $body);
		$this->assertStringContainsString('$this->streamTagsRequest->replaceStreamTags($stream)', $body);
		$this->assertStringContainsString('$this->dbConnection->rollBack()', $body);
	}
}
