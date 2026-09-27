<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use DateTime;
use OCA\Social\Migration\BackfillStreamPostFields;
use OCA\Social\Service\ConfigService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * The repair step that fills in the five post columns for the rows written
 * before `Version1000Date20260912000007` added them.
 *
 * Every one of those rows still carries the fields inside its stored wire
 * object, which is what the step reads — through `Stream::importFromDatabase()`
 * rather than a second copy of the parsing, so what it writes is exactly what a
 * read of the row would otherwise have derived.
 */
#[AllowMockObjectsWithoutExpectations]
class BackfillStreamPostFieldsTest extends TestCase {
	private const MARKER = 'migration_stream_post_fields_backfilled';

	private const SOURCE = <<<'JSON'
		{
			"id": "https://remote.test/users/alice/statuses/1",
			"type": "Note",
			"contentMap": {"pt-br": "olá"},
			"updated": "2026-09-12T10:00:00+02:00",
			"quote": "https://remote.test/users/bob/statuses/7",
			"quoteAuthorization": "https://remote.test/users/bob/statuses/7/quote_authorizations/x",
			"tag": [
				{"type": "Mention", "href": "https://remote.test/users/bob", "name": "@bob@remote.test"},
				{"type": "Hashtag", "href": "https://remote.test/tags/nc", "name": "#nc"}
			]
		}
		JSON;

	private ConfigService|MockObject $configService;
	private IOutput|Stub $output;

	protected function setUp(): void {
		parent::setUp();
		$this->configService = $this->createMock(ConfigService::class);
		$this->output = $this->createStub(IOutput::class);
	}

	private function step(FakeConnection $connection): BackfillStreamPostFields {
		return new BackfillStreamPostFields($connection, $this->configService);
	}

	/** A row as it stands before the backfill: the columns empty, the JSON full. */
	private function legacyRow(int $nid = 1, string $source = self::SOURCE): array {
		return [
			'nid' => $nid,
			'source' => $source,
			'tags' => '',
			'language' => '',
			'updated' => null,
			'quote' => '',
			'quote_authorization' => '',
		];
	}

	public function testEveryFieldIsLiftedOutOfTheStoredWireObject(): void {
		$connection = new FakeConnection([[$this->legacyRow()]]);

		$this->step($connection)->run($this->output);

		$writes = $connection->writes();
		$this->assertCount(1, $writes);
		$sets = $writes[0]->sets;

		$this->assertSame('social_stream', $writes[0]->table);
		// the language of a wire object that declares none at the top level is
		// the key of its contentMap, normalised the way BCP 47 cases one
		$this->assertSame('pt-BR', $sets['language']);
		$this->assertSame('https://remote.test/users/bob/statuses/7', $sets['quote']);
		$this->assertSame(
			'https://remote.test/users/bob/statuses/7/quote_authorizations/x',
			$sets['quote_authorization']
		);

		$tags = json_decode((string)$sets['tags'], true);
		$this->assertCount(2, $tags);
		$this->assertSame('@bob@remote.test', $tags[0]['name']);
		$this->assertSame('#nc', $tags[1]['name']);
	}

	public function testTheEditStampIsStoredAsAnInstantInUtc(): void {
		$connection = new FakeConnection([[$this->legacyRow()]]);

		$this->step($connection)->run($this->output);

		$updated = $connection->writes()[0]->sets['updated'];
		// Doctrine renders a DateTime in whatever zone it carries, so a remote
		// `updated` of 10:00+02:00 has to be converted before it is bound or the
		// column keeps the right text for the wrong instant
		$this->assertInstanceOf(DateTime::class, $updated);
		$this->assertSame('2026-09-12 08:00:00 UTC', $updated->format('Y-m-d H:i:s T'));
	}

