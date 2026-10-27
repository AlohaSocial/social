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
use OCA\Social\Atproto\Moderation\Blocklist;
use OCA\Social\Atproto\Publisher\TextMapper;
use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Db\ReactionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\AtprotoException;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class ChatSenderTest extends TestCase {
	private const ALICE = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const BOB = 'did:plc:z72i7hdynmk6r22z27h6tvur';
	private const NOTE = 'https://social.test/users/alice/statuses/7';

	/** @var list<array{string, array, ?array}> */
	private array $calls = [];
	private string $status = 'accepted';
	/** @var list<string> */
	private array $woken = [];
	private bool $on = true;
	private int $now = 1790000000;
	/** @var list<array> the conversation on the chat service, oldest first */
	private array $convo = [];
	/** the chat service is out of reach: `sendMessage` fails before it arrives */
	private bool $down = false;
	/** `sendMessage` arrives, and its answer is lost on the way back */
	private bool $answerLost = false;
	/** the namespace of `ChatStore` whose writes fail, '' for none */
	private string $failing = '';
	/** @var list<int> what `ChatStore::attempted()` said when `sendMessage` went */
	private array $attemptedAtSend = [];
	private ?ChatStore $store = null;

	/**
	 * A sender whose store persists across calls, as a retry of the queued
	 * job finds it, against a chat service that keeps what it was sent.
	 */
	private function sender(): ChatSender {
		$store = $this->store();
		$appView = $this->createMock(AppViewClient::class);
		$appView->method('chatAs')->willReturnCallback(function (string $did, PrivateKey $key, string $method, array $params = [], ?array $input = null) use ($store): array {
			$this->calls[] = [$method, $params, $input];
			if ($method === 'chat.bsky.convo.sendMessage') {
				$this->attemptedAtSend[] = $store->attempted(self::NOTE);
				if ($this->down) {
					throw new AtprotoException('chat service unreachable', 503);
				}
				$message = ['id' => 'msg' . (count($this->convo) + 1), 'rev' => 'r' . count($this->convo), 'text' => $input['message']['text'] ?? '', 'sender' => ['did' => $did], 'sentAt' => gmdate('Y-m-d\TH:i:s.000\Z', $this->now)];
				$this->convo[] = $message;
				if ($this->answerLost) {
					throw new AtprotoException('timed out', 0);
				}

				return $message;
			}

			return match ($method) {
				'chat.bsky.convo.getConvoForMembers' => ['convo' => ['id' => 'convo1', 'status' => $this->status]],
				'chat.bsky.convo.acceptConvo' => ['rev' => '3k1'],
				'chat.bsky.convo.getMessages' => ['messages' => array_slice(array_reverse($this->convo), 0, (int)$params['limit'])],
				default => [],
			};
		});
		$identities = $this->createMock(IdentityService::class);
		$identities->method('forActor')->willReturn(new Identity(1, 'https://social.test/users/alice', self::ALICE, 'alice.social.test', 'sealed', '', '', Identity::STATE_ACTIVE, '', 0));
		$identities->method('signingKey')->willReturn(PrivateKey::generate(Curve::K256));
		$cacheActors = $this->createMock(CacheActorService::class);
		$cacheActors->method('getFromId')->willReturn(new Person());
		$poller = $this->createMock(ChatPoller::class);
		$poller->method('isOn')->willReturnCallback(fn (): bool => $this->on);
		$poller->method('wake')->willReturnCallback(function (string $did): void {
			$this->woken[] = $did;
		});

		return new ChatSender($appView, $identities, $cacheActors, new TextMapper(), $store, $poller, new NullLogger(), $this->time());
	}

	private function store(): ChatStore {
		if ($this->store !== null) {
			return $this->store;
		}
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(false);
		$table = new DurableCache($factory, new InMemoryDurableCacheRequest(), $this->time());
		$cache = $this->createMock(DurableCache::class);
		$cache->method('getShared')->willReturnCallback(static fn (string $namespace, string $key): mixed => $table->getShared($namespace, $key));
		$cache->method('removeShared')->willReturnCallback(static fn (string $namespace, string $key) => $table->removeShared($namespace, $key));
		$cache->method('setShared')->willReturnCallback(function (string $namespace, string $key, mixed $value, int $ttl) use ($table): void {
			if ($namespace === $this->failing) {
				throw new \RuntimeException('lock wait timeout');
			}
			$table->setShared($namespace, $key, $value, $ttl);
		});

		return $this->store = new ChatStore(
			$this->createMock(PostStore::class), $this->createMock(BlueskyActorService::class), $this->createMock(Blocklist::class),
			$cache, new NullLogger(), $this->createMock(StreamRequest::class), $this->createMock(CacheActorService::class), $this->createMock(ConversationService::class), $this->createMock(ReactionsRequest::class),
		);
	}

	private function time(): ITimeFactory {
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return $time;
	}

	/**
	 * @return list<string>
	 */
	private function methods(): array {
		return array_column($this->calls, 0);
	}

	private static function message(string $content, string $visibility = Stream::TYPE_DIRECT): Note {
		$note = new Note();
		$note->setId(self::NOTE);
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
		$this->assertSame(self::NOTE, $this->store()->last(self::ALICE, 'convo1'), 'the answer will answer it');
		$this->assertSame(self::NOTE, $this->store()->sentHere(self::ALICE, 'msg1'), 'its copy in the log is known');
		$this->assertSame('convo1', $this->store()->convoOf(self::NOTE));
		$this->assertSame([self::ALICE], $this->woken);
		$this->assertCount(2, $this->calls, 'an accepted conversation is not accepted again');
	}

	public function testANormalSendRecordsItsAttemptBeforeItGoesAndCallsSendOnce(): void {
		$this->assertTrue($this->sender()->send(self::message('<p>Hello</p>')));

		$this->assertSame(['chat.bsky.convo.getConvoForMembers', 'chat.bsky.convo.sendMessage'], $this->methods(), 'no look for an earlier attempt where there was none');
		$this->assertSame([$this->now], $this->attemptedAtSend, 'the attempt is recorded before the message goes');
		$this->assertSame(0, $this->store()->attempted(self::NOTE), 'a message recorded as sent is no attempt any more');
	}

	public function testAMessageRecordedAsSentIsNotSentAgain(): void {
		$this->assertTrue($this->sender()->send(self::message('<p>Hello</p>')));
		$this->calls = [];

		$this->assertFalse($this->sender()->send(self::message('<p>Hello</p>')));

		$this->assertSame([], $this->calls);
		$this->assertCount(1, $this->convo);
	}

	public static function recordingSteps(): array {
		return [
			'the conversation\'s newest message' => ['social.chat'],
			'the message by its Bluesky id' => ['social.chat.sent'],
			'the conversation of the message' => ['social.chat.convo'],
		];
	}

	#[DataProvider('recordingSteps')]
	public function testARetryAfterTheMessageWentAndWasNotRecordedFindsItAndDoesNotSendItAgain(string $failing): void {
		$this->failing = $failing;
		try {
			$this->sender()->send(self::message('<p>Hello</p>'));
			$this->fail('the bookkeeping failed');
		} catch (\RuntimeException) {
		}
		$this->failing = '';
		$this->now += 60;

		$this->assertTrue($this->sender()->send(self::message('<p>Hello</p>')));

		$this->assertSame(1, count(array_keys($this->methods(), 'chat.bsky.convo.sendMessage', true)), 'sent once, never twice');
		$this->assertSame(['convoId' => 'convo1', 'limit' => ChatSender::LOOKBACK], $this->calls[count($this->calls) - 1][1]);
		$this->assertCount(1, $this->convo);
		$this->assertSame(self::NOTE, $this->store()->sentHere(self::ALICE, 'msg1'), 'the message found is recorded as this one');
		$this->assertSame('convo1', $this->store()->convoOf(self::NOTE));
		$this->assertSame([self::ALICE], $this->woken);
	}

	public function testARetryAfterTheAnswerWasLostFindsTheMessageAndDoesNotSendItAgain(): void {
		$this->answerLost = true;
		try {
			$this->sender()->send(self::message('<p>Hello</p>'));
			$this->fail('the answer was lost');
		} catch (AtprotoException) {
		}
		$this->answerLost = false;
		$this->now += 60;

		$this->assertTrue($this->sender()->send(self::message('<p>Hello</p>')));

		$this->assertSame(1, count(array_keys($this->methods(), 'chat.bsky.convo.sendMessage', true)));
		$this->assertCount(1, $this->convo);
		$this->assertSame(self::NOTE, $this->store()->sentHere(self::ALICE, 'msg1'));
	}

	public function testARetryWhoseMessageNeverArrivedSendsIt(): void {
		$this->down = true;
		try {
			$this->sender()->send(self::message('<p>Hello</p>'));
			$this->fail('the chat service was down');
		} catch (AtprotoException) {
		}
		$this->down = false;
		$first = $this->now;
		$this->now += 60;

		$this->assertTrue($this->sender()->send(self::message('<p>Hello</p>')));

		$this->assertSame(['chat.bsky.convo.getConvoForMembers', 'chat.bsky.convo.getMessages', 'chat.bsky.convo.sendMessage'], array_slice($this->methods(), 2));
		$this->assertSame([$first, $first], $this->attemptedAtSend, 'a retry looks from the first attempt');
		$this->assertCount(1, $this->convo);
		$this->assertSame(self::NOTE, $this->store()->sentHere(self::ALICE, 'msg1'));
	}

	public function testARetryTakesNoOtherMessageForItsOwn(): void {
		$message = static fn (string $id, string $did, string $text, int $at): array => ['id' => $id, 'text' => $text, 'sender' => ['did' => $did], 'sentAt' => gmdate('Y-m-d\TH:i:s.000\Z', $at)];
		$this->convo = [
			$message('old', self::ALICE, 'Hello', $this->now - 3600),
			$message('other', self::ALICE, 'Hello', $this->now + 10),
			$message('bob', self::BOB, 'Hello', $this->now + 20),
			$message('else', self::ALICE, 'Hello there', $this->now + 30),
		];
		$this->store()->sent(self::ALICE, 'convo1', 'other', 'https://social.test/users/alice/statuses/6');
		$this->store()->attempting(self::NOTE, $this->now);
		$this->now += 60;

		$this->assertTrue($this->sender()->send(self::message('<p>Hello</p>')));

		$this->assertSame(1, count(array_keys($this->methods(), 'chat.bsky.convo.sendMessage', true)), 'an older one, another sent from here, somebody else\'s and another text are not this one');
		$this->assertSame(self::NOTE, $this->store()->sentHere(self::ALICE, 'msg5'));
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
