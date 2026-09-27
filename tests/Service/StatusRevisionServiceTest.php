<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\StatusRevisionsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\StatusRevision;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\StatusRevisionService;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The rule an edit history stands or falls on: the first version recorded for
 * a status is the text that was posted, and every later edit adds exactly one
 * row.
 *
 * Recording the replaced version unconditionally would double every
 * intermediate version; recording only the new one would lose the original for
 * good, because `PostService::snapshotSource()` has already overwritten the
 * only other copy of it by the time an edit is federated.
 */
class StatusRevisionServiceTest extends TestCase {
	private const STATUS = 'https://cloud.example/users/alice/posts/1';
	private const AUTHOR = 'https://cloud.example/users/alice';

	private StatusRevisionsRequest|Stub $revisionsRequest;
	private CacheActorService|Stub $cacheActorService;
	private StatusRevisionService $service;

	/** @var StatusRevision[] every revision handed to the store, in order */
	private array $saved = [];
	/** @var StatusRevision[] what the store answers a read with */
	private array $stored = [];
	private bool $storeFails = false;

	protected function setUp(): void {
		$this->revisionsRequest = $this->createStub(StatusRevisionsRequest::class);
		$this->revisionsRequest->method('save')
			->willReturnCallback(function (StatusRevision $revision): void {
				if ($this->storeFails) {
					throw new RuntimeException('the database is not there');
				}

				$this->saved[] = $revision;
			});
		$this->revisionsRequest->method('hasRevisions')
			->willReturnCallback(fn (): bool => $this->stored !== []);
		$this->revisionsRequest->method('getByStreamId')
			->willReturnCallback(fn (): array => $this->stored);

		$this->cacheActorService = $this->createStub(CacheActorService::class);
		$this->cacheActorService->method('getFromId')
			->willReturnCallback(function (string $id): Person {
				$person = new Person();
				$person->setId($id);

				return $person;
			});

		$this->service = new StatusRevisionService(
			$this->revisionsRequest, $this->cacheActorService, new NullLogger()
		);
	}

	private function note(string $content, string $published, string $updated = ''): Note {
		$note = new Note();
		$note->setId(self::STATUS);
		$note->setAttributedTo(self::AUTHOR);
		$note->setContent($content);
		$note->setPublished($published);
		$note->setUpdated($updated);

		return $note;
	}

	public function testTheFirstEditRecordsTheOriginalBeforeTheNewVersion(): void {
		$before = $this->note('what it said', '2026-09-11T10:00:00Z');
		$after = $this->note('what it says now', '2026-09-11T10:00:00Z', '2026-09-11T11:00:00Z');

		$this->service->recordEdit($before, $after);

		$this->assertCount(2, $this->saved);
		$this->assertSame('what it said', $this->saved[0]->getContent());
		$this->assertSame('what it says now', $this->saved[1]->getContent());
	}

	/**
	 * The original carries the time the post was made, not the time of the
	 * edit that displaced it: a client draws "posted at" from `history[0]`.
	 */
	public function testTheOriginalIsStampedWithThePublishTime(): void {
		$before = $this->note('what it said', '2026-09-11T10:00:00Z');
		$after = $this->note('what it says now', '2026-09-11T10:00:00Z', '2026-09-11T11:00:00Z');

		$this->service->recordEdit($before, $after);

		$this->assertSame('2026-09-11T10:00:00Z', $this->saved[0]->getPublished());
		$this->assertSame('2026-09-11T11:00:00Z', $this->saved[1]->getPublished());
	}

	public function testALaterEditRecordsOnlyTheNewVersion(): void {
		$this->stored = [(new StatusRevision())->setContent('what it said')];

		$before = $this->note('second version', '2026-09-11T10:00:00Z', '2026-09-11T11:00:00Z');
		$after = $this->note('third version', '2026-09-11T10:00:00Z', '2026-09-11T12:00:00Z');

		$this->service->recordEdit($before, $after);

		$this->assertCount(1, $this->saved);
		$this->assertSame('third version', $this->saved[0]->getContent());
	}

	public function testTheEditSurvivesAStoreThatFails(): void {
		$this->storeFails = true;

		$this->service->recordEdit(
			$this->note('before', '2026-09-11T10:00:00Z'),
			$this->note('after', '2026-09-11T10:00:00Z', '2026-09-11T11:00:00Z')
		);

		$this->assertSame([], $this->saved);
	}

	public function testAStatusWithNoRecordedVersionsHasOneVersion(): void {
		$history = $this->service->history($this->note('never edited', '2026-09-11T10:00:00Z'));

		$this->assertCount(1, $history);
		$this->assertSame('never edited', $history[0]->getContent());
		$this->assertSame('2026-09-11T10:00:00Z', $history[0]->getPublished());
	}

	public function testEveryVersionCarriesTheAuthor(): void {
		$this->stored = [
			(new StatusRevision())->setContent('one'),
			(new StatusRevision())->setContent('two'),
		];

		$history = $this->service->history($this->note('two', '2026-09-11T10:00:00Z'));

		$this->assertCount(2, $history);
		foreach ($history as $revision) {
			$this->assertNotNull($revision->getAccount());
			$this->assertSame(self::AUTHOR, $revision->getAccount()->getId());
		}
	}

	/**
	 * A history is about the text. An author whose profile was never cached
	 * must not take the revisions down with it.
	 */
	public function testAnUncachedAuthorLeavesTheHistoryReadable(): void {
		$cacheActorService = $this->createStub(CacheActorService::class);
		$cacheActorService->method('getFromId')->willThrowException(new RuntimeException('not cached'));

		$service = new StatusRevisionService(
			$this->revisionsRequest, $cacheActorService, new NullLogger()
		);

		$history = $service->history($this->note('still readable', '2026-09-11T10:00:00Z'));

		$this->assertCount(1, $history);
		$this->assertSame('still readable', $history[0]->getContent());
		$this->assertNull($history[0]->getAccount());
	}

	/** Exactly Mastodon's StatusEdit keys, so a strict client can decode it. */
	public function testTheEntityIsAStatusEdit(): void {
		$revision = (new StatusRevision())
			->setContent('<p>hello</p>')
			->setSpoilerText('cw')
			->setSensitive(true)
			->setPublished('2026-09-11T10:00:00Z');

		$this->assertSame(
			['content', 'spoiler_text', 'sensitive', 'created_at', 'account', 'poll', 'media_attachments', 'emojis'],
			array_keys($revision->jsonSerialize())
		);
		$this->assertSame('<p>hello</p>', $revision->jsonSerialize()['content']);
		$this->assertSame('cw', $revision->jsonSerialize()['spoiler_text']);
		$this->assertTrue($revision->jsonSerialize()['sensitive']);
		$this->assertNull($revision->jsonSerialize()['poll']);
		$this->assertSame([], $revision->jsonSerialize()['media_attachments']);
	}

	public function testARowBecomesTheEntityItWasWrittenFrom(): void {
		$revision = (new StatusRevision())->importFromDatabase([
			'id' => '7',
			'stream_id_prim' => md5(self::STATUS),
			'content' => 'stored text',
			'spoiler_text' => 'stored cw',
			'sensitive' => '1',
			'published' => '2026-09-11T10:00:00Z',
		]);

		$this->assertSame(7, $revision->getId());
		$this->assertSame('stored text', $revision->getContent());
		$this->assertSame('stored cw', $revision->getSpoilerText());
		$this->assertTrue($revision->isSensitive());
		$this->assertSame('2026-09-11T10:00:00Z', $revision->getPublished());
	}
}
