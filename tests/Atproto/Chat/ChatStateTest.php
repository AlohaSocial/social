<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Chat;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Chat\ChatPoller;
use OCA\Social\Atproto\Chat\ChatState;
use OCA\Social\Atproto\Chat\ChatStore;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Cron\AtprotoPublish;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\CacheActorService;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Reading, taking and turning down a conversation here does the same to it
 * on Bluesky, in the background.
 */
#[AllowMockObjectsWithoutExpectations]
class ChatStateTest extends TestCase {
	private const ALICE = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const BOB = 'did:plc:z72i7hdynmk6r22z27h6tvur';
	private const VIEWER = 'https://social.test/users/alice';

	private bool $on = true;
	private bool $hasIdentity = true;
	/** @var list<array{string, mixed}> */
	private array $queued = [];
	/** @var list<array{string, array, ?array}> */
	private array $calls = [];
	/** @var array<string, string> the status of each conversation on Bluesky */
	private array $status = ['c1' => 'request', 'c2' => 'accepted'];

	private function state(): ChatState {
		$poller = $this->createMock(ChatPoller::class);
		$poller->method('isOn')->willReturnCallback(fn (): bool => $this->on);
		$store = $this->createMock(ChatStore::class);
		$store->method('convoOf')->willReturnCallback(static fn (string $id): string => match ($id) {
			'https://bsky.app/profile/' . self::BOB . '/convo/c1/m1' => 'c1',
			'https://social.test/users/alice/statuses/7' => 'c2',
			default => '',
		});
		$identities = $this->createMock(IdentityService::class);
		$identities->method('forActor')->willReturnCallback(fn (): ?Identity => $this->hasIdentity
			? new Identity(1, self::VIEWER, self::ALICE, 'alice.social.test', 'sealed', '', '', Identity::STATE_ACTIVE, '', 0)
			: null);
		$identities->method('signingKey')->willReturn(PrivateKey::generate(Curve::K256));
		$appView = $this->createMock(AppViewClient::class);
		$appView->method('chatAs')->willReturnCallback(function (string $did, PrivateKey $key, string $method, array $params = [], ?array $input = null): array {
			$this->calls[] = [$method, $params, $input];

			return match ($method) {
				'chat.bsky.convo.getConvo' => ['convo' => ['id' => $params['convoId'], 'status' => $this->status[$params['convoId']] ?? 'accepted']],
				'chat.bsky.convo.getConvoAvailability' => ['canChat' => true, 'convo' => ['id' => 'c1', 'status' => $this->status['c1']]],
				default => [],
			};
		});
		$cacheActors = $this->createMock(CacheActorService::class);
		$cacheActors->method('getFromId')->willReturnCallback(static fn (string $id): Person => (new Person())->setId($id));
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('add')->willReturnCallback(function (string $job, mixed $argument): void {
			$this->queued[] = [$job, $argument];
		});

		return new ChatState($poller, $store, $identities, $appView, $cacheActors, $jobs);
	}

	private static function viewer(): Person {
		return (new Person())->setId(self::VIEWER);
	}

	private static function bob(): Person {
		return (new Person())->setId('https://bsky.app/profile/' . self::BOB);
	}

	public function testReadingAConversationHereQueuesReadingItsBlueskyConversations(): void {
		$this->state()->read(self::viewer(), ['https://bsky.app/profile/' . self::BOB . '/convo/c1/m1', 'https://social.test/users/alice/statuses/7', 'https://mastodon.test/users/carol/statuses/1']);

		$this->assertSame([[AtprotoPublish::class, ['action' => 'chat', 'id' => self::VIEWER, 'chat' => ChatState::READ, 'convos' => ['c1', 'c2'], 'member' => '']]], $this->queued);
	}

	public function testReadingEverythingHereReadsEverythingThere(): void {
		$this->state()->read(self::viewer(), ['https://bsky.app/profile/' . self::BOB . '/convo/c1/m1'], true);

		$this->assertSame(ChatState::READ_ALL, $this->queued[0][1]['chat']);
		$this->assertSame([], $this->queued[0][1]['convos']);
	}

