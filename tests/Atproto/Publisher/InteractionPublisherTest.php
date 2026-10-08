<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Publisher;

use OCA\Social\Atproto\Model\StoredRecord;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Publisher\InteractionPublisher;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Atproto\Reader\PostMapper;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class InteractionPublisherTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const OTHER = 'did:plc:z72i7hdynmk6r22z27h6tvur';

	/** @var Publisher&MockObject */
	private Publisher $publisher;
	/** @var RepositoryService&MockObject */
	private RepositoryService $repositories;
	private InteractionPublisher $interactions;
	private Person $alice;

	protected function setUp(): void {
		$this->publisher = $this->createMock(Publisher::class);
		$this->repositories = $this->createMock(RepositoryService::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1760000000);
		$this->interactions = new InteractionPublisher($this->publisher, $this->repositories, $time);
		$this->alice = new Person();
		$this->alice->setId('https://social.test/@alice');
		$this->alice->setLocal(true);
	}

	public function testALikeOfABlueskyPostNamesItsUriAndCid(): void {
		$post = new Note();
		$post->setId('https://bsky.app/profile/' . self::OTHER . '/post/3kpost');
		$post->setDetailArray(PostMapper::DETAIL, ['uri' => 'at://' . self::OTHER . '/app.bsky.feed.post/3kpost', 'cid' => 'bafypost']);
		$this->publisher->expects($this->once())->method('writeRecord')->with($this->alice, RecordMapper::LIKE, [
			'$type' => RecordMapper::LIKE,
			'subject' => ['uri' => 'at://' . self::OTHER . '/app.bsky.feed.post/3kpost', 'cid' => 'bafypost'],
			'createdAt' => '2025-10-09T08:53:20.000Z',
		], 'https://social.test/@alice#like/abc')->willReturn(true);

		$this->assertTrue($this->interactions->like($this->alice, $post, 'https://social.test/@alice#like/abc'));
	}

	public function testARepostOfALocalPostWithARecordNamesTheRecord(): void {
		$post = new Note();
		$post->setId('https://social.test/@bob/1');
		$post->setLocal(true);
		$this->repositories->method('getRecordsByLocalId')->with('https://social.test/@bob/1')->willReturn([new StoredRecord(self::DID, RecordMapper::POST, '3krec', Cid::forRaw('r'), '', 'https://social.test/@bob/1', 0)]);
		$this->publisher->expects($this->once())->method('writeRecord')->with($this->alice, RecordMapper::REPOST, $this->callback(static fn (array $r): bool => $r['subject'] === ['uri' => 'at://' . self::DID . '/app.bsky.feed.post/3krec', 'cid' => Cid::forRaw('r')->toString()]), 'https://social.test/announce/1')->willReturn(true);

		$this->assertTrue($this->interactions->repost($this->alice, $post, 'https://social.test/announce/1'));
	}

	public function testAFediverseOnlyPostWritesNothing(): void {
		$post = new Note();
		$post->setId('https://mastodon.test/users/bob/statuses/1');
		$this->repositories->method('getRecordsByLocalId')->willReturn([]);
		$this->publisher->expects($this->never())->method('writeRecord');

		$this->assertFalse($this->interactions->like($this->alice, $post, 'x'));
		$this->assertNull($this->interactions->subjectOf($post));
	}

	public function testUndoingRemovesTheRecordByTheSameKey(): void {
		$this->publisher->expects($this->exactly(2))->method('removeRecord')->willReturnCallback(static fn (string $collection, string $id): bool => $collection === RecordMapper::LIKE ? $id === 'like-id' : $id === 'announce-id');
		$this->assertTrue($this->interactions->unlike('like-id'));
		$this->assertTrue($this->interactions->unrepost('announce-id'));
	}
}
