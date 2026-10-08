<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use OCA\Social\AP;
use OCA\Social\Atproto\Reader\ActorMapper;
use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Atproto\Reader\PostMapper;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Details;
use OCA\Social\Service\ImportService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class PostStoreTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const OTHER = 'did:plc:z72i7hdynmk6r22z27h6tvur';

	/** @var ImportService&MockObject */
	private ImportService $import;
	/** @var StreamRequest&MockObject */
	private StreamRequest $streams;
	/** @var BlueskyActorService&MockObject */
	private BlueskyActorService $actors;
	private PostStore $store;
	/** @var string[] ids the stream already has */
	private array $known = [];
	/** @var ACore[] what reached the import path */
	private array $imported = [];

	protected function setUp(): void {
		$ap = $this->createMock(AP::class);
		$ap->method('getItemFromData')->willReturnCallback(static function (array $data): ACore {
			$item = match ($data['type']) {
				'Create' => new Create(),
				'Announce' => new Announce(),
				'Delete' => new Delete(),
				'Person' => new Person(),
			};
			$item->setUrlCloud('https://social.test');
			$item->import($data);
			if (is_array($data['object'] ?? null)) {
				$note = new Note();
				$note->setUrlCloud('https://social.test');
				$note->import($data['object']);
				$item->setObject($note);
			}

			return $item;
		});
		AP::set($ap);
		$this->import = $this->createMock(ImportService::class);
		$this->import->method('parseIncomingRequest')->willReturnCallback(function (ACore $activity): void {
			$this->imported[] = $activity;
			// the post is known once its Create went through
			if ($activity instanceof Create && $activity->getObject() !== null) {
				$this->known[] = $activity->getObject()->getId();
			}
		});
		$this->streams = $this->createMock(StreamRequest::class);
		$this->streams->method('getStreamById')->willReturnCallback(fn (string $id): Stream => in_array($id, $this->known, true) ? new Note() : throw new StreamNotFoundException());
		$this->streams->method('getAnnounceBy')->willThrowException(new StreamNotFoundException());
		$this->actors = $this->createMock(BlueskyActorService::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1760000000);
		$this->store = new PostStore(new PostMapper(), new ActorMapper(), $this->actors, $this->import, $this->streams, $time, new NullLogger());
	}

	protected function tearDown(): void {
		AP::set(null);
	}

	public function testAPostGoesThroughTheImportPathFromBskyApp(): void {
		$this->actors->method('cached')->willReturn($this->person(self::DID));
		$this->assertSame(1, $this->store->storeFeedItem(['post' => $this->postView()]));

		$this->assertCount(1, $this->imported);
		$create = $this->imported[0];
		$this->assertInstanceOf(Create::class, $create);
		$this->assertSame('bsky.app', $create->getOrigin(), 'the origin every id is checked against');
		$this->assertSame('https://bsky.app/profile/' . self::DID, $create->getActorId());
		$note = $create->getObject();
		$this->assertSame('https://bsky.app/profile/' . self::DID . '/post/3kznmn7xqxl22', $note->getId());
		$this->assertSame(3, $note->getDetailInt(Details::LIKES));
		$this->assertSame(3, $note->getDetailInt(Details::REMOTE_LIKES));
		$this->assertSame(1, $note->getDetailInt(Details::BOOSTS));
		$this->assertSame('at://' . self::DID . '/app.bsky.feed.post/3kznmn7xqxl22', $note->getDetails(PostMapper::DETAIL)['uri']);
	}

	public function testAKnownPostIsNotStoredAgain(): void {
		$this->known[] = 'https://bsky.app/profile/' . self::DID . '/post/3kznmn7xqxl22';
		$this->assertSame(0, $this->store->storeFeedItem(['post' => $this->postView()]));
		$this->assertSame([], $this->imported);
	}

	public function testAnUnseenAuthorIsMadeACachedActorFirst(): void {
		$this->actors->method('cached')->willReturn(null);
		$this->actors->expects($this->once())->method('store')->with($this->callback(static fn (Person $p): bool => $p->getId() === 'https://bsky.app/profile/' . self::DID && $p->getAccount() === 'alice.bsky.social'));
		$this->assertTrue($this->store->storePost($this->postView()));
	}

	public function testALimitedAuthorsPostsAreNotStored(): void {
		$limited = $this->person(self::DID);
		$limited->setDetailArray(ActorMapper::DETAIL, ['limited' => true]);
		$this->actors->method('cached')->willReturn($limited);
		$this->assertFalse($this->store->storePost($this->postView()));
		$this->assertSame([], $this->imported);
	}

	public function testARepostStoresThePostThenTheAnnounceByTheReposter(): void {
		$this->actors->method('cached')->willReturnCallback(fn (string $did): ?Person => $this->person($did));
		$item = ['post' => $this->postView(), 'reason' => ['$type' => 'app.bsky.feed.defs#reasonRepost', 'by' => ['did' => self::OTHER, 'handle' => 'bob.bsky.social'], 'indexedAt' => '2026-10-08T11:00:00.000Z']];
		$this->assertSame(2, $this->store->storeFeedItem($item));
		$this->assertInstanceOf(Create::class, $this->imported[0]);
		$announce = $this->imported[1];
		$this->assertInstanceOf(Announce::class, $announce);
		$this->assertSame('https://bsky.app/profile/' . self::OTHER, $announce->getActorId());
		$this->assertSame('https://bsky.app/profile/' . self::DID . '/post/3kznmn7xqxl22', $announce->getObjectId());
		$this->assertSame('bsky.app', $announce->getOrigin());
	}

	public function testADeleteIsSentForAKnownPostOnly(): void {
		$id = 'https://bsky.app/profile/' . self::DID . '/post/3kznmn7xqxl22';
		$this->assertFalse($this->store->delete($id));
		$this->known[] = $id;
		$this->assertTrue($this->store->delete($id));
		$delete = $this->imported[0];
		$this->assertInstanceOf(Delete::class, $delete);
		$this->assertSame('https://bsky.app/profile/' . self::DID, $delete->getActorId());
		$this->assertSame($id, $delete->getObjectId());
	}

	private function person(string $did): Person {
		$person = new Person();
		$person->setId('https://bsky.app/profile/' . $did);

		return $person;
	}

	private function postView(): array {
		return [
			'uri' => 'at://' . self::DID . '/app.bsky.feed.post/3kznmn7xqxl22',
			'cid' => 'bafyreipost',
			'author' => ['did' => self::DID, 'handle' => 'alice.bsky.social', 'displayName' => 'Alice'],
			'record' => ['$type' => 'app.bsky.feed.post', 'text' => 'Hello', 'createdAt' => '2026-10-08T10:00:00.000Z'],
			'replyCount' => 0, 'repostCount' => 1, 'likeCount' => 3, 'quoteCount' => 0,
			'indexedAt' => '2026-10-08T10:00:01.000Z',
		];
	}
}
