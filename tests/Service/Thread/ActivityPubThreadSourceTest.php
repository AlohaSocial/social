<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service\Thread;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\StreamQueueService;
use OCA\Social\Service\Thread\ActivityPubThreadSource;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class ActivityPubThreadSourceTest extends TestCase {
	/** @var array<string, Stream> what this server holds */
	private array $held = [];
	/** @var array<string, array> what the remote servers answer */
	private array $remote = [];
	/** @var string[] the posts fetched */
	private array $fetched = [];
	private ActivityPubThreadSource $source;

	protected function setUp(): void {
		$streams = $this->createMock(StreamRequest::class);
		$streams->method('getStreamById')->willReturnCallback(fn (string $id): Stream => $this->held[$id] ?? throw new StreamNotFoundException());
		$curl = $this->createMock(CurlService::class);
		$curl->method('retrieveObject')->willReturnCallback(fn (string $id): array => $this->remote[$id] ?? throw new \RuntimeException('404'));
		$queue = $this->createMock(StreamQueueService::class);
		$queue->method('fetchNow')->willReturnCallback(function (string $id): void {
			$this->fetched[] = $id;
			if (!isset($this->remote[$id])) {
				throw new \RuntimeException('404');
			}
			$this->held[$id] = $this->note($id, $this->remote[$id]);
		});
		$this->source = new ActivityPubThreadSource($streams, $curl, $queue, new NullLogger());
	}

	private function note(string $id, array $source = [], bool $local = false): Note {
		$note = new Note();
		$note->setId($id)->setLocal($local);
		$note->setSource((string)json_encode(['id' => $id] + $source));

		return $note;
	}

	public function testTheRepliesItsServerListsAreFetchedAndTheirsInTurn(): void {
		$post = $this->held['https://remote.example/notes/1'] = $this->note('https://remote.example/notes/1', [
			'replies' => ['type' => 'Collection', 'first' => ['type' => 'CollectionPage', 'items' => ['https://other.example/notes/a', 'https://remote.example/notes/b'], 'next' => 'https://remote.example/notes/1/replies?page=2']],
		]);
		$this->remote['https://remote.example/notes/1/replies?page=2'] = ['type' => 'CollectionPage', 'items' => [['id' => 'https://third.example/notes/c']]];
		$this->held['https://remote.example/notes/b'] = $this->note('https://remote.example/notes/b');
		$this->remote['https://other.example/notes/a'] = ['replies' => 'https://other.example/notes/a/replies'];
		$this->remote['https://other.example/notes/a/replies'] = ['type' => 'OrderedCollection', 'orderedItems' => ['https://other.example/notes/a2']];
		$this->remote['https://other.example/notes/a2'] = [];
		$this->remote['https://third.example/notes/c'] = [];

		$this->assertTrue($this->source->supports($post));
		$this->assertSame(3, $this->source->fill($post, 10));
		$this->assertSame(['https://other.example/notes/a', 'https://third.example/notes/c', 'https://other.example/notes/a2'], $this->fetched, 'the one held is not fetched again');
	}

	public function testNoMoreThanTheBudgetIsFetched(): void {
		$post = $this->note('https://remote.example/notes/1', ['replies' => ['type' => 'Collection', 'items' => ['https://a.example/1', 'https://a.example/2', 'https://a.example/3']]]);
		$this->remote = ['https://a.example/1' => [], 'https://a.example/2' => [], 'https://a.example/3' => []];

		$this->assertSame(2, $this->source->fill($post, 2));
	}

	public function testAPostWithoutARepliesCollectionOrOfThisServerIsNotAskedAbout(): void {
		$this->assertFalse($this->source->supports($this->note('https://remote.example/notes/1')));
		$this->assertFalse($this->source->supports($this->note('https://social.test/@alice/1', ['replies' => 'https://social.test/@alice/1/replies'], true)));
		$this->assertFalse($this->source->supports($this->note('https://bsky.app/profile/did:plc:bob/post/3k', ['replies' => 'https://x.example/r'])));
	}
}