	public function testARowThatAlreadyAgreesWithItsWireObjectIsNotWritten(): void {
		$row = $this->legacyRow();
		$row['language'] = 'pt-BR';
		$row['quote'] = 'https://remote.test/users/bob/statuses/7';
		$row['quote_authorization']
			= 'https://remote.test/users/bob/statuses/7/quote_authorizations/x';
		$row['updated'] = '2026-09-12 08:00:00';
		$row['tags'] = json_encode([
			['type' => 'Mention', 'href' => 'https://remote.test/users/bob', 'name' => '@bob@remote.test'],
			['type' => 'Hashtag', 'href' => 'https://remote.test/tags/nc', 'name' => '#nc'],
		], JSON_UNESCAPED_SLASHES);

		$connection = new FakeConnection([[$row]]);

		$this->step($connection)->run($this->output);

		$this->assertSame([], $connection->writes());
	}

	public function testARowWithNothingToDeriveFromIsLeftAlone(): void {
		// an in-app notification: local-only, no wire object, and none of the
		// five fields to begin with
		$connection = new FakeConnection([[$this->legacyRow(1, '')]]);

		$this->step($connection)->run($this->output);

		$this->assertSame([], $connection->writes());
	}

	public function testAPostWithNoneOfTheFiveIsNotWrittenEither(): void {
		$connection = new FakeConnection([[$this->legacyRow(1, '{"id":"x","type":"Note"}')]]);

		$this->step($connection)->run($this->output);

		$this->assertSame([], $connection->writes());
	}

	public function testThePagingWalksThePrimaryKeyAndStopsShortOfAFullChunk(): void {
		$connection = new FakeConnection([[$this->legacyRow(41), $this->legacyRow(42)]]);

		$this->step($connection)->run($this->output);

		$reads = array_values(array_filter(
			$connection->queries,
			static fn (FakeQueryBuilder $query): bool => $query->statements === 0
		));

		// one read, because a short chunk means there is no next page: an
		// offset scan would re-read everything it has already walked, and
		// social_stream is the largest table in the app
		$this->assertCount(1, $reads);
		$this->assertSame('nid asc', $reads[0]->orderBy);
		$this->assertSame(['nid > 0'], $reads[0]->wheres);
		$this->assertSame(500, $reads[0]->maxResults);
		$this->assertSame(
			['nid', 'source', 'tags', 'language', 'updated', 'quote', 'quote_authorization'],
			$reads[0]->selects
		);
	}

	public function testASecondPageIsAskedForAfterAFullOne(): void {
		$first = [];
		for ($nid = 1; $nid <= 500; $nid++) {
			$first[] = $this->legacyRow($nid);
		}

		$connection = new FakeConnection([$first, []]);
		$this->step($connection)->run($this->output);

		$reads = array_values(array_filter(
			$connection->queries,
			static fn (FakeQueryBuilder $query): bool => $query->statements === 0
		));

		$this->assertCount(2, $reads);
		// the second page starts after the last nid of the first
		$this->assertSame(['nid > 500'], $reads[1]->wheres);
	}

	public function testTheMarkerIsSetSoALaterUpgradeDoesNotScanTheTableAgain(): void {
		$connection = new FakeConnection([[$this->legacyRow()]]);
		$this->configService->expects($this->once())
			->method('setAppValue')
			->with(self::MARKER, '1');

		$this->step($connection)->run($this->output);
	}

	public function testAMarkedInstanceReadsNothingAtAll(): void {
		$connection = new FakeConnection([[$this->legacyRow()]]);
		$this->configService->method('getAppValueInt')->with(self::MARKER)->willReturn(1);
		$this->configService->expects($this->never())->method('setAppValue');

		$this->step($connection)->run($this->output);

		$this->assertSame([], $connection->queries);
	}

	public function testTheOperatorIsToldOnlyWhenSomethingWasWritten(): void {
		$connection = new FakeConnection([[$this->legacyRow()]]);
		$messages = [];
		$this->output->method('info')->willReturnCallback(
			function (string $message) use (&$messages): void {
				$messages[] = $message;
			}
		);

		$this->step($connection)->run($this->output);
		$this->assertSame(['filled in the post fields of 1 status(es)'], $messages);

		$quiet = new FakeConnection([[$this->legacyRow(1, '')]]);
		$messages = [];
		$this->step($quiet)->run($this->output);
		$this->assertSame([], $messages);
	}
}
