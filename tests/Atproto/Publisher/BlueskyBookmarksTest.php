<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Publisher;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Client\ClientSession;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Publisher\BlueskyBookmarks;
use OCA\Social\Atproto\Publisher\PostRefs;
use OCA\Social\Atproto\Reader\LocalRecordResolver;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\StreamAction;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\StreamActionService;
use OCA\Social\Service\StreamService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class BlueskyBookmarksTest extends TestCase {
	private const BLUESKY_POST = 'https://bsky.app/profile/did:plc:bob/post/3k';
	private const URI = 'at://did:plc:bob/app.bsky.feed.post/3k';

	private array $told = [];
	private array $set = [];
	private BlueskyBookmarks $bookmarks;
	private Identity $identity;
	/** @var Note[] the person's bookmarks here */
	private array $timeline = [];

	protected function setUp(): void {
		$this->identity = new Identity(1, 'https://social.test/@alice', 'did:plc:alice', 'alice.social.test', 'sealed', '', '', Identity::STATE_ACTIVE, '', 0);
		$appView = $this->createMock(AppViewClient::class);
		$appView->method('procedureAs')->willReturnCallback(function (string $did, PrivateKey $key, string $method, array $input): array {
			$this->told[] = [$method, $input];

			return [];
		});
		$appView->method('queryAs')->willReturn(['posts' => [['uri' => self::URI, 'cid' => 'bafy', 'record' => ['text' => 'hi']]]]);
		$identities = $this->createMock(IdentityService::class);
		$identities->method('forActor')->willReturn($this->identity);
		$identities->method('signingKey')->willReturn(PrivateKey::generate(Curve::K256));
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturnCallback(static fn (string $id): ?array => match ($id) {
			self::BLUESKY_POST => ['uri' => self::URI, 'cid' => 'bafy'],
			'https://social.test/@alice/gone' => ['uri' => 'at://did:plc:alice/app.bsky.feed.post/gone', 'cid' => 'bafg'],
			default => null,
		});
		$local = $this->createMock(LocalRecordResolver::class);
		$local->method('postId')->willReturnCallback(static fn (string $uri): string => $uri === self::URI ? self::BLUESKY_POST : '');
		$store = $this->createMock(PostStore::class);
		$store->method('isKnown')->willReturn(true);
		$streamActions = $this->createMock(StreamActionService::class);
		$streamActions->method('setActionBool')->willReturnCallback(function (string $actor, string $post, string $key, bool $on): void {
			$this->set[] = [$post, $key, $on];
			// what the action service does on any bookmark: tell Bluesky
			$this->bookmarks->bookmarked((new Person())->setId($actor), $post, $on);
		});
		$streams = $this->createMock(StreamService::class);
		$streams->method('getTimeline')->willReturnCallback(fn (): array => $this->timeline);
		$accounts = $this->createMock(AccountService::class);
		$accounts->method('getActorFromUserId')->willReturn((new Person())->setId('https://social.test/@alice'));
		$this->bookmarks = new BlueskyBookmarks($appView, $identities, $refs, $local, $store, $streamActions, $streams, $accounts, new NullLogger());
	}

	public function testABookmarkHereOfAPostOnBlueskyIsToldToTheAppView(): void {
		$alice = (new Person())->setId('https://social.test/@alice');
		$this->bookmarks->bookmarked($alice, self::BLUESKY_POST, true);
		$this->bookmarks->bookmarked($alice, 'https://remote.example/notes/1', true);
		$this->bookmarks->bookmarked($alice, self::BLUESKY_POST, false);

		$this->assertSame([
			[BlueskyBookmarks::CREATE, ['uri' => self::URI, 'cid' => 'bafy']],
			[BlueskyBookmarks::DELETE, ['uri' => self::URI]],
		], $this->told, 'a post that is not on Bluesky is not told');
	}

	public function testAnAppsBookmarkIsMadeHereWithoutBeingToldBack(): void {
		$this->bookmarks->fromApp(new ClientSession('alice', $this->identity, 'jti'), BlueskyBookmarks::CREATE, ['uri' => self::URI, 'cid' => 'bafy']);

		$this->assertSame([[self::BLUESKY_POST, StreamAction::BOOKMARKED, true]], $this->set);
		$this->assertSame([], $this->told);
	}

	public function testTheAppsListIsTheBookmarksHereThatAreOnBluesky(): void {
		$this->timeline = [
			(new Note())->setId(self::BLUESKY_POST)->setNid(30),
			(new Note())->setId('https://remote.example/notes/1')->setNid(20),
			(new Note())->setId('https://social.test/@alice/gone')->setNid(10),
		];

		$answer = $this->bookmarks->list(new ClientSession('alice', $this->identity, 'jti'), 3, '');

		$this->assertSame(['uri' => self::URI, 'cid' => 'bafy'], $answer['bookmarks'][0]['subject']);
		$this->assertSame('app.bsky.feed.defs#postView', $answer['bookmarks'][0]['item']['$type']);
		$this->assertSame('app.bsky.feed.defs#notFoundPost', $answer['bookmarks'][1]['item']['$type'], 'a post the AppView no longer has');
		$this->assertCount(2, $answer['bookmarks'], 'a post that is not on Bluesky is not in it');
		$this->assertSame('10', $answer['cursor']);
	}
}
