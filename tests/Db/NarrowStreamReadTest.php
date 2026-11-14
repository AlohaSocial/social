<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use LogicException;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\SocialQueryBuilder;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * How a post is read: with no `DISTINCT` over its TEXT columns, and — where
 * the posts go to a client and nowhere else — without its `source` and its
 * author's `public_key`.
 *
 * The SQL needs a database to run, which this suite has none of, so the
 * shape is read off the source the way StreamReadPredicatesTest reads it;
 * what can run without one — the deduplication, the marker, the guard on the
 * write — runs.
 */
#[AllowMockObjectsWithoutExpectations]
class NarrowStreamReadTest extends TestCase {
	private const DB = __DIR__ . '/../../lib/Db/';

	private function methodBody(string $file, string $name): string {
		$source = (string)file_get_contents(self::DB . $file);
		$this->assertMatchesRegularExpression('/function ' . preg_quote($name, '/') . '\(/', $source, $name . '() is gone');
		$body = preg_split('/function ' . preg_quote($name, '/') . '\(/', $source, 2)[1] ?? '';

		return preg_split('/\n\t\}\n/', $body, 2)[0] ?? '';
	}

	public function testThePostSelectIsNotDistinct(): void {
		$body = $this->methodBody('StreamRequestBuilder.php', 'getStreamSelectSql');

		$this->assertStringNotContainsString('selectDistinct', $body, 'a DISTINCT over TEXT columns builds a temporary table');
		$this->assertStringContainsString("selectStreamSource('s', 'source')", $body);
		$this->assertStringContainsString('$format === ACore::FORMAT_LOCAL', $body, 'an ActivityPub export carries the source');
	}

	/**
	 * A page over the viewer's recipient rows meets a post once per matching
	 * row; without a DISTINCT it is chosen by nid first.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function pagesOverTheRecipientRows(): array {
		return [
			'quotes' => ['StreamRequest.php', 'getQuotesOf'],
			'a thread level' => ['StreamThreads.php', 'getRepliesTo'],
			'replies as the viewer' => ['StreamThreads.php', 'getRepliesByParentId'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('pagesOverTheRecipientRows')]
	public function testAPageOverTheRecipientRowsIsChosenByNidFirst(string $file, string $method): void {
		$body = $this->methodBody($file, $method);

		$this->assertStringContainsString('$this->getStreamNidsSelectSql()', $body);
		$this->assertStringContainsString('$this->streamsInPageOrder(', $body);
		$this->assertStringContainsString('->joinCacheActors(', $body, 'a post without a cached author takes no place on the page');
	}

	/** @return array<string, array{string, string}> */
	public static function narrowReads(): array {
		return [
			'home and tag pages' => ['StreamTimelines.php', 'streamsByNids'],
			'notifications' => ['StreamTimelines.php', 'getTimelineNotifications'],
			'favourites and bookmarks' => ['StreamTimelines.php', 'getTimelineMarked'],
			'public pages' => ['StreamTimelines.php', 'publicStreams'],
			'lists' => ['ListsRequest.php', 'streamsByNids'],
			'trending statuses' => ['TrendsRequest.php', 'statusesByNids'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('narrowReads')]
	public function testTheTimelinesReadTheirRowsNarrow(string $file, string $method): void {
		$this->assertMatchesRegularExpression(
			'/getStreamSelectSql\(.*, false, true\);/',
			$this->methodBody($file, $method)
		);
	}

	public function testTheAuthorsKeyIsLeftOutOfANarrowRead(): void {
		$body = $this->methodBody('SocialCrossQueryBuilder.php', 'linkToCacheActors');

		$this->assertMatchesRegularExpression(
			"/if \\(!\\\$this->narrowStreamRead\\) \\{\\s*\\\$this->selectAlias\\(\\\$pf \\. '\\.public_key', 'ca_public_key'\\);/",
			$body
		);
		$this->assertStringContainsString("'ca_source'", $body, 'the account entity is read out of the source');
	}

	public function testTheDigestIsAColumnBothWritePathsFill(): void {
		$tables = (new ReflectionClass(CoreRequestBuilder::class))->getStaticPropertyValue('tables');
		$this->assertContains('source_digest', $tables[CoreRequestBuilder::TABLE_STREAM]);

		$this->assertStringContainsString(
			"'source_digest' => [Stream::sourceDigestOf(\$stream->getSource())",
			$this->methodBody('StreamRequest.php', 'setPostFields')
		);
	}

	public function testANarrowReadMarksThePostsWhoseSourceItLeftOut(): void {
		$qb = (new ReflectionClass(SocialQueryBuilder::class))->newInstanceWithoutConstructor();
		$row = ['type' => 'Note', 'source_digest' => '{}'];

		$this->assertFalse($qb->omitsSourceOf($row), 'a full read leaves nothing out');

		$qb->setNarrowStreamRead(true);
		$this->assertTrue($qb->omitsSourceOf($row));
		$this->assertFalse($qb->omitsSourceOf(['type' => 'Note', 'source_digest' => null]), 'not digested yet: read whole');
		$this->assertFalse($qb->omitsSourceOf(['type' => 'Question', 'source_digest' => '{}']), 'a poll is read out of its source');
	}

	public function testAPostReadNarrowIsNeverWrittenBack(): void {
		$request = $this->getMockBuilder(StreamRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['getStreamUpdateSql'])
			->getMock();
		$request->expects($this->never())->method('getStreamUpdateSql');

		$post = new Note();
		$post->setSourceOmitted(true);

		$this->expectException(LogicException::class);
		$request->update($post);
	}

	public function testAPostMetByMoreThanOneRowIsReturnedOnce(): void {
		$rows = [];
		foreach (['3', '2', '3', '1', '2'] as $nid) {
			$rows[] = (new Stream())->setNid($nid);
		}
		$qb = $this->createMock(SocialQueryBuilder::class);
		$qb->method('getRows')->willReturn($rows);

		$request = $this->getMockBuilder(StreamRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['getViewerId'])
			->getMock();
		$request->method('getViewerId')->willReturn('');

		$streams = $request->getStreamsFromRequest($qb);

		$this->assertSame(['3', '2', '1'], array_map(static fn (Stream $s): string => (string)$s->getNid(), $streams));
		$this->assertSame($rows[0], $streams[0], 'the first row of a post is the one kept');
	}
}