	public function testNothingIsQueuedForAConversationWithNobodyOnBluesky(): void {
		$state = $this->state();
		$state->read(self::viewer(), ['https://mastodon.test/users/carol/statuses/1'], true);
		$state->dismissed(self::viewer(), ['https://mastodon.test/users/carol/statuses/1']);
		$state->acceptedSender(self::viewer(), (new Person())->setId('https://mastodon.test/users/carol'));

		$this->assertSame([], $this->queued);
	}

	public function testNothingIsQueuedWithoutMessagesOnBlueskyOrWithoutAnIdentity(): void {
		$this->on = false;
		$this->state()->read(self::viewer(), ['https://bsky.app/profile/' . self::BOB . '/convo/c1/m1']);
		$this->on = true;
		$this->hasIdentity = false;
		$this->state()->dismissedSender(self::viewer(), self::bob());

		$this->assertSame([], $this->queued);
	}

	public function testDecisionsAboutASenderAreQueuedByWhoTheyAre(): void {
		$state = $this->state();
		$state->acceptedSender(self::viewer(), self::bob());
		$state->dismissedSender(self::viewer(), self::bob());
		$state->dismissed(self::viewer(), ['https://bsky.app/profile/' . self::BOB . '/convo/c1/m1']);

		$this->assertSame([ChatState::ACCEPT, self::BOB], [$this->queued[0][1]['chat'], $this->queued[0][1]['member']]);
		$this->assertSame([ChatState::DECLINE, self::BOB], [$this->queued[1][1]['chat'], $this->queued[1][1]['member']]);
		$this->assertSame([ChatState::DECLINE, ['c1']], [$this->queued[2][1]['chat'], $this->queued[2][1]['convos']]);
	}

	public function testReadIsToldPerConversationOrForAll(): void {
		$state = $this->state();
		$state->apply(self::VIEWER, ChatState::READ, ['c1', 'c2']);
		$state->apply(self::VIEWER, ChatState::READ_ALL, []);

		$this->assertSame([
			['chat.bsky.convo.updateRead', [], ['convoId' => 'c1']],
			['chat.bsky.convo.updateRead', [], ['convoId' => 'c2']],
			['chat.bsky.convo.updateAllRead', [], []],
		], $this->calls);
	}

	public function testOnlyARequestIsAcceptedOrLeftAndAConversationIsNeverStartedForIt(): void {
		$state = $this->state();
		$state->apply(self::VIEWER, ChatState::DECLINE, ['c1', 'c2']);

		$this->assertSame(['chat.bsky.convo.leaveConvo', [], ['convoId' => 'c1']], $this->calls[2]);
		$this->assertCount(3, $this->calls, 'an accepted conversation is not left');

		$this->calls = [];
		$state->apply(self::VIEWER, ChatState::ACCEPT, [], self::BOB);
		$this->assertSame(['chat.bsky.convo.getConvoAvailability', ['members' => [self::BOB]], null], $this->calls[0]);
		$this->assertSame(['chat.bsky.convo.acceptConvo', [], ['convoId' => 'c1']], $this->calls[1]);

		$this->calls = [];
		$this->status['c1'] = 'accepted';
		$state->apply(self::VIEWER, ChatState::DECLINE, [], self::BOB);
		$this->assertCount(1, $this->calls, 'accepted already: nothing is left');
	}

	public function testAnUnknownActionDoesNothing(): void {
		$this->state()->apply(self::VIEWER, 'leave_everything', ['c1'], self::BOB);

		$this->assertSame([], $this->calls);
	}

	public function testNothingIsToldWhileMessagesAreOff(): void {
		$this->on = false;
		$this->state()->apply(self::VIEWER, ChatState::READ_ALL, []);

		$this->assertSame([], $this->calls);
	}
}
