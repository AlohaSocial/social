<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Chat;

use OCA\Social\Atproto\AppView\AppViewClient;
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

	private string $chat = 'https://api.bsky.chat';
	/** @var array<string, array> answers by method */
	private array $answers = [];
	/** @var list<array{string, array}> */
	private array $asked = [];
	/** @var list<array{string, string}> what the store was handed: kind and message id */
	private array $handed = [];
	/** @var list<array> */
	private array $synced = [];
	/** @var list<array> */
	private array $failed = [];

	private function poller(): ChatPoller {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$config->method('chat')->willReturnCallback(fn (): string => $this->chat);
		$config->method('syncCeiling')->willReturn(200);
		$identities = $this->createMock(IdentityService::class);
		$identities->method('getByDid')->willReturn(new Identity(1, 'https://social.test/users/alice', self::ALICE, 'alice.social.test', 'sealed', '', '', Identity::STATE_ACTIVE, '', 0));
		$identities->method('signingKey')->willReturn(PrivateKey::generate(Curve::K256));
		$appView = $this->createMock(AppViewClient::class);
		$appView->method('chatAs')->willReturnCallback(function (string $did, PrivateKey $key, string $method, array $params = []): array {
			$this->asked[] = [$method, $params];

			return $this->answers[$method] ?? throw new AtprotoException('chat service down');
		});
		$cursors = $this->createMock(AtprotoWatchRequest::class);
		$cursors->method('synced')->willReturnCallback(function (string $did, string $cursor, int $now, int $next, string $table): void {
			$this->synced[] = [$did, $cursor, $next - $now, $table];
		});
		$cursors->method('failed')->willReturnCallback(function (string $did, string $error, int $next, string $table): void {
			$this->failed[] = [$did, $next - self::NOW, $table];
		});
		$store = $this->createMock(ChatStore::class);
		$store->method('received')->willReturnCallback(function (Identity $identity, string $convo, array $message): bool {
			$this->handed[] = ['received', $convo . '/' . $message['id']];

			return true;
		});
		$store->method('deleted')->willReturnCallback(function (string $convo, array $message): bool {
			$this->handed[] = ['deleted', $convo . '/' . $message['id']];

			return true;
		});
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new ChatPoller($config, $identities, $cursors, $appView, $store, $time, new NullLogger());
	}

	private static function cursor(string $rev, int $lastSync = 0, int $nextSync = 0): Watch {
		return new Watch(self::ALICE, 'alice.social.test', $rev, $lastSync, $nextSync, 0, '', 0);
	}

	private static function message(string $id): array {
		return ['id' => $id, 'text' => 'hi', 'sender' => ['did' => self::BOB], 'sentAt' => '2026-10-09T10:00:00.000Z'];
	}

	public function testTheFirstReadTakesTheUnreadMessagesOldestFirstAndStartsTheLogAtTheNewest(): void {
		$this->answers['chat.bsky.convo.listConvos'] = ['convos' => [
			['id' => 'c1', 'rev' => '3k002', 'unreadCount' => 2],
			['id' => 'c2', 'rev' => '3k005', 'unreadCount' => 0],
		]];
		$this->answers['chat.bsky.convo.getMessages'] = ['messages' => [self::message('m2'), self::message('m1')]];

		$this->assertSame(2, $this->poller()->pollAccount(self::cursor('')));

		$this->assertSame([['received', 'c1/m1'], ['received', 'c1/m2']], $this->handed);
		$this->assertSame(['convoId' => 'c1', 'limit' => 2], $this->asked[1][1]);
		$this->assertSame([self::ALICE, '3k005', ChatPoller::INTERVAL, self::TABLE], $this->synced[0]);
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

	public function testNothingIsReadWithoutAChatService(): void {
		$this->chat = '';

		$this->assertSame(['accounts' => 0, 'handled' => 0], $this->poller()->poll());
		$this->assertFalse($this->poller()->isOn());
	}
}
