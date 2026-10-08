<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\SocialQueryBuilder;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Tests\Migration\FakeConnection;
use OCP\DB\IResult;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * `social_stream_media`: how a local post is tied to the documents it
 * carries, and how a finished transcode finds those posts through it rather
 * than by searching the `attachments` text.
 */
#[AllowMockObjectsWithoutExpectations]
class StreamMediaLinksTest extends TestCase {
	private const POST = 'https://cloud.example/@alice/1';

	/** @var array<int, array{string, array<string, mixed>}> table and values of every insert */
	public array $inserted = [];
	/** @var string[] tables deleted from */
	private array $deletedFrom = [];
	/** @var string[] */
	private array $from = [];
	/** @var string[] */
	private array $wheres = [];

	private function streamRequest(): StreamRequest {
		$request = $this->getMockBuilder(StreamRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['getQueryBuilder'])
			->getMock();
		$request->method('getQueryBuilder')->willReturnCallback(fn (): SocialQueryBuilder => $this->queryBuilder());

		$test = $this;
		$connection = new class($test) extends FakeConnection {
			public function __construct(
				private StreamMediaLinksTest $test,
			) {
				parent::__construct();
			}

			public function insertIgnoreConflict(string $table, array $values): int {
				$this->test->inserted[] = [$table, $values];

				return 1;
			}
		};
		(new \ReflectionProperty(StreamRequest::class, 'dbConnection'))->setValue($request, $connection);

		return $request;
	}

	private function queryBuilder(): SocialQueryBuilder {
		$qb = $this->createMock(SocialQueryBuilder::class);
		$qb->method('prim')->willReturnCallback(static fn (string $id): string => ($id === '') ? '' : md5($id));
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value) => (string)$value);
		$qb->method('expr')->willReturn(new FakeExpressions());
		foreach (['select', 'innerJoin', 'orderBy'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('from')->willReturnCallback(function (string $table) use ($qb): SocialQueryBuilder {
			$this->from[] = $table;

			return $qb;
		});
		$qb->method('delete')->willReturnCallback(function (string $table) use ($qb): SocialQueryBuilder {
			$this->deletedFrom[] = $table;

			return $qb;
		});
		foreach (['where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnCallback(function (string $predicate) use ($qb): SocialQueryBuilder {
				$this->wheres[] = $predicate;

				return $qb;
			});
		}
		$none = $this->createStub(IResult::class);
		$none->method('fetch')->willReturn(false);
		$qb->method('executeQuery')->willReturn($none);

		return $qb;
	}

	private function localPost(string ...$nids): Note {
		$note = new Note();
		$note->setId(self::POST);
		$note->setLocal(true);
		$note->setAttachments(array_map(
			static fn (string $nid): MediaAttachment => (new MediaAttachment())->setId($nid), $nids
		));

		return $note;
	}

	private function link(Note $note, bool $replace): void {
		(new \ReflectionMethod(StreamRequest::class, 'linkLocalMedia'))
			->invoke($this->streamRequest(), $note, $replace);
	}

	public function testTheDocumentsOfAStoredCopyAreItsNids(): void {
		$this->assertSame([3, 7], StreamRequest::documentNidsOf(
			(string)json_encode([['id' => '7'], ['id' => '3'], ['id' => '7'], ['id' => 'abc'], ['type' => 'image'], 'x'])
		));
		$this->assertSame([], StreamRequest::documentNidsOf('not json'));
	}

	public function testALocalPostIsLinkedToEachDocumentItCarriesOnce(): void {
		$this->link($this->localPost('7', '3', '7'), false);

		$this->assertSame([
			['social_stream_media', ['stream_id_prim' => md5(self::POST), 'doc_nid' => 3]],
			['social_stream_media', ['stream_id_prim' => md5(self::POST), 'doc_nid' => 7]],
		], $this->inserted);
		$this->assertSame([], $this->deletedFrom);
	}

	public function testAnEditReplacesTheLinksItMayHaveMadeWrong(): void {
		$this->link($this->localPost('9'), true);

		$this->assertSame(['social_stream_media'], $this->deletedFrom);
		$this->assertSame([['social_stream_media', ['stream_id_prim' => md5(self::POST), 'doc_nid' => 9]]], $this->inserted);
	}

	public function testARemotePostIsNotLinked(): void {
		$note = $this->localPost('7');
		$note->setLocal(false);

		$this->link($note, true);

		$this->assertSame([], $this->inserted);
		$this->assertSame([], $this->deletedFrom);
	}

	public function testATranscodeFindsItsPostsThroughTheLinks(): void {
		$document = new Document();
		$document->setNid(42);

		$this->streamRequest()->updateLocalAttachmentCopies($document);

		$this->assertSame(['social_stream_media'], $this->from);
		$this->assertContains('sm.doc_nid = 42', $this->wheres);
		foreach ($this->wheres as $where) {
			$this->assertStringNotContainsString('LIKE', $where);
		}
	}
}
