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
use OCA\Social\Model\ActivityPub\Actor\Person;
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
		$actors = $this->createMock(BlueskyActorService::class);
		$actors->method('resolve')->willReturnCallback(static fn (string $did): Person => (new Person())->setId('https://bsky.app/profile/' . $did));
		$blocklist = $this->createMock(Blocklist::class);
		$blocklist->method('isBlockedDid')->willReturnCallback(fn (): bool => $this->blocked);
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(false);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1790000000);

		return new ChatStore($posts, $actors, $blocklist, new DurableCache($factory, new InMemoryDurableCacheRequest(), $time), new NullLogger());
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

	public function testTheAccountsOwnAndBlockedSendersAreNotStored(): void {
		$store = $this->store();

		$this->assertFalse($store->received($this->alice(), 'convo1', self::message('m1', 'mine', self::ALICE)));
		$this->blocked = true;
		$this->assertFalse($store->received($this->alice(), 'convo1', self::message('m2', 'blocked')));
		$this->assertSame([], $this->stored);
	}

	public function testASharedPostIsALinkToIt(): void {
		$message = self::message('m1', 'look') + ['embed' => ['record' => ['uri' => 'at://' . self::BOB . '/app.bsky.feed.post/3kpost']]];

		$this->store()->received($this->alice(), 'convo1', $message);

		$this->assertStringContainsString('https://bsky.app/profile/' . self::BOB . '/post/3kpost', $this->stored[0]['object']['content']);
	}

	public function testADeletedMessageGoes(): void {
		$this->assertTrue($this->store()->deleted('convo1', ['id' => 'm1', 'sender' => ['did' => self::BOB]]));
		$this->assertSame(['https://bsky.app/profile/' . self::BOB . '/convo/convo1/m1'], $this->deleted);
	}
}
