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
use OCA\Social\Service\IRecommendationSignalRecorder;
use OCA\Social\Service\StreamActionService;
use OCA\Social\Service\StreamService;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

#[AllowMockObjectsWithoutExpectations]
class BlueskyBookmarksTest extends TestCase {
	private const BLUESKY_POST = 'https://bsky.app/profile/did:plc:bob/post/3k';
	private const URI = 'at://did:plc:bob/app.bsky.feed.post/3k';

	private array $told = [];
	private array $queued = [];
	private array $set = [];
	private BlueskyBookmarks $bookmarks;
	private Identity $identity;
	/** @var Note[] the person's bookmarks here */
	private array $timeline = [];
	private array $references = [];
	private array $views = [];
	private array $reads = [];
	private IRecommendationSignalRecorder $recommendationSignals;
	private bool $currentlyBookmarked = false;
	private bool $active = true;
	private string $failedUri = '';

	protected function setUp(): void {
		$this->identity = new Identity(1, 'https://social.test/@alice', 'did:plc:alice', 'alice.social.test', 'sealed', '', '', Identity::STATE_ACTIVE, '', 0);
		$this->references = [
			self::BLUESKY_POST => ['uri' => self::URI, 'cid' => 'bafy'],
			'https://social.test/@alice/gone' => ['uri' => 'at://did:plc:alice/app.bsky.feed.post/gone', 'cid' => 'bafg'],
		];
		$this->views = [self::URI => ['uri' => self::URI, 'cid' => 'bafy', 'record' => ['text' => 'hi']]];
		$appView = $this->createMock(AppViewClient::class);
		$appView->method('procedureAs')->willReturnCallback(function (string $did, PrivateKey $key, string $method, array $input): array {
			$this->told[] = [$method, $input];

			return [];
		});
		$appView->method('queryAs')->willReturnCallback(fn (string $did, PrivateKey $key, string $method, array $params): array => $this->readPosts($params, true));
		$appView->method('query')->willReturnCallback(fn (string $method, array $params): array => $this->readPosts($params, false));
		$identities = $this->createMock(IdentityService::class);
		$identities->method('activeForActor')->willReturnCallback(fn (): ?Identity => $this->active ? $this->identity : null);
		$identities->method('signingKey')->willReturn(PrivateKey::generate(Curve::K256));
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturnCallback(fn (string $id): ?array => $this->references[$id] ?? null);
		$local = $this->createMock(LocalRecordResolver::class);
		$local->method('postId')->willReturnCallback(static fn (string $uri): string => $uri === self::URI ? self::BLUESKY_POST : '');
		$store = $this->createMock(PostStore::class);
		$store->method('isKnown')->willReturn(true);
		$streamActions = $this->createMock(StreamActionService::class);
		$streamActions->method('getActionBool')->willReturnCallback(function (): bool {
			return $this->currentlyBookmarked;
		});
		$streamActions->method('setActionBool')->willReturnCallback(function (string $actor, string $post, string $key, bool $on): void {
			$this->set[] = [$post, $key, $on];
			$this->currentlyBookmarked = $on;
			// what the action service does on any bookmark: tell Bluesky
			$this->bookmarks->bookmarked((new Person())->setId($actor), $post, $on);
		});
		$streams = $this->createMock(StreamService::class);
		$streams->method('getTimeline')->willReturnCallback(fn (): array => $this->timeline);
		$accounts = $this->createMock(AccountService::class);
		$accounts->method('getActorFromUserId')->willReturn((new Person())->setId('https://social.test/@alice'));
		$streams->method('getStreamById')->willReturn(new Note());
		$this->recommendationSignals = $this->createMock(IRecommendationSignalRecorder::class);
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('add')->willReturnCallback(function (string $job, array $argument): void {
			$this->queued[] = [$job, $argument];
		});
		$this->bookmarks = new BlueskyBookmarks($appView, $identities, $refs, $local, $store, $streamActions, $streams, $accounts, $this->recommendationSignals, $jobs, new NullLogger());
	}

	public function testABookmarkHereOfAPostOnBlueskyQueuesAnAppViewSyncThatReadsTheLatestState(): void {
		$alice = (new Person())->setId('https://social.test/@alice');
		$this->bookmarks->bookmarked($alice, self::BLUESKY_POST, true);
		$this->bookmarks->bookmarked($alice, 'https://remote.example/notes/1', true);
		$this->bookmarks->bookmarked($alice, self::BLUESKY_POST, false);
		$this->currentlyBookmarked = true;
		$this->bookmarks->syncCurrent($alice, self::BLUESKY_POST);
		$this->currentlyBookmarked = false;
		$this->bookmarks->syncCurrent($alice, self::BLUESKY_POST);

		$this->assertSame([
			[\OCA\Social\Cron\AtprotoPublish::class, ['action' => 'bookmark', 'id' => self::BLUESKY_POST, 'actor' => $alice->getId()]],
			[\OCA\Social\Cron\AtprotoPublish::class, ['action' => 'bookmark', 'id' => self::BLUESKY_POST, 'actor' => $alice->getId()]],
		], $this->queued, 'each state change is durably queued');
		$this->assertSame([
			[BlueskyBookmarks::CREATE, ['uri' => self::URI, 'cid' => 'bafy']],
			[BlueskyBookmarks::DELETE, ['uri' => self::URI]],
		], $this->told, 'the worker syncs the latest state; a post that is not on Bluesky is ignored');
	}

