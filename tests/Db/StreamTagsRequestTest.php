<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\StreamTagsRequest;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The rows that put a post in a hashtag timeline.
 *
 * `social_stream_tag` is the only thing a tag page, a followed tag and the
 * trend counters read, so a post whose rows are missing is a post that tag
 * cannot reach — and nothing anywhere notices, because the post itself is
 * stored and visible everywhere else.
 *
 * The statements are not run: the unit suite has no database, so the
 * connection is a double that records what it was asked to write.
 */
#[AllowMockObjectsWithoutExpectations]
class StreamTagsRequestTest extends TestCase {
	private const POST = 'https://social.example/@alice/1';

	private IDBConnection|Stub $connection;
	private IQueryBuilder|Stub $queryBuilder;
	private StreamTagsRequest|MockObject $request;

	/** @var array<int, array{string, array<string, string>}> every insertIgnoreConflict */
	private array $inserted = [];
	/** @var string[] the posts whose rows were removed first */
	private array $deleted = [];

	protected function setUp(): void {
		$this->queryBuilder = $this->createStub(IQueryBuilder::class);
		$this->queryBuilder->method('createNamedParameter')->willReturn(':p');

		$this->connection = $this->createStub(IDBConnection::class);
		$this->connection->method('getQueryBuilder')->willReturn($this->queryBuilder);
		$this->connection->method('insertIgnoreConflict')
			->willReturnCallback(function (string $table, array $values): int {
				$this->inserted[] = [$table, $values];

				return 1;
			});

		// the DELETE is the one statement here that needs an expression
		// builder, and `IExpressionBuilder` cannot be loaded without a real
		// Doctrine — see tests/Helper/doctrine-parameter-types.php. What it
		// removes is exercised by the integration suite; that it is *run*, and
		// run before the rows are written again, is what matters here.
		$this->request = $this->getMockBuilder(StreamTagsRequest::class)
			->setConstructorArgs([
				$this->connection,
				new NullLogger(),
				$this->createMock(IURLGenerator::class),
				$this->createMock(ConfigService::class),
				$this->createMock(MiscService::class),
			])
			->onlyMethods(['deleteStreamTags'])
			->getMock();
		$this->request->method('deleteStreamTags')
			->willReturnCallback(function (string $streamId): void {
				$this->deleted[] = $streamId;
			});
	}

	/** @return string[] the hashtags written, in order */
	private function hashtagsWritten(): array {
		return array_map(
			static fn (array $insert): string => (string)$insert[1]['hashtag'], $this->inserted
		);
	}

	private function note(Note $note, array $hashtags): Note {
		$note->setId(self::POST);
		$note->setHashtags($hashtags);

		return $note;
	}

	public function testANotesHashtagsAreWritten(): void {
		$this->request->generateStreamTags($this->note(new Note(), ['nextcloud', 'fediverse']));

		$this->assertSame(['nextcloud', 'fediverse'], $this->hashtagsWritten());
		$this->assertSame(CoreRequestBuilder::TABLE_STREAM_TAGS, $this->inserted[0][0]);
		$this->assertSame(md5(self::POST), $this->inserted[0][1]['stream_id']);
	}

	/**
	 * Stored in the one form every reader compares, so the hashtag timeline
	 * and the followed-tags join can compare the column as it stands — which
	 * the `social_st_ht` index answers — instead of `LOWER(st.hashtag)`, which
	 * no index does. Two spellings of one tag on one post are one row.
	 */
	public function testTheTagsAreStoredNormalised(): void {
		$this->request->generateStreamTags(
			$this->note(new Note(), ['NextCloud', 'nextcloud', '#Fediverse', 'ÄRGER', ' '])
		);

		$this->assertSame(['nextcloud', 'fediverse', 'ärger'], $this->hashtagsWritten());
	}

	/**
	 * A poll is a `Question`, which extends `Note` and carries hashtags like
	 * any other post. Comparing the type name instead left every poll out of
	 * every tag timeline and out of every followed-tag home page, and its
	 * client entity reported `tags: []`.
	 */
	public function testAPollsHashtagsAreWrittenToo(): void {
		$this->request->generateStreamTags($this->note(new Question(), ['election']));

		$this->assertSame(['election'], $this->hashtagsWritten());
	}

	public function testSomethingThatIsNotAPostHasNoTagRows(): void {
		$notification = new SocialAppNotification();
		$notification->setId(self::POST);

		$this->request->generateStreamTags($notification);

		$this->assertSame([], $this->inserted);
	}

	/**
	 * An edit is not an insert. A hashtag removed from the text has a row that
	 * nothing else would ever delete — the post stays in that tag's timeline
	 * for good — and one written into the text has no row at all.
	 */
	public function testAnEditRewritesTheRowsRatherThanAddingToThem(): void {
		$this->request->replaceStreamTags($this->note(new Note(), ['nextcloud']));

		$this->assertSame([self::POST], $this->deleted);
		$this->assertSame(['nextcloud'], $this->hashtagsWritten());
	}

	public function testAnEditThatLeftNoHashtagsLeavesNoRows(): void {
		$this->request->replaceStreamTags($this->note(new Note(), []));

		$this->assertSame([self::POST], $this->deleted);
		$this->assertSame([], $this->inserted);
	}

	public function testRewritingSomethingThatIsNotAPostTouchesNothing(): void {
		$stream = new Stream();
		$stream->setId(self::POST);

		$this->request->replaceStreamTags($stream);

		$this->assertSame([], $this->deleted);
		$this->assertSame([], $this->inserted);
	}
}
