<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Chat;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Chat\ChatPoller;
use OCA\Social\Atproto\Chat\ChatSender;
use OCA\Social\Atproto\Chat\ChatStore;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Publisher\TextMapper;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\CacheActorService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class ChatSenderTest extends TestCase {
	private const ALICE = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const BOB = 'did:plc:z72i7hdynmk6r22z27h6tvur';

	/** @var list<array{string, array, ?array}> */
	private array $calls = [];
	/** @var list<array{string, string, string, string}> */
	private array $remembered = [];
	private string $status = 'accepted';
	/** @var list<string> */
	private array $woken = [];
	private bool $on = true;

	private function sender(): ChatSender {
		$appView = $this->createMock(AppViewClient::class);
		$appView->method('chatAs')->willReturnCallback(function (string $did, PrivateKey $key, string $method, array $params = [], ?array $input = null): array {
			$this->calls[] = [$method, $params, $input];

			return match ($method) {
				'chat.bsky.convo.getConvoForMembers' => ['convo' => ['id' => 'convo1', 'status' => $this->status]],
				'chat.bsky.convo.acceptConvo' => ['rev' => '3k1'],
				default => ['id' => 'msg1'],
			};
		});
		$identities = $this->createMock(IdentityService::class);
		$identities->method('forActor')->willReturn(new Identity(1, 'https://social.test/users/alice', self::ALICE, 'alice.social.test', 'sealed', '', '', Identity::STATE_ACTIVE, '', 0));
		$identities->method('signingKey')->willReturn(PrivateKey::generate(Curve::K256));
		$cacheActors = $this->createMock(CacheActorService::class);
		$cacheActors->method('getFromId')->willReturn(new Person());
		$store = $this->createMock(ChatStore::class);
		$store->method('sent')->willReturnCallback(function (string $did, string $convo, string $messageId, string $id): void {
			$this->remembered[] = [$did, $convo, $messageId, $id];
		});
		$poller = $this->createMock(ChatPoller::class);
		$poller->method('isOn')->willReturnCallback(fn (): bool => $this->on);
		$poller->method('wake')->willReturnCallback(function (string $did): void {
			$this->woken[] = $did;
		});

		return new ChatSender($appView, $identities, $cacheActors, new TextMapper(), $store, $poller, new NullLogger());
	}

	private static function message(string $content, string $visibility = Stream::TYPE_DIRECT): Note {
		$note = new Note();
		$note->setId('https://social.test/users/alice/statuses/7');
		$note->setAttributedTo('https://social.test/users/alice');
		$note->setLocal(true);
		$note->setVisibility($visibility);
		$note->setToArray(['https://bsky.app/profile/' . self::BOB, 'https://mastodon.test/users/carol']);
		$note->setContent($content);

		return $note;
	}

	public function testADirectMessageToSomebodyOnBlueskyIsSentThereWithoutItsAddress(): void {
		$sent = $this->sender()->send(self::message('<p><span class="h-card"><a href="https://bsky.app/profile/' . self::BOB . '" class="u-url mention">@bob.test</a></span> Hello, see https://example.com</p>'));

		$this->assertTrue($sent);
		$this->assertSame(['chat.bsky.convo.getConvoForMembers', ['members' => [self::BOB]], null], $this->calls[0], 'only the people on Bluesky, never the others');
		[$method, , $input] = $this->calls[1];
		$this->assertSame('chat.bsky.convo.sendMessage', $method);
		$this->assertSame('convo1', $input['convoId']);
		$this->assertSame('Hello, see https://example.com', $input['message']['text']);
		$this->assertSame('app.bsky.richtext.facet#link', $input['message']['facets'][0]['features'][0]['$type']);
		$this->assertSame([[self::ALICE, 'convo1', 'msg1', 'https://social.test/users/alice/statuses/7']], $this->remembered, 'the answer will answer it, and its copy in the log is known');
		$this->assertSame([self::ALICE], $this->woken);
		$this->assertCount(2, $this->calls, 'an accepted conversation is not accepted again');
	}

	public function testAnsweringARequestAcceptsItFirst(): void {
		$this->status = 'request';

		$this->assertTrue($this->sender()->send(self::message('<p>Hello</p>')));

		$this->assertSame(['chat.bsky.convo.acceptConvo', [], ['convoId' => 'convo1']], $this->calls[1]);
		$this->assertSame('chat.bsky.convo.sendMessage', $this->calls[2][0]);
	}

	public function testOnlyADirectMessageWithSomethingToSayAndOnlyWhileMessagesAreOn(): void {
		$this->assertFalse($this->sender()->send(self::message('<p>Hello</p>', Stream::TYPE_PUBLIC)));
		$this->assertFalse($this->sender()->send(self::message('<p>@bob.test</p>')));
		$this->on = false;
		$this->assertFalse($this->sender()->send(self::message('<p>Hello</p>')));
		$this->assertSame([], $this->calls);
	}

	public function testALongMessageIsCutToBlueskysLimit(): void {
		$text = $this->sender()->textOf(self::message('<p>' . str_repeat('word ', 400) . '</p>'))['text'];

		$this->assertLessThanOrEqual(ChatSender::MAX_GRAPHEMES, mb_strlen($text));
		$this->assertStringEndsWith('…', $text);
	}

	public function testTheRecipientsAreThePeopleOnBluesky(): void {
		$this->assertSame([self::BOB], ChatSender::recipients(self::message('')));
	}
}
