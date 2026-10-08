<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\SocialQueryBuilder;
use OCA\Social\Db\StreamRequest;
use OCP\DB\IResult;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * What `StreamRequest::deleteRelatedTo()` clears when a post goes.
 *
 * A table that keys a row on one post by its `stream_id_prim` holds something
 * about that post alone; left out of the cascade, the row outlives the post.
 * `social_import_post` was such a table: the import skips any source id it
 * remembers, so an imported post that was deleted could never be imported
 * again.
 */
#[AllowMockObjectsWithoutExpectations]
class StreamDeleteRelatedTest extends TestCase {
	/** @var array<string, string> table => the column the delete filtered on */
	private array $deleted = [];

	private function streamRequest(): StreamRequest {
		$streamRequest = $this->getMockBuilder(StreamRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['getQueryBuilder'])
			->getMock();
		$streamRequest->method('getQueryBuilder')->willReturnCallback(fn (): SocialQueryBuilder => $this->queryBuilder());

		return $streamRequest;
	}

	private function queryBuilder(): SocialQueryBuilder {
		$qb = $this->createMock(SocialQueryBuilder::class);
		$table = '';
		foreach (['select', 'from'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('delete')->willReturnCallback(function (string $name) use ($qb, &$table): SocialQueryBuilder {
			$table = $name;

			return $qb;
		});
		$qb->method('where')->willReturnCallback(function (string $predicate) use ($qb, &$table): SocialQueryBuilder {
			if ($table !== '') {
				$this->deleted[$table] = explode(' ', $predicate)[0];
			}

			return $qb;
		});
		$qb->method('expr')->willReturn(new FakeExpressions());
		$qb->method('createNamedParameter')->willReturn(':prims');
		$none = $this->createStub(IResult::class);
		$none->method('fetchAll')->willReturn([]);
		$qb->method('executeQuery')->willReturn($none);

		return $qb;
	}

	/** @return array<string, string> every table of the schema with a `stream_id_prim` column */
	private function tablesKeyedOnAPost(): array {
		/** @var array<string, array{columns: array<string, mixed>}> $schema */
		$schema = json_decode((string)file_get_contents(__DIR__ . '/../Migration/schema.json'), true);
		$tables = [];
		foreach ($schema as $table => $definition) {
			if (array_key_exists('stream_id_prim', $definition['columns'])) {
				$tables[$table] = 'stream_id_prim';
			}
		}

		return $tables;
	}

	public function testEveryTableKeyedOnAPostIsCleared(): void {
		$this->streamRequest()->deleteRelatedTo([md5('https://cloud.example/@alice/1')]);

		foreach ($this->tablesKeyedOnAPost() as $table => $column) {
			$this->assertSame($column, $this->deleted[$table] ?? null, $table . ' outlives the post');
		}
	}

	public function testTheImportRecordGoesWithThePost(): void {
		$this->streamRequest()->deleteRelatedTo([md5('https://cloud.example/@alice/1')]);

		$this->assertSame('stream_id_prim', $this->deleted['social_import_post'] ?? null);
	}

	public function testThePostsWordsGoWithIt(): void {
		$this->streamRequest()->deleteRelatedTo([md5('https://cloud.example/@alice/1')]);

		$this->assertSame('stream_id_prim', $this->deleted['social_search_term'] ?? null);
	}

	/** A table added after the squash, so not in schema.json: named here. */
	public function testTheMediaLinksGoWithThePost(): void {
		$this->streamRequest()->deleteRelatedTo([md5('https://cloud.example/@alice/1')]);

		$this->assertSame('stream_id_prim', $this->deleted['social_stream_media'] ?? null);
	}
}