	public function testAnAppsBookmarkIsMadeHereWithoutBeingToldBack(): void {
		$this->bookmarks->fromApp(new ClientSession('alice', $this->identity, 'jti'), BlueskyBookmarks::CREATE, ['uri' => self::URI, 'cid' => 'bafy']);

		$this->assertSame([[self::BLUESKY_POST, StreamAction::BOOKMARKED, true]], $this->set);
		$this->assertSame([], $this->told);
	}

	public function testAnAppsBookmarkAndRemovalUpdateConsentedLearningOncePerTransition(): void {
		$session = new ClientSession('alice', $this->identity, 'jti');
		$viewer = (new Person())->setId('https://social.test/@alice');
		$actions = [];
		$this->recommendationSignals->expects($this->exactly(2))->method('recordAction')->willReturnCallback(
			function (Person $actor, Note $status, string $action) use ($viewer, &$actions): void {
				$this->assertSame($viewer->getId(), $actor->getId());
				$this->assertInstanceOf(Note::class, $status);
				$actions[] = $action;
			}
		);

		$this->bookmarks->fromApp($session, BlueskyBookmarks::CREATE, ['uri' => self::URI, 'cid' => 'bafy']);
		$this->bookmarks->fromApp($session, BlueskyBookmarks::CREATE, ['uri' => self::URI, 'cid' => 'bafy']);
		$this->bookmarks->fromApp($session, BlueskyBookmarks::DELETE, ['uri' => self::URI]);

		$this->assertSame([
			[self::BLUESKY_POST, StreamAction::BOOKMARKED, true],
			[self::BLUESKY_POST, StreamAction::BOOKMARKED, true],
			[self::BLUESKY_POST, StreamAction::BOOKMARKED, false],
		], $this->set);
		$this->assertSame(['save', 'unsave'], $actions);
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

	public function testBookmarkCursorKeepsNidsLargerThanNativeIntegersExact(): void {
		$this->timeline = [
			(new Note())->setId(self::BLUESKY_POST)->setNid('9223372036854775809'),
			(new Note())->setId(self::BLUESKY_POST . '/2')->setNid('9223372036854775808'),
			(new Note())->setId(self::BLUESKY_POST . '/3')->setNid('9223372036854775810'),
		];

		$answer = $this->bookmarks->list(new ClientSession('alice', $this->identity, 'jti'), 3, '');

		$this->assertSame('9223372036854775808', $answer['cursor']);
	}

	#[DataProvider('bookmarkPages')]
	public function testBookmarkPreviewsRespectTheAppViewLimitAndKeepTheirOrder(int $count, bool $active): void {
		$uris = $this->fillBookmarks($count);
		$this->active = $active;

		$answer = $this->bookmarks->list(new ClientSession('alice', $this->identity, 'jti'), $count ?: 50, '');

		$this->assertSame($uris, array_column(array_column($answer['bookmarks'], 'item'), 'uri'));
		foreach ($answer['bookmarks'] as $bookmark) {
			$this->assertSame('app.bsky.feed.defs#postView', $bookmark['item']['$type']);
		}
		$this->assertSame(array_map(static fn (array $chunk): array => ['uris' => $chunk, 'authenticated' => $active], array_chunk($uris, 25)), $this->reads);
		if ($count > 0) {
			$this->assertSame((string)(1000 - $count), $answer['cursor']);
		} else {
			$this->assertArrayNotHasKey('cursor', $answer);
		}
	}

	public static function bookmarkPages(): iterable {
		foreach ([0, 25, 26, 50, 100] as $count) {
			yield "{$count} authenticated" => [$count, true];
			yield "{$count} public" => [$count, false];
		}
	}

	public function testAFailedBatchKeepsSuccessfulPreviewsAndDoesNotStopLaterBatches(): void {
		$uris = $this->fillBookmarks(60);
		$this->failedUri = $uris[25];

		$answer = $this->bookmarks->list(new ClientSession('alice', $this->identity, 'jti'), 60, '');

		$this->assertCount(3, $this->reads);
		$this->assertSame($uris, array_column(array_column($answer['bookmarks'], 'subject'), 'uri'));
		foreach ($answer['bookmarks'] as $index => $bookmark) {
			$this->assertSame($index >= 25 && $index < 50 ? 'app.bsky.feed.defs#notFoundPost' : 'app.bsky.feed.defs#postView', $bookmark['item']['$type']);
		}
		$this->assertSame('940', $answer['cursor']);
	}

	private function fillBookmarks(int $count): array {
		$uris = [];
		for ($index = 1; $index <= $count; $index++) {
			$id = self::BLUESKY_POST . $index;
			$uri = self::URI . $index;
			$this->timeline[] = (new Note())->setId($id)->setNid(1000 - $index);
			$this->references[$id] = ['uri' => $uri, 'cid' => 'bafy'];
			$this->views[$uri] = ['uri' => $uri, 'cid' => 'bafy', 'record' => ['text' => 'post ' . $index]];
			$uris[] = $uri;
		}

		return $uris;
	}

	private function readPosts(array $params, bool $authenticated): array {
		$uris = $params['uris'];
		$this->reads[] = ['uris' => $uris, 'authenticated' => $authenticated];
		if (count($uris) > 25 || in_array($this->failedUri, $uris, true)) {
			throw new RuntimeException('AppView refused the request');
		}

		return ['posts' => array_reverse(array_values(array_intersect_key($this->views, array_flip($uris))))];
	}
}
