<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Chat;

use OCA\Social\Atproto\Chat\ChatStore;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Moderation\Blocklist;
use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConversationService;
use OCA\Social\Service\DurableCache;
use OCA\Social\Tests\Helper\InMemoryDurableCacheRequest;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class ChatStoreTest extends TestCase {
	private const ALICE = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const BOB = 'did:plc:z72i7hdynmk6r22z27h6tvur';

	/** @var list<array> */
	private array $stored = [];
	/** @var list<string> */
	private array $deleted = [];
	private bool $blocked = false;
	/** @var array<string, Stream> what the stream holds by id */
	private array $streams = [];
	/** @var list<string> the messages a conversation here was read up to */
	private array $readUpTo = [];

	private function store(): ChatStore {
		$posts = $this->createMock(PostStore::class);
		$posts->method('storeMessage')->willReturnCallback(function (array $create): bool {
			$this->stored[] = $create;

			return true;
		});
		$posts->method('delete')->willReturnCallback(function (string $id): bool {
			$this->deleted[] = $id;

			return true;
		});
		$posts->method('isKnown')->willReturnCallback(fn (string $id): bool => isset($this->streams[$id]));
		$streams = $this->createMock(StreamRequest::class);
		$streams->method('save')->willReturnCallback(function (Stream $stream): void {
			$this->streams[$stream->getId()] = $stream;
		});
		$streams->method('getStreamById')->willReturnCallback(fn (string $id): Stream => $this->streams[$id] ?? throw new StreamNotFoundException());
		$streams->method('deleteById')->willReturnCallback(function (string $id): void {
			$this->deleted[] = $id;
			unset($this->streams[$id]);
		});
		$cacheActors = $this->createMock(CacheActorService::class);
		$cacheActors->method('getFromId')->willReturnCallback(static fn (string $id): Person => (new Person())->setId($id));
		$conversations = $this->createMock(ConversationService::class);
		$conversations->method('markReadUpTo')->willReturnCallback(function (Person $viewer, string $id): bool {
			$this->readUpTo[] = $viewer->getId() . ' ' . $id;

			return true;
		});
		$actors = $this->createMock(BlueskyActorService::class);
		$actors->method('resolve')->willReturnCallback(static fn (string $did): Person => (new Person())->setId('https://bsky.app/profile/' . $did));
		$blocklist = $this->createMock(Blocklist::class);
		$blocklist->method('isBlockedDid')->willReturnCallback(fn (): bool => $this->blocked);
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(false);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1790000000);

		return new ChatStore($posts, $actors, $blocklist, new DurableCache($factory, new InMemoryDurableCacheRequest(), $time), new NullLogger(), $streams, $cacheActors, $conversations);
	}

	private function alice(): Identity {
		return new Identity(1, 'https://social.test/users/alice', self::ALICE, 'alice.social.test', 'sealed', '', '', Identity::STATE_ACTIVE, '', 0);
	}

	private static function message(string $id, string $text, string $sender = self::BOB): array {
		return ['id' => $id, 'rev' => 'r' . $id, 'text' => $text, 'sender' => ['did' => $sender], 'sentAt' => '2026-10-09T10:00:00.000Z'];
	}

	public function testAMessageIsADirectNoteToTheAccountThatNamesIt(): void {
		$store = $this->store();

		$this->assertTrue($store->received($this->alice(), 'convo1', self::message('m1', 'Hi https://example.com')));

		$note = $this->stored[0]['object'];
		$this->assertSame('https://bsky.app/profile/' . self::BOB . '/convo/convo1/m1', $note['id']);
		$this->assertSame('https://bsky.app/profile/' . self::BOB, $note['attributedTo']);
		$this->assertSame(['https://social.test/users/alice'], $note['to']);
		$this->assertSame([], $note['cc']);
		$this->assertSame([['type' => 'Mention', 'href' => 'https://social.test/users/alice', 'name' => '@alice.social.test']], $note['tag']);
		$this->assertStringContainsString('href="https://example.com"', $note['content']);
		$this->assertSame('', $note['inReplyTo'], 'the first message starts the conversation');
	}

	public function testEachMessageAnswersTheOneBeforeItSentHereOrReceived(): void {
		$store = $this->store();
		$store->received($this->alice(), 'convo1', self::message('m1', 'one'));
		$this->assertSame('https://bsky.app/profile/' . self::BOB . '/convo/convo1/m1', $store->last(self::ALICE, 'convo1'));

		$store->remember(self::ALICE, 'convo1', 'https://social.test/users/alice/statuses/7');
		$store->received($this->alice(), 'convo1', self::message('m3', 'three'));

		$this->assertSame('https://social.test/users/alice/statuses/7', $this->stored[1]['object']['inReplyTo']);
		$this->assertSame('', $store->last(self::ALICE, 'convo2'), 'another conversation is its own');
	}

	public function testABlockedSenderIsNotStored(): void {
		$store = $this->store();

		$this->blocked = true;
		$this->assertFalse($store->received($this->alice(), 'convo1', self::message('m2', 'blocked')));
		$this->assertSame([], $this->stored);
	}

	public function testWhatTheAccountWroteInABlueskyAppIsItsOwnDirectMessageAndIsNeverDelivered(): void {
		$store = $this->store();
		$store->received($this->alice(), 'convo1', self::message('m1', 'Hi Alice'));

		$this->assertTrue($store->received($this->alice(), 'convo1', self::message('m2', 'Hi Bob', self::ALICE), [self::ALICE, self::BOB]));

		$id = 'https://bsky.app/profile/' . self::ALICE . '/convo/convo1/m2';
		$note = $this->streams[$id];
		$this->assertInstanceOf(Note::class, $note);
		$this->assertSame('https://social.test/users/alice', $note->getAttributedTo(), 'the account wrote it');
		$this->assertSame(['https://bsky.app/profile/' . self::BOB], $note->getToArray(), 'to the others in the conversation');
		$this->assertSame(Stream::TYPE_DIRECT, $note->getVisibility());
		$this->assertFalse($note->isLocal(), 'nothing delivers a message that is not local');
		$this->assertSame('https://bsky.app/profile/' . self::BOB . '/convo/convo1/m1', $note->getInReplyTo(), 'in the conversation it is in');
		$this->assertCount(1, $this->stored, 'saved as it is, not through the import path');
		$this->assertSame($id, $store->last(self::ALICE, 'convo1'));
		$this->assertFalse($store->received($this->alice(), 'convo1', self::message('m2', 'Hi Bob', self::ALICE), [self::ALICE, self::BOB]), 'once');
	}

	public function testWhatWasSentFromHereIsNotStoredAgainWhenTheLogShowsIt(): void {
		$store = $this->store();
		$store->sent(self::ALICE, 'convo1', 'm9', 'https://social.test/users/alice/statuses/7');

		$this->assertFalse($store->received($this->alice(), 'convo1', self::message('m9', 'Hello', self::ALICE), [self::ALICE, self::BOB]));
		$this->assertSame([], $this->streams);
		$this->assertSame('https://social.test/users/alice/statuses/7', $store->sentHere(self::ALICE, 'm9'));
		$this->assertSame('https://social.test/users/alice/statuses/7', $store->last(self::ALICE, 'convo1'));
	}

	public function testAnOwnMessageWithNobodyToAddressIsNotStored(): void {
		$this->assertFalse($this->store()->received($this->alice(), 'convo1', self::message('m2', 'Hi', self::ALICE), [self::ALICE]));
		$this->assertSame([], $this->streams);
	}

	public function testTheConversationOfAMessageIsInItsIdOrRememberedWhenSentFromHere(): void {
		$store = $this->store();
		$store->sent(self::ALICE, 'convo/2', 'm9', 'https://social.test/users/alice/statuses/7');

		$this->assertSame('convo1', $store->convoOf(ChatStore::messageId(self::BOB, 'convo1', 'm1')));
		$this->assertSame('convo/2', $store->convoOf('https://social.test/users/alice/statuses/7'));
		$this->assertSame('', $store->convoOf('https://mastodon.test/users/carol/statuses/1'));
		$this->assertSame('', $store->convoOf('https://bsky.app/profile/' . self::BOB . '/post/3kpost'), 'a post is in no conversation');
	}

	public function testAConversationReadOnBlueskyIsReadHereUpToTheSameMessage(): void {
		$store = $this->store();
		$store->sent(self::ALICE, 'convo1', 'm9', 'https://social.test/users/alice/statuses/7');

		$this->assertTrue($store->read($this->alice(), 'convo1', ['id' => 'm1', 'sender' => ['did' => self::BOB]]));
		$this->assertTrue($store->read($this->alice(), 'convo1', ['id' => 'm9', 'sender' => ['did' => self::ALICE]]));
		$this->assertFalse($store->read($this->alice(), 'convo1', ['id' => 'm1']), 'a system message names nobody');

		$this->assertSame([
			'https://social.test/users/alice https://bsky.app/profile/' . self::BOB . '/convo/convo1/m1',
			'https://social.test/users/alice https://social.test/users/alice/statuses/7',
		], $this->readUpTo);
	}

	public function testASharedPostIsALinkToIt(): void {
		$message = self::message('m1', 'look') + ['embed' => ['record' => ['uri' => 'at://' . self::BOB . '/app.bsky.feed.post/3kpost']]];

		$this->store()->received($this->alice(), 'convo1', $message);

		$this->assertStringContainsString('https://bsky.app/profile/' . self::BOB . '/post/3kpost', $this->stored[0]['object']['content']);
	}

	public function testADeletedMessageGoes(): void {
		$this->assertTrue($this->store()->deleted($this->alice(), 'convo1', ['id' => 'm1', 'sender' => ['did' => self::BOB]]));
		$this->assertSame(['https://bsky.app/profile/' . self::BOB . '/convo/convo1/m1'], $this->deleted);
	}

	public function testAnOwnMessageDeletedInABlueskyAppGoesButOneSentFromHereStays(): void {
		$store = $this->store();
		$store->received($this->alice(), 'convo1', self::message('m2', 'Hi', self::ALICE), [self::ALICE, self::BOB]);

		$this->assertTrue($store->deleted($this->alice(), 'convo1', ['id' => 'm2', 'sender' => ['did' => self::ALICE]]));
		$this->assertFalse($store->deleted($this->alice(), 'convo1', ['id' => 'm9', 'sender' => ['did' => self::ALICE]]));
		$this->assertSame(['https://bsky.app/profile/' . self::ALICE . '/convo/convo1/m2'], $this->deleted);
	}
}
