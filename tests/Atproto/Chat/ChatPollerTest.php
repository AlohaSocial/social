<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Chat;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Chat\ChatDeclaration;
use OCA\Social\Atproto\Chat\ChatPoller;
use OCA\Social\Atproto\Chat\ChatStore;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\Watch;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\AtprotoWatchRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Exceptions\AtprotoException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class ChatPollerTest extends TestCase {
	private const ALICE = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const BOB = 'did:plc:z72i7hdynmk6r22z27h6tvur';
	private const NOW = 1790000000;
	private const TABLE = CoreRequestBuilder::TABLE_ATPROTO_CHAT_CURSOR;

	private int $now = self::NOW;
	/** how far the clock moves each time an account's read ends */
	private int $tick = 0;
	/** @var Watch[] the cursors due */
	private array $due = [];

	private string $chat = 'https://api.bsky.chat';
	/** @var array<string, array> answers by method */
	private array $answers = [];
	/** @var array<string, array<string, array>> answers by method and opaque page cursor */
	private array $cursorAnswers = [];
	/** @var list<array{string, array}> */
	private array $asked = [];
	/** @var list<array{string, string}> what the store was handed: kind and message id */
	private array $handed = [];
	/** @var array<string, list<string>> the members each message was handed with */
	private array $members = [];
	/** @var array<string, string> messages sent from here, by Bluesky id */
	private array $sentHere = [];
	/** @var list<string> the accounts whose declaration was seen to */
	private array $declared = [];
	/** @var list<array> */
	private array $synced = [];
	/** @var list<array> */
	private array $failed = [];
	private string $state = Identity::STATE_ACTIVE;
	/** @var list<string> the DIDs whose cursor was dropped */
	private array $removed = [];
	private bool $failReceivedOnce = false;
	private bool $failGetConvoOnce = false;

	private function poller(): ChatPoller {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$config->method('chat')->willReturnCallback(fn (): string => $this->chat);
		$config->method('syncCeiling')->willReturn(200);
		$identities = $this->createMock(IdentityService::class);
		$identities->method('getByDid')->willReturnCallback(fn (): Identity => new Identity(1, 'https://social.test/users/alice', self::ALICE, 'alice.social.test', 'sealed', '', '', $this->state, '', 0));
		$identities->method('signingKey')->willReturn(PrivateKey::generate(Curve::K256));
		$appView = $this->createMock(AppViewClient::class);
		$appView->method('chatAs')->willReturnCallback(function (string $did, PrivateKey $key, string $method, array $params = []): array {
			$this->asked[] = [$method, $params];
			if ($method === 'chat.bsky.convo.getConvo' && $this->failGetConvoOnce) {
				$this->failGetConvoOnce = false;
				throw new AtprotoException('temporary conversation lookup failure');
			}
			$cursor = (string)($params['cursor'] ?? '');
			if (isset($this->cursorAnswers[$method][$cursor])) {
				return $this->cursorAnswers[$method][$cursor];
			}

			return $this->answers[$method] ?? throw new AtprotoException('chat service down');
		});
		$cursors = $this->createMock(AtprotoWatchRequest::class);
		$cursors->method('getDue')->willReturnCallback(fn (): array => $this->due);
		$cursors->method('synced')->willReturnCallback(function (string $did, string $cursor, int $now, int $next, string $table): void {
			$this->synced[] = [$did, $cursor, $next - $now, $table];
			$this->now += $this->tick;
		});
		$cursors->method('remove')->willReturnCallback(function (string $did): void {
			$this->removed[] = $did;
		});
		$cursors->method('failed')->willReturnCallback(function (string $did, string $error, int $next, string $table): void {
			$this->failed[] = [$did, $next - self::NOW, $table];
			$this->now += $this->tick;
		});
		$store = $this->createMock(ChatStore::class);
		$store->method('received')->willReturnCallback(function (Identity $identity, string $convo, array $message, array $members = []): bool {
			if ($this->failReceivedOnce) {
				$this->failReceivedOnce = false;
				throw new AtprotoException('temporary local persistence failure');
			}
			$this->handed[] = ['received', $convo . '/' . $message['id']];
			$this->members[$message['id']] = $members;

			return true;
		});
		$store->method('deleted')->willReturnCallback(function (Identity $identity, string $convo, array $message): bool {
			$this->handed[] = ['deleted', $convo . '/' . $message['id']];

			return true;
		});
		$store->method('read')->willReturnCallback(function (Identity $identity, string $convo, array $message): bool {
			$this->handed[] = ['read', $convo . '/' . $message['id']];

			return true;
		});
		$store->method('reaction')->willReturnCallback(function (Identity $identity, string $convo, array $message, array $reaction, bool $added, string $revision): bool {
			$this->handed[] = [$added ? 'reaction-added' : 'reaction-removed', $convo . '/' . $message['id'] . '/' . $reaction['value'] . '/' . $revision];

			return true;
		});
		$store->method('sentHere')->willReturnCallback(fn (string $did, string $id): string => $this->sentHere[$id] ?? '');
		$declaration = $this->createMock(ChatDeclaration::class);
		$declaration->method('ensure')->willReturnCallback(function (Identity $identity): void {
			$this->declared[] = $identity->did;
		});
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return new ChatPoller($config, $identities, $cursors, $appView, $store, $time, new NullLogger(), $declaration);
	}

	private static function cursor(string $rev, int $lastSync = 0, int $nextSync = 0): Watch {
		return new Watch(self::ALICE, 'alice.social.test', $rev, $lastSync, $nextSync, 0, '', 0);
	}

	private static function message(string $id, string $sender = self::BOB): array {
		return ['id' => $id, 'text' => 'hi', 'sender' => ['did' => $sender], 'sentAt' => '2026-10-09T10:00:00.000Z'];
	}

	public function testTheFirstReadTakesTheUnreadMessagesOldestFirstAndStartsTheLogAtTheNewest(): void {
		$this->answers['chat.bsky.convo.listConvos'] = ['convos' => [
			['id' => 'c1', 'rev' => '3k002', 'unreadCount' => 2, 'members' => [['did' => self::ALICE], ['did' => self::BOB]]],
			['id' => 'c2', 'rev' => '3k005', 'unreadCount' => 0],
		]];
		$this->answers['chat.bsky.convo.getMessages'] = ['messages' => [self::message('m2'), self::message('m1')]];

		$this->assertSame(2, $this->poller()->pollAccount(self::cursor('')));

		$this->assertSame([['received', 'c1/m1'], ['received', 'c1/m2']], $this->handed);
		$this->assertSame([self::ALICE, self::BOB], $this->members['m1'], 'the conversation\'s members come along');
		$this->assertSame(['convoId' => 'c1', 'limit' => 2], $this->asked[1][1]);
		$this->assertSame([self::ALICE, '3k005', ChatPoller::INTERVAL, self::TABLE], $this->synced[0]);
		$this->assertSame([self::ALICE], $this->declared, 'who may write is seen to on the first read');
	}

	public function testTheInitialReadFollowsConversationAndUnreadMessagePages(): void {
		$convos = [];
		for ($i = 0; $i < 20; $i++) {
			$convos[] = ['id' => 'c' . $i, 'rev' => '3k00' . $i, 'unreadCount' => 0];
		}
		$convos[0]['unreadCount'] = 21;
		$this->cursorAnswers['chat.bsky.convo.listConvos'][''] = ['convos' => $convos, 'cursor' => 'convos-next'];
		$this->cursorAnswers['chat.bsky.convo.listConvos']['convos-next'] = ['convos' => [
			['id' => 'c20', 'rev' => '3k021', 'unreadCount' => 0],
		]];
		$firstMessages = [];
		for ($i = 21; $i >= 2; $i--) {
			$firstMessages[] = self::message('m' . $i);
		}
		$this->cursorAnswers['chat.bsky.convo.getMessages'][''] = ['messages' => $firstMessages, 'cursor' => 'messages-next'];
		$this->cursorAnswers['chat.bsky.convo.getMessages']['messages-next'] = ['messages' => [self::message('m1')]];

		$this->assertSame(21, $this->poller()->pollAccount(self::cursor('')));

		$this->assertSame(range(1, 21), array_map(
			static fn (array $message): int => (int)substr($message[1], strrpos($message[1], 'm') + 1),
			$this->handed,
		));
		$this->assertSame([self::ALICE, '3k021', ChatPoller::INTERVAL, self::TABLE], $this->synced[0]);
		$this->assertSame(['convoId' => 'c0', 'limit' => 21], $this->asked[1][1]);
		$this->assertSame(['convoId' => 'c0', 'limit' => 1, 'cursor' => 'messages-next'], $this->asked[2][1]);
		$this->assertSame(['limit' => ChatPoller::PAGE, 'cursor' => 'convos-next'], $this->asked[3][1]);
	}

	public function testTheLogSaysWhatWasSentAndDeletedSinceTheLastRead(): void {
		$this->answers['chat.bsky.convo.getLog'] = ['cursor' => '3k009', 'logs' => [
			['$type' => 'chat.bsky.convo.defs#logBeginConvo', 'rev' => '3k006', 'convoId' => 'c3'],
			['$type' => 'chat.bsky.convo.defs#logCreateMessage', 'rev' => '3k007', 'convoId' => 'c3', 'message' => self::message('m5')],
			['$type' => 'chat.bsky.convo.defs#logDeleteMessage', 'rev' => '3k008', 'convoId' => 'c1', 'message' => ['id' => 'm1', 'sender' => ['did' => self::BOB]]],
		]];

		$this->assertSame(2, $this->poller()->pollAccount(self::cursor('3k005')));

		$this->assertSame(['cursor' => '3k005'], $this->asked[0][1]);
		$this->assertSame([['received', 'c3/m5'], ['deleted', 'c1/m1']], $this->handed);
		$this->assertSame('3k009', $this->synced[0][1]);
	}

	public function testWhatTheAccountWroteInABlueskyAppIsHandedWithTheMembersAskedForOnce(): void {
		$this->answers['chat.bsky.convo.getConvo'] = ['convo' => ['id' => 'c3', 'members' => [['did' => self::ALICE], ['did' => self::BOB]]]];
		$this->sentHere['m8'] = 'https://social.test/users/alice/statuses/7';
		$this->answers['chat.bsky.convo.getLog'] = ['cursor' => '3k009', 'logs' => [
			['$type' => 'chat.bsky.convo.defs#logCreateMessage', 'rev' => '3k006', 'convoId' => 'c3', 'message' => self::message('m6', self::ALICE)],
			['$type' => 'chat.bsky.convo.defs#logCreateMessage', 'rev' => '3k007', 'convoId' => 'c3', 'message' => self::message('m7', self::ALICE)],
			['$type' => 'chat.bsky.convo.defs#logCreateMessage', 'rev' => '3k008', 'convoId' => 'c3', 'message' => self::message('m8', self::ALICE)],
			['$type' => 'chat.bsky.convo.defs#logCreateMessage', 'rev' => '3k009', 'convoId' => 'c3', 'message' => self::message('m9')],
		]];

		$this->poller()->pollAccount(self::cursor('3k005'));

		$this->assertSame([self::ALICE, self::BOB], $this->members['m6']);
		$this->assertSame([self::ALICE, self::BOB], $this->members['m7']);
		$this->assertSame([], $this->members['m8'], 'one sent from here needs nobody');
		$this->assertSame([], $this->members['m9'], 'nor does one somebody else wrote');
		$this->assertCount(1, array_filter($this->asked, static fn (array $call): bool => $call[0] === 'chat.bsky.convo.getConvo'));
		$this->assertSame([], $this->declared, 'only the first read sees to the declaration');
	}

	public function testAConversationLookupFailureDoesNotAdvanceTheLogCursorAndRetries(): void {
		$this->answers['chat.bsky.convo.getConvo'] = ['convo' => ['id' => 'c3', 'members' => [['did' => self::ALICE], ['did' => self::BOB]]]];
		$this->answers['chat.bsky.convo.getLog'] = ['cursor' => '3k007', 'logs' => [
			['$type' => 'chat.bsky.convo.defs#logCreateMessage', 'rev' => '3k006', 'convoId' => 'c3', 'message' => self::message('m6', self::ALICE)],
		]];
		$this->failGetConvoOnce = true;
		$poller = $this->poller();

		$this->assertSame(0, $poller->pollAccount(self::cursor('3k005')));
		$this->assertSame([], $this->synced, 'the cursor remains before the unimported message');
		$this->assertSame([self::ALICE, ChatPoller::INTERVAL * 2, self::TABLE], $this->failed[0]);

		$this->assertSame(1, $poller->pollAccount(self::cursor('3k005')));
		$this->assertSame('3k007', $this->synced[0][1]);
		$this->assertSame([['received', 'c3/m6']], $this->handed);
	}

	public function testAStoreFailureDoesNotAdvanceTheLogCursorAndIsRetried(): void {
		$this->answers['chat.bsky.convo.getLog'] = ['cursor' => '3k007', 'logs' => [
			['$type' => 'chat.bsky.convo.defs#logCreateMessage', 'rev' => '3k006', 'convoId' => 'c3', 'message' => self::message('m6')],
		]];
		$this->failReceivedOnce = true;
		$poller = $this->poller();

		$this->assertSame(0, $poller->pollAccount(self::cursor('3k005')));
		$this->assertSame([], $this->synced, 'no cursor is committed before a durable write');

		$this->assertSame(1, $poller->pollAccount(self::cursor('3k005')));
		$this->assertSame('3k007', $this->synced[0][1]);
	}

	public function testAConversationReadOnBlueskyIsReadHere(): void {
		$this->answers['chat.bsky.convo.getLog'] = ['cursor' => '3k009', 'logs' => [
			['$type' => 'chat.bsky.convo.defs#logReadConvo', 'rev' => '3k006', 'convoId' => 'c1', 'message' => self::message('m1')],
			['$type' => 'chat.bsky.convo.defs#logReadMessage', 'rev' => '3k007', 'convoId' => 'c2', 'message' => self::message('m2')],
			['$type' => 'chat.bsky.convo.defs#logAcceptConvo', 'rev' => '3k008', 'convoId' => 'c2'],
		]];

		$this->assertSame(2, $this->poller()->pollAccount(self::cursor('3k005')));
		$this->assertSame([['read', 'c1/m1'], ['read', 'c2/m2']], $this->handed);
	}

	public function testChatReactionLogEventsAreAppliedBeforeTheCursorAdvances(): void {
		$this->answers['chat.bsky.convo.getLog'] = ['cursor' => '3k009', 'logs' => [
			['$type' => 'chat.bsky.convo.defs#logAddReaction', 'rev' => '3k006', 'convoId' => 'c1', 'message' => self::message('m1'), 'reaction' => ['value' => '❤️', 'sender' => ['did' => self::ALICE]]],
			['$type' => 'chat.bsky.convo.defs#logRemoveReaction', 'rev' => '3k007', 'convoId' => 'c1', 'message' => self::message('m1'), 'reaction' => ['value' => '❤️', 'sender' => ['did' => self::ALICE]]],
		]];

		$this->assertSame(2, $this->poller()->pollAccount(self::cursor('3k005')));
		$this->assertSame([
			['reaction-added', 'c1/m1/❤️/3k006'],
			['reaction-removed', 'c1/m1/❤️/3k007'],
		], $this->handed);
		$this->assertSame('3k009', $this->synced[0][1]);
	}

	public function testAQuietAccountIsAskedLessOftenButAtLeastEveryHalfHour(): void {
		$this->answers['chat.bsky.convo.getLog'] = ['logs' => []];

		$this->poller()->pollAccount(self::cursor('3k005', self::NOW - 600, self::NOW));
		$this->poller()->pollAccount(self::cursor('3k005', self::NOW - 1500, self::NOW));

		$this->assertSame(['3k005', 1200], [$this->synced[0][1], $this->synced[0][2]]);
		$this->assertSame(ChatPoller::MAX_BACKOFF, $this->synced[1][2]);
	}

	public function testAChatServiceThatDoesNotAnswerIsAskedAgainLater(): void {
		$this->assertSame(0, $this->poller()->pollAccount(self::cursor('3k005')));
		$this->assertSame([], $this->synced);
		$this->assertSame([self::ALICE, ChatPoller::INTERVAL * 2, self::TABLE], $this->failed[0]);
	}

	/** The clock is checked between accounts, so a slow chat service ends the pass at the deadline. */
	public function testAPassStopsAtItsDeadline(): void {
		$this->due = [self::cursor(''), self::cursor(''), self::cursor('')];
		$this->tick = 20;

		$this->assertSame(2, $this->poller()->poll(ChatPoller::BATCH, self::NOW + 30)['accounts'], 'the third account waits for the next pass');
		$this->assertSame(3, $this->poller()->poll(ChatPoller::BATCH)['accounts'], 'without a deadline every due account is read');
	}

	public function testNothingIsReadWithoutAChatService(): void {
		$this->chat = '';

		$this->assertSame(['accounts' => 0, 'handled' => 0], $this->poller()->poll());
		$this->assertFalse($this->poller()->isOn());
	}

	/** Switched off for Bluesky, the messages there are not read, and the cursor goes. */
	public function testAnAccountSwitchedOffForBlueskyIsNotReadAndLosesItsCursor(): void {
		$this->state = Identity::STATE_DEACTIVATED;
		$this->answers['chat.bsky.convo.getLog'] = ['logs' => []];

		$this->assertSame(0, $this->poller()->pollAccount(self::cursor('3k005')));

		$this->assertSame([], $this->asked);
		$this->assertSame([self::ALICE], $this->removed);
	}
}
